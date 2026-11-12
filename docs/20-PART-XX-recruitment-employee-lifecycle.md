# PART XX — RECRUITMENT AND EMPLOYEE LIFECYCLE

*Covers brief Sections 61, 62, 63, 64, 65, 66, 67 and 68.*
*Conforms to spine S3 (org model, scope, precedence), S4 (effective dating, draft/publish),
S5 (SPX), S6 (rule engine), S7 (formula engine), S8 (workflow engine), S9 (entity schema
engine), S11 (payroll pipeline), S12 (statutory rule packs), S13 (security), S14 (audit
ledger), S15 (explainability), S16 (data conventions), S17 (API), S19 (preserve what works).*
*Depends on: Part V (domain model), Part VI (configuration), Part VII (rule engine),
Part VIII (formula engine), Part IX (workflow engine), **Part X (employee schema engine —
which owns `EntityWriteService`, the field definition model, the effective-dated assignment
tables, the employment status machine and self-onboarding)**, Part XII (work week, weekly
off, holiday), Part XIII (leave — encashment at exit), Part XIV (payroll), Part XV (salary
structure), Part XVI (payslip), Part XVII (India statutory), Part XVIII (loans, advances,
recoveries), Part XXVIII (database and ERD — which registers the C10 Talent tables this
part specifies).*
*Depended on by: Part XIV (payroll — joiners, leavers, arrears, F&F), Part XVII (statutory
— exit-triggered filings), Part XVI (payslip — the settlement statement), the compliance
module (a hire's compliance file starts here), and the reporting layer.*
*Evidence base: `analysis/H-recruitment-hrops-reports.md`, `analysis/D-employee-master.md`,
`analysis/E-leave-requests-notifications.md`, `analysis/N-product-business-modules.md`.
Every claim about SmartPRS 2 in this part carries a `file.php:line` citation carried through
from those four files.*

---

## XX.0 Purpose

This part decides six things.

1. **How a person enters the organisation** — a configurable recruitment pipeline with a
   real transition matrix, a candidate entity built on the same metadata engine as the
   employee (Part X), and a **conversion that re-enters nothing and drops nothing**.
2. **What a signed document is** — a rendered, hashed, immutable artefact captured at the
   instant of signature, together with the evidence that makes it defensible, replacing a
   template pointer that silently rewrites itself.
3. **How employment changes over time** — probation and confirmation, increment and
   promotion, transfer — each as an *effective-dated change to an assignment* (Part X §X.6)
   driven by the workflow engine, never as a direct write to a column.
4. **How employment ends** — resignation, notice, clearance, encashment, terminal benefits,
   recovery, the full and final statement, the letters, and the retention class that governs
   what survives.
5. **What an employee may do for themselves** — the ESS surface, and the structural rule
   that makes acting on behalf of a colleague *impossible to express* rather than merely
   forbidden.
6. **What a manager may see and do** — a surface bounded by the effective-dated reporting
   line, resolved as at the date being viewed.

Two of these exist because of security defects rated **CRITICAL** in the as-is analysis, and
one exists because a compliance control that customers are scored on **cannot be operated at
all**. §XX.1 is the evidence.

---

## XX.1 What SmartPRS 2 does today, and why it must change

Presented in order of consequence, not in order of code.

### XX.1.1 The NDA compliance control cannot be operated — every NDA score is unearned

The compliance score that the product presents to banks and to RBI-facing audits awards
**15 of 100 points** for a signed NDA:

```
+15 if EXISTS letters WHERE employee_id = ? AND letter_type = 'nda'
                        AND is_template = 0 AND status = 'signed'
                                              -- ComplianceController::scoreFor() :289-330
```
*(`N-product-business-modules.md` §4.4.)*

The screen that would create that row is registered in the SPA in full — nav id
(`AppController.php:667`), icon (`:1381`), title "NDA / Confidentiality" (`:3251`), a
complete field set with a template picker and Preview / Email / Send-for-e-signature actions
(`:10850`) — but **`MasterController::def()` has no `letters-nda` entry**
(`MasterController.php:356-389`). `GET /app/master/letters-nda` returns
`{"rows":[],"error":"Unknown master"}`; `POST` returns `422 "Unknown master"`.
*(`H-recruitment-hrops-reports.md` §1.8, Q4.)*

Two consequences, both material:

- **An NDA row can only be produced by direct SQL.** A perfectly compliant agent — valid
  DRA, verified PCC, clear BGV, no complaints — **cannot score above 85 through the user
  interface** (`N-…` §4.4 worked example).
- Any existing installation showing a score that includes the NDA component is showing a
  score that was not earned through the product. The analysis states this plainly:
  *"Until this is answered, every NDA component of every compliance score should be treated
  as unearned"* (`H-…` PO-01).

This is carried into this part as **D-XX-05** (§XX.20), and it is the reason §XX.4 treats the
document engine as a compliance artefact rather than a convenience.

### XX.1.2 The recruitment pipeline is a `private const`, and there is no transition matrix

```php
private const STAGES = ['applied','screening','interview','offer','hired','rejected'];
                                                    -- RecruitmentController.php:31
```

`moveStage()` validates membership of that list **and nothing else** (`:396-419`). Any stage
may move to any other stage, in any order, at any time. A `rejected` candidate can be moved
directly to `hired`. There is no transition matrix, no guard, no required-field-per-stage, no
approval, no reason capture except when the target happens to be `rejected`.
*(BR-REC-006, NEW-REC-101.)*

The same shape repeats for every other vocabulary in the module: approval states (`:33`),
interview modes (`:35`), interview statuses (`:37`), recommendations (`:39`) and requisition
priorities (`:301`) are all fixed lists in code (NEW-REC-102, NEW-REC-103). The analysis
records the pipeline as *"the single most-requested ATS change"*.

Three further consequences of there being no matrix:

| Effect | Evidence |
|---|---|
| Interview scores and recommendations **gate nothing** — a `strong_no_hire` candidate can be moved to *offer* and hired with no warning | BR-REC-020; the only readers of `interviews.score` / `.recommendation` are `board()` and `interviews()` |
| The "recruitable" flag on a requisition is computed **for display only and enforced nowhere** — `saveCandidate`, `assignToReq` and `assignBulk` all attach candidates to any requisition, approved or not | BR-REC-004; `:260` vs `:363-374`, `:1094-1106`, `:1128-1135` |
| A requisition with an **unrecognised** `approval_status` is read as *approved* | BR-REC-005, `:160-165` |

### XX.1.3 Candidate fields are ~30 fixed columns, with no custom-field mechanism anywhere

The candidate table is created at runtime with `id, tenant_id, timestamps` and then has
**30 further columns bolted on** by `addCols()` in four documented waves
(`RecruitmentController.php:93-114`), plus `last_contacted_at` and `contact_status` added
**inside the WhatsApp send loop** (`RecruitMessagingController::stampCandidate():240-246`).

> *"Candidate fields are not extensible: the ~30 columns are declared in `ensure()`
> (`:100-114`) and there is **no custom-field mechanism anywhere in the product**."*
> — `H-…` Q2

Adding "LinkedIn profile", "referred by", "visa status" or "notice period in days" is a code
change and a release (NEW-REC-104). The downloadable import template does not even match the
importer it is for: `template()` advertises eight columns `importCandidates()` never reads
and omits the two it does understand (`:795-809` vs `:812-878`).

### XX.1.4 Conversion keeps 12 fields, drops 16, never captures the statutory identity, and is idempotent by name

`createEmployeeFromCandidate()` (`RecruitmentController.php:579-645`) is the only
candidate→employee path. The analysis enumerates it exactly (`H-…` Q3;
`D-employee-master.md` §Q6 row 5, §"Registry fields present" row REC):

| | Fields |
|---|---|
| **Carried** (7 from the candidate, `:626-635`) | `name`, `email`, `mobile`, `position → designation_id` (looked up, **auto-created if missing**), `offered_ctc → ctc`, `offer_doj → doj` (falling back to today), `company_id` |
| **Supplied at conversion time** by the Hire dialog (5) | `department_id`, `branch_id`, `reporting_manager_id`, `salary_type` (default `only_salary`), `type` (default `office`) |
| **Held on the candidate and silently dropped** (16) | `location`, `experience_years`, `current_company`, `current_designation`, `current_ctc`, `expected_ctc`, `skills`, `resume_url`, `notes`, `interview_remarks`, `offer_incentive`, `joining_bonus`, `offer_notes`, `rating`, `source`, `applied_on` — **plus the entire interview history**, which stays linked to the candidate and never reaches the employee |
| **Never captured at any point in recruitment**, so they must be re-entered later | `pan`, `uan`, `bank_acc`, `ifsc`, `pf_no`, address, date of birth, emergency contact, references, DRA/PCC status. *The candidate table has no columns for any of them.* |

Two structural failures follow.

**Failure 1 — the new joiner starts non-compliant.** Because nothing statutory or
compliance-related is ever captured, *"a newly hired agent starts with an empty compliance
file and is immediately reported as non-compliant by the agent audit"* (`H-…` Q3). The
payroll preview then blocks on missing PAN, UAN and bank data (spine S11), for a person the
business has already hired.

**Failure 2 — a namesake is never hired at all.**

```php
// RecruitmentController.php:588-596
if (a live employee in this tenant already has name = $candidate->name)
      → mark the candidate hired, reconcile the requisition fill, ensure onboarding,
        return the existing employee's code, and CREATE NOTHING
```

> **NEW-EMP-077** — *"Hiring a second 'Ravi Kumar' silently creates NO employee and reports
> the first one's code as the hire."* `createOffrollFromCandidate()` does the same (`:668`).
> — `D-…` §Q6 row 4

The same name-keyed idempotency appears twice more in the lifecycle: the onboarding record
(`:750-769`) and the transfer-destination onboarding
(`TransferService.php:91`) — *"a namesake's open onboarding suppresses the transferee's
destination checklist"* (`D-…` §Q6 row 7).

Conversion also **creates master data as a side effect**: a free-text candidate position with
no name match causes an `INSERT designations`, polluting the master with near-duplicates
(BR-REC-015). And the employee code is `EMP` + zero-padded `(count + 1)`, computed
non-transactionally with no unique index — *"codes are reused after deletion"*, and two
concurrent creates can both pass the `exists()` check and insert the same code
(`H-…` §4.1; BR-REC-014).

### XX.1.5 An off-roll hire produces no employee record, no onboarding, no seat, and no compliance visibility

`engagement = 'off_roll'` diverts the hire to `createOffrollFromCandidate()`
(`RecruitmentController.php:557-562`, `:648-688`), which inserts an `offroll_agents` row and
stops. BR-REC-016 records the consequence verbatim: **"no seat check, no onboarding, no
compliance record"**; `dra` and `pcc` are captured as free strings that nothing reads.

`N-…` §1.7 module 20 confirms the module-level position: off-roll agents are
*"excluded from all compliance scanning and all seat counting"* — while being exactly the
population that RBI recovery-agent norms target. The analysis calls this
*"simultaneously a compliance hole and a revenue leak"* (`H-…` PO-03).

The two `offroll_agents` schemas also disagree: the migration declares `company_id NOT NULL`
and no `company_name`; the runtime creator declares `company_name` and no `company_id`; the
`MasterController` definition declares `company_name` (`H-…` §2.2).

### XX.1.6 The public offer and NDA token flows are permanent, unauthenticated signing capabilities

`H-…` Q5 assesses the four public routes in full. Reproduced with its findings intact:

| Property | Finding |
|---|---|
| Generation | `Str::random(40)` ≈ 238 bits — cryptographically ample (`LetterController.php:402`) |
| Storage | plaintext in `letters.accept_token`; **no unique index, no index at all** |
| Expiry | **none.** No column, no TTL, no check. *The 404 text on both routes claims an expiry that does not exist* |
| Rotation | **none** — re-sending reuses the existing token (`?:` at `:402`) |
| Revocation | **none.** No code path clears the token |
| Single use | **not enforced** — the token still resolves after acceptance |
| Rate limiting | **none** on any of the four routes |
| CSRF | offer accept: protected. **NDA sign: explicitly exempted** (`routes/web.php:63-64`) |
| Server-side validation | **NDA: none** — neither the typed name nor the consent tick is required or checked (`NdaSignController.php:96`) |
| Type scoping | **the offer route does not filter `letter_type` at all** — any non-template letter with a token resolves and can be "accepted" (`OfferAcceptController.php:36`, NEW-DOC-101) |
| Evidence captured | `status`, `accepted_at` (server time), `accepted_ip`, and for NDA `signed_name` |
| Evidence **not** captured | user agent, device, signature image, consent record, the document content, a content hash, a rendered PDF, any identity verification, **and any `activity_logs` entry** |
| Identity proof | **none.** Anyone holding the URL can bind the named person. *The product already implements OTP on other public token routes (self-onboarding) and does not here* |

And the offer page itself never shows the offer:

> **NEW-DOC-103** — the public acceptance page renders **four fields**: name, position,
> company and offer date. Never the offer body, CTC, incentive, joining bonus or joining
> date. *"This is what makes the 'acceptance' evidentially worthless."*
> — `resources/views/offer-accept.blade.php:38-44`

The analysis's verdict: **"not secure, and not adequate as a legal artefact."**

### XX.1.7 The signed artefact is a pointer to a mutable template, and the PDF store was never built

An issued letter row has **no body of its own**. `LetterController::buildLetter()`
(`:53-68`) re-merges the *current* template at every render.

> **NEW-DOC-104** — *"The signed artefact stores a template pointer, never a snapshot …
> editing a template silently rewrites what someone signed."*

Template resolution compounds it: chosen template → **the newest template of that type in the
tenant** → a built-in default → 422. `letters.status` (`active`/`inactive`) is written by the
UI and **never read**, so deactivating a template does not stop it being used (BR-CMP-023).
The merge itself is a single literal `strtr()` over **21 `{{placeholder}}` keys**; unknown
placeholders print verbatim, and the key list is code (BR-CMP-024).

And the snapshot store exists as a column and nothing else:

> `letters.pdf_path` is **never written or read by any code** — a column for an issued-PDF
> store that was never built. — `H-…` §2.2

Finally, the letter-type vocabulary is a 7-value dropdown of which only 5 have issue screens
and one of those five is the broken `letters-nda`; a customer cannot add "Appointment
Letter", "Confirmation Letter", "Transfer Letter" or "Show Cause" (NEW-DOC-106).

### XX.1.8 A self-service transaction takes its subject from the request body

This is the most serious authorisation failure in the request area, and the analysis rates
both findings **CRITICAL**.

> **SEC-02 — Any authenticated user can raise any request type for any colleague.**
> The self-forcing rule exists **only** inside `if ($module === 'commissions')`. The eight
> other modules — expenses, advances, loans, clawbacks, increments, exits, bonus, transfers —
> accept an arbitrary `employee` string with no role check and no relationship check.
> `RequestController::apply():405-419` (the guard) vs `:389-396` (the unguarded lookup).
> *Trivial:* `POST /app/requests/exits {employee: "CEO", …}`. An employee can file an
> **exit/F&F for the CEO**, a ₹5,00,000 **loan** for a colleague, or an **increment** for
> themselves. — `E-leave-requests-notifications.md` §7

> **SEC-01 — Every request module is readable, tenant-wide, by any authenticated user.**
> `RequestController::list()` has **no role guard**, and row-level scoping is applied only
> when `$module === 'commissions'` (`list():125-178`, the `when()` at `:155`). Any employee
> reads **every colleague's salary increment (old CTC → new CTC), every loan, every salary
> advance, every expense claim, every exit record with its clearance remarks, and every
> transfer** for the whole tenant. — `E-…` §7

The root cause is stated in SmartPRS 2's own source: *"SmartPRS gates the menu **CLIENT-SIDE**
… and gates writes SERVER-SIDE"* (`FeaturePermissions.php:9-13`). Leave was fixed after a
7 Aug 2026 test report (`LeaveController.php:296-302`) and **the fix was never propagated**.

For completeness and fairness to the code: `EssController` itself gets this right. **NEW-ESS-101**
records that ESS resolves the subject only from the session and that *"no ESS transaction
takes an employee id from the request"* (`EssController::currentEmployee():43-59`). The
defect is that the *other* self-service transaction surface — the nine request modules the
same employee uses for an advance, a loan or an exit — does not. SmartPRS 3 does not have two
surfaces, so it cannot have two rules (§XX.9.2).

Three further ESS defects carried into §XX.9:

| ID | Finding | Evidence |
|---|---|---|
| NEW-ESS-102 | Which ten fields are self-editable is a **literal validation array** | `EssController.php:266-277` |
| NEW-ESS-104 / SEC-08 | `email` is self-changeable with **no verification** — and payslips, leave decisions and request decisions are all delivered to `employees.email` | `:269` |
| NEW-ESS-105 / DEF-ESS-02 | **ESS self-edits are entirely unaudited** — no `Audit::record`, no `activity_logs` row | absence in `updateProfile():259-294` |
| NEW-ESS-103 | `dob` is accepted as **any string ≤ 20 chars**, and `GreetingService` selects birthday recipients by `DATE_FORMAT(dob,'%m-%d')` — an employee can choose the day they are greeted | `:276` |
| SEC-23 | Identity resolution falls back to a **name match** (`employees.name = users.name`, `->first()`, no ordering) — *"a user may act as, and see the data of, a namesake"* | `RequestController.php:31-50`; identical at `LeaveController.php:63-81` and `EssController.php:43-59` |
| NEW-ESS-109 | Neither ESS route is rate-limited | `routes/web.php:598-599` |

### XX.1.9 Probation, increment, transfer and exit, as they stand

| Area | What exists | What is wrong | Evidence |
|---|---|---|---|
| **Probation** | End date = explicit `probation_end` → `doj + months` → null; per-tenant `default_months`, `reminder_days[]`, notify toggles in `app_settings.ckey='probation'`; confirm sets `employment_stage=''`; extend requires a new date **and** non-blank remarks | Reminders fire on an **exact `days_left` match** against `[30,15,7,0]` — *"a missed scheduler day is never caught up"*. `employees.probation_months`, the per-employee override, **has no writer anywhere in the codebase**: it is a real column, read by the service, unit-tested, and unreachable. Reminder recipients are hard-coded roles. Confirmation produces **no compensation change and no letter** | BR-EMP-044/045/046/047; NEW-EMP-073; `ProbationService.php:102-120`, `:175-236`, `:252`, `:281-318` |
| **Increment** | `increments` carries `old_ctc`, `new_ctc`, `pct`, `effective`, `applied_at`; approval emails a letter from an editable template; applying is a manual one-click, idempotent, and freezes the row | Application is **manual, deliberately not auto-applied on the effective date**. Arrears use a hard-coded `/12` divisor and **calendar-day** proration of the first month, with a `>= 1` rupee floor, and are written as a single **`commissions` row** with `status='approved'` — money created outside any approval. A **decrease produces nothing**. Designation is written **by name only**, with no FK | BR-EMP-048/049/050/051; `IncrementController.php:314-347`, `:349-426`, `:338-347` |
| **Transfer** | Approved transfers apply on their effective date via a daily 06:30 sweep; a from→to snapshot is recorded; a public acknowledgement token exists | The `transfers` table has **no migration** — it is created by `ApprovalService::ensureAuditCols()`. `to_company_id` is used **with no tenant check**, so a transfer row carrying another tenant's company id **moves the employee across tenants** (DEF-31). A company transfer clears manager/team-leader/team unconditionally. `from_branch` is snapshotted at **apply** time, not request time. The 06:30 command is the only applier — a missed scheduler day means the transfer does not happen | BR-EMP-040/041/042/043; DEF-31; `TransferService.php:33-73`, `:123`, `:131-139`, `:150-157`, `:185`; `routes/console.php:190-193` |
| **Exit / F&F** | An `exits` request module, an `exits` table, a nav screen, and an `fnf` report dataset | **No path in the employee subsystem can produce the `exited` status**, and *"any form save resets it to `active`"* (DEF-04, `AppDataController.php:2920`) — silently returning a leaver to the seat count. The F&F "calculation" is a report, not a settlement: see below | `D-…` PO-01, PO-16; DEF-04 |

The F&F arithmetic is worth quoting in full, because it is the whole of SmartPRS 2's
settlement capability (`ReportController.php:264-283`, `H-…` §4.4):

```
monthly  = round(ctc / 12, 2)
basic    = round(monthly * 0.5, 2)          -- hard-coded 50% assumption
years    = Carbon(doj)->diffInYears(last_day)
gratuity = years >= 5 ? round(15 / 26 * basic * years, 2) : 0
FnF      = round(monthly + gratuity - advances_recovered - dues, 2)
```

Every divisor and basis is hard-coded: **12**, **0.5**, **15/26**, **5 years**. The 50 % is
*"unrelated to the tenant's actual salary structure in `salary_components`"*. `diffInYears`
truncates, so 4 years 11 months yields zero gratuity. The date arithmetic sits inside a
swallowed `catch` that leaves `gratuity = 0` on any parse failure (`:273-275`). There is no
notice-period arithmetic, no leave encashment, no pending OT, no clearance, no final
attendance, no tax, and no statement.

### XX.1.10 What SmartPRS 3 explicitly does not replicate

Stated so the Product Owner can hold the build to it. **None** of the following is carried
forward, and each has a requirement in this part that removes it:

| # | SmartPRS 2 behaviour | Removed by |
|---|---|---|
| 1 | A pipeline as a `private const` with no transition matrix (`:31`, `:396-419`) | R-XX-001, R-XX-006 |
| 2 | Fixed candidate columns and no custom-field mechanism (`:100-114`) | R-XX-016 |
| 3 | Conversion that drops 16 fields silently (`:626-635`) | R-XX-038, R-XX-041, R-XX-044 |
| 4 | Hire idempotency keyed on `employees.name` (`:588-596`, `:668`, `TransferService.php:91`) | R-XX-048, R-XX-049 |
| 5 | Master data created as a conversion side effect (BR-REC-015) | R-XX-040 |
| 6 | Off-roll hires invisible to onboarding, seats and compliance (BR-REC-016) | R-XX-053, R-XX-054 |
| 7 | An NDA screen that cannot save (`MasterController.php:356-389`) | R-XX-055, R-XX-056 |
| 8 | Signed artefacts stored as template pointers (NEW-DOC-104) | R-XX-061, R-XX-062 |
| 9 | `letters.pdf_path` written by nothing | R-XX-064 |
| 10 | Tokens with no expiry, no single use, no revocation, no throttle (BR-CMP-022) | R-XX-072 – R-XX-078 |
| 11 | An acceptance page that never shows the offer (NEW-DOC-103) | R-XX-066 |
| 12 | A CSRF-exempt sign endpoint with no server-side validation (NEW-DOC-102) | R-XX-070, R-XX-077 |
| 13 | Probation reminders on exact-day match with no catch-up (BR-EMP-045) | R-XX-090 |
| 14 | An unreachable per-employee probation override (NEW-EMP-073) | R-XX-084 |
| 15 | Manual increment application and a hard-coded arrears formula (BR-EMP-049/050) | R-XX-102, R-XX-105 |
| 16 | Increment arrears written as an auto-approved `commissions` row | R-XX-107 |
| 17 | Transfers applied by a 06:30 cron (`ApplyTransfers.php`) | R-XX-114 (via Part X R-X-048) |
| 18 | `to_company_id` used with no tenant check (DEF-31) | R-XX-117 |
| 19 | An `exited` status any form save resets to `active` (DEF-04) | R-XX-126 (via Part X R-X-058) |
| 20 | A four-line F&F report standing in for a settlement (`ReportController.php:264-283`) | R-XX-135 – R-XX-140 |
| 21 | Self-service transactions that name their subject in the request body (SEC-02) | R-XX-145 – R-XX-150 |
| 22 | Request listings with no role guard and no row scoping (SEC-01) | R-XX-152 |
| 23 | Unaudited, unverified ESS self-edits (NEW-ESS-104/105) | R-XX-156 (audit); §XX.9.3 (verified contact change) |
| 24 | Identity resolution falling back to a name match (SEC-23) | R-XX-150 |
| 25 | Runtime DDL on the request path across this entire area (`ensure()`, `ensureCols()`, `ensureAuditCols()`) | Law 2; every table in §XX.11 is migration-owned |

---

## XX.2 Recruitment and the ATS *(brief Section 61)*

### XX.2.1 The founding rule

**R-XX-001** A recruitment **pipeline** is a configurable, scoped, effective-dated,
versioned artefact (spine S4), not a constant. Its stages, their order, their labels, their
colours, their semantics and the transitions between them are data. *No stage code, stage
name or stage count may appear as a literal anywhere in application code.* *Origin:*
`private const STAGES` at `RecruitmentController.php:31`, recorded by the analysis as
*"the single most-requested ATS change"* (NEW-REC-101).

**R-XX-002** A tenant may define **any number of pipelines** and bind them per requisition
type, per employment type, per business unit, per designation family or per hiring drive —
by the ordinary Scope mechanism of spine S3.1, resolved by spine S3.2 precedence. *Origin:*
one hard-coded six-column board serves every hire in SmartPRS 2, from a night-shift
telecalling agent to a regional collections manager.

**R-XX-003** A pipeline artefact is resolved as
`resolve('recruitment_pipeline', key, scope, as_of)`. A candidate binds to the **pipeline
version** in force when they entered it, and stays on that version until they leave the
pipeline; a republished pipeline never re-shapes a candidate already in flight. *Origin:*
spine S4; the same rule the workflow engine applies to in-flight instances (S8).

### XX.2.2 Stages

```
cfg_pipeline_versions
  stages[]:
    stage_key            immutable within the version
    label, short_label, display_order, colour
    stage_kind_code      sourcing | screening | assessment | offer | onboarding
                         | terminal_success | terminal_failure   (semantics, not name)
    is_terminal          bool
    sla_days             null | integer          -- ageing and escalation
    required_field_keys[]                        -- candidate fields that must be present
    required_document_type_keys[]
    entry_guard_expr     SPX Boolean | null
    exit_guard_expr      SPX Boolean | null
    on_entry_actions[]   typed actions (S6 catalogue)
    on_exit_actions[]
    workflow_artefact_key null | code             -- a stage may require approval to enter
```

**R-XX-004** Every stage declares a `stage_kind_code`. **All behaviour keys off the kind or
off a declared flag, never off the label.** A tenant renaming "Screening" to "CV Sift"
changes nothing but the display. *Origin:* Law 1; and the pattern that breaks SmartPRS 2
everywhere else in the product (leave types matched by name string, deduction buckets matched
by substring, points rules bucketed by keyword — NEW-LVE-107, BR-RPT-005, BR-REC-036).

**R-XX-005** A pipeline must declare exactly one stage of kind `terminal_success` and at
least one of kind `terminal_failure`. Publication is blocked otherwise, naming the pipeline.
*Origin:* the conversion trigger and the consent-expiry clock both need an unambiguous
terminal state; SmartPRS 2 infers "hired" from a label.

### XX.2.3 The transition matrix

**R-XX-006** Movement between stages is governed by an explicit **transition matrix**. A
transition not present in the matrix is impossible — not discouraged, not warned about,
**impossible**. *Origin:* `moveStage()` validates list membership and nothing else
(`:396-419`, BR-REC-006), so `rejected → hired` is a legal move today.

```
cfg_pipeline_transitions
  from_stage_key, to_stage_key
  required_permission
  requires_reason              bool
  reason_value_set_key         null | code       -- structured rejection reasons
  requires_comment             bool
  requires_attachment_type_key null | code
  guard_expr                   SPX Boolean | null
  workflow_artefact_key        null | code       -- approval on the transition itself
  on_transition_actions[]      typed actions
  is_backward                  bool              -- for funnel reporting
```

**R-XX-007** A transition's `guard_expr` is an SPX Boolean evaluated against a prepared
candidate context. The engine returns an `AppliedAction[]` plus a `DecisionTrace` and mutates
nothing (spine S6). A blocked transition tells the user **which guard failed, in business
language**. *Origin:* Law 4; and BR-REC-020 — today a `strong_no_hire` recommendation gates
nothing at all.

Worked guard, expressing exactly the control BR-REC-020 lacks:

```
-- entry to OFFER requires a completed panel and no blocking recommendation
agg.count('interview', 'since(candidate.entered_pipeline_on)',
          'status = COMPLETED') >= policy.min_completed_rounds
AND NOT ANY(interview.recommendations, r -> r IN ['NO_HIRE','STRONG_NO_HIRE'])
AND candidate.field('pan') <> NULL
```

**R-XX-008** Every stage change writes a `candidate_stage_events` row (Part XXVIII C10)
carrying actor, timestamp, from, to, reason code, comment, the transition version and the
trace id. The pipeline board's funnel, ageing and conversion-ratio reporting are derived from
these events, never from a mutable current-stage column alone. *Origin:* `stage` is a bare
column today, and the requisition `filled` count is *"never called from `moveStage`"*, so a
card dragged to Hired does not update it (`H-…` §4.2).

**R-XX-009** A stage's `sla_days` produces an ageing measure and, on breach, a domain event
handled by the notification and escalation machinery of spine S8. *Origin:* nothing ages in
SmartPRS 2; there is no `due_at`, `sla_` or `escalate_at` column anywhere in the request or
recruitment areas (`E-…` req. 14).

### XX.2.4 Requisitions

**R-XX-010** A requisition is created against a **requisition type**, which is a configured
artefact declaring the field set, the approval workflow, the default pipeline, the budget
control and whether the engagement is on-roll, off-roll or either. *Origin:* BR-REC-001 —
today every requisition is forced to `approval_status='pending'` and only an `admin`, never
an `hr_manager`, may approve exactly one level (BR-REC-002).

**R-XX-011** Requisition approval is the **workflow engine** (spine S8) — multi-step,
role-, hierarchy-, amount- and scope-routable, with SLA, delegation, escalation and
maker-checker available as ordinary node configuration. There is no bespoke approval code in
the recruitment module. *Origin:* BR-REC-002; and `E-…` req. 8/9 — today an approver is only
an employee id from `reporting_manager_id`, with a single `>=` against one of three literal
thresholds.

**R-XX-012** A **material change** to an approved requisition — headcount, budget band,
designation, grade, location, employment type — re-opens approval. Which fields are material
is configuration. *Origin:* BR-REC-003 — an edit deliberately omits `approval_status` from
the update row, *"so the approval no longer describes what was approved"* (`:1184-1189`).

**R-XX-013** Requisition state is **enforced, not displayed**: a candidate cannot be attached
to, advanced within, or offered against a requisition that is not in an `open` state, and an
unrecognised state is treated as **not approved**. *Origin:* BR-REC-004 — the `recruitable`
flag is *"computed for display only and enforced nowhere"* (`:260` vs `:363-374`,
`:1094-1106`, `:1128-1135`); and BR-REC-005 — an unrecognised `approval_status` is currently
read as **approved** (`:160-165`), which is a fail-open default on a business gate.

Requisition numbering, headcount and fill:

**R-XX-014** `requisition_no` is allocated by the platform's **numbering service** — the same
configured-sequence mechanism as the employee code (Part X R-X-045: prefix, width, per-tenant
scheme, allocated from `code_sequence_counters` under `FOR UPDATE` inside the write
transaction), with a database uniqueness constraint. *Origin:* `H-…` §4.1 — `REQ` + `count+1`
computed non-transactionally with no unique index; *"codes are reused after deletion"* and two
concurrent creates can mint the same code.

**R-XX-015** `positions_filled` is **derived from the count of converted employments**, not
from candidates sitting in a stage, and is protected by
`CHECK (positions_filled <= positions_approved)` (Part XXVIII C10). Over-hire against a
requisition requires an explicit, permissioned, audited exception. *Origin:* BR-REC-017 —
fill is computed from `stage = 'hired'` candidates inside a **swallowed catch** (`:789-791`),
is never recomputed on a board drag, and never decrements on deletion; `board()` masks the
divergence at read time with `max(stored, actual)`.

### XX.2.5 The candidate entity — built on the Entity Schema Engine

**R-XX-016** `candidate` is a first-class entity in the **Entity Schema Engine** (spine S9,
Part X), with `field_definitions`, `field_surface_rules`, `value_sets` and
`entity_field_values` exactly as `employee` has. A tenant administrator adds "LinkedIn
Profile", "Referred By", "Notice Period (days)", "Visa Status" or "Preferred Location" as a
**configuration action**, with a type, a value set, validation, required-ness per surface,
PII class and retention class — and no migration, no release and no DDL. *Origin:* `H-…` Q2 —
*"there is no custom-field mechanism anywhere in the product"*; and Law 2.

**R-XX-017** The candidate's surfaces are the enumerated set of spine S9, and **one field
definition serves all of them**: candidate portal · recruiter console · bulk import · bulk
export · public careers API · interview scorecard context · offer template merge ·
**recruitment conversion** · reports. *Origin:* `D-…` req. 3 — the SmartPRS 2 failure is
"one definition per surface", producing the field-parity matrix that has 34 untyped columns
and surfaces that silently drop values.

**R-XX-018** Every candidate field carries a `maps_to_employee_field_key` (nullable). This is
the single fact that makes §XX.3 possible: the conversion map is **derived from shared
metadata**, not written by hand. *Origin:* `H-…` req. 69.

**R-XX-019** Candidate PII is classified per field and carries a **consent record** —
`consent_recorded_at`, `consent_purpose_code`, `consent_expires_on` (Part XXVIII C10
`candidates`) — with a scheduled purge sweep on the expiry index. *Origin:* rejected
candidates' CVs, contact details, ID proofs and interview notes are kept indefinitely with no
consent expiry and no bulk purge — *"the most exposed PII class in the product"*
(`M-…` §6.5, carried in Part XXVIII C10).

**R-XX-020** Candidate free text is stored **as entered** and escaped **on output**. Input is
never destructively stripped. *Origin:* BR-REC-007 — `stripHtmlDeep()` runs on
`saveCandidate` only and not on `patchCandidate` or `importRows`, so the same field is
sanitised or not depending on which endpoint wrote it; and BR-SEC-401/DEFECT-DOC-05 — the
same helper *"destroys legitimate rich text"* including the seeded HTML offer template on
first edit.

### XX.2.6 Sourcing channels and consent

**R-XX-021** A **sourcing channel** is a configured artefact: code, label, kind
(`job_board | referral | agency | walk_in | campus | internal | direct | portal_feed`),
cost model, owner, an optional connector recipe (spine S10.1 / S17) and a **lawful-basis
declaration**. Every candidate records the channel version they arrived through. *Origin:*
`source` is a free string today, and channel effectiveness cannot be reported.

**R-XX-022** A candidate reached by any outbound channel carries a **consent and suppression
state**. A suppression list (per tenant, per channel, keyed on the contact identifier's blind
index) is consulted before every send, and an inbound STOP/opt-out is honoured within the
channel's declared window. *Origin:* BR-REC-033 — *"There is no candidate opt-out,
suppression list or STOP handling"*; the analysis classes this as
*"a consent/DPDP obligation, not a preference"*.

**R-XX-023** Employee referral is a first-class channel with a referrer link, a configurable
eligibility and payout rule expressed as a Part VIII formula, and a payout that becomes an
ordinary payroll input through Part XVIII. *Origin:* absent entirely; referral bonuses are
today entered by hand as an ad-hoc component.

### XX.2.7 Résumé storage and parsing

**R-XX-024** A résumé is an `employee_documents`-class private object (Part XXVIII), stored
by `storage_key` with a `content_hash`, served **only** through an authorised, signed,
expiring URL. Nothing in this area is written to a publicly readable disk path. *Origin:*
spine S13; and `D-…`/Part XXVIII — SmartPRS 2 writes onboarding identity documents to
`storage/app/public/employee-docs/<sequential id>/<basename>`, a predictable path on a
public disk; `resume_url` on the candidate is a free-text string with no ownership check at
all.

**R-XX-025** Résumé parsing is a **connector**, not a core dependency. It receives the
document, returns a typed field map with a per-field confidence, and every parsed value lands
in a **review queue** as a *proposal* — never written silently. A tenant with no parser
configured loses no capability except the pre-fill. *Origin:* SMARTPRS 3 ARCHITECTURE
RECOMMENDATION; there is no parsing of any kind today, and the three import surfaces read
10, 14 and 8 columns respectively with three different dedupe behaviours (`H-…` §1.4).

**R-XX-026** Bulk candidate import uses **one** importer, with the mapping wizard, the
preview, the per-row verdict and the round-trip-symmetrical template of Part X §X.9.
Duplicate policy (key set, and skip vs merge vs create) is **configuration per import run**,
and every skipped row is counted and reportable. *Origin:* BR-REC-010/011 — three import
surfaces with three dedupe rules; the CSV importer has none and *"silently discards"* the
columns it does not read; and the downloadable template does not match its own importer
(`:795-809`).

### XX.2.8 Interviews, panels and scorecards

**R-XX-027** An **interview round** is a configured artefact per pipeline stage: round number,
label, mode value set, duration, whether it is mandatory, the **panel composition rule**
(named users, a role within a scope, or an SPX expression), the quorum, and the evaluation
form version to be used. *Origin:* NEW-REC-102 — modes, statuses and recommendations are
three fixed lists; and the panel is *"a free-text string, not people"* (`H-…` §1.3).

**R-XX-028** A **panel member** is a user, resolved and permissioned as one. A panellist sees
the candidate fields their `field_surface_rules` allow on the `interview` surface — which is
how "the panel must not see expected CTC" becomes configuration. *Origin:* spine S9 and S13;
today a free-text panel name grants and denies nothing.

**R-XX-029** An **evaluation form** is a configured artefact: an ordered set of criteria, each
with a weight, a rating scale (itself a value set with declared numeric anchors), whether
comments are mandatory, and an overall recommendation value set. Forms are versioned and
effective-dated; **a submitted scorecard stores the form version it was completed on**.
*Origin:* Law 3; and NEW-PRF-101 — the performance module's `rating` is validated as
`numeric` with *no minimum and no maximum*, so *"there is no rating scale at all"*.

**R-XX-030** A scorecard's aggregate is a declared **Part VIII formula** on the form version —
weighted mean, unweighted mean, median, minimum, or a custom expression — with an explicit
rounding mode. There is no default aggregation and no default rounding. *Origin:* spine S5.4;
and `H-…` §4.3 — today the board takes an unweighted mean at 1 decimal with PHP's half-away-
from-zero `round()`, and `iv_rec` is *"the recommendation of the last completed round in **id
order** — not the highest round number"*.

**R-XX-031** A submitted scorecard is **immutable**. A correction is a new scorecard
superseding it, with a reason. *Origin:* Law 3; and NEW-REC-106 — hiring-drive roster
`score`, `recommendation` and `outcome` are written *"with no validation"* and no 0–10 clamp,
directly contradicting `saveInterview`, which does validate.

**R-XX-032** Interview scheduling produces calendar invitations through the notification
channel adapters, handles reschedule and cancellation as first-class events, records
no-shows, and honours the interviewer's declared availability. Slot capacity is enforced.
*Origin:* hiring-drive slots are today a JSON array of `{time, capacity}` with the capacity
enforced nowhere, and time windows are stored as `string(20)`, not `TIME` (`H-…` §1.5, §2.2).

### XX.2.9 Document checklists and background verification

**R-XX-033** A **document checklist** is a configured artefact bound to a pipeline stage or
to an offer: an ordered list of document types, each with required/optional, an expiry rule,
a verification requirement, and an SPX applicability expression so that "a driving licence is
required only for field roles in Telangana" is configuration. The same artefact family
serves onboarding, compliance and exit (§XX.8.5). *Origin:* NEW-DOC-105 — the document
category list is a 6-value constant *"and is not enforced"*, falling back to `'Other'`; and
NEW-EMP-081 — onboarding and transfer checklists are two hard-coded 6-item PHP constants
(`OnboardingController.php:17-39`).

**R-XX-034** **Background verification** is an instance of the platform's compliance-item
model (Part XXVIII C11 `cfg_compliance_item_types`), not a bespoke table: a typed item with
a vendor, a status value set, evidence documents, a validity period, a revalidation rule and
a gate severity (`off | warn | block`) at declared gate points. A candidate-stage BGV and an
employee-stage BGV are the **same item type at two subject kinds**. *Origin:* spine S19 #1
and #3 — the DRA compliance-to-money gate is the product's strongest differentiator and the
reference case for the rule engine; `bgv` today is a master table with a `next_due` computed
by one hard-coded `addMonths` (BR-CMP-007).

### XX.2.10 Hiring drives and bulk campaigns

**R-XX-035** A **hiring drive** is a scoped event with typed date/time columns, venue,
capacity-controlled slots, a resolved panel of users, and a roster whose funnel stages are
**the drive pipeline's stages** — not a second, parallel, five-value vocabulary. Roster
outcomes flow through the same transition matrix as any other candidate movement, so a
reverse transition exists exactly when the matrix says it does. *Origin:* BR-REC-022/023 —
the drive funnel is a separate five-stage list; marking a row `selected` pushes the candidate
to *Offer* through an **untenanted** `UPDATE recruitment` inside a swallowed catch
(`HiringDriveController.php:319-325`), and *"there is no reverse transition"*.

**R-XX-036** Drive instructions, joining instructions and every other candidate-facing string
are **template content**, per tenant, per language. *Origin:* BR-REC-025 — the default drive
instruction is a hard-coded English sentence about carrying a resume, a photo ID and a pen
(`HiringDriveController.php:82`, `:183`), described by the analysis as
*"English-only, India-walk-in-specific, not tenant-editable"*.

**R-XX-037** A bulk campaign is **queued**, throttled, provider-agnostic, arity-checked
against the chosen template, suppression-filtered (R-XX-022), and delivers a per-recipient
verdict. Nothing is sent from inside a web request. *Origin:* BR-REC-026/028 — a 2 000-recipient
campaign is *"2 000 blocking HTTP calls inside one web request"* with **no arity check
against the chosen template**; BR-REC-029 — mobile normalisation *"takes the last 10 digits"*,
hard-wiring India and silently discarding a country code; NEW-REC-107 — the delivery webhook
determines status by **string-searching the whole JSON payload**, so a message body containing
the word "read" is read as a read receipt.

---

## XX.3 Candidate → employee conversion without re-entry

This section exists because of one measured fact: **12 fields carried, 16 dropped, and the
statutory identity never captured at all** (`H-…` Q3, §XX.1.4).

### XX.3.1 The map is derived, not written

**R-XX-038** The conversion map is **derived from shared field metadata**. A candidate field
declaring `maps_to_employee_field_key = K` lands in employee field `K` at conversion,
automatically, with no code and no per-tenant mapping table to maintain. Adding a candidate
field that maps to an existing employee field is a one-step configuration action that changes
conversion behaviour immediately. *Origin:* `H-…` req. 69 — *"today the map is 12 fields in
code and everything else is lost."*

```
FieldMappingResolver.resolve(candidate_entity_version, employee_entity_version, as_of)
  → CarryPlan {
      carried[]        { candidate_field_key, employee_field_key, transform, value }
      transformed[]    { …, transform_code, from_value, to_value, formula_version }
      collected[]      { employee_field_key, required_at_conversion, source_surface }
      not_applicable[] { candidate_field_key, reason_code }
      blocked[]        { employee_field_key, reason_code }     -- required, absent, uncollected
    }
```

**R-XX-039** A **transform** is a declared, versioned, typed conversion — never inline code.
The catalogue is closed and extensible only by Ametecs: `direct`, `value_set_remap`,
`lookup_master(entity, match_field, on_missing)`, `formula(expr)` (a Part VIII formula),
`unit_convert`, `concat`, `split`, `date_default(expr)`. Every applied transform is named in
the conversion trace. *Origin:* Law 1; and `:626-635`, where `position → designation_id`,
`offered_ctc → ctc` and `offer_doj → doj (falling back to today)` are three different
untraceable inline behaviours.

**R-XX-040** `lookup_master`'s `on_missing` is `block | propose | create` and defaults to
**`propose`**: the conversion halts with a named, permissioned action *"Designation 'Sr.
Collections Officer' does not exist — create it, or map it to an existing designation."*
Master data is **never** created as a silent side effect of a hire. *Origin:* BR-REC-015 —
a free-text candidate position with no name match causes an `INSERT designations`, which
*"pollutes the master with near-duplicates"*; and `H-…` req. 70.

### XX.3.2 Nothing is silently dropped

**R-XX-041** Conversion is **preview-then-commit**. The preview renders the full
`CarryPlan` and **every candidate field appears in exactly one of its five lists.** A field
that is neither carried, transformed nor explicitly marked not-applicable is a
**publish-time configuration error on the candidate entity**, not a runtime surprise.
*Origin:* the 16 silently dropped fields; and Law 4 applied to a business operation rather
than a number.

**R-XX-042** `not_applicable` requires a **declared reason code** on the field definition
(`recruitment_only`, `superseded_by_employee_field`, `retained_on_candidate_record`,
`purged_under_consent`). "It was not in the mapping list" is not a reason. *Origin:* the
same 16 fields, several of which — `expected_ctc`, `current_ctc`, `source`, `rating` — are
exactly what a customer later asks to report on.

**R-XX-043** Data that is genuinely recruitment-only remains **queryable from the employment
record** through the preserved `candidate_id` link: the interview history, the scorecards,
the requisition, the sourcing channel and the offer. It is not copied and it is not lost.
*Origin:* `H-…` Q3 — *"the entire interview history … stays linked to the candidate, not the
employee"*, with no navigable link from the employee back to it.

**R-XX-044** A **conversion report** is produced and stored as an immutable artefact against
both the candidate and the employment, listing **every field carried, transformed, collected,
not applicable or blocked**, with values, transforms, versions, actor and timestamp. It is
reachable from the employment record forever and is rendered by `Explain('employment',
'created_from')` (§XX.16). *Origin:* explicitly required by the task brief; and Law 4.

### XX.3.3 What is collected, and when

**R-XX-045** Employee fields declared `required_at_conversion` on the `recruitment_conversion`
surface must be present before conversion commits. They are collected in the order the
customer chooses, at any of three points, all configuration: **on the offer**, **through the
onboarding journey** (Part X §X.8, the self-onboarding portal the new joiner completes on
their phone), or **on the conversion form**. *Origin:* `H-…` Q3 — PAN, UAN, bank account,
IFSC, PF number, address, date of birth, emergency contact, references and DRA/PCC status are
*"never captured at all"*, so *"a newly hired agent starts with an empty compliance file and
is immediately reported as non-compliant by the agent audit"*; and spine S11, whose payroll
preview blocks on precisely those fields.

**R-XX-046** The onboarding journey and the candidate portal render **the same field
definitions** with the same validation and the same value sets. *Origin:* `D-…` req. 34 —
today *"the candidate portal, the HR console and the injection mapping"* each derive their own
list, and the injection mapping's `array_intersect_key` silently drops values whose column is
absent (`D-…` §Q table row 3).

**R-XX-047** The **compliance file opens with the employment**, not later: the checklist
resolved for the employee's scope (R-XX-033) is instantiated at conversion, each item
carrying its due date and gate severity, and the compliance score is computed against **items
that exist and can be satisfied**. *Origin:* §XX.1.1 and §XX.1.4 together — a score with an
unreachable component, applied to a person with an empty file.

### XX.3.4 Identity is an id, never a name

**R-XX-048** Conversion idempotency is keyed on the **candidate's identity** — the
`candidate_id`, enforced by `candidates.converted_employment_id` and the unique index
`(tenant_id, requisition_id, contact_email_blind_index)` (Part XXVIII C10). **No idempotency
key, anywhere in this part, is a person's name.** *Origin:* NEW-EMP-077 — *"Hiring a second
'Ravi Kumar' silently creates NO employee and reports the first one's code as the hire"*
(`RecruitmentController.php:594-596`, and identically at `:668` for off-roll).

**R-XX-049** The same prohibition applies to the two other name-keyed idempotency points in
the lifecycle: the onboarding record (`:750-769`) and the transfer-destination onboarding
(`TransferService.php:91`, *"a namesake's open onboarding suppresses the transferee's
destination checklist"*). Both key on the subject's id. *Origin:* `D-…` §Q6 rows 4 and 7; and
`D-…` req. 40, which asks for **one** rule covering all three.

**R-XX-050** A **person match** is offered, never applied: on conversion the platform
searches for an existing `person` (rehire, group transfer, contractor becoming permanent) on
the configured identity keys — PAN, UAN, blind-indexed email, blind-indexed mobile — and
presents candidates for an **explicit human confirmation** that is audited. Silent merge and
silent create are both prohibited. *Origin:* `D-…` req. 39 — the import wizard requires
*"explicit per-row confirmation of an existing-employee match"* (SEC-06, DEF-10), and the
same rule must hold here; and `H-…` PO-04, which asks for the authoritative person
identifier before any migration.

**PRODUCT OWNER DECISION REQUIRED — D-XX-01: the identity key set and its precedence.**
Recommendation: **PAN → UAN → (email blind index) → (mobile blind index)**, with PAN and UAN
as *confirming* keys (a match is strong evidence) and email/mobile as *suggesting* keys (a
match raises a review, never an automatic link). Trade-offs: PAN is not held for every
worker at the point of hire, particularly off-roll field agents, so it cannot be mandatory
for matching; email and mobile are shared within families and reused by agencies, so treating
them as confirming would merge distinct people. Requiring a human confirmation on every
suggested match costs recruiter time and is the only defensible default.
*(Answers `H-…` PO-04 and `D-…` PO-04.)*

### XX.3.5 The conversion transaction

**R-XX-051** Conversion is a single database transaction performing, in order:

```
1  authorise            (permission + data scope, fails closed — spine S13)
2  resolve CarryPlan    (R-XX-038); abort if blocked[] is non-empty
3  person match         (R-XX-050) — confirmed by the actor before this point
4  seat check           inside the transaction (Part X R-X-059)
5  allocate employee code   numbering service, DB-unique (Part X R-X-045)
6  create person / employment / employment_assignment (effective from joining date)
7  create employment_status_period  (pre_hire → active on the joining date)
8  write carried + transformed + collected values through EntityWriteService   (spine S9)
9  instantiate onboarding journey and compliance checklist
10 link candidate.converted_employment_id; move candidate to terminal_success
11 update requisition fill (derived)
12 write the conversion report; write field-level audit; emit events
```

Any step failing rolls back **all** of it. There is no partial hire. *Origin:* BR-REC-013/017
— the seat check sits between two unguarded writes, the fill reconciliation is in a
**swallowed catch** (`:789-791`), and the onboarding creation is a separate best-effort call,
so a failure anywhere leaves a candidate marked hired with no employee, no onboarding, or a
requisition that never closes.

**R-XX-052** Conversion writes **only** through `EntityWriteService` (spine S9), with PATCH
semantics, field-level surface rules, type coercion, validation, database-constrained
uniqueness, effective-dated assignment handling and field-level audit. There is no second
write path. *Origin:* `D-…` §Q6 — five distinct employee write paths exist today with
divergent field sets, of which the recruitment path writes *"12 fields, FKs only"* and leaves
denormalised name columns blank, *"→ export blanks"*.

### XX.3.6 Off-roll engagement

**R-XX-053** An off-roll engagement produces a **real `person` and a real `employment`** whose
`employment_type_code` declares it off-roll. It is not a separate person model, not a separate
table and not a separate lifecycle. What differs is declared, not structural:
`payroll_eligible`, `consumes_seat`, the applicable statutory profile, the compensation model
(commission or vendor payout, Part XVIII) and the applicable compliance checklist. *Origin:*
BR-REC-016 — off-roll hires get *"no seat check, no onboarding, no compliance record"*, and
`N-…` §1.7 module 20 — off-roll agents are *"excluded from all compliance scanning and all
seat counting"* while being the exact population RBI recovery-agent norms target.

**R-XX-054** Off-roll engagements are **in scope for compliance by default**. Whether they
consume a licensed seat is a commercial declaration on the employment type
(`consumes_seat`, Part X R-X-056), not an accident of which code path created the row.
*Origin:* `H-…` PO-03 — *"simultaneously a compliance hole and a revenue leak"*.

**PRODUCT OWNER DECISION REQUIRED — D-XX-02: do off-roll agents consume a licensed seat?**
Recommendation: **yes, at a distinct off-roll rate**, because they consume compliance,
document, messaging and payout capability and are the vertical's core population; the
alternative is that the product's most-used capability is unmetered. Trade-offs: it is a
price increase against today's actual behaviour for every existing customer who has off-roll
agents, and it must be communicated at renewal rather than applied at migration. Compliance
tracking, by contrast, is **not** optional and is not conditional on the seat decision.
*(Answers `H-…` PO-03.)*

---

## XX.4 The offer, NDA and letter engine *(brief Section 62)*

One engine serves every generated document in SmartPRS 3: offer, appointment, confirmation,
increment, promotion, transfer, warning, show-cause, NDA, relieving, experience, and anything
a customer invents. There is no per-letter-type code.

### XX.4.1 Document types are data

**R-XX-055** A **document type** is a configured artefact — code, label, subject kind
(`candidate | employment | person | org_unit`), the workflow that issues it, whether it
requires acceptance or signature, the evidence level required, the numbering scheme, the
retention class and the storage class. A tenant adds "Appointment Letter", "Confirmation
Letter", "Transfer Order" or "Show Cause Notice" without a release. *Origin:* NEW-DOC-106 —
the letter-type vocabulary is a 7-value dropdown of which **only 5 have issue screens**, one
of those five being the unreachable `letters-nda`; *"a customer cannot add 'Appointment
Letter', 'Confirmation Letter', 'Transfer Letter', 'Show Cause'…"*.

**R-XX-056** A document type registered in the navigation **must** have a resolvable
definition; a CI assertion fails the build when a registered screen has no backing
definition. *Origin:* the NDA screen — complete in the SPA at `AppController.php:667`,
`:1381`, `:3251`, `:10850`, absent from `MasterController::def():356-389`, and therefore a
screen that *cannot save at all*.

### XX.4.2 Templates, placeholders and versioning

**R-XX-057** A **template** is a versioned, scoped, effective-dated artefact (spine S4) with
a rich-text body, a header/footer/letterhead per company, page geometry, and a declared
locale. Templates are drafted, previewed, approved and published like every other artefact;
a template whose status is not `published` **cannot be selected and cannot be resolved as a
fallback**. *Origin:* BR-CMP-023 — resolution falls back to *"the newest of that type in the
tenant"*, and `letters.status` (`active`/`inactive`) is *"never consulted, so a deactivated
template is still used if newest"*.

**R-XX-058** Placeholders are resolved from the **entity schema symbol table** (spine S9), not
from a fixed key list: every candidate, employment, org, compensation, statutory and custom
field the actor is authorised to see is available, by key, with type checking at publish
time. An unknown placeholder is a **publish-time error**, never rendered text. *Origin:*
BR-CMP-024 — the merge is *"a single literal `strtr()` over 21 `{{placeholder}}` keys"*,
*"unknown placeholders print verbatim"*, and the key list is code, so adding
`{{probation_period}}`, `{{notice_period}}`, `{{reporting_manager}}`, `{{work_location}}`,
`{{pf_number}}` or `{{appraisal_date}}` is a release.

**R-XX-059** A template may contain **SPX conditional blocks and repeaters** (`IF`, `CASE`,
list iteration over a declared collection such as salary components or clearance items),
evaluated in the same sandbox as every other expression (spine S5.6). Customer-supplied PHP,
JavaScript or template escapes are never accepted. *Origin:* spine S5; and BR-CMP-025 — today
whether the body is escaped or not is decided by *"a regex matching a block or inline tag"*,
which is both a rendering lottery and, given the unresolved dompdf configuration (`H-…`
UD-01), a potential injection surface.

**R-XX-060** Rich text is preserved end to end. No write path destructively strips markup.
*Origin:* BR-SEC-401 / DEFECT-DOC-05 — `stripHtmlDeep()` on the master save path *"destroys
any HTML, and destroys the seeded HTML offer template on first edit"*.

### XX.4.3 The signed artefact — rendered, hashed, immutable

This is the central rule of §XX.4.

**R-XX-061** At the moment a document is **issued**, the platform renders the merged document
to a **PDF/A byte stream**, computes a SHA-256 `content_hash`, and stores the bytes as a
private object. The stored artefact is **the document**. The template is provenance, not
content. *Origin:* NEW-DOC-104 — *"The signed artefact stores a template pointer, never a
snapshot … editing a template silently rewrites what someone signed"*
(`LetterController::buildLetter():53-68`; no `body` on issued rows).

**R-XX-062** At the moment a document is **accepted or signed**, the platform renders a
**second, final artefact** that embeds the signature block, the signatory's declaration, the
evidence summary and the acceptance timestamp; hashes it; stores it; and **seals it**. A
sealed artefact is immutable: it has no update path, its storage object is write-once, and
its hash is written into the audit ledger's hash chain (spine S14). *Origin:* the same
finding, plus `H-…` Q5 — evidence **not** captured today includes *"the document content, a
content hash, a rendered PDF"*.

**R-XX-063** The rendered artefact records, on its row, **every version that produced it**:
template version, document-type version, entity schema version, formula versions used in any
computed value, statutory pack version where a statutory value appears, and the
`calculation_trace_id` where money is shown. *Origin:* spine S4's closing rule — *"every
derived record stores the version ids it was produced from"* — which is what makes an offer
letter defensible three years later.

**R-XX-064** The document store is real: `generated_documents.storage_key` is written on
issue and read on every retrieval. *Origin:* `letters.pdf_path` — *"never written or read by
any code — a column for an issued-PDF store that was never built"* (`H-…` §2.2). SmartPRS 3
does not repeat a column that stands for an unbuilt feature.

**R-XX-065** Re-issue produces a **new artefact superseding the old**, with a reason; the
superseded artefact is retained and remains retrievable. Nothing is regenerated in place.
*Origin:* Law 3.

### XX.4.4 What the signer sees

**R-XX-066** The acceptance or signature page renders **the document itself**, in full, above
the acceptance control — not a summary card. The signatory must be able to read and download
exactly the bytes that will be hashed. *Origin:* NEW-DOC-103 — the public page renders
**four fields** (name, position, company, offer date) and *"never the offer body, CTC,
incentive, bonus or joining date"*; the analysis: *"this is what makes the 'acceptance'
evidentially worthless"* (`offer-accept.blade.php:38-44`).

**R-XX-067** The page states, in the recipient's declared language: what is being accepted,
by whom, the legal effect, the retention period, and how to decline or ask a question. A
**decline** path exists and is recorded with a reason. *Origin:* there is no decline path
today — the only public outcome is acceptance.

### XX.4.5 Identity, consent and signature capture

**R-XX-068** Acceptance requires **identity proof appropriate to the document type**,
declared per type as one of: `token_only` (never permitted for a document requiring
signature), `otp_to_verified_channel`, `otp_plus_document_number`, or an external
e-signature provider through a connector. The platform default for offers and NDAs is
**`otp_to_verified_channel`**. *Origin:* `H-…` Q5 — identity proof: *"none. Anyone holding
the URL can bind the named person"* — and the analysis's own observation that *"the product
already implements OTP on other public token routes (self-onboarding) and does not here"*.

**R-XX-069** The OTP implementation is the platform's, with a declared code length, expiry,
attempt limit, lockout, per-channel throttle, single-use invalidation and invalidation of all
outstanding codes on resend. The code is **never returned in a response body**. *Origin:*
Part X §X.8 and `D-…` req. 36 — SmartPRS 2's self-onboarding *"returns a one-time code in a
response"* and *"never invalidates OTPs on resend"*.

**R-XX-070** Signature capture is per document type: `typed_name`, `drawn_signature` (stored
as an image object referenced by the artefact), `uploaded_signature`, or
`provider_signature`. Where a typed name is used, the platform requires an **exact match
against the declared signatory name** under a configured comparison rule, plus an explicit
consent tick, **both validated server-side**. *Origin:* NEW-DOC-102 — the NDA sign endpoint
*"performs no server-side validation of the name or the consent tick, and is CSRF-exempt"*,
signing *"with `name` falling back to the existing value or NULL"*
(`NdaSignController.php:96`; `routes/web.php:63-64`).

**R-XX-071** The **evidence bundle** captured with every acceptance or signature:

| Evidence | Detail |
|---|---|
| Subject | candidate/employment uuid, document uuid, document type version |
| Content | `content_hash` of the exact bytes presented, and of the sealed artefact |
| Timestamp | UTC instant from the platform clock, plus the business timezone applied |
| Network | source IP (`VARBINARY(16)`, IPv6-capable), reverse-resolved ASN/country if available |
| Device | user-agent hash, declared platform, screen class |
| Identity proof | method code, channel used, masked destination, verification instant |
| Consent | the exact consent text version presented, and the tick |
| Signature | method code, typed value or image object reference |
| Journey | every view of the document, timestamped (R-XX-076) |
| Chain | the audit-ledger entry id and hash (spine S14) |

*Origin:* `H-…` Q5 — today the evidence set is `status`, `accepted_at`, `accepted_ip` and
(for NDA) `signed_name`, **and no `activity_logs` entry of any kind**.

### XX.4.6 Token security

**R-XX-072** A public document token is **256 bits of CSPRNG output**, stored **hashed**
(`invite_token_hash` pattern, Part XXVIII C10), never in plaintext, with a unique index.
*Origin:* generation entropy is already ample in SmartPRS 2 (`Str::random(40)` ≈ 238 bits);
what is wrong is that it is stored *"plaintext in `letters.accept_token`, a plain string
column, **no unique index, no index at all**"* — which is also why the public route performs
an unindexed scan on every hit.

**R-XX-073** Every token carries an **explicit expiry**, defaulted per document type and
overridable per issue within a configured maximum. An expired token returns a page offering
to request a fresh one; it never silently resolves. *Origin:* BR-CMP-022 — *"no expiry, no
rotation, no revocation, no single-use"* — compounded by the fact that *"the 404 text on both
routes claims an expiry that does not exist"*.

**R-XX-074** A token is **single use for its decisive action**. Once accepted, declined or
signed, the token no longer authorises a decision; the artefact remains retrievable through
an authenticated route or a fresh, separately issued, read-only token. *Origin:* `H-…` Q5 —
*"the token still resolves after acceptance"* and *"remains a permanent capability"*.

**R-XX-075** Tokens are **revocable and rotatable**, individually and in bulk (by document
type, by issue batch, by date range), by a permissioned, audited action. Re-sending a
document **mints a new token and revokes the old one**. *Origin:* BR-CMP-022 — re-sending
*"reuses the existing token"* (`?:` at `LetterController.php:402`), and *"no code path clears
the token"*.

**R-XX-076** Every token resolution — successful, expired, revoked or unknown — writes an
**access record** (timestamp, IP, user-agent hash, verdict). The document's own view history
is part of its evidence bundle. *Origin:* `H-…` Q5 — no audit entry is written on any public
token route, so there is no record that the signatory ever opened the document.

**R-XX-077** All public document routes are **throttled** per token, per IP and per tenant,
with lockout and alerting; and they are **CSRF-protected**. No route in SmartPRS 3 is
CSRF-exempt for convenience. *Origin:* `H-…` Q5 — *"rate limiting: none on any of the four
routes"*; and NEW-DOC-102's explicit exemption.

**R-XX-078** A token resolves **only** to a document of the type its route serves, in the
tenant that issued it, in a state that permits the action. *Origin:* NEW-DOC-101 — the offer
route *"accepts any letter type — there is no `letter_type` filter"*, so any non-template
letter with a token can be "accepted" as an offer (`OfferAcceptController.php:36`); and
`H-…` Q5 — the NDA controller's employee and template lookups are **not tenant-scoped**
(`NdaSignController.php:44`, `:47-49`).

**R-XX-079** Public document routes execute **no DDL**. *Origin:* `OfferAcceptController::ensureCols():18-30`
and `NdaSignController::ensureCols():19-31` run `hasColumn` checks and `ALTER TABLE`
**on every public, unauthenticated token page hit** (`H-…` §2.3) — an unauthenticated
schema-modification surface, and the clearest single illustration of why Law 2 exists.

### XX.4.7 Delivery, archival and retention

**R-XX-080** Delivery is through the platform's channel adapters with a per-recipient
delivery record and retry. Per-company SMTP is resolved **per message** and never mutates
process configuration. *Origin:* BR-CMP-026 — emailing a letter *"rewrites the process's mail
config to the letter's company SMTP and does not restore it"*
(`LetterController.php:229-252`), so every subsequent message in that PHP process is sent
from the wrong company.

**R-XX-081** Sealed artefacts are retained under the document type's **retention class** and
are included in the backup set (spine S18). Retrieval is always through an authorised,
signed, expiring URL. *Origin:* spine S13; and BR-REC-042 — today *"any user with the
`employee` role can download any document in the tenant"*, fetched by `id` + `tenant_id`
with **no ownership check** (`DocumentController.php:263-281`).

**R-XX-082** An **e-signature register** exists per tenant: every issued, viewed, accepted,
declined, signed, expired and revoked document, filterable and exportable, with the hash of
each sealed artefact, for audit and for regulatory submission. *Origin:* spine S19 #3 — the
RBI audit pack is genuine domain capital, and it currently cannot evidence a single
signature.

---

## XX.5 Probation and confirmation *(brief Section 63)*

### XX.5.1 The probation policy

**R-XX-083** A **probation policy** is a scoped, effective-dated, versioned artefact (spine
S4), resolved for an employment at its joining date and re-resolvable on a scope change. It
is not a per-tenant JSON blob. *Origin:* today the whole configuration is one
`app_settings.ckey='probation'` JSON document merged with `array_replace_recursive`, which
means *"a key can never be removed, only overridden"* (`D-…` §5(a)1;
`ProbationService.php:44-70`), and it cannot vary by company, grade, designation or
employment type.

Declared attributes:

| Group | Fields |
|---|---|
| Duration | `duration_expr` (SPX → `Duration`), `min_duration`, `max_total_duration` |
| Applicability | `applies_to_employment_types[]`, `applies_when` (SPX), `waivable`, `waiver_workflow_key` |
| Review | `review_interval_expr`, `review_points[]` (declared offsets or dates), `review_form_version_key`, `reviewers[]` (role / hierarchy hop / named) |
| Reminders | `reminder_offsets_days[]`, `reminder_semantics` (`exact` \| `at_or_past`), `catch_up`, `overdue_nudge_interval_days`, `audience_expression` (SPX) |
| Extension | `extension_allowed`, `max_extensions`, `max_extension_duration`, `requires_reason`, `extension_workflow_key` |
| Outcome | `on_confirm_actions[]`, `on_extend_actions[]`, `on_terminate_actions[]` |
| Compensation | `confirmation_compensation_change` (none \| formula \| structure change), `compensation_effective_rule` |
| Documents | `confirmation_letter_type_key`, `extension_letter_type_key` |
| Entitlement | which leave types, benefits and policies become available on confirmation (by reference, not by name) |

**R-XX-084** Probation duration resolves as: an **explicit per-employment override** →
the resolved policy's `duration_expr` → nothing. The per-employment override is a **writable,
reachable field on the employment record**. *Origin:* BR-EMP-044 gives the same precedence,
but NEW-EMP-073 records that `employees.probation_months` — *"a real column, read by
`ProbationService::endDate()`, and unit-tested"* — **has no writer anywhere**:
*"the feature is unreachable."*

**R-XX-085** `probation_end_on` is a **stored derived value** (Part XXVIII `employments`),
recomputed whenever an input changes (joining date, override, policy version, an approved
extension), each recomputation traced. *Origin:* Law 4; and BR-EMP-044.

**R-XX-086** Probation is an **employment stage on the dated assignment** (Part V §V.6.5,
Part X §X.7.1), not a status and not a free string. *Origin:* BR-EMP-012 — employment stage
is `''` / `probation` / `internship`, *"anything unrecognised → `''` (Permanent)"*
(`AppDataController.php:123-137`), so Contract, Trainee, Apprentice, Consultant and Retainer
— all real Indian stages — silently become Permanent.

### XX.5.2 Reviews

**R-XX-087** A probation review is an instance of a **configured evaluation form** (the same
artefact family as the interview scorecard, R-XX-029), with declared participants — the
employee, the reporting manager, a skip-level, HR — each seeing the sections their surface
rules allow. The **employee participates in their own review**. *Origin:* `H-…` §1.11 — both
the performance board and its advance endpoint are guarded `admin | hr_manager | super_admin`,
so *"the employee cannot perform their own self-review — HR types it in on their behalf"*.

**R-XX-088** A review form may declare **KPI capture**: named measures with a target, an
actual, a source (manual, or an SPX expression over `agg.*`, so "average collection
recovery %, this quarter" is computed rather than typed), a weight and a scale. Captured KPIs
are stored with the review and are addressable by later artefacts (increment eligibility,
promotion guards). *Origin:* `H-…` §1.11 — *"no rating scale, no weighting, no goal structure
beyond a free-text field"*, and NEW-PRF-102 — *"nothing downstream consumes `final_rating`"*.

**R-XX-089** Review submission, ordering and completeness are enforced by the **workflow
engine** — self-review before manager review before HR finalisation, where the customer
declares that order. *Origin:* BR-REC-035 — *"a review advances through three actions with no
ordering enforcement"*, on a query with **no tenant filter** (`PerformanceController.php:94-135`,
a cross-tenant IDOR).

### XX.5.3 Reminders

**R-XX-090** Reminder semantics are configuration, defaulting to **`at_or_past` with
catch-up**: a reminder due while the scheduler was down fires on the next run, once, deduped
per (employment, milestone, policy version). *Origin:* BR-EMP-045 — reminders fire on an
**exact `days_left` match** against `[30,15,7,0]`, so *"a missed scheduler day is never caught
up"*; `D-…` req. 51 lists this as an undetermined-and-wrong behaviour, and spine S2 makes the
scheduler mandatory precisely because SmartPRS 2's worst operational failures come from
customers running none.

**R-XX-091** The reminder audience is an **SPX expression**, not a role literal. *Origin:*
`ProbationService.php:252` — recipients are hard-coded `admin|hr_manager|hr|super_admin`
(`D-…` §5(b)52); and `E-…` req. 60 — *"audience must be a rule, not a literal"*.

**R-XX-092** An overdue probation is a **compliance item with a gate severity**, not merely a
nudge: a customer may declare that an employment more than N days past its probation end
without a decision blocks confirmation-dependent actions, or warns, or does nothing.
*Origin:* SMARTPRS 3 ARCHITECTURE RECOMMENDATION, following spine S19 #1 — the tri-state gate
is the product's proven pattern and generalises here.

### XX.5.4 The confirmation decision

**R-XX-093** The outcomes are `confirm`, `extend`, `terminate` and `waive_remaining`, each a
**workflow-decided transition** with a required reason, a required effective date, and a
declared action set. A decided step is immutable (spine S8). *Origin:* BR-EMP-046/047 — today
confirmation is a direct service call that sets `employment_stage=''`, extension requires
only a date and non-blank remarks, and `decide()` never checks the current status, so
*"an approved request can be re-approved indefinitely"* (`E-…` req. 17), re-firing whatever
the approval fires.

**R-XX-094** Confirmation is **effective-dated**. It closes the probation stage on the
assignment and opens the confirmed stage from the effective date, which may be earlier or
later than the decision date. Payroll, entitlement and policy resolution for any date read the
stage in force **on that date**. *Origin:* Part X §X.6; and the fact that SmartPRS 2 cannot
answer *"what was this employee's employment stage on 15 May?"* at all (`D-…` §Q5).

**R-XX-095** A confirmation may carry a **compensation change** — a new structure, a
formula-computed revision, or none — created as an ordinary effective-dated compensation
record (Part XV) and therefore automatically eligible for arrears if back-dated (R-XX-105).
*Origin:* today confirmation changes nothing but a stage string; every real confirmation
increase is re-keyed by hand into the increment module or straight onto `employees.ctc`.

**R-XX-096** Confirmation issues the **confirmation letter** through §XX.4, with the same
sealing and evidence rules, and the same document type configurability. *Origin:*
NEW-DOC-106 — there is no confirmation letter type at all.

**R-XX-097** Confirmation emits `employment.confirmed`, which is what unlocks
confirmation-gated entitlements (leave types with
`available_during_probation = false`, Part XIII), benefits and policies — **by reference to
the stage, never by a name comparison**. *Origin:* Law 1.

---

## XX.6 Increment and promotion *(brief Section 64)*

### XX.6.1 One mechanism, four expressions of amount

**R-XX-098** A **compensation revision** declares its amount in exactly one of four
**configured modes**, and the mode is part of the request, not an inference:

| Mode | Declares | Resolves to |
|---|---|---|
| `percentage` | a percentage of a declared basis (CTC, gross, basic, or any component by **code**) | new component values through the structure engine |
| `fixed_amount` | a `Money` delta on a declared basis | as above |
| `new_ctc` | the target annual CTC | re-computed structure at the new CTC (Part XV) |
| `component_values` | explicit new values for named component **codes** | direct, validated against the structure's rules |

*Origin:* `IncrementController` supports old/new CTC and a percentage only, cross-computed by
one of two formulas depending on which field is blank (`H-…`/`D-…` §4.7), with **no** ability
to change a single component; and Law 1 — every basis is referenced by code, never by name
(spine S5.3 `salary.component(code)`).

**R-XX-099** A revision carries an **effective date**, which is authoritative. It may be
back-dated (producing arrears, §XX.6.3) or future-dated (applying by the passage of time,
R-XX-102). *Origin:* BR-EMP-050 gives back-dating; forward-dating exists only for transfers,
and only through a cron.

**R-XX-100** A revision may **decrease** compensation. A decrease follows the same path, with
its own permission, its own workflow and its own retro treatment. *Origin:* BR-EMP-050 —
*"a decrease produces nothing"*; the arrears path is guarded by `new_ctc > old_ctc`
(`IncrementController.php:362`), so a corrected over-payment is silently untracked.

### XX.6.2 Promotion is a revision plus assignment changes

**R-XX-101** A **promotion** is one transaction combining a compensation revision with
effective-dated changes to `designation`, `grade`, `employee_category`, `employment_type`,
`pay_group`, reporting line or org unit — each written as an assignment row (Part X §X.6),
all sharing one effective date, one workflow instance and one reason. Either all of it applies
or none of it does. *Origin:* today designation is written **by name only, with no FK**, as a
side effect of applying an increment (`IncrementController.php:342`; `D-…` field matrix row
24), while grade *does not exist as a concept at all* (`D-…` §Q5).

**R-XX-102** Application is **automatic by the passage of time**, not by a job and not by a
click: the assignment and compensation rows exist from approval and become in force on their
effective date (Part X R-X-048). A "scheduled changes" panel shows what is pending; cancelling
a pending change before its effective date is a permissioned, audited action. *Origin:*
BR-EMP-049 — *"applying an increment to the employee is a manual, idempotent one-click"* and
*"deliberately no auto-apply"*, which the analysis records as a behaviour customers do not
want (`D-…` §5(b)47, §5(b)50: *"most customers expect auto-apply on the effective date"*);
and BR-EMP-040 — transfers apply through a **daily 06:30 command**, so a missed scheduler day
means the change does not happen.

**R-XX-103** A revision is **immutable once approved**; a correction is a new revision
superseding it, with a reason, and the superseding relationship is queryable. *Origin:*
BR-EMP-051 is right in spirit — an increment freezes once applied — but freezes on
`applied_at`, which is a manual click, so an approved-but-unapplied increment remains
editable.

**R-XX-104** Eligibility is a **rule**, not a convention: minimum service, minimum time since
the last revision, employment stage, last review outcome or KPI threshold, budget headroom,
and any custom condition, expressed in SPX and evaluated with a trace. *Origin:* NEW-PRF-102
— *"nothing downstream consumes `final_rating`"*; the analysis's requirement is explicit:
*"appraisal must drive increments, incentives and promotions"*.

### XX.6.3 Arrears — a declarative retro effect

**R-XX-105** When a revision's effective date falls in a **closed or already-paid** period,
the retro effect is computed by a **declared retro policy** resolved for the employee's
scope, never by a hard-coded formula. *Origin:* BR-EMP-050 — the current arrears calculation
hard-codes `/12`, calendar-day proration of the first month, and a `>= 1` rupee floor
(`IncrementController.php:359-421`), *"and the annual→monthly divisor and proration basis are
per-customer"*.

```
cfg_retro_policies (scoped, effective-dated, versioned)
  applies_to_change_kinds[]        compensation | assignment | statutory | policy
  recompute_mode                   full_recompute | delta_formula
  delta_formula_key                → a Part VIII formula, when delta mode
  proration_basis_code             calendar_days | working_days | payable_days
                                   | fixed_divisor(n)     ← per the Part XII day counts
  period_boundary_rule             from_effective_date | from_period_start
  components_in_scope              ALL | codes[] | flag_expression
  statutory_recompute              recompute | freeze | recompute_with_pack_as_at_original
  minimum_payable                  Money            (default zero, not one rupee)
  rounding                         mode + unit      (explicit; spine S5.4 — no default)
  target_period_rule               next_open_run | named_run | separate_arrears_run
  payslip_presentation             single_line | per_component | per_month_breakdown
  requires_approval                bool + workflow_artefact_key
```

**R-XX-106** The **recommended default** is `full_recompute`: re-run each affected closed
period against the new inputs and the rule and pack versions **that were in force in that
period**, and pay the difference. The delta-formula mode exists for customers who require the
simpler arithmetic. Both produce a trace. *Origin:* spine S11's input snapshot makes
`full_recompute` reproducible; the current delta arithmetic silently ignores LOP, mid-period
joining, structure changes and every statutory consequence of the higher wage.

**R-XX-107** Arrears become an ordinary **payroll input** on a declared target run, subject
to that run's approval and its statutory treatment. They are **never** written as a
pre-approved money row into another module's table. *Origin:* BR-EMP-050 — arrears are
written as one `commissions` row with `purpose='Salary Arrears'`, `status='approved'`,
`payout_method='with_salary'` (`:396-416`) — money created with no approval, in the
collections module's ledger, where it distorts commission reporting and bypasses every
control the payroll run has.

**R-XX-108** Statutory treatment of arrears is declared, not assumed: whether PF, ESI, PT,
LWF and tax are recomputed on the arrear month, on the original months, or frozen, is a
`statutory_recompute` choice governed by the rule pack (spine S12). This blueprint asserts no
statutory position. *Origin:* the hard rule on legal values; and the current code, which
computes an arrear amount and applies **no statutory treatment at all**.

**Worked example — a back-dated increment (the analysis's own case, recomputed under this
design).**

```
Employee   HYD-0412        Old CTC ₹6,00,000    New CTC ₹7,20,000
Effective  16-Apr-2026     Decision approved 03-Sep-2026
Retro policy  RETRO-STD v2  recompute_mode = delta_formula
                            proration_basis_code = calendar_days
                            period_boundary_rule = from_effective_date
                            minimum_payable = ₹0.00 · rounding = half_up, 1 paisa
                            target_period_rule = next_open_run

Monthly delta  = (₹7,20,000 − ₹6,00,000) / 12          = ₹10,000.00
April fraction = (30 − 16 + 1) / 30                     = 0.5000000
Months payable = Apr 0.5 + May 1 + Jun 1 + Jul 1 + Aug 1 = 4.5
Arrears        = ₹10,000.00 × 4.5                       = ₹45,000.00
Target run     = September 2026 (open); if Sep is past draft → October 2026
Statutory      = <per RETRO-STD.statutory_recompute and the resolved pack version>
```

The arithmetic reproduces `IncrementController.php:359-421` exactly — **and that is the
point**: the same customer gets the same number, but every constant in it (`12`, the
calendar-day proration, the `>= 1` floor which is now `₹0.00`, the target-run rule) is a named
field on a versioned policy, the result carries a trace, and the money enters payroll as an
input rather than as an auto-approved commission row.

### XX.6.4 The letter and the history

**R-XX-109** The increment or promotion letter is a §XX.4 document, issued by a declared
action on the workflow's approval node, **exactly once**. *Origin:* `E-…` BR-REQ-029 — the
post-approval document firing is *"two hard-coded `if`s"* on module name
(`RequestController.php:1465-1492`), each in its own catch that logs and continues, and
because `decide()` does not check current status, **re-approval re-fires the letter and the
transfer order** (spine S8's "a decided step is immutable" exists for this).

**R-XX-110** Full revision history — every proposal, decision, effective date, mode, basis,
amount, approver, reason, letter and arrear — is queryable per employment, and
`AsAtService::field(employment, 'ctc', date)` answers *"what was this employee's CTC on
15 May?"* in one call. *Origin:* spine Law 3's test; `D-…` §Q5 — *"the honest answer to the
Product Owner is: SmartPRS 2 cannot answer that question"*, because four of nine write paths
change compensation and assignment with no history row at all.

---

## XX.7 Transfer *(brief Section 65)*

### XX.7.1 A transfer is an effective-dated assignment change

**R-XX-111** A transfer changes any subset of the assignment dimensions in **one**
effective-dated transaction: legal entity, company, establishment, state, zone, branch,
location, department, sub-department, team, cost centre, business unit, client process,
designation, grade, employee category, employment type, pay group, shift or shift group,
reporting manager, functional manager and team leader. There is no separate "branch transfer"
and "company transfer" code path. *Origin:* `TransferService` supports company, branch and
department only; every other dimension is changed by editing the employee form, which writes
the value directly **with no history row at all** (`D-…` §Q5 table).

**R-XX-112** Every dimension changed writes a row on `employment_assignments` (Part X §X.6),
so **historical payroll, statutory returns and reporting retain the old assignment**. August's
payslip, August's PT state, August's cost centre and August's approval routing all read the
assignment in force in August, whatever happened in September. *Origin:* the direct reason
Law 3 exists; and `D-…` §Q5 — a back-dated payroll re-run, a statutory return for a past
period, or *"who reported to whom last quarter"* is **not answerable** from SmartPRS 2's
schema.

**R-XX-113** The "from" values on the transfer record are **derived from the assignment in
force on the effective date**, never snapshotted at an arbitrary earlier or later instant.
*Origin:* BR-EMP-040 — `from_branch` is snapshotted at **apply** time (`TransferService.php:123`,
`:185`), so *"if the employee's branch changed between request and apply the 'from' is the
later value"*.

**R-XX-114** A future-dated transfer becomes in force **by the passage of time** (Part X
R-X-048). No job applies it and no job may rewrite it. *Origin:* BR-EMP-040 — the daily 06:30
`ApplyTransfers` sweep (`routes/console.php:190-193`) is the only applier, so a scheduler
outage silently defers every due transfer, and the analysis's remedy (`D-…` req. 16) is to
generalise the pattern to *the effective date itself*.

**R-XX-115** What a transfer does to the reporting line, the team, the shift, the cost centre
and the onboarding checklist is **declared per transfer type**, not assumed. *Origin:*
BR-EMP-041 — a company transfer **unconditionally** clears `reporting_manager`,
`team_leader`, `team` and `reporting_manager_id` (`TransferService.php:150-157`), which is
right for some customers and wrong for an intra-group move where the manager comes too
(`D-…` §5(b)49).

**R-XX-116** A destination onboarding/relocation checklist is instantiated from a
**configurable template** resolved for the destination scope, keyed on the employment id.
*Origin:* BR-EMP-042 — the destination onboarding is idempotent **by employee name**
(`TransferService.php:91`), and NEW-EMP-081 — the transfer checklist is a hard-coded 6-item
PHP constant with *"no per-tenant, per-company, per-department or per-role variation"*.

**R-XX-117** Every org unit and company referenced by a transfer is **tenant-verified inside
the persistence layer** (Law 6). *Origin:* **DEF-31** — `to_company_id` is used with **no
tenant check** (`TransferService.php:131-139`; only the name-resolution branch is scoped), so
*"a transfer row carrying another tenant's company id moves the employee across tenants."*

**R-XX-118** A transfer that crosses a **legal entity** is a distinct, separately permissioned
transfer type with its own declared consequences — exit-and-rehire versus continuity of
service, seat and licence accounting, statutory registration change, gratuity and leave
liability transfer, and whether the employee code is retained. Each consequence is
configuration. *Origin:* `D-…` §0 — *"the employee keeps their code across a company transfer
inside the group"* is today an implicit behaviour with no declared rule; and Part XIII
D-XIII-15, which makes leave balance transfer configurable per type with `settle` the default
across legal entities.

### XX.7.2 Approval, acknowledgement and the order

**R-XX-119** Transfer approval is the workflow engine, with the source and destination
managers as ordinary `parallel_all` or sequential nodes and the destination's headcount or
budget as a `condition_branch`. *Origin:* spine S8; today the transfer rides the generic
approval service whose escalation is a single `>=` against one of three literal thresholds
(`ApprovalService.php:30-32`, `:332`).

**R-XX-120** The transfer order is a §XX.4 document, issued once on approval, sealed, with the
same evidence rules. **Acknowledgement is recorded as acknowledgement, and consent — where
the customer requires consent — is a distinct, declared, separately evidenced act.**
*Origin:* BR-EMP-043 — *"transfer acknowledgement is recorded but is not consent"*
(`TransferController.php:179-208`), on a public, non-expiring, unthrottled token
(SEC-11) whose access check is a **case-insensitive email match**, so *"a shared or
duplicated email grants access to another employee's transfer order"* (`D-…` §Q6 row 15). All
of §XX.4.6 applies to this token.

### XX.7.3 The statutory consequences of a state change

**R-XX-121** A transfer that changes the **work state** changes the applicable statutory
position. SmartPRS 3 does not encode what changes; it **re-resolves** the statutory profile
from the rule pack layering (spine S12: central baseline + state/UT rule + establishment
applicability + company policy) as at the effective date, and reports the differences for
acknowledgement before the transfer is approved. *Origin:* the hard rule on legal values —
this blueprint asserts no rate, slab, threshold or due date; and BR-RPT-004, which shows what
happens when jurisdiction is ignored: the minimum-wage check uses the **maximum** notified
wage across the whole tenant, so *"a Mumbai minimum is applied to a Nagpur agent"*
(`ReportController.php:139`) even though state and zone rows exist.

**R-XX-122** The transfer preview shows the **resolved statutory delta** as a table:
per statutory head, the position before and after, the pack version and jurisdiction that
produced each, the first payroll period affected, and whether registration action is required
of the employer. Values are `<from rule pack>`. A head whose applicability cannot be resolved
is an **exception that blocks approval**, never a silent default. *Origin:* spine S12 and
S11's "preview cannot proceed with unresolved exceptions"; and the general finding that
SmartPRS 2 resolves statutory applicability from a per-tenant JSON blob with no scope
(`H-…` §1.16, NEW-CFG-101).

**R-XX-123** Mid-period transfers carry a declared **split rule** per statutory head and per
component: which establishment's return the period belongs to, whether the period is split
pro rata, and which cost centre bears the cost. Declared per head in the pack or per tenant
where the pack permits a choice. *Origin:* SMARTPRS 3 ARCHITECTURE RECOMMENDATION; there is
no concept of a split period in SmartPRS 2 at all.

**PRODUCT OWNER DECISION REQUIRED — D-XX-03: does an inter-state transfer mid-month split
the payroll period?** Recommendation: **yes, with a per-head split rule and the default
"the establishment in force on the last day of the period owns the return"**, because it is
the simplest defensible rule and matches the way most Indian returns are filed; the
alternative (true pro-rata split across two returns) is more accurate and materially more
work in both the payroll engine and the filing layer. Trade-off: the simple rule mis-states
one month's contribution split at each transfer, which some customers' auditors will
challenge — so the rule must be **per head and per tenant**, not global.

---

## XX.8 Resignation, exit and full & final *(brief Section 66)*

This is the largest single capability gap in the area: SmartPRS 2 has an `exits` request
module, an `exits` table, a nav screen — and *"no path in the employee subsystem can produce
the `exited` status, while any form save resets it to `active`"* (`D-…` PO-01; DEF-04,
`AppDataController.php:2920`). Its entire settlement capability is the four-line report
transcribed in §XX.1.9.

### XX.8.1 The exit case

**R-XX-124** An **exit case** (Part XXVIII C10 `exit_cases`) is the aggregate that owns the
whole separation: initiation, resignation date, last working date, notice arithmetic, reason,
rehire eligibility, clearance, settlement run, documents and closure. Nothing about an exit
is scattered across other modules' tables. *Origin:* `exits` today is one of nine rows in a
generic `RequestController` with a free-form `fields` array that the server accepts
*"wholly unvalidated"* (`E-…` req. 2, `RequestController.php:386`).

**R-XX-125** An exit case may be initiated by the employee (resignation), by the employer
(termination, redundancy, end of contract, retirement), or by the system (absconding, on a
declared rule). The **exit reason** is a value set with declared semantics: voluntary,
involuntary, rehire-eligible, notice-applicable, gratuity-applicable, statutory-notification-
required. Behaviour keys off the semantics, never off the reason's label. *Origin:* Law 1;
today there is one undifferentiated `exits` request.

**R-XX-126** Exit approval, and every subsequent gate, is the workflow engine. The
transition `active | on_notice → exited` exists only in `status_transitions` with the guard
`exit.clearance_complete OR context.override_approved` and workflow `EMP-EXIT` (Part X
§X.7.2), and **the status is writable only through `EntityWriteService::transition()`**.
*Origin:* Part X R-X-058 and D-X-04, which explicitly defer the exit lifecycle to this part;
and DEF-04 — an exited employee is *"silently reset to `active` by the next unrelated edit"*,
re-consuming a licensed seat.

### XX.8.2 Notice period, shortfall and recovery

**R-XX-127** The notice requirement is resolved from a **scoped, effective-dated notice
policy**: `notice_days_expr` (SPX, so it may vary by grade, service band, employment stage or
employment type), whose obligation applies on resignation, on termination, or both, and
whether probationers have a different requirement. *Origin:* there is no notice period
concept anywhere in SmartPRS 2 — not a column, not a setting, not a placeholder
(`BR-CMP-024`'s missing-placeholder list names `{{notice_period}}` explicitly).

**R-XX-128** `notice_days_served` is derived from resignation date to last working date using
a **declared day basis** (calendar, working, or payable days — the Part XII counts), and the
shortfall is `MAX(0, required − served)`. *Origin:* Law 1 and spine S5.1 — `Days` and `Hours`
do not silently coerce, and this is exactly the class of unit confusion the analysis found in
LOP (`CONSOLIDATED-REQUIREMENTS` item 7).

**R-XX-129** Shortfall **recovery** is a declared Part VIII formula on the notice policy —
per-day value basis (basic, gross, CTC/12, a fixed amount), the divisor, the rounding mode
and the rounding point — producing a `recovery` obligation in Part XVIII, netted in the F&F.
Recovery is **capped** at a configurable maximum and may be **waived** by a permissioned,
audited, workflow-decided action with a reason. *Origin:* absent entirely; and Law 7 — every
such figure today would be a float.

**R-XX-130** A shortfall waiver, a garden-leave arrangement, a notice buy-out by the employee
and a payment in lieu of notice by the employer are four **declared settlement kinds**, each
with its own sign, its own statutory-base flags (spine S12) and its own approval. *Origin:*
SMARTPRS 3 ARCHITECTURE RECOMMENDATION.

### XX.8.3 Clearance

**R-XX-131** Clearance is a **workflow instance with `parallel_all` department nodes**, not a
checklist table with a status column. Each department's node carries its own SLA, reminder
schedule, escalation target, required comment, required attachment and the specific items it
must clear. *Origin:* spine S8 — one engine, zero module-specific approval code; and
NEW-EMP-081 — checklists today are hard-coded PHP constants.

**R-XX-132** The clearance template is **configuration**: which departments participate, in
what order or in parallel, which items each owns, which items block settlement versus warn,
and which are conditional on an SPX expression (so "IT asset return applies only to on-roll
employees holding an asset"). *Origin:* `D-…` req. 49 — *"onboarding, offboarding and transfer
checklists MUST be configurable templates"*.

Seeded default template (editable, and shipped as content, not code):

| Node | Owner | Items | Blocking |
|---|---|---|---|
| Reporting manager | manager hop 1 | handover complete, pending work reassigned, knowledge transfer note | block |
| IT | role in scope | laptop, phone, SIM, access cards, VPN, account deactivation date | block |
| Administration | role in scope | ID card, uniform, keys, seat, parking | warn |
| Finance | role in scope | outstanding advances, travel settlements, corporate card, imprest | block |
| HR | role in scope | documents, exit interview, bond, retention agreement, references | block |
| Compliance *(vertical)* | role in scope | DRA card surrender, agent authorisation withdrawal, bank/portfolio de-allocation, code-of-conduct exit acknowledgement | block |
| Payroll | role in scope | final attendance frozen, leave settled, recoveries confirmed | block |

**R-XX-133** Compliance clearance **withdraws the agent's authorisations** and emits the
events the compliance module consumes, so an exited agent cannot remain authorised against a
bank portfolio. *Origin:* spine S19 #3; and BR-RPT-009 — the ex-employee access audit today
joins `users` on **email, unscoped by tenant**, so *"an ex-employee whose email exists in
another tenant reports an active login"*.

**R-XX-134** An unclearable item may be **overridden** by a named authority with a reason;
the override is recorded on the exit case, in the audit ledger, and on the F&F statement.
*Origin:* spine S19 #1's tri-state gate pattern — a block that cannot ever be overridden
becomes a block that gets bypassed outside the system.

### XX.8.4 The full and final settlement

**R-XX-135** The F&F is a **payroll run of kind `final_settlement`** executed by the Part XIV
pipeline, with an input snapshot, a reconciliation assertion and a payslip — **not a report**.
*Origin:* `H-…` §4.4 — the entire current capability is a five-line report expression on a
report dataset, with hard-coded `12`, `0.5`, `15/26` and `5 years`, *"unrelated to the
tenant's actual salary structure"*, whose date arithmetic sits in a swallowed catch that
yields `gratuity = 0` on any parse failure.

The settlement composes, each element resolved by its owning part:

| Element | Owner | Rule |
|---|---|---|
| Final attendance and payable days | Part XI / Part XII | period frozen to the last working date; `period_settlement` locked (spine S10) |
| Final salary for the part period | Part XIV | proration by the declared basis; not `ctc/12` |
| Leave encashment | Part XIII §XIII.5 | per-type encashment rule, `trigger = at_exit`, declared valuation basis and divisor |
| Gratuity | Part XVII | `<from rule pack>` eligibility, formula, wage base and ceiling — **never asserted here** |
| Statutory bonus | Part XVII | `<from rule pack>` eligibility and computation for the part year |
| Pending overtime | Part XI / Part XIV | unpaid OT settled at the resolved OT rate version |
| Expense claims | Part XVIII | approved-and-unpaid claims pulled in; unapproved claims listed as an exception |
| Commission and incentive | Part XVIII | earned-and-unpaid; clawback exposure disclosed and, per policy, withheld |
| Loans and advances | Part XVIII R-XVIII-046 | `exit_recovery_policy_code`: recover in full, recover what the F&F allows and convert the rest to a receivable, continue as a receivable, or waive by workflow |
| Notice shortfall | this part, R-XX-129 | recovery obligation |
| Asset non-return | this part, R-XX-136 | valued recovery, per the asset policy |
| Other recoveries | Part XVIII | training bond, relocation clawback, retention bonus, notice buy-out |
| Income tax | Part XVII | final-period computation on the full-year projection, `<from rule pack>` |
| Employer contributions and their closure | Part XVII | per pack; PF/ESI cessation dates |

**R-XX-136** Asset return is tracked against the asset register with a **valuation and a
recovery rule per asset type** (declared: full book value, depreciated value, a fixed
penalty, or none), producing an ordinary recovery obligation. *Origin:* the asset module
exists as a master table; nothing connects it to exit or to money.

**R-XX-137** The F&F run obeys the spine S11 **preview gate**: it cannot proceed with
unresolved exceptions — missing bank data, missing PAN or UAN, unfrozen attendance, unsettled
leave, an open clearance blocker, an unapproved variable input, a loan inconsistency, or a
negative net that no policy permits. *Origin:* spine S11; and the current F&F, which produces
a number regardless of the state of anything.

**R-XX-138** The F&F asserts **exactly** that the sum of statement lines equals the net
payable, and that every obligation in Part XVIII referencing this employment is either settled
on the statement, converted to a declared receivable, or explicitly waived. A failure aborts
the run. *Origin:* Law 7 and spine S11's reconciliation assertion; and R-XVIII-046 — *"an exit
with an outstanding loan cannot close without an explicit decision."*

**R-XX-139** A **negative F&F** (the employee owes the employer) is a first-class outcome
producing a receivable, a demand statement and a recovery workflow — not an error and not a
clamp to zero. *Origin:* SMARTPRS 3 ARCHITECTURE RECOMMENDATION; today the expression simply
yields a negative number in a report.

**R-XX-140** The **F&F statement** is a §XX.4 sealed document rendering every line with its
"How was this calculated?" trace (spine S15): each element, its formula version, its inputs,
its rounding and its statutory pack version. It is issued to the employee and retained.
*Origin:* Law 4; and the four-line report, which explains nothing.

**Worked example — the analysis's own F&F case, restated under this design.**

The analysis's case (`H-…` §4.4): CTC ₹6,00,000; date of joining 01-Apr-2018; last working
day 31-Mar-2026; advances ₹5,000; dues ₹1,200. SmartPRS 2 returns **₹1,44,761.54**, composed
of `monthly = ctc/12`, `basic = monthly × 0.5`, `gratuity = 15/26 × basic × diffInYears`,
minus advances and dues.

Under SmartPRS 3 the same employee produces a statement whose **shape** is fixed and whose
**values** come from configuration and the rule pack:

```
Final salary  01–31 Mar 2026    Part XIV, structure v7, payable days from Part XII
                                (NOT ctc ÷ 12 — the structure decides)
Leave encashment                 Part XIII encashment rule EL-ENCASH v3
                                 units × declared basis ÷ declared divisor
Gratuity                         Part XVII, eligibility <from rule pack>,
                                 formula <from rule pack>, wage base = the
                                 components flagged is_gratuity_wage (Part XV)
                                 — NOT "50 % of ctc/12"
Statutory bonus (part year)      Part XVII, <from rule pack>
Pending OT / expenses / comm.    Parts XI, XVIII
LESS  advance recovery ₹5,000    Part XVIII, exit_recovery_policy_code
LESS  other dues ₹1,200          Part XVIII
LESS  notice shortfall            R-XX-129, if any
LESS  tax                        Part XVII, <from rule pack>
= Net settlement                 asserted equal to the sum of lines (R-XX-138)
```

Three specific defects the restatement removes, named so the Product Owner can see the
change: the **50 % basic assumption** disappears because the wage base is the set of
components flagged `is_gratuity_wage`; the **`diffInYears` truncation** that gives zero
gratuity at 4 years 11 months becomes the pack's declared service rule; and the **swallowed
catch** that silently yields `gratuity = 0` on a date-parse failure becomes an exception that
blocks the run (spine S5.6 — no silent catch in a domain path).

### XX.8.5 Documents, data and closure

**R-XX-141** Relieving and experience letters are §XX.4 documents, issued on declared
conditions (clearance complete, settlement paid, or an override), sealed, and delivered.
*Origin:* NEW-DOC-106 — `experience` is *"templatable, never issuable"*, and `relieving` has
an issue screen but no link to any exit process.

**R-XX-142** Exit **de-provisioning** is a declared action set: login disabled on the last
working day, sessions revoked, API keys revoked, mobile device registrations revoked,
biometric device user removed, delegations cancelled, and pending approval tasks reassigned
by the workflow engine's reassignment rules. *Origin:* BR-RPT-009 — the ex-employee access
audit exists precisely because nothing does this today, and it is itself defective.

**R-XX-143** An exited employment carries a **post-exit data retention class** governing each
class of record separately: statutory registers and payroll records under the statutory
retention class; the settlement statement and letters with them; compliance evidence under
its own class; contact and identity data under the shortest defensible period; and biometric
templates purged on exit unless a declared obligation requires otherwise. Retention is
executed by a scheduled, audited purge, and the **last successful purge date is visible**.
*Origin:* spine S16's retention rule; NEW-CFG-102 — `data_retention_months` (a DPDP retention
period) exists in `defaults()` but is **absent from `save()`'s validation array**, so *"it can
never be changed"*; and the general finding that SmartPRS 2 retains everything and prunes
nothing.

**R-XX-144** Rehire eligibility is recorded on the exit case, is a **guard on the
`exited → active` transition** (Part X §X.7.2), and is surfaced during recruitment when a
person match (R-XX-050) resolves to a former employment. *Origin:* `exit_cases.is_rehire_eligible`
(Part XXVIII C10) exists in the schema; nothing consumes it today because nothing produces an
exit.

**PRODUCT OWNER DECISION REQUIRED — D-XX-04: may an F&F be paid before clearance completes?**
Recommendation: **yes, under a permissioned override with a mandatory reason recorded on the
statement**, because Indian practice frequently requires settlement within a statutory window
that clearance cannot always meet. Trade-off: an override that is easy to reach becomes the
norm and the clearance gate stops meaning anything, so the override must be a distinct
permission held by few, must appear on the statement, and must be reported on monthly.

---

## XX.9 Employee Self-Service *(brief Section 67)*

### XX.9.1 The absolute rule

> **An employee never selects another employee from a dropdown for a self-service
> transaction. The server resolves the subject from the session.**

This restates spine S13's non-negotiable, and it is the direct answer to the two **CRITICAL**
findings of §XX.1.8: `POST /app/requests/exits {employee: "CEO", …}` succeeds today, and
`GET /app/requests/increments` returns every colleague's old and new CTC.

**R-XX-145** For every self-service transaction, the subject is `SelfSubject`, a value object
produced **only** by `SessionSubjectResolver::resolve(session)`. There is no constructor, no
factory and no deserialiser that produces a `SelfSubject` from request input. *Origin:*
SEC-02.

### XX.9.2 How it is structurally enforced, not checked per endpoint

Five mechanisms, each of which independently makes the defect unexpressible. Per-endpoint
checks appear nowhere in this list, because per-endpoint checks are what failed: the rule was
implemented for `commissions`, and for leave after a 7 August 2026 test report, and *"never
propagated"* to the other eight modules (`E-…` SEC-02).

**Mechanism 1 — a separate route namespace with no subject in the path.**

**R-XX-146** Self-service endpoints live under `/api/v3/me/*` and **no route in that namespace
declares an employee, person or employment path parameter or query parameter**. A CI sweep
fails the build if one appears. *Origin:* `RequestController::apply()` reads
`'employee' => ['required','string']` and matches on name **or** emp_code (`:383-396`), which
is the whole of the vulnerability.

**Mechanism 2 — the request DTO has no field to carry a subject.**

**R-XX-147** Self-service commands are typed objects (`RaiseAdvanceRequest`,
`SubmitExitIntent`, `UpdateMyProfile`, …) whose constructors take a `SelfSubject` supplied by
the framework binder from the session, and whose remaining fields are the transaction's own
data. **There is no `employee_id` field to populate**, so a malicious body has nothing to
bind to and an unmapped key is rejected by the strict deserialiser. *Origin:* `E-…` req. 2 —
the server today accepts `fields` as *"a wholly unvalidated array"*
(`RequestController.php:386`).

**Mechanism 3 — a distinct policy family that cannot be satisfied by a scope.**

**R-XX-148** Every `/me/*` route binds to a `SelfPolicy`, which asserts
`subject.employment_id === session.employment_id` and **ignores data scope entirely**. A user
holding `tenant` scope, or `platform` scope, still cannot act as another employee through a
self-service route; they must use the administrative route, which has its own permission and
its own audit reason. *Origin:* spine S13 — permission and scope are orthogonal; SmartPRS 2
*"conflates them and enforces neither"*.

**Mechanism 4 — the persistence layer applies the subject.**

**R-XX-149** In a self-service unit of work, the subject predicate is applied by the
persistence layer alongside the tenant predicate (Law 6), not written into each query. A
self-service repository method that constructs a query without a resolved subject **throws**.
*Origin:* Law 6's rationale — the declared tenancy scope in SmartPRS 2 is inert because it
filters on a column that does not exist; the same class of failure is prevented here by
making the filter structural rather than clerical, and by `E-…` req. 96, which lists **six
places** in this area where the tenant `when()` clause is simply absent.

**Mechanism 5 — one resolver, one identity key.**

**R-XX-150** `SessionSubjectResolver` is the **only** own-employee resolver in the codebase,
backed by a mandatory, non-null, tenant-scoped `users.employment_id` foreign key. It never
falls back to matching on name or email. A user without a linked employment gets an explicit
"your login is not linked to an employee record" state and **no self-service capability at
all**. *Origin:* **SEC-23** — `currentEmployee()` matches `employees.email = users.email`
**OR** `employees.name = users.name`, with `->first()` and no ordering, and its
`users.employee_id` branch is *not tenant-scoped*, so *"a user may act as, and see the data
of, a namesake"*; and **TD-05** — three copies of `currentEmployee()` exist
(`RequestController.php:31-50`, `LeaveController.php:63-81`, `EssController.php:43-59`),
*"the single most reused piece of business logic in the product exists twice and diverges."*
`D-…` req. 41 asks for exactly this: **one** own-employee resolver.

**R-XX-151** A CI **sweep asserts, per route, that every route is either `/me/*` bound to a
`SelfPolicy`, or an administrative route with an explicit permission and a data-scope
binding.** A route with neither fails the build. *Origin:* spine S13; and
`FeaturePermissions.php:9-13`, whose own comment states that SmartPRS 2
*"gates the menu **CLIENT-SIDE**"*.

**R-XX-152** Listing endpoints obey the same rule from the other side: an administrative
listing returns **only rows within the caller's data scope**, and there is no listing route
without a policy binding. *Origin:* **SEC-01** — `RequestController::list()` has *"no role
guard"* and row-level scoping only for `commissions`, exposing every colleague's increment,
loan, advance, expense, exit record with clearance remarks and transfer, tenant-wide, to any
authenticated user; plus BR-LVE-010/SEC-07 (leave balances), NEW-MST-104 (**every master row
of all 58 masters** returned to any authenticated user), NEW-DSH-105 (global employee search
with *"no role guard and no self-scope"*) and NEW-NOT-112 (greeting logs).

### XX.9.3 The ESS surface

Every item below is `/api/v3/me/*`, subject from session, policy `SelfPolicy`, rate-limited
(R-XX-158), and audited where it writes (R-XX-157).

| Area | Capability | Notes and origin |
|---|---|---|
| **Profile** | View my record; edit the fields configuration permits; upload a photo | Editable set is **configuration per tenant per field** via `field_surface_rules` on the `ess` surface (spine S9), not a literal array — *Origin:* NEW-ESS-102, `EssController.php:266-277` |
| | Request a change to a field I may not self-edit | Routed to a workflow; the change applies only on approval |
| | Change email / mobile | **Verified by OTP to the existing channel before the new value takes effect** — *Origin:* SEC-08/NEW-ESS-104: payslips, leave decisions and request decisions all deliver to `employees.email`, which is today *"self-changeable with no verification"* |
| | Date of birth | A typed `Date` field with validation — *Origin:* NEW-ESS-103: today `dob` is *"any string ≤ 20 chars"*, and the greeting sweep matches on `DATE_FORMAT(dob,'%m-%d')`, so *"an employee can choose the day they are greeted"* |
| **Bank** | View masked account; request a change | **Maker-checker mandatory** (spine S8), old and new values in the audit ledger, notification to the previously registered channel |
| **Statutory** | View PAN, UAN, PF, ESI, PT state, masked per role; submit or correct identifiers | Feeds the same fields payroll's preview gate blocks on (spine S11) |
| **Documents** | Upload documents against the resolved checklist; see verification state, expiry and what is outstanding; download my own documents | Served by signed, expiring, **object-level authorised** URLs — *Origin:* BR-REC-042: any user with the `employee` role can today download **any** document in the tenant |
| **Attendance** | My punches, my resolved day statuses, my late/early/OT minutes, my roster and shift, with **"How was this calculated?"** on every derived day (spine S15) | Configurable period, not a fixed current-month window — *Origin:* NEW-ESS-107: list sizes are fixed literals (6 payslips, 6 leaves, 5 notices, current month only) |
| | Raise an attendance regularisation | Workflow-decided |
| **Leave** | Balances per type with tranches and expiry; the **same number the engine enforces**; day-by-day preview before applying; apply, withdraw, cancel; upload a certificate; team calendar where permitted | *Origin:* DEF-LVE-04/NEW-LVE-101 — ESS publishes `ent − used` while the leave module enforces `ent − used − pending`, so *"an employee reads '5 remaining' in My Space and is refused a 5-day application"*; and DEF-ESS-01 — every ESS leave row renders the literal `'Leave'` because `type_id` is never written |
| **Comp-off** | Balance, tranches, expiry, and the earned-time election where the policy offers one | Part XIII §XIII.10 |
| **Payslips** | List, view with per-line explainability, download where the company policy permits | The existing per-company download policy is preserved (NEW-ESS-106 — a rev172 behaviour that is correct) |
| **Tax** | Declarations, proofs, projected liability, Form 16 where issued | Part XVII |
| **Requests** | Advance, loan, expense/reimbursement, bonus/encashment, resignation intent, asset request, grievance, helpdesk ticket, POSH complaint | **All for myself only, structurally** (§XX.9.2) — *Origin:* SEC-02 |
| **Approvals** | My own submitted items, their current workflow step, who holds them, and the SLA | *Origin:* `E-…` req. 14: there is no `due_at`, `sla_` or `reminder_sent_at` column anywhere, so a request assigned to an inactive approver *"sits Pending forever, silently"* |
| **Exit** | Submit resignation intent with a proposed last working date; see the computed notice requirement, served days and shortfall; track my clearance; view my F&F statement when issued | §XX.8 |
| **Compliance** | My compliance items, their status, what is expiring, and what I must do | Spine S19 #1 |
| **Learning** | Assigned training, mandatory items and their due dates, tests, certificates | *Origin:* NEW-SEED-103 — `mandatory` and `validity` on a seeded training programme are *"stored and enforced by nothing"* |
| **Policies** | Read published policies; acknowledge; re-acknowledge on a version bump | Part XXVIII `policy_acknowledgements`, unique **per version** |
| **Directory** | Search colleagues within the fields and population configuration exposes | *Origin:* NEW-DSH-105 — the current global search has *"no role guard and no self-scope"*, so an employee can enumerate every colleague's contact details |
| **Notifications** | In-app inbox, per-user channel preferences, working unsubscribe for non-mandatory notices | *Origin:* `E-…` req. 63 — *"absent entirely"* |
| **Help** | Screen help, resolved for the tenant's edition and language | *Origin:* NEW-HLP-101 — help is 120 static PHP entries with no tenant, edition or language awareness |

**R-XX-153** ESS content, section visibility, list sizes and default periods are
**configuration**, resolved by scope, with sensible defaults. *Origin:* NEW-ESS-107;
`E-…` req. 57.

**R-XX-154** One number is published everywhere. A quantity shown in ESS is produced by the
**same service call** the enforcing engine uses; there is no second computation of a balance,
an entitlement or a day count anywhere in the ESS layer. *Origin:* DEF-LVE-04/NEW-LVE-101 and
`E-…` req. 49 — *"one balance figure must be published everywhere."*

**R-XX-155** ESS renders configured vocabulary by **resolving the code**, never by falling
back to a literal. A display fallback that hides a data defect is prohibited. *Origin:*
DEF-ESS-01 — `$types[$a['type_id'] ?? null] ?? ($a['type'] ?? 'Leave')` renders `'Leave'` on
every row and thereby masked, for the life of the product, the fact that `type_id` is never
written — the defect that makes unpaid leave paid (Part XIII §XIII.1.1).

**R-XX-156** Every ESS write is audited with **field-level old→new values** through the audit
service (spine S14). *Origin:* NEW-ESS-105/DEF-ESS-02 — *"ESS self-edits are entirely
unaudited"*; and `E-…` NEW-AUD-109 — `AuditTrail::change()`, the only old→new helper in the
codebase, has **no call site in this entire area**.

**R-XX-157** Every ESS route is rate-limited per user and per tenant, with lockout on abuse.
*Origin:* NEW-ESS-109 — *"neither ESS route is rate-limited"*; and SEC-19 — the commission
proof upload has *"no role guard and no rate limit"*.

**R-XX-158** ESS is available on the mobile surface with the **same field definitions, the
same policies and the same resolver** (spine S9's enumerated surfaces). There is no mobile
back door. *Origin:* the mobile device gate today has a *"status not enforced server-side"*
(`N-…` §1.7).

---

## XX.10 Manager Self-Service *(brief Section 68)*

### XX.10.1 The manager's population is resolved, never selected

**R-XX-159** A manager's population is `HierarchyResolver::population(session, as_of_date,
depth_policy)`, derived from the **effective-dated reporting line** on
`employment_assignments` (Part X §X.6). It is never a client-supplied list, never a
department string comparison, and never an unbounded query narrowed by the UI. *Origin:*
`ApprovalService.php:320-329` — an approver is only *"an employee id derived from
`reporting_manager_id`"*, a single mutable column with no history; and `E-…` §7, where the
only relationship check in the whole request area exists for `commissions` and reads
`reporting_manager_id`/`reporting_manager` — an id **or a name string**.

**R-XX-160** Resolution is **as at a date**. A manager viewing August sees the team that
reported to them in August. A transfer in September does not rewrite August's team, its
attendance exceptions or its approvals. *Origin:* Law 3; and `D-…` §Q5 — *"who reported to
whom last quarter"* is not answerable in SmartPRS 2 because nothing records reporting-manager
history at all.

**R-XX-161** Depth is a **declared policy**: direct reports only, N levels, or the full
sub-tree, per capability. Approving leave may be direct-reports-only while viewing a team
calendar is two levels — that is configuration, not two code paths. *Origin:* spine S13's
`data_scope` ladder; `FeaturePermissions::MAP` today is *"a PHP constant"* and therefore
*"not customer-configurable"* (`D-…` §5(a)6).

**R-XX-162** Functional (dotted-line) managers, team leaders and delegated managers are
**separate dated relationships** with their own declared capabilities. A delegation has a
validity window, is audited, and is visible to both parties. *Origin:* spine S8's per-node
delegate and backup approver; today `team_leader` is a name string on the employee row and
delegation does not exist.

**R-XX-163** Every MSS route binds `permission × data_scope` and **fails closed** on any
error, unknown role or null scope. *Origin:* **SEC-05** — `denyUnlessRole()` *"deliberately
returns `null` (= allowed) on any exception, across 71 call sites in 30 files"*, while the
correct pattern (`abort_unless`) already exists elsewhere in the same codebase.

### XX.10.2 The MSS surface

| Area | Capability |
|---|---|
| **Team overview** | Headcount as at a date, joiners, leavers, on notice, on probation, vacancies against approved requisitions |
| **Attendance** | Today's presence, the period's day statuses, late/early/absent exceptions with the rule that produced each and its trace (spine S15), missing punches, uncovered shifts |
| **Roster** | View and publish the team roster within the configured window; shift swaps and their approval |
| **Leave** | Team calendar, who is out on any date, overlap and minimum-staffing warnings at the moment of approving, balances where permitted (never the leave **type** unless `leave.calendar.view_type` is held — Part XIII R-XIII-093) |
| **Approvals** | One inbox across every request type the manager holds a step on, with SLA, ageing, escalation state, delegation, bulk decision where the workflow permits it, and a mandatory comment where the node requires one |
| **Exceptions** | A single worklist of everything needing the manager's attention: attendance exceptions, missing timesheets, expiring compliance documents, overdue probation reviews, overdue clearance items, unacknowledged policies, expiring assets |
| **Probation** | Reviews due, reviews overdue, the manager's own review forms, and the confirmation recommendation |
| **Performance** | Cycles in flight, the manager's pending reviews, KPI capture, calibration where the cycle declares it |
| **Compensation** | Increment and promotion proposals within budget headroom, in the modes of R-XX-098, with the salary visibility the role permits |
| **Documents** | Documents requiring the manager's action — countersignature, verification, acknowledgement — never a general document browser |
| **Recruitment** | The manager's requisitions and their state, candidates at the manager's stages, scorecards owed, interviews scheduled |
| **Exit** | Resignations pending the manager's action, handover and clearance items the manager owns, exit interview where assigned |
| **Alerts** | SLA breaches, compliance expiries, negative leave balances, unapproved items ageing past a threshold — audience and thresholds configured, never literal |

**R-XX-164** MSS shows a derived number **only with its trace available** — a late count, an
LOP day, an exception — through the same `Explain` service the employee sees. A manager
approving a penalty can always answer the employee's "why?". *Origin:* Law 4.

**R-XX-165** Salary visibility in MSS is a **distinct permission** from team visibility, and
is masked by default. *Origin:* SEC-01 — every colleague's old and new CTC is today one
authenticated GET away.

**R-XX-166** A manager cannot approve their own request at any node; self-approval prohibition
is a workflow node property, applied by the engine. *Origin:* spine S8; and `E-…` req. 17 —
`decide()` never checks the current status, so nothing today prevents any decision being
repeated or self-made.

---

## XX.11 Data model

Specified to the Section 123 standard, in the conventions of spine S16 and Part XXVIII.
**Part XXVIII already registers the C10 Talent tables** (`requisitions`, `candidates`,
`interviews`, `offers`, `onboarding_journeys`, `exit_cases`, plus the derivable children
`candidate_stage_events` and `exit_clearance_items`); where Part XXVIII names a column, that
name is used, and this part **adds** the tables the engines require rather than restating
them. **Forty-six tables are specified or extended here, in thirty-one blocks** (a block that
names an artefact and its version child covers both).

**Shared conventions, stated once and not repeated per table.**

- Every table: `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` PK; `uuid CHAR(36) NOT NULL`
  (UUIDv7, the only identifier on any external surface — spine S2); `tenant_id BIGINT
  UNSIGNED NOT NULL` FK → `tenants.id` `ON DELETE RESTRICT`, participating in **every**
  unique index (Law 6); `created_at`, `created_by_user_id`, `updated_at`,
  `updated_by_user_id`; `UQ (tenant_id, uuid)`.
- Every configuration artefact carries the spine S4 **version envelope**: `artefact_id`,
  `version_no`, `status_code`, `scope_json`, `specificity_weight`, `priority`,
  `effective_from DATE NOT NULL`, `effective_to DATE NULL`, `submitted_by_user_id`,
  `approved_by_user_id`, `approved_at`, `published_at`, `superseded_by_version_id`,
  `reason`, `change_note`, `checksum`. Not repeated below.
- **No database `ENUM` anywhere.** Every `*_code` column is a `VARCHAR` FK into
  `status_values` or a `value_sets` entry (spine S16, Part XXVIII §XXVIII.4).
- Money is `DECIMAL(18,4)` at rest with an explicit `currency_code`; integer paise in memory
  (Law 7). Dates are `DATE`; instants are `DATETIME` in UTC with a business timezone and
  business date beside them; never `VARCHAR`.
- Business records are never hard-deleted; every table declares a **retention class**.
- Legend: **Null** Y = nullable · **Hist** = history mechanism · **SD** = soft delete ·
  **PII** per spine S13 classes.

### XX.11.1 Recruitment configuration

##### `cfg_recruitment_pipelines` — artefact identity
`pipeline_key VARCHAR(64) NOT NULL` (immutable), `name`, `description`, `is_system TINYINT(1)`,
`status_code`.
**Keys.** UQ `(tenant_id, pipeline_key)` · IX `(tenant_id, status_code)`.
**Policy.** Tenant-owned · Hist: versions · SD yes · Audit `full` · PII none · retention
`R-CFG-PERM`.
*Note the absence of a stage list on this table:* stages live on the version, so renaming or
reordering a stage is a version and history keeps the shape it had. *Origin:*
`RecruitmentController.php:31`.

##### `cfg_pipeline_versions` — the published definition
Version envelope, plus `default_for_requisition_type_code VARCHAR(40) NULL`,
`allows_speculative_candidates TINYINT(1) NOT NULL DEFAULT 1`,
`consent_purpose_code VARCHAR(40) NOT NULL`,
`candidate_entity_version_id BIGINT UNSIGNED NOT NULL` (FK → entity schema version, RESTRICT).
**Keys.** UQ `(tenant_id, pipeline_id, version_no)` ·
IX `(tenant_id, pipeline_id, effective_from, effective_to)`, `(tenant_id, status_code)`.
**Policy.** Hist A5 · delete: only `draft`/`testing` (Part VI T10) · Audit `full` ·
retention `R-CFG-PERM`.

##### `cfg_pipeline_stages` — typed child of a version
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `pipeline_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `stage_key` | VARCHAR(64) | N | — | immutable within the version |
| `label`, `short_label` | VARCHAR(120)/(24) | N | — | display only; **nothing keys off these** |
| `display_order` | SMALLINT | N | — | |
| `colour` | VARCHAR(16) | Y | NULL | |
| `stage_kind_code` | VARCHAR(40) | N | — | `sourcing\|screening\|assessment\|offer\|onboarding\|terminal_success\|terminal_failure` |
| `is_terminal` | TINYINT(1) | N | 0 | |
| `sla_days` | SMALLINT | Y | NULL | ageing and escalation (R-XX-009) |
| `required_field_keys_json` | JSON | Y | NULL | candidate field keys |
| `required_document_type_ids_json` | JSON | Y | NULL | |
| `entry_guard_expr_id`, `exit_guard_expr_id` | BIGINT UNSIGNED | Y | NULL | FK → `cfg_expressions` RESTRICT |
| `on_entry_actions_json`, `on_exit_actions_json` | JSON | Y | NULL | typed actions (spine S6) |
| `workflow_artefact_key` | VARCHAR(64) | Y | NULL | approval to enter |

**Keys.** UQ `(tenant_id, pipeline_version_id, stage_key)`,
`(tenant_id, pipeline_version_id, display_order)` ·
IX `(tenant_id, pipeline_version_id, stage_kind_code)`.
**Policy.** Immutable with its version · Audit `full` · PII none.
`CHECK`: exactly one `terminal_success` and at least one `terminal_failure` per version
(R-XX-005), asserted at publish.

##### `cfg_pipeline_transitions` — the transition matrix (R-XX-006)
`pipeline_version_id` (FK RESTRICT), `from_stage_key`, `to_stage_key`,
`required_permission VARCHAR(80)`, `requires_reason TINYINT(1)`,
`reason_value_set_key VARCHAR(64) NULL`, `requires_comment TINYINT(1)`,
`required_attachment_type_id BIGINT UNSIGNED NULL`, `guard_expr_id` (FK RESTRICT, nullable),
`workflow_artefact_key VARCHAR(64) NULL`, `on_transition_actions_json JSON NULL`,
`is_backward TINYINT(1) NOT NULL DEFAULT 0`.
**Keys.** UQ `(tenant_id, pipeline_version_id, from_stage_key, to_stage_key)` ·
IX `(tenant_id, pipeline_version_id, from_stage_key)`.
**Policy.** Immutable with its version · Audit `full`.
*Why the table exists at all:* `moveStage()` validates list membership *"and nothing else"*
(`:396-419`), so `rejected → hired` is legal today.

##### `cfg_sourcing_channels` — versioned artefact
`channel_key`, `name`, `channel_kind_code`, `cost_model_code`, `cost_amount DECIMAL(18,4)
NULL`, `owner_user_id NULL`, `connector_recipe_id NULL`, `lawful_basis_code`,
`consent_required TINYINT(1)`, `default_consent_purpose_code`.
**Keys.** UQ `(tenant_id, channel_key)`. **Policy.** Audit `full` · retention `R-CFG-PERM`.

##### `candidate_contact_suppressions` — append-only
`contact_kind_code` (`email|mobile`), `contact_blind_index BINARY(32) NOT NULL`,
`channel_key VARCHAR(64) NULL` (NULL = all channels), `source_code`
(`inbound_stop|manual|regulator|bounce|consent_expiry`), `effective_from DATETIME`,
`expires_at DATETIME NULL`, `evidence_json`.
**Keys.** UQ `(tenant_id, contact_kind_code, contact_blind_index, channel_key)` ·
IX `(tenant_id, contact_blind_index)`.
**Policy.** Append-only · Audit `event-only` · PII `identifying` (blind-indexed only) ·
retention `R-STAT-8Y`.
*Origin:* BR-REC-033 — *"there is no candidate opt-out, suppression list or STOP handling"*,
which the analysis classes as a DPDP obligation.

### XX.11.2 Candidates and assessment

##### `candidates` — **extends Part XXVIII C10**
Part XXVIII specifies the row. This part adds:
`pipeline_version_id BIGINT UNSIGNED NOT NULL` (FK RESTRICT — the version the candidate is
bound to, R-XX-003), `current_stage_key VARCHAR(64) NOT NULL`,
`sourcing_channel_version_id BIGINT UNSIGNED NULL` (FK RESTRICT),
`referrer_employment_id BIGINT UNSIGNED NULL` (FK RESTRICT),
`entered_pipeline_on DATE NOT NULL`, `hiring_drive_id BIGINT UNSIGNED NULL` (FK RESTRICT).
Custom and extended candidate attributes are **not columns**: they are typed rows in
`entity_field_values` keyed on `entity_key='candidate'` (spine S9), which is the whole of
R-XX-016. **Keys added.** IX `(tenant_id, pipeline_version_id, current_stage_key)`,
`(tenant_id, sourcing_channel_version_id)`, `(tenant_id, hiring_drive_id)`.

##### `candidate_stage_events` — A4, append-only (Part XXVIII names it; specified here)
`candidate_id` (FK RESTRICT), `from_stage_key VARCHAR(64) NULL`, `to_stage_key VARCHAR(64)`,
`transition_id BIGINT UNSIGNED NULL` (FK RESTRICT), `pipeline_version_id` (FK RESTRICT),
`occurred_at DATETIME`, `actor_user_id`, `reason_code VARCHAR(64) NULL`,
`comment VARCHAR(1000) NULL`, `attachment_document_id NULL`, `trace_id BINARY(16) NULL`,
`workflow_instance_id NULL`.
**Keys.** IX `(tenant_id, candidate_id, occurred_at)`,
`(tenant_id, to_stage_key, occurred_at)` ← funnel and ageing reporting.
**Policy.** Append-only · no update path · Audit `event-only` · PII `none` · retention
`R-PII-CONSENT` (purged with the candidate).
*Origin:* R-XX-008; today `stage` is a bare column and the funnel is recomputed from it.

##### `interviews` — **extends Part XXVIII C10**
Adds `pipeline_stage_key VARCHAR(64) NOT NULL`, `evaluation_form_version_id BIGINT UNSIGNED
NULL` (FK RESTRICT), `location_text VARCHAR(200) NULL`, `meeting_url_encrypted VARBINARY(512)
NULL`, `slot_id BIGINT UNSIGNED NULL` (FK RESTRICT), `rescheduled_from_interview_id NULL`.
Part XXVIII's `panel_user_ids_json` is **replaced** by the child table below, because a panel
member is a user with permissions, not a JSON name (R-XX-028).

##### `interview_panel_members`
`interview_id` (FK RESTRICT), `user_id` (FK RESTRICT), `role_code`
(`interviewer|chair|observer|coordinator`), `is_required TINYINT(1)`,
`invited_at`, `responded_at`, `response_code`, `attended TINYINT(1) NULL`.
**Keys.** UQ `(tenant_id, interview_id, user_id)` · IX `(tenant_id, user_id, interview_id)`
← "my interviews". **Policy.** Audit `full` · PII none.
*Origin:* `H-…` §1.3 — the panel is *"a free-text string, not people"*.

##### `cfg_evaluation_forms` + `cfg_evaluation_form_versions` + `cfg_evaluation_criteria`
Artefact identity (`form_key`, `subject_kind_code` = `interview|probation|performance`), the
version envelope, and criteria rows: `criterion_key`, `label`, `display_order`,
`weight DECIMAL(9,4)`, `rating_scale_value_set_key`, `min_value`, `max_value`,
`comment_requirement_code` (`never|optional|required|required_below_threshold`),
`threshold_value DECIMAL(9,4) NULL`, `is_knockout TINYINT(1)`.
Version-level: `aggregate_formula_id` (FK → `cfg_formulas` RESTRICT),
`rounding_mode_code`, `rounding_unit`, `overall_recommendation_value_set_key`.
**Keys.** UQ `(tenant_id, form_key)`, `(tenant_id, form_version_id, criterion_key)`,
`(tenant_id, form_version_id, display_order)`.
**Policy.** Hist A5 · Audit `full` · retention `R-CFG-PERM`.
*Origin:* NEW-PRF-101 — today `rating` is `numeric` with **no minimum and no maximum**, so
*"there is no rating scale at all"*; and spine S5.4 — `ROUND` requires an explicit mode.

##### `evaluation_scorecards` + `scorecard_criterion_scores` — immutable
Scorecard: `subject_kind_code`, `subject_id`, `form_version_id` (FK RESTRICT),
`submitted_by_user_id`, `submitted_at`, `aggregate_value DECIMAL(12,4)`,
`aggregate_trace_id BINARY(16) NOT NULL`, `overall_recommendation_code`,
`narrative_text TEXT NULL`, `supersedes_scorecard_id NULL`, `superseded_by_scorecard_id NULL`,
`supersede_reason VARCHAR(500) NULL`.
Scores: `scorecard_id` (FK RESTRICT), `criterion_key`, `raw_value DECIMAL(9,4)`,
`weighted_value DECIMAL(12,4)`, `comment_text TEXT NULL`.
**Keys.** UQ `(tenant_id, scorecard_id, criterion_key)` ·
IX `(tenant_id, subject_kind_code, subject_id)`, `(tenant_id, submitted_by_user_id)`.
**Policy.** **No update path** — correction is supersession (R-XX-031) · Audit `full` ·
PII `identifying` (feedback about a person) · retention `R-PII-CONSENT` for candidates,
`R-STAT-8Y` for employments.
*Origin:* NEW-REC-106 — drive roster scores are written *"with no validation"* and no clamp,
contradicting the endpoint next to them.

##### `hiring_drives`, `hiring_drive_slots`, `hiring_drive_roster`
Drive: `drive_code`, `name`, `drive_kind_code`, `pipeline_version_id` (FK RESTRICT),
`requisition_id NULL` (FK RESTRICT), `from_on DATE`, `to_on DATE`,
`window_from TIME NULL`, `window_to TIME NULL` (**`TIME`, not `VARCHAR(20)`**),
`venue_text`, `geo_lat DECIMAL(11,7) NULL`, `geo_lng DECIMAL(11,7) NULL`,
`map_url VARCHAR(500) NULL` (scheme- and host-validated, spine S17),
`coordinator_user_id`, `instructions_template_version_id NULL`, `lifecycle_state_code`.
Slots: `drive_id`, `starts_at`, `ends_at`, `capacity SMALLINT`, `booked_count SMALLINT`.
Roster: `drive_id`, `candidate_id NULL`, `slot_id NULL`, `current_stage_key`,
`invited_at`, `responded_at`, `attended_at`, `scorecard_id NULL`.
**Keys.** UQ `(tenant_id, drive_code)`, `(tenant_id, drive_id, candidate_id)` ·
IX `(tenant_id, drive_id, current_stage_key)`, `(tenant_id, slot_id)`.
`CHECK (booked_count <= capacity)` — enforced, not advisory.
**Policy.** Audit `full` · PII `identifying` · retention `R-PII-CONSENT`.
*Origin:* `H-…` §2.2 — `time_from`/`time_to` are `string(20)`, panel and slots are text JSON,
the map link is unvalidated, and capacity is enforced nowhere.

##### `candidate_conversions` — the conversion report (R-XX-044)
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `candidate_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `person_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `person_match_kind_code` | VARCHAR(40) | N | — | `new_person\|confirmed_match\|forced_new` |
| `person_match_confirmed_by_user_id` | BIGINT UNSIGNED | Y | NULL | mandatory when kind = `confirmed_match` (CHECK) |
| `candidate_entity_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `employee_entity_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `plan_json` | JSON | N | — | the full `CarryPlan`: carried / transformed / collected / not_applicable / blocked, with values, transforms and formula versions |
| `plan_hash` | BINARY(32) | N | — | integrity |
| `report_document_id` | BIGINT UNSIGNED | Y | NULL | FK → `generated_documents` RESTRICT |
| `converted_at` | DATETIME | N | — | UTC |
| `converted_by_user_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `seat_check_result_code` | VARCHAR(40) | N | — | |
| `employee_code_allocated` | VARCHAR(40) | N | — | from the numbering service |

**Keys.** UQ `(tenant_id, candidate_id)` — **one conversion per candidate, enforced by the
database** · UQ `(tenant_id, employment_id)` · IX `(tenant_id, converted_at)`.
**Policy.** Append-only · Audit `full` · PII `sensitive` (carries compensation values) ·
retention `R-STAT-8Y`.
*Origin:* NEW-EMP-077 — idempotency on `employees.name`; and `H-…` Q3 — the 16 silently
dropped fields, which `plan_json` now enumerates by construction.

### XX.11.3 Documents and signature

##### `cfg_document_types` + `cfg_document_type_versions`
`document_type_key`, `label`, `subject_kind_code`, `requires_acceptance TINYINT(1)`,
`requires_signature TINYINT(1)`, `identity_proof_code`
(`token_only|otp_to_verified_channel|otp_plus_document_number|external_provider`),
`signature_capture_code` (`typed_name|drawn|uploaded|provider`),
`name_match_rule_code`, `numbering_scheme_key`, `token_ttl_hours SMALLINT NULL`,
`token_max_ttl_hours SMALLINT NOT NULL`, `issue_workflow_artefact_key NULL`,
`retention_class_code`, `storage_class_code`, `is_compliance_evidence TINYINT(1)`,
`compliance_item_type_id NULL` (FK RESTRICT).
**Keys.** UQ `(tenant_id, document_type_key)`.
**Policy.** Hist A5 · Audit `full` · retention `R-CFG-PERM`.
`CHECK`: `requires_signature = 1` ⇒ `identity_proof_code <> 'token_only'` (R-XX-068).
*Origin:* NEW-DOC-106; and §XX.1.1 — a registered screen with no definition.

##### `cfg_document_templates` + `cfg_document_template_versions`
Identity: `template_key`, `document_type_id` (FK RESTRICT), `locale_code`.
Version: envelope, plus `body_richtext MEDIUMTEXT`, `body_ast_json JSON`
(the compiled SPX conditional/repeater AST — spine S5's "the stored artefact is the AST plus
the source text"), `header_html`, `footer_html`, `page_geometry_json`,
`letterhead_asset_id NULL`, `placeholder_manifest_json` (the resolved, type-checked symbol
list), `sample_render_document_id NULL`.
**Keys.** UQ `(tenant_id, template_key)`, `(tenant_id, template_id, version_no)` ·
IX `(tenant_id, document_type_id, effective_from, effective_to)`.
**Policy.** Hist A5 · Audit `full` · retention `R-CFG-PERM`.
*Origin:* BR-CMP-023 — resolution falls back to *"the newest of that type"* and
`letters.status` is *"never consulted"*; only a **published** version is resolvable here.

##### `generated_documents` — the issued and sealed artefact (R-XX-061, R-XX-062)
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `document_type_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `template_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT — **provenance, not content** |
| `document_no` | VARCHAR(40) | N | — | numbering service |
| `subject_kind_code` | VARCHAR(40) | N | — | `candidate\|employment\|person\|org_unit` |
| `subject_id` | BIGINT UNSIGNED | N | — | resolved per kind; FK enforced per kind by trigger-free application invariant + CHECK on kind |
| `company_org_unit_id` | BIGINT UNSIGNED | N | — | FK RESTRICT — issuing entity, letterhead and SMTP |
| `issued_at` | DATETIME | N | — | UTC |
| `issued_by_user_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `storage_key` | VARCHAR(400) | N | — | **private** object key; never a public path |
| `content_hash` | BINARY(32) | N | — | SHA-256 of the issued bytes |
| `byte_size` | BIGINT UNSIGNED | N | — | |
| `sealed_storage_key` | VARCHAR(400) | Y | NULL | the post-signature artefact |
| `sealed_content_hash` | BINARY(32) | Y | NULL | written into the audit chain (spine S14) |
| `sealed_at` | DATETIME | Y | NULL | |
| `merge_snapshot_json` | JSON | N | — | every substituted value, for reproduction |
| `version_manifest_json` | JSON | N | — | entity schema, formula, statutory pack and rule versions used (R-XX-063) |
| `calculation_trace_id` | BINARY(16) | Y | NULL | where the document shows money |
| `lifecycle_state_code` | VARCHAR(40) | N | `issued` | `draft\|issued\|delivered\|viewed\|accepted\|declined\|signed\|expired\|revoked\|superseded` |
| `supersedes_document_id`, `superseded_by_document_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |
| `retention_class_code` | VARCHAR(40) | N | — | from the type |

**Keys.** UQ `(tenant_id, document_no)`, `(tenant_id, sealed_content_hash)` where non-null ·
IX `(tenant_id, subject_kind_code, subject_id, issued_at)`,
`(tenant_id, document_type_version_id, lifecycle_state_code)`,
`(tenant_id, lifecycle_state_code, issued_at)`.
**Policy.** Tenant-owned · Hist: supersession · **no delete path**; storage object is
write-once and removed only under an audited retention purge in one transaction with the
storage delete confirmed · Audit `full` · PII `sensitive` (compensation, identity) ·
retention per type.
*Origin, stated in one line because it is the reason this table exists:* NEW-DOC-104 — the
issued row today stores a template pointer and the merge happens at render, so
*"editing the template rewrites what was signed"*; and `letters.pdf_path`, a column for this
store *"never written or read by any code"*.

##### `document_signature_events` — the evidence bundle (R-XX-071)
`document_id` (FK RESTRICT), `event_kind_code`
(`viewed|otp_sent|otp_verified|otp_failed|accepted|declined|signed|revoked|expired`),
`occurred_at DATETIME`, `ip_address VARBINARY(16)`, `user_agent_hash BINARY(32)`,
`device_class_code VARCHAR(40) NULL`, `geo_country_code CHAR(2) NULL`, `asn INT UNSIGNED NULL`,
`identity_proof_code VARCHAR(40) NULL`, `identity_channel_masked VARCHAR(60) NULL`,
`consent_text_version_id BIGINT UNSIGNED NULL`, `consent_accepted TINYINT(1) NULL`,
`signature_method_code VARCHAR(40) NULL`, `signature_typed_value VARCHAR(200) NULL`,
`signature_image_object_key VARCHAR(400) NULL`, `presented_content_hash BINARY(32) NULL`,
`audit_ledger_entry_id BIGINT UNSIGNED NULL` (FK RESTRICT), `reason VARCHAR(500) NULL`.
**Keys.** IX `(tenant_id, document_id, occurred_at)`,
`(tenant_id, event_kind_code, occurred_at)`.
**Policy.** **Append-only, no update, no delete** · Audit `event-only` (it *is* evidence) ·
PII `sensitive` · retention with the document.
*Origin:* `H-…` Q5 — the evidence **not** captured today is user agent, device, signature
image, consent record, document content, content hash, rendered PDF, identity verification
*"and any `activity_logs` entry"*.

##### `document_access_tokens` (R-XX-072 – R-XX-078)
`document_id` (FK RESTRICT), `token_hash BINARY(32) NOT NULL`, `token_purpose_code`
(`accept|sign|view`), `issued_at`, `expires_at DATETIME NOT NULL`,
`max_uses SMALLINT NOT NULL DEFAULT 1`, `used_count SMALLINT NOT NULL DEFAULT 0`,
`consumed_at DATETIME NULL`, `revoked_at DATETIME NULL`, `revoked_by_user_id NULL`,
`revoke_reason VARCHAR(300) NULL`, `rotated_from_token_id NULL`,
`delivery_channel_code`, `delivery_destination_masked VARCHAR(120)`.
**Keys.** **UQ `(tenant_id, token_hash)`** — the index whose absence today makes every public
page load a table scan · IX `(tenant_id, document_id, token_purpose_code)`,
`(tenant_id, expires_at)` ← the expiry sweep.
**Policy.** Audit `full` on issue and revoke · PII none (hash only) · retention with the
document.
*Origin:* BR-CMP-022 — *"no expiry, no rotation, no revocation, no single-use"*, plaintext,
unindexed.

##### `document_token_access_log` — append-only
`token_id NULL` (FK RESTRICT — null for an unknown token), `document_id NULL`,
`presented_token_hash BINARY(32)`, `occurred_at`, `ip_address VARBINARY(16)`,
`user_agent_hash BINARY(32)`, `verdict_code`
(`ok|expired|revoked|consumed|unknown|wrong_type|wrong_tenant|throttled`).
**Keys.** IX `(tenant_id, occurred_at)`, `(tenant_id, document_id, occurred_at)`,
`(tenant_id, verdict_code, occurred_at)` ← abuse detection.
**Policy.** Append-only · retention `R-SEC-2Y` (declared separately from the document).

### XX.11.4 Lifecycle

##### `cfg_probation_policies` + `cfg_probation_policy_versions`
Version columns per §XX.5.1: `duration_expr_id` (FK → `cfg_expressions`),
`min_duration_days`, `max_total_duration_days`, `applies_when_expr_id NULL`,
`waivable`, `waiver_workflow_artefact_key NULL`, `review_form_version_id NULL`,
`review_points_json`, `reviewer_rules_json`, `reminder_offsets_days_json`,
`reminder_semantics_code` (`exact|at_or_past`), `catch_up TINYINT(1) NOT NULL DEFAULT 1`,
`overdue_nudge_interval_days`, `audience_expr_id NULL`, `extension_allowed`,
`max_extensions TINYINT`, `max_extension_days SMALLINT`, `extension_workflow_artefact_key`,
`confirm_workflow_artefact_key`, `confirmation_compensation_mode_code`,
`confirmation_compensation_formula_id NULL`, `confirmation_letter_type_id NULL`,
`extension_letter_type_id NULL`, `overdue_gate_severity_code` (`off|warn|block`),
`on_confirm_actions_json`, `on_extend_actions_json`, `on_terminate_actions_json`.
**Keys.** UQ `(tenant_id, policy_key)`, `(tenant_id, policy_id, version_no)`.
**Policy.** Hist A5 · Audit `full` · retention `R-CFG-PERM`.
*Origin:* one per-tenant JSON blob today, merged with `array_replace_recursive` so
*"a key can never be removed"*, with no scoping at all.

##### `probation_cases` + `probation_events`
Case: `employment_id` (FK RESTRICT), `policy_version_id` (FK RESTRICT),
`started_on DATE`, `scheduled_end_on DATE`, `current_end_on DATE`,
`extension_count TINYINT NOT NULL DEFAULT 0`, `outcome_code NULL`
(`confirmed|extended|terminated|waived`), `decided_on DATE NULL`,
`decided_by_user_id NULL`, `workflow_instance_id NULL`,
`confirmation_document_id NULL`, `compensation_revision_id NULL` (FK RESTRICT),
`lifecycle_state_code`.
Events (append-only): `probation_case_id`, `event_kind_code`, `effective_on`,
`occurred_at`, `actor_user_id`, `reason VARCHAR(500)`, `old_end_on`, `new_end_on`,
`review_scorecard_id NULL`, `trace_id NULL`.
**Keys.** UQ `(tenant_id, employment_id, started_on)` ·
IX `(tenant_id, current_end_on, outcome_code)` ← the reminder and overdue sweep;
`(tenant_id, probation_case_id, occurred_at)`.
**Policy.** Case: Audit `full`. Events: append-only. PII none. Retention `R-STAT-8Y`.
*Origin:* `probation_events` exists today and is *"created at RUNTIME by
`ProbationService::ensureSchema()`"* (`D-…` §2) — migration-owned here.

##### `compensation_revisions` (increment / promotion / confirmation revision)
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `revision_kind_code` | VARCHAR(40) | N | — | `increment\|decrement\|promotion\|confirmation\|correction\|structure_change` |
| `amount_mode_code` | VARCHAR(40) | N | — | `percentage\|fixed_amount\|new_ctc\|component_values` (R-XX-098) |
| `basis_component_code` | VARCHAR(64) | Y | NULL | **code, never name** |
| `percentage_value` | DECIMAL(9,4) | Y | NULL | one of the four amount columns is non-null (CHECK on mode) |
| `delta_amount` | DECIMAL(18,4) | Y | NULL | |
| `new_annual_ctc` | DECIMAL(18,4) | Y | NULL | |
| `component_values_json` | JSON | Y | NULL | `{component_code: amount}` |
| `currency_code` | CHAR(3) | N | — | |
| `effective_on` | DATE | N | — | authoritative; may be past or future |
| `previous_compensation_id`, `new_compensation_id` | BIGINT UNSIGNED | Y | NULL | FK → `employee_compensations` RESTRICT |
| `assignment_change_id` | BIGINT UNSIGNED | Y | NULL | the promotion's assignment row |
| `eligibility_trace_id` | BINARY(16) | Y | NULL | R-XX-104 |
| `retro_policy_version_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `letter_document_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — issued **once** |
| `supersedes_revision_id`, `superseded_by_revision_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |
| `lifecycle_state_code` | VARCHAR(40) | N | `draft` | `draft\|pending_approval\|approved\|in_force\|cancelled\|superseded` |

**Keys.** UQ `(tenant_id, employment_id, effective_on, revision_kind_code)` ·
IX `(tenant_id, employment_id, effective_on)`,
`(tenant_id, lifecycle_state_code, effective_on)` ← the "scheduled changes" panel,
`(tenant_id, workflow_instance_id)`.
**Policy.** **No update after approval** — correction is supersession (R-XX-103) ·
Audit `full` with field-level before/after · PII `sensitive` · retention `R-PAY-8Y`.

##### `cfg_retro_policies` + versions (R-XX-105)
Version columns exactly as the block in §XX.6.3: `applies_to_change_kinds_json`,
`recompute_mode_code`, `delta_formula_id NULL`, `proration_basis_code`,
`fixed_divisor SMALLINT NULL`, `period_boundary_rule_code`, `components_in_scope_json`,
`statutory_recompute_code`, `minimum_payable DECIMAL(18,4) NOT NULL DEFAULT 0`,
`rounding_mode_code`, `rounding_unit`, `target_period_rule_code`,
`target_run_kind_code`, `payslip_presentation_code`, `requires_approval`,
`workflow_artefact_key NULL`.
**Keys.** UQ `(tenant_id, policy_key)`. **Policy.** Hist A5 · Audit `full`.
*Origin:* `IncrementController.php:359-421` — `/12`, calendar-day first-month proration and a
`>= 1` rupee floor, all hard-coded.

##### `retro_effects` — the computed arrear, per employment per affected period
`source_kind_code` (`compensation_revision|assignment_change|policy_change|statutory_change`),
`source_id`, `employment_id` (FK RESTRICT), `retro_policy_version_id` (FK RESTRICT),
`affected_period_id` (FK RESTRICT), `original_run_id NULL`, `target_run_id NULL`,
`amount DECIMAL(18,4)`, `currency_code`, `component_breakdown_json`,
`statutory_treatment_code`, `trace_id BINARY(16) NOT NULL`, `lifecycle_state_code`
(`computed|approved|paid|cancelled`).
**Keys.** UQ `(tenant_id, source_kind_code, source_id, affected_period_id)` — idempotent
recomputation · IX `(tenant_id, employment_id, target_run_id)`,
`(tenant_id, target_run_id, lifecycle_state_code)`.
**Policy.** Audit `full` · PII `sensitive` · retention `R-PAY-8Y`.
*Origin:* R-XX-107 — arrears today are one auto-approved row in the **commissions** table.

##### `employment_transfers`
`employment_id` (FK RESTRICT), `transfer_type_version_id` (FK RESTRICT),
`effective_on DATE NOT NULL`, `requested_on`, `reason_code`, `reason_text`,
`from_assignment_id`, `to_assignment_id` (FK → `employment_assignments` RESTRICT),
`changed_dimensions_json` (the dimensions actually changed, with from/to ids),
`crosses_legal_entity TINYINT(1) NOT NULL`, `statutory_delta_json`,
`statutory_delta_acknowledged_by_user_id NULL`, `checklist_instance_id NULL`,
`order_document_id NULL`, `acknowledged_at NULL`, `consent_document_id NULL`,
`workflow_instance_id`, `lifecycle_state_code`
(`draft|pending_approval|approved|in_force|cancelled|superseded`).
**Keys.** UQ `(tenant_id, employment_id, effective_on)` ·
IX `(tenant_id, lifecycle_state_code, effective_on)`,
`(tenant_id, to_assignment_id)`.
**Policy.** Audit `full` · PII none · retention `R-STAT-8Y`.
*Origin:* `transfers` today has **no migration at all** — it is created by
`ApprovalService::ensureAuditCols('transfers')` — and `to_company_id` is used *"with no
tenant check"* (DEF-31). Here every org reference is an FK, and `tenant_id` participates in
every index.

##### `cfg_notice_policies` + versions
`notice_days_expr_id` (FK RESTRICT), `applies_on_resignation`, `applies_on_termination`,
`probation_notice_days_expr_id NULL`, `day_basis_code`
(`calendar|working|payable`), `shortfall_recovery_formula_id NULL`,
`shortfall_cap_expr_id NULL`, `waiver_workflow_artefact_key NULL`,
`payment_in_lieu_formula_id NULL`, `garden_leave_allowed`.
**Keys.** UQ `(tenant_id, policy_key)`. **Policy.** Hist A5 · Audit `full`.
*Origin:* there is no notice-period concept in SmartPRS 2.

##### `exit_cases` — **extends Part XXVIII C10**
Adds `initiated_by_kind_code` (`employee|employer|system`),
`notice_policy_version_id` (FK RESTRICT), `notice_days_basis_code`,
`notice_shortfall_days DECIMAL(9,3)`, `shortfall_waived_days DECIMAL(9,3) NOT NULL DEFAULT 0`,
`shortfall_waiver_reason VARCHAR(500) NULL`, `settlement_kind_code`,
`clearance_workflow_instance_id NULL`, `clearance_override_by_user_id NULL`,
`clearance_override_reason VARCHAR(500) NULL`, `deprovisioned_at NULL`,
`relieving_document_id NULL`, `experience_document_id NULL`,
`settlement_statement_document_id NULL`, `retention_class_code`.
Part XXVIII's `notice_shortfall_recovery_id` FK to `recoveries` is retained.

##### `exit_clearance_items` — A3 (Part XXVIII names it; specified here)
`exit_case_id` (FK RESTRICT), `checklist_item_version_id` (FK RESTRICT),
`item_key`, `owner_role_code`, `owner_user_id NULL`, `workflow_node_instance_id NULL`,
`is_blocking TINYINT(1)`, `state_code` (`pending|cleared|waived|failed`),
`cleared_by_user_id NULL`, `cleared_at NULL`, `comment VARCHAR(1000) NULL`,
`evidence_document_id NULL`, `recovery_obligation_id NULL` (FK RESTRICT — an unreturned
asset becomes money), `override_by_user_id NULL`, `override_reason VARCHAR(500) NULL`.
**Keys.** UQ `(tenant_id, exit_case_id, item_key)` ·
IX `(tenant_id, exit_case_id, state_code)`, `(tenant_id, owner_user_id, state_code)`.
**Policy.** Audit `full` · PII none · retention `R-STAT-8Y`.

##### `exit_settlement_lines`
`exit_case_id` (FK RESTRICT), `payroll_run_id` (FK RESTRICT), `payslip_id NULL`,
`line_kind_code` (`final_salary|leave_encashment|gratuity|bonus|overtime|expense|commission|
notice_recovery|loan_recovery|advance_recovery|asset_recovery|other_recovery|tax|
employer_contribution`), `component_code NULL`, `display_order`,
`amount DECIMAL(18,4)`, `sign TINYINT`, `currency_code`,
`source_kind_code`, `source_id NULL`, `formula_version_id NULL`,
`statutory_pack_version_id NULL`, `trace_id BINARY(16) NOT NULL`.
**Keys.** UQ `(tenant_id, exit_case_id, line_kind_code, component_code, source_id)` ·
IX `(tenant_id, exit_case_id, display_order)`, `(tenant_id, payroll_run_id)`.
**Policy.** Immutable once the run is approved · Audit `full` · PII `sensitive` · retention
`R-PAY-8Y`.
`ASSERT SUM(amount × sign) = exit_cases.net_settlement` — a run-level assertion that aborts
the run on failure (R-XX-138, Law 7).

##### `cfg_checklist_templates` + versions + `checklist_instances` + `checklist_instance_items`
One artefact family serving **onboarding, transfer/relocation and exit clearance**
(R-XX-033, R-XX-116, R-XX-132). Template version: `purpose_code`
(`onboarding|transfer|exit|other`), `items[]` each with `item_key`, `label`,
`display_order`, `owner_kind_code` (`role|named_user|manager_hop|department_role`),
`owner_ref`, `is_blocking`, `applies_when_expr_id NULL`, `document_type_id NULL`,
`sla_days NULL`, `workflow_node_key NULL`.
Instance: `purpose_code`, `template_version_id`, `subject_kind_code`, `subject_id`,
`workflow_instance_id NULL`, `lifecycle_state_code`, `completed_at NULL`.
**Keys.** UQ `(tenant_id, template_key)`,
`(tenant_id, purpose_code, subject_kind_code, subject_id)` — **one open instance per subject
per purpose, keyed on the subject's id, never on a name** ·
IX `(tenant_id, lifecycle_state_code)`.
**Policy.** Audit `full` · retention `R-STAT-8Y`.
*Origin:* NEW-EMP-081 — two hard-coded 6-item PHP constants; BR-REC-018 and BR-EMP-042 —
onboarding idempotency keyed on the employee's **name** in two separate places.

### XX.11.5 What is deliberately absent

| Not built | Why |
|---|---|
| A `candidates.custom_field_*` column family | Custom fields are `entity_field_values` rows (Law 2, spine S9) |
| A `letters` table holding both templates and issued letters | Part XXVIII T9 already requires the split; they are different entities with different lifecycles, retention and PII classes |
| A `pdf_path` column | R-XX-064 — the store is real or the column does not exist |
| A `transfers.from_branch` snapshot column | Derived from the assignment in force on the effective date (R-XX-113) |
| A per-module approval table | The workflow engine owns every approval (spine S8) |
| An `employees.status` write path | Status is a transition (Part X R-X-058) |
| A second employee-write path for conversion | `EntityWriteService` only (R-XX-052) |
| A `probation_months` column with no writer | R-XX-084 makes the override reachable, or it is not built |

---

## XX.12 Configuration surface — the Section 127 test

Every row is a real request an Indian HR or collections customer has made or will make. The
test is the brief's: *"3 late arrivals over 10 minutes = half day" must not require code.*

| # | Customer requirement | Configuration | Code? |
|---|---|---|---|
| 1 | "Our pipeline is Applied → Telephonic → Assessment → Panel → Ops Approval → Offer → Joined, plus Dropped and On Hold" | one pipeline version, seven stages, two terminal | none |
| 2 | "A candidate cannot reach Offer without two completed panel rounds and no `no_hire`" | one transition guard (§XX.2.3 worked example) | none |
| 3 | "Field-agent hires use a three-stage walk-in pipeline; managers use the seven-stage one" | two pipelines, scoped by designation family | none |
| 4 | "Capture 'Referred by' and 'Two-wheeler owned' on the candidate, and carry both to the employee" | two candidate field definitions with `maps_to_employee_field_key` | none |
| 5 | "The panel must not see expected CTC" | `field_surface_rules`: `expected_ctc` hidden on the `interview` surface | none |
| 6 | "Scorecard: 5 criteria, weights 30/25/20/15/10, 1–5 scale, comment mandatory below 3" | one evaluation form version | none |
| 7 | "Reject reasons must be picked from a list, and 'Salary mismatch' must be reportable" | a `reason_value_set_key` on the terminal transition | none |
| 8 | "Add an 'Appointment Letter' that merges probation period, notice period and reporting manager" | one document type + one template; placeholders resolve from the entity schema | none |
| 9 | "The offer link must expire in 7 days and be usable once" | `token_ttl_hours = 168`, `max_uses = 1` on the offer document type | none |
| 10 | "The NDA must be OTP-verified and the signer must type their exact name" | `identity_proof_code = otp_to_verified_channel`, `signature_capture_code = typed_name`, name-match rule | none |
| 11 | "Probation is 6 months for staff, 3 months for managers, 1 year for trainees" | three probation policy versions, scoped by grade and employment stage | none |
| 12 | "Remind HR and the manager at 45, 30 and 7 days, and keep reminding weekly if overdue" | `reminder_offsets_days = [45,30,7]`, `overdue_nudge_interval_days = 7`, audience expression | none |
| 13 | "Confirmation gives a 10 % increase effective the confirmation date" | `confirmation_compensation_mode_code = formula`, a Part VIII formula | none |
| 14 | "An increment may raise HRA only, leaving basic alone" | `amount_mode_code = component_values` | none |
| 15 | "Back-dated increments pay arrears on working days, not calendar days, and recompute PF" | one retro policy version: `proration_basis_code = working_days`, `statutory_recompute = recompute` | none |
| 16 | "Arrears must go to a separate arrears run, not the regular payroll" | `target_period_rule_code = separate_arrears_run` | none |
| 17 | "Increments need the appraisal rating to be 3 or above" | one SPX eligibility rule over the captured KPI/rating | none |
| 18 | "A transfer inside the group keeps the manager; a transfer to another legal entity does not" | two transfer types with different `on_transfer` declarations | none |
| 19 | "Show me the statutory change before I approve a Hyderabad → Mumbai transfer" | built in (R-XX-122); the values come from the rule pack | none |
| 20 | "Notice is 30 days for staff and 90 days for managers; shortfall recovers at basic ÷ 30 per day" | one notice policy version + one recovery formula | none |
| 21 | "Waive notice shortfall for a redundancy" | waiver workflow on the notice policy, reason mandatory | none |
| 22 | "Clearance needs IT, Admin, Finance, HR and Compliance in parallel, and Compliance blocks" | one clearance template version | none |
| 23 | "An unreturned laptop recovers its depreciated value from the F&F" | asset type recovery rule → recovery obligation → settlement line | none |
| 24 | "Pay the F&F within 45 days even if Admin has not cleared" | the R-XX-134 override, held by a named permission | none |
| 25 | "Employees may edit their address and emergency contact, but a bank change needs approval" | `field_surface_rules` on the `ess` surface + maker-checker on bank | none |
| 26 | "An email change must be confirmed on the old address first" | built in (§XX.9.3, contact-change row); channel and template configurable | none |
| 27 | "Managers see two levels down for the team calendar but approve only direct reports" | two depth policies on two capabilities | none |
| 28 | "A manager must not see team salaries" | withhold the salary-visibility permission | none |
| 29 | "Add 'Show Cause Notice' with a 48-hour response deadline" | one document type + template + workflow | none |
| 30 | "Rename 'Screening' to 'CV Sift'" | edit the label on a new pipeline version — **and no historic funnel figure changes** (R-XX-004) | none |
| 31 | "Purge rejected candidates' data 6 months after rejection" | `consent_expires_on` rule on the pipeline's consent purpose | none |
| 32 | "Employee codes are `HYD/25-26/0001`" | one numbering scheme (R-XX-014) | none |
| 33 | "Off-roll agents must be compliance-tracked but must not consume a payroll seat" | `consumes_seat = false`, `payroll_eligible = false`, compliance checklist scoped in | none |
| 34 | "Reduce the notice period below the statutory minimum" | **blocked at publish**, naming the pack version and the jurisdiction (spine S12) | n/a |

---

## XX.13 Worked SPX

Realistic Indian collections/BPO scenarios. Every value shown is illustrative configuration,
never a legal assertion.

### XX.13.1 Offer-stage entry guard *(R-XX-007)*

```
-- No candidate reaches OFFER without a complete panel and no blocking recommendation
agg.count('interview', 'since(candidate.entered_pipeline_on)', 'status = COMPLETED')
    >= policy.min_completed_rounds
AND NONE(interview.recommendations, r -> r IN ['NO_HIRE','STRONG_NO_HIRE'])
AND candidate.field('expected_ctc') <= requisition.budget_ctc_max
```
*Replaces:* BR-REC-020, under which a `strong_no_hire` candidate can be hired with no warning.

### XX.13.2 Probation duration by grade and stage *(R-XX-083)*

```
PROBATION_DURATION :=
  CASE
    WHEN employee.employment_stage = 'TRAINEE'            THEN 12 months
    WHEN org.grade IN ['M1','M2','M3']                    THEN 3 months
    WHEN employee.employment_type = 'FIXED_TERM'          THEN 0 months
    ELSE                                                       6 months
  END
```

### XX.13.3 Confirmation eligibility *(R-XX-093)*

```
service.months >= policy.min_service_months
AND agg.count('attendance_day', 'since(employment.joining_date)', 'status = ABSENT_UNPAID')
      <= policy.max_unpaid_absences
AND probation.latest_review_aggregate >= policy.min_review_score
AND NOT employment.has_open_disciplinary_case
```
*Replaces:* nothing — there is no confirmation eligibility concept in SmartPRS 2 at all, and
NEW-PRF-102 records that `final_rating` drives nothing.

### XX.13.4 Increment eligibility *(R-XX-104)*

```
DATEDIFF(period.start, employment.last_revision_effective_on, 'months') >= 12
AND employee.stage = 'CONFIRMED'
AND performance.latest_cycle_rating >= 3
AND agg.sum('lop_days', 'financial_year', '') <= 5
```

### XX.13.5 Increment amount by rating band *(R-XX-098, mode `percentage`)*

```
INCREMENT_PCT :=
  BANDS( performance.latest_cycle_rating,
         [ {from: 4.5, to: 5.0, result: 14.0},
           {from: 3.5, to: 4.5, result: 10.0},
           {from: 3.0, to: 3.5, result:  6.0},
           {from: 0.0, to: 3.0, result:  0.0} ] )
```
Applied as `salary.component('CTC') % INCREMENT_PCT` — spine S5.2's percent-of operator, in
`Money`, with the component's declared rounding. Every band boundary is configuration.

### XX.13.6 Notice requirement and shortfall recovery *(R-XX-127, R-XX-129)*

```
NOTICE_DAYS :=
  CASE
    WHEN employee.stage = 'PROBATION'  THEN policy.probation_notice_days
    WHEN org.grade IN ['M1','M2','M3'] THEN 90
    ELSE                                    30
  END

NOTICE_SHORTFALL_DAYS := MAX(0, NOTICE_DAYS - exit.notice_days_served)

NOTICE_RECOVERY :=
  ROUND( ( salary.component('BASIC') / policy.notice_day_divisor ) * NOTICE_SHORTFALL_DAYS,
         'half_up', 0.01 )
```
`policy.notice_day_divisor` is configuration (26, 30, or the period's payable days). There is
**no default divisor** — spine S5.4.

**Worked example.** Basic ₹25,000.00 per month; divisor 30; required notice 30 days;
resignation 05-Aug-2026; last working day 20-Aug-2026 → served 16 days (calendar basis,
inclusive), shortfall 14 days.
`NOTICE_RECOVERY = ROUND((₹25,000.00 / 30) × 14, half_up, 0.01) = ₹11,666.67`.
The trace names the divisor, the basis, the policy version and the two dates.

### XX.13.7 Exit clearance blocking condition *(R-XX-132)*

```
-- Compliance clearance applies only to agents who held a bank authorisation
EXISTS(compliance.items, i -> i.type_code = 'AGENT_AUTHORISATION'
                          AND i.state_code IN ['ACTIVE','EXPIRING'])
```

### XX.13.8 Retro effect, delta mode *(R-XX-105)*

```
RETRO_MONTHS :=
  CASE WHEN retro.period_boundary_rule = 'from_effective_date'
       THEN ( retro.period.end_day - revision.effective_on_day + 1 )
              / PAYABLE_DAYS(retro.period.start, retro.period.end, employee.scope)
       ELSE 1
  END

RETRO_AMOUNT := ( revision.new_monthly - revision.old_monthly ) * RETRO_MONTHS
```
Note that even the "same as SmartPRS 2" behaviour is expressed with `PAYABLE_DAYS` rather
than a hard-coded `daysInMonth`, so a customer switching `proration_basis_code` to
`working_days` changes the result without changing code — the exact freedom
`IncrementController.php:366` does not have.

### XX.13.9 Document token expiry by type *(R-XX-073)*

```
TOKEN_TTL_HOURS :=
  CASE
    WHEN document.type_key = 'OFFER'   THEN 168      -- 7 days
    WHEN document.type_key = 'NDA'     THEN 72
    WHEN document.type_key = 'SHOWCAUSE' THEN 48
    ELSE                                    24
  END
```

---

## XX.14 API surface

All `/api/v3`, OpenAPI-generated, UUIDs only, cursor pagination on every collection,
idempotency keys on every unsafe method, a consistent error envelope, and **an explicit
policy binding on every route** (Law 5, spine S17).

### XX.14.1 Recruitment

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET/POST | `/recruitment/pipelines` | list, create pipeline artefacts | `recruitment.pipeline.view` / `.manage` |
| POST | `/recruitment/pipelines/{uuid}/versions` | draft a version (stages + transitions) | `recruitment.pipeline.manage` |
| POST | `/recruitment/pipelines/{uuid}/versions/{v}/validate` | matrix reachability, terminal-state and guard type checks | `recruitment.pipeline.manage` |
| POST | `/recruitment/pipelines/{uuid}/versions/{v}/publish` | publish (maker-checker where declared) | `recruitment.pipeline.publish` |
| GET/POST | `/recruitment/requisitions` | list / raise | `recruitment.requisition.view` **scoped** / `.create` |
| POST | `/recruitment/requisitions/{uuid}/submit` | into the approval workflow | `recruitment.requisition.create` |
| GET | `/recruitment/requisitions/{uuid}/positions` | approved, filled, offered, in-pipeline | scoped |
| GET/POST | `/recruitment/candidates` | search / create | `recruitment.candidate.view` scoped / `.create` |
| PATCH | `/recruitment/candidates/{uuid}` | PATCH semantics only (spine S9) | `recruitment.candidate.edit` |
| POST | `/recruitment/candidates/{uuid}/transitions` | move stage — **the only stage-write path** | derived from the transition's `required_permission` |
| GET | `/recruitment/candidates/{uuid}/timeline` | stage events, interviews, documents, communications | scoped |
| POST | `/recruitment/candidates/import` | one importer: upload → map → preview → commit | `recruitment.candidate.import` |
| GET/POST | `/recruitment/interviews` | schedule, list | `recruitment.interview.manage` / panel membership |
| POST | `/recruitment/interviews/{uuid}/scorecards` | submit a scorecard (immutable) | panel membership |
| POST | `/recruitment/interviews/{uuid}/scorecards/{uuid}/supersede` | correction with a reason | `recruitment.scorecard.supersede` |
| GET/POST | `/recruitment/drives`, `/recruitment/drives/{uuid}/roster` | drives and roster | `recruitment.drive.manage` |
| POST | `/recruitment/campaigns` | queued, throttled, suppression-filtered bulk send | `recruitment.campaign.send` |
| GET | `/recruitment/funnel?pipeline=&from=&to=&scope=` | funnel, ageing, conversion ratios from stage events | `recruitment.report.view` scoped |
| POST | `/recruitment/candidates/{uuid}/conversion/preview` | the full `CarryPlan` — **mandatory before commit** | `employee.create` |
| POST | `/recruitment/candidates/{uuid}/conversion` | convert (idempotency key required) | `employee.create` |
| GET | `/recruitment/candidates/{uuid}/conversion/report` | the stored conversion report | `employee.view` scoped |

### XX.14.2 Documents and signature

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET/POST | `/documents/types`, `/documents/templates`, `/documents/templates/{uuid}/versions` | the artefacts | `document.type.manage` / `document.template.manage` |
| POST | `/documents/templates/{uuid}/versions/{v}/render-sample` | typed placeholder check + sample PDF | `document.template.manage` |
| POST | `/documents/issue` | render, hash, store, number, deliver | `document.issue.<type>` |
| GET | `/documents/{uuid}` | metadata + a signed, expiring content URL | scoped, object-level |
| POST | `/documents/{uuid}/tokens` | mint a token (revokes prior) | `document.token.issue` |
| DELETE | `/documents/{uuid}/tokens/{uuid}` | revoke | `document.token.revoke` |
| GET | `/documents/{uuid}/evidence` | the full evidence bundle | `document.evidence.view` |
| GET | `/documents/register?from=&to=&type=&state=` | the e-signature register (R-XX-082) | `document.register.view` |
| **public** | `GET /public/documents/{token}` | render the **document itself** | token; throttled; access-logged |
| **public** | `POST /public/documents/{token}/otp` | request an OTP | token; throttled |
| **public** | `POST /public/documents/{token}/decision` | accept / decline / sign | token + verified OTP; single use; CSRF-protected |

### XX.14.3 Lifecycle

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET/POST | `/lifecycle/probation-policies` (+ versions, publish) | the artefact | `probation.policy.manage` / `.publish` |
| GET | `/lifecycle/probation/board?scope=&as_of=` | due, overdue, extended, decided | `probation.view` **scoped** |
| POST | `/lifecycle/probation/{uuid}/reviews` | submit a review form | participant or `probation.review` |
| POST | `/lifecycle/probation/{uuid}/decision` | confirm / extend / terminate / waive | `probation.decide` (workflow-derived) |
| GET/POST | `/lifecycle/revisions` | list / propose a compensation revision | `compensation.revision.view` scoped / `.create` |
| POST | `/lifecycle/revisions/{uuid}/simulate` | new structure, arrears, statutory delta, before approval | `compensation.revision.create` |
| POST | `/lifecycle/revisions/{uuid}/cancel` | cancel a pending future-dated revision | `compensation.revision.cancel` |
| GET/POST | `/lifecycle/retro-policies` (+ versions, publish) | the artefact | `payroll.retro.manage` |
| GET | `/lifecycle/retro-effects?run=&employee=` | computed arrears with traces | `payroll.view` scoped |
| GET/POST | `/lifecycle/transfers` | list / raise | `transfer.view` scoped / `.create` |
| POST | `/lifecycle/transfers/{uuid}/preview` | assignment delta **and statutory delta** | `transfer.create` |
| POST | `/lifecycle/transfers/{uuid}/cancel` | cancel before the effective date | `transfer.cancel` |
| GET/POST | `/lifecycle/exits` | list / initiate | `exit.view` scoped / `.create` |
| POST | `/lifecycle/exits/{uuid}/notice` | recompute notice, shortfall, waiver | `exit.manage` |
| GET/POST | `/lifecycle/exits/{uuid}/clearance` | items and decisions | item owner, workflow-derived |
| POST | `/lifecycle/exits/{uuid}/clearance/override` | override with a reason | `exit.clearance.override` |
| POST | `/lifecycle/exits/{uuid}/settlement/preview` | the F&F working, fully traced | `exit.settle` |
| POST | `/lifecycle/exits/{uuid}/settlement/run` | execute the `final_settlement` run | `exit.settle` + `payroll.run` |
| GET | `/lifecycle/exits/{uuid}/statement` | the sealed F&F statement | scoped; the employee sees their own |
| POST | `/lifecycle/exits/{uuid}/close` | close the case, trigger retention classification | `exit.close` |
| GET/POST | `/lifecycle/checklist-templates` | onboarding / transfer / exit templates | `checklist.manage` |

### XX.14.4 Self-service and manager self-service

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/me` | my profile, resolved for the `ess` surface | `SelfPolicy` |
| PATCH | `/me/profile` | edit what configuration permits; audited old→new | `SelfPolicy` |
| POST | `/me/contact-changes` | email/mobile change → OTP to the **existing** channel | `SelfPolicy` |
| POST | `/me/bank-changes` | maker-checker mandatory | `SelfPolicy` |
| GET | `/me/documents` · POST `/me/documents` | my checklist, my uploads | `SelfPolicy` |
| GET | `/me/attendance` · `/me/attendance/{date}/explain` | my days and their traces | `SelfPolicy` |
| GET | `/me/leave/*` · `/me/payslips` · `/me/tax/*` | Part XIII, XVI, XVII surfaces | `SelfPolicy` |
| GET/POST | `/me/requests/{type}` | **no subject field exists on any of these** (R-XX-147) | `SelfPolicy` |
| POST | `/me/exit/intent` | resignation intent with a proposed last working date | `SelfPolicy` |
| GET | `/me/exit` | my notice arithmetic, clearance and statement | `SelfPolicy` |
| GET | `/me/compliance` · `/me/learning` · `/me/policies` · POST `/me/policies/{uuid}/acknowledge` | | `SelfPolicy` |
| GET | `/team?as_of=` | my population, resolved from the dated reporting line | `mss.team.view` + depth policy |
| GET | `/team/attendance` · `/team/leave` · `/team/roster` · `/team/exceptions` | | `mss.*` scoped |
| GET | `/team/approvals` · POST `/team/approvals/{uuid}/decide` | the unified inbox; the engine decides | workflow-derived |
| GET | `/team/probation` · `/team/performance` · `/team/documents-for-action` | | `mss.*` scoped |

**R-XX-167** No route in `/me/*` accepts an employee, person or employment identifier in any
position — path, query, body or header. Asserted by a CI sweep over the generated OpenAPI
document, which fails the build. *Origin:* SEC-02.

**R-XX-168** Every collection endpoint in this part is **cursor-paginated with a declared
maximum page size**, and every export is queued and streamed. *Origin:* BR-REC-021 — the
candidate board silently truncates at 2 000 rows; `H-…` Q6 — report export
*"passes `limit = null`"* and builds the whole CSV in memory in one web request.

---

## XX.15 Events

Emitted by this part (Part V's `domain_event_types` catalogue; the same catalogue drives the
signed webhook contract of spine S17):

| Event | When | Principal consumers |
|---|---|---|
| `requisition.raised` / `.approved` / `.reopened` / `.filled` / `.closed` | requisition lifecycle | notification, budget, recruitment reporting |
| `candidate.created` / `.stage_changed` / `.rejected` / `.withdrawn` | pipeline movement | funnel reporting, campaign suppression, consent clock |
| `candidate.consent_expiring` / `.consent_expired` | the consent sweep | PII purge, notification |
| `interview.scheduled` / `.rescheduled` / `.completed` / `.no_show` | interview lifecycle | calendar, panel reminders, ageing |
| `scorecard.submitted` / `.superseded` | assessment | stage guards, reporting |
| `offer.issued` / `.viewed` / `.accepted` / `.declined` / `.lapsed` / `.withdrawn` | offer lifecycle | conversion trigger, requisition fill, notification |
| `document.issued` / `.delivered` / `.viewed` / `.accepted` / `.signed` / `.declined` / `.revoked` / `.expired` | every generated document | compliance scoring, e-signature register, audit |
| `document.token_abuse_detected` | throttle or unknown-token burst | security alerting (spine S18 operations dashboard) |
| `employment.created_from_candidate` | conversion commits | onboarding, compliance, payroll eligibility, licensing, biometric enrolment |
| `employment.conversion_blocked` | preview blocked | recruiter worklist |
| `probation.started` / `.review_due` / `.review_submitted` / `.overdue` | probation clock | notification, escalation, compliance gate |
| `employment.confirmed` / `probation.extended` / `probation.terminated` | decision | entitlement unlock, compensation, letters, payroll |
| `compensation.revision_approved` / `.in_force` / `.cancelled` / `.superseded` | revision lifecycle | payroll, arrears, letters, CTC reporting |
| `retro.effect_computed` / `.approved` / `.paid` | arrears | payroll run inputs, GL |
| `employment.transfer_approved` / `.in_force` / `.cancelled` | transfer lifecycle | payroll, statutory re-resolution, cost centre, biometric device mapping, MSS hierarchy cache |
| `employment.statutory_profile_changed` | any assignment change that alters applicability | Part XVII, compliance |
| `exit.initiated` / `.notice_computed` / `.clearance_started` / `.clearance_item_cleared` / `.clearance_complete` / `.clearance_overridden` | exit lifecycle | notification, asset, IT de-provisioning, compliance withdrawal |
| `exit.settlement_previewed` / `.settled` / `.negative_settlement_raised` | F&F | payroll, receivables, GL, statutory |
| `employment.exited` | status transition | licensing seat release, access revocation, retention classification, reporting |
| `employment.rehire_flagged` | a person match resolves to a former employment | recruiter worklist |

Consumed by this part:

| Event | Source | Effect here |
|---|---|---|
| `config.version_published` | Part VI | re-resolve pipelines, policies, templates; invalidate resolution caches |
| `workflow.instance_completed` / `.step_decided` / `.sla_breached` | Part IX | drive stage transitions, probation decisions, clearance nodes, escalation |
| `payroll.run_approved` / `.period_locked` | Part XIV | freeze final attendance; permit or refuse F&F execution |
| `leave.balance_changed` | Part XIII | exit encashment valuation |
| `loan.obligation_changed` | Part XVIII | exit recovery position |
| `compliance.item_state_changed` | compliance module | stage guards, clearance blocking |
| `rule_pack.activated` | Part XVII / spine S12 | re-resolve statutory deltas on pending transfers and exits, and warn where a pending case's basis has moved |
| `document.type_version_published` | this part | invalidate template resolution |

**R-XX-169** Every event carries `tenant_id`, `correlation_id`, the actor, the subject uuid,
the artefact versions in force and — where a number is involved — the `trace_id`. No consumer
may re-derive a value the producer already computed. *Origin:* Law 4; and BR-REQ-029, where
the only two post-approval actions in SmartPRS 2 are hard-coded `if ($module === '…')` blocks
whose failures are logged and ignored.

---

## XX.16 Explainability *(spine S15)*

`Explain(entity, field)` answers for every derived value this part produces. Four
representative traces.

**Why is this candidate blocked from the Offer stage?**

```
Candidate        Sunita Rao  (CND-2026-01188)
Pipeline         Collections Field Hiring v4 (published 01-Jul-2026, effective 01-Jul-2026 →)
Attempted        Panel  →  Offer
Transition       PANEL→OFFER (matrix row 11), requires permission recruitment.offer.create
Guard            SPX  agg.count('interview', …, 'status = COMPLETED') >= 2
                      AND NONE(recommendations, r -> r IN ['NO_HIRE','STRONG_NO_HIRE'])
Evaluated        completed rounds = 2                                     PASS
                 recommendations  = ['HIRE', 'NO_HIRE']                   FAIL
Outcome          BLOCKED — a panel member recorded No Hire (Round 2, 14-Aug-2026)
Override         permitted for role Talent Head, reason mandatory
```

**What did this conversion carry?** *(R-XX-044)*

```
Candidate        CND-2026-01188   →   Employment  HYD-0447
Converted        02-Sep-2026 11:04 IST by  p.menon@…       Idempotency  CNV-9f2c…
Person match     new_person (no PAN, UAN, email or mobile match)
Carried    14    name, mobile, email, location, experience_years,
                 current_employer, notice_period_days, source_channel,
                 referrer, skills, expected_ctc, resume (document 4471), …
Transformed 4    position "Sr. Collections Officer" → designation_id 91
                     transform lookup_master(designations, name, on_missing=propose)
                     confirmed by p.menon@… at 11:03
                 offered_ctc ₹4,20,000 → compensation (structure "TG Collections" v7)
                 offer_joining_date 15-Sep-2026 → joining_date
                 expected_ctc → retained as a recruitment attribute, not employee CTC
Collected   9    pan, uan, bank_account, ifsc, dob, address, emergency_contact,
                 pf_number, dra_status        — via onboarding journey OJ-2026-0331
Not applicable 3 applied_on          (recruitment_only)
                 rating              (recruitment_only)
                 interview_remarks   (retained_on_candidate_record)
Blocked     0
Seat check       PASS (licensed 500, used 412)
Employee code    HYD-0447   scheme "HYD/FY/SEQ"   allocated transactionally
```

**How was this arrear calculated?** *(R-XX-105)*

```
Employment       HYD-0412        Revision  REV-2026-0233  (approved 03-Sep-2026)
Change           CTC ₹6,00,000 → ₹7,20,000, mode = new_ctc, effective 16-Apr-2026
Retro policy     RETRO-STD v2 (published 01-Apr-2026, effective 01-Apr-2026 →)
                 recompute_mode = delta_formula · proration = calendar_days
                 boundary = from_effective_date · minimum_payable = ₹0.00
                 rounding = half_up @ 0.01 · target = next_open_run
Monthly delta    (₹7,20,000 − ₹6,00,000) ÷ 12                    = ₹10,000.00
Apr-2026         (30 − 16 + 1) ÷ 30                              = 0.5000000
May–Aug 2026     4 whole months                                  = 4.0000000
Months payable                                                   = 4.5000000
Arrears          ₹10,000.00 × 4.5                                = ₹45,000.00
Statutory        statutory_recompute = recompute  →  PF/ESI/PT/TDS on the arrear month
                 per India pack <pack version>, <values from rule pack>
Target run       Sep-2026 regular (open)
```

**How was this full and final calculated?**

```
Employment       HYD-0412   Exit case EX-2026-0074
Resignation      05-Aug-2026     Last working day  20-Aug-2026
Notice           required 30 (NOTICE-STD v3, grade band) · served 16 (calendar basis)
                 shortfall 14 days · waived 0 · recovery ₹11,666.67
                 = ROUND((BASIC ₹25,000.00 ÷ 30) × 14, half_up, 0.01)
Final salary     01–20 Aug 2026, payable days 17 of 26 (Part XII), structure "TG Collections" v7
Leave encashment EL 12.5 days × (BASIC ÷ 26) — rule EL-ENCASH v3
Gratuity         eligibility, formula, wage base and ceiling: <from India rule pack, v…>
                 wage base = components flagged is_gratuity_wage (Part XV)
Bonus            <from India rule pack, v…>, part year
Recoveries       advance ₹5,000.00 (ADV-2026-0091, policy full_from_fnf)
                 other dues ₹1,200.00 · asset none outstanding
Tax              <from India rule pack, v…>
Clearance        IT ✓ · Admin ✓ · Finance ✓ · HR ✓ · Compliance ✓ (authorisations withdrawn
                 21-Aug-2026) · Payroll ✓
Assertion        SUM(lines) = net settlement                     PASS
Statement        sealed 24-Aug-2026, SHA-256 3b7c…, delivered to the verified personal email
```

**R-XX-170** A derived value in this part may not be returned without a trace; a trace-less
result throws (Law 4). Traces are retained with the record they explain.

---

## XX.17 Migration implication

### XX.17.1 Disposition per capability

| Capability | Verdict | What comes across | What does not |
|---|---|---|---|
| Requisitions (`job_openings`) | **MIGRATE + REDESIGN** | Rows, mapped to `requisitions`; `req_code` retained as a legacy identifier and **re-issued under a real numbering scheme** | Approval state is re-derived: `approval_status` values outside the four-value list were being read as *approved* (BR-REC-005) and must be **re-decided or migrated as `draft`**, per tenant |
| Candidates (`recruitment`) | **MIGRATE + REDESIGN** | The 30 columns map to candidate field definitions; talent-pool rows (`pool=1`) become candidates with no requisition | The six-stage vocabulary maps into a **seeded default pipeline** whose transition matrix is initially permissive, then tightened by the tenant. Stage *history* does not exist and cannot be back-filled |
| Interviews | **MIGRATE (as history)** | Rows, with panel free text preserved as `panel_text_legacy` | Free-text panels cannot be resolved to users; scores were unvalidated and carry no form version, so they migrate as **historical scorecards with `form_version = LEGACY`** and are excluded from any guard |
| Hiring drives and campaigns | **MIGRATE** | Drives, slots, roster, campaigns, per-recipient messages | Delivery statuses derived by string-searching the payload (NEW-REC-107) are migrated as `unknown` rather than trusted |
| Offers and NDAs (`letters`, `is_template=0`) | **MIGRATE — AND RE-PAPER** | The row, its status, `accepted_at`, `accepted_ip`, `signed_name` migrate as **legacy evidence**, explicitly marked `evidence_grade = legacy_insufficient` | **No sealed artefact can be produced retrospectively**, because the body was never stored and the template has since changed. See D-XX-06 |
| Letter templates (`is_template=1`) | **MIGRATE** | Body, type, company; re-parsed against the new placeholder symbol table with a **per-template report of unresolvable placeholders** | The 21-key `strtr` list; the HTML-detection heuristic |
| NDA rows | **AUDIT BEFORE MIGRATING** | Whatever exists | Because the screen cannot save, any existing row arrived by direct SQL or a removed path. **Each must be confirmed before it is allowed to contribute to a compliance score** (D-XX-05) |
| Probation (`probation_events`, employee columns) | **MIGRATE** | End dates, confirmations, extensions become `probation_cases` + `probation_events`; the per-tenant JSON becomes one seeded policy per tenant | Exact-day reminder semantics; the unreachable `probation_months` override becomes a real field with **no historic values to migrate** |
| Increments | **MIGRATE + REDESIGN** | Rows become `compensation_revisions` with `amount_mode_code = new_ctc`; `applied_at` maps to `in_force` | The arrears rows written into `commissions` are migrated as **historical commission entries flagged `legacy_arrear`**, not as retro effects, because their basis cannot be reconstructed |
| Transfers | **MIGRATE + REDESIGN** *(spine S19 — the effective-dated from→to register is one of the few good patterns)* | Rows become `employment_transfers` **plus** back-filled `employment_assignments` rows | Any transfer whose `to_company_id` belongs to another tenant (DEF-31) is **quarantined and reported**, never migrated |
| Exits (`exits` request rows) | **MIGRATE (as history)** | Rows become closed `exit_cases` with `lifecycle_state_code = legacy_closed` | No notice arithmetic, no clearance, no settlement lines exist to migrate. The `fnf` report was never persisted — **there are no historic F&F statements at all** |
| Employee statuses `resigned`/`terminated`/`left`/`absconded` | **AUDIT BEFORE MIGRATING** | — | These are recognised by `SEAT_FREE_STATUSES` but *"writable only by the import wizard's unvalidated `status` column or direct SQL"* (`D-…` PO-16). A per-tenant data audit precedes the status migration |
| Performance reviews | **MIGRATE (as text)** | Rows as historical records | *"There is no appraisal product here — no scale, no cycle, no competencies, no weighting, no calibration, no employee participation, no downstream effect, and a cross-tenant IDOR"* (`H-…` §9). **Nothing computed can be carried forward** |
| Off-roll agents | **MIGRATE + REDESIGN** | Become `person` + `employment` with an off-roll employment type | The two divergent `offroll_agents` schemas must be reconciled per install first (`H-…` §2.2) |
| Onboarding checklists | **REPLACE** | Open items as instance items against a seeded template | The two 6-item PHP constants |
| Public tokens | **DO NOT MIGRATE** | — | Every existing `accept_token` is **revoked at cutover**. They have no expiry, no single-use and no revocation; carrying them forward would carry the vulnerability forward |

### XX.17.2 Migration reports required before cutover

**R-XX-171** A **legacy signature evidence report** listing every non-template `letters` row
with a token, its status, whether it was accepted or signed, whether the template it points at
has been edited since, and the resulting `evidence_grade`. *Origin:* NEW-DOC-104 and
`H-…` PO-02 — *"if any offer acceptance or NDA signature has been used in a dispute, an audit
or a bank submission, the evidential gaps are a live commercial risk."*

**R-XX-172** An **NDA provenance report** per tenant: how many `letters` rows with
`letter_type='nda'` and `status='signed'` exist, when each was created, by what means, and how
many compliance scores currently include the 15-point NDA component. *Origin:* §XX.1.1 and
`H-…` PO-01.

**R-XX-173** A **namesake collision report**: every pair of live employees sharing a name; every
candidate marked hired with no corresponding employee row; every onboarding record whose name
matches more than one employee. These are the people SmartPRS 2 **never hired**, never
onboarded, or onboarded against the wrong record. *Origin:* NEW-EMP-077 and BR-EMP-042.

**R-XX-174** A **cross-tenant transfer report**: every `transfers` row whose `to_company_id`
does not belong to the row's tenant, and every employee currently sitting in a company outside
their tenant as a result. *Origin:* DEF-31.

**R-XX-175** An **unhired-off-roll report**: every off-roll agent with no compliance record, no
DRA/PCC verification and no seat, with their current bank/portfolio allocations. *Origin:*
BR-REC-016 and `N-…` §1.7 module 20.

**R-XX-176** A **candidate PII retention report**: candidate rows by age and terminal state,
with the volume that would be purged under each candidate retention option, so the retention
period is chosen with the number visible. *Origin:* Part XXVIII C10 — rejected candidates'
data is *"the most exposed PII class in the product"*.

---

## XX.18 Edge cases and failure states

Specific and unglamorous. Each states the required behaviour, and where SmartPRS 2's behaviour
is known it is named.

### XX.18.1 Recruitment

| # | Case | Required behaviour |
|---|---|---|
| 1 | Two candidates with the same name in one requisition | Both exist, both convert, both get distinct employments. *(SmartPRS 2: the second is never hired — NEW-EMP-077.)* |
| 2 | The same person applies twice through different channels | Duplicate detection on the configured keys raises a **merge proposal**; a merge is a permissioned action preserving both event histories |
| 3 | A candidate is a current employee | The pipeline shows it; the conversion path becomes an internal transfer or promotion, not a new employment |
| 4 | A candidate is a former employee | The person match surfaces the exit case and `is_rehire_eligible`; a non-eligible rehire is blocked unless overridden with a reason |
| 5 | The pipeline version is republished while candidates are in flight | In-flight candidates stay on their bound version (R-XX-003); a supervisor may migrate them explicitly, per candidate, with a mapping |
| 6 | A stage is removed in a new version while candidates sit in it | Publication is **blocked** with the affected candidates named |
| 7 | The requisition is closed while a candidate holds an accepted offer | The offer is honoured; the requisition reports an over-fill exception requiring an explicit decision |
| 8 | An offer is accepted after the candidate was rejected | Impossible: the offer token resolves only while the candidate is in a permitted state (R-XX-078) |
| 9 | Interview panel member leaves the organisation | The scorecard survives; the panel slot reports as vacated; quorum recomputes |
| 10 | The scorecard aggregate formula changes | Existing scorecards keep their form version and their stored aggregate; nothing is recomputed |
| 11 | Import file with 40 000 rows | Queued, chunked, resumable, with a per-row verdict file. *(SmartPRS 2: synchronous, capped, silently truncated at 2 000 — BR-REC-021.)* |
| 12 | Campaign template arity mismatch | Rejected at composition, before a single send. *(SmartPRS 2: no arity check — BR-REC-028.)* |
| 13 | Candidate on the suppression list is included in a campaign audience | Excluded, counted, and reported; never silently sent to |
| 14 | Consent expires while the candidate is mid-pipeline | An alert to the recruiter and a defined grace; on expiry without renewal, the candidate is purged per the retention class and the requisition reports the loss |

### XX.18.2 Conversion

| # | Case | Required behaviour |
|---|---|---|
| 15 | A required-at-conversion field is missing | Conversion is **blocked** with the field named; no partial employment is created |
| 16 | The designation on the candidate does not exist | `on_missing = propose` → an explicit, permissioned confirmation. *(SmartPRS 2: silent `INSERT designations` — BR-REC-015.)* |
| 17 | No licensed seat is available | Blocked inside the transaction, with the licence position shown. *(SmartPRS 2: import and self-onboarding bypass the check entirely — DEF-06.)* |
| 18 | The employee code scheme collides | The allocation is transactional and DB-unique (Part X R-X-045); a collision retries within the transaction. *(SmartPRS 2: non-transactional `count+1` with reuse after deletion — `H-…` §4.1.)* |
| 19 | Joining date is in the future | The employment exists in `pre_hire`; it becomes `active` on the joining date by the passage of time (Part X R-X-048); payroll ignores it until then |
| 20 | The candidate never joins after conversion | A `no_show` transition reverses the employment to a cancelled state, releases the seat, and records the reason against the requisition |
| 21 | Conversion is retried after a network failure | The idempotency key returns the original result; `UQ (tenant_id, candidate_id)` on `candidate_conversions` makes a duplicate impossible |
| 22 | The candidate entity version changed between preview and commit | The commit is refused with a `409` and a diff; the preview must be re-run |

### XX.18.3 Documents

| # | Case | Required behaviour |
|---|---|---|
| 23 | The template is edited after a document was signed | The sealed artefact is unaffected — it *is* bytes. *(SmartPRS 2: the signed document silently changes — NEW-DOC-104.)* |
| 24 | The token link is forwarded to a third party | The OTP goes to the **verified channel**, not to whoever holds the link; every view is logged with IP and user-agent hash |
| 25 | The recipient opens the link after expiry | An expiry page offering a re-issue request; the recruiter sees the request. *(SmartPRS 2: no expiry exists, and the 404 copy claims one that does not.)* |
| 26 | The signatory types a different name | Refused server-side against the declared name-match rule. *(SmartPRS 2: no validation; signs with a fallback or NULL — NEW-DOC-102.)* |
| 27 | Rendering fails (font, image, template error) | Issue fails atomically; nothing is delivered; the operator sees the cause. No document is ever delivered without a stored artefact |
| 28 | The storage write succeeds and the database write fails | The transaction rolls back and an orphan-object sweep reclaims the object; the reverse order is prohibited |
| 29 | The same document is issued twice by a double-click | Idempotency key; one document, one number |
| 30 | A revoked token is presented | `verdict_code = revoked`, logged, throttled, with a neutral page that discloses nothing |
| 31 | Bulk token revocation during an incident | One permissioned action by type, batch or date range, fully audited |

### XX.18.4 Lifecycle

| # | Case | Required behaviour |
|---|---|---|
| 32 | Probation ends on a non-working day | The end date stands; reminders and the review window use the working-day calendar (Part XII) |
| 33 | The scheduler was down for three days over a probation milestone | `at_or_past` + catch-up fires each missed milestone once, deduped. *(SmartPRS 2: never caught up — BR-EMP-045.)* |
| 34 | Probation is extended past the configured maximum | Refused, with the policy's maximum and the extension count shown |
| 35 | An employee resigns during probation | The exit path governs; the probation case closes as `superseded_by_exit`, and probation-specific notice applies if declared |
| 36 | Two revisions carry the same effective date | Refused by `UQ (tenant_id, employment_id, effective_on, revision_kind_code)`; a correction supersedes instead |
| 37 | A revision is approved after its effective date has passed | Retro effects compute for the elapsed periods; if a period is locked, the arrear targets the next open run per the retro policy |
| 38 | A revision is cancelled after arrears were paid | Cancellation is blocked; a reversing revision plus a negative retro effect is the only path |
| 39 | Arrears round to less than the configured minimum | Suppressed and **reported**, never silently dropped. *(SmartPRS 2: a hard-coded `>= 1` rupee floor with no report.)* |
| 40 | A transfer's effective date precedes the last locked payroll period | Allowed, but it produces retro effects and a statutory delta; both are shown before approval |
| 41 | An employee is transferred and promoted on the same date | One transaction, one assignment row, one workflow; partial application is impossible |
| 42 | A future-dated transfer's destination org unit is deactivated before the effective date | The pending change is flagged; the transfer cannot come into force against an inactive unit and raises an exception |
| 43 | A state change makes a statutory head applicable that has no registration | Blocked at approval with the registration named. *(SmartPRS 2: silently applies the tenant's single blob.)* |
| 44 | Last working day before the resignation date | Refused at validation |
| 45 | The employee absconds — no resignation, no notice | An employer-initiated exit with reason semantics `involuntary`, notice not applicable, clearance items force-failed with reasons, and a negative settlement if recoveries exceed dues |
| 46 | An exit is withdrawn before the last working day | A permissioned reversal restoring the status, cancelling clearance, and recording the reason; pending de-provisioning is cancelled |
| 47 | The employee is rehired before the F&F is settled | Blocked; the settlement must close first, or an explicit link is recorded between the two employments |
| 48 | Gratuity eligibility falls one day short | The pack's rule decides, and the trace shows the exact service computation. *(SmartPRS 2: `diffInYears` truncation silently yields zero.)* |
| 49 | The F&F is negative | A receivable and a demand statement, not a clamp to zero (R-XX-139) |
| 50 | A clearance owner has left the organisation | The workflow's reassignment rule applies; the item never becomes unowned |
| 51 | The final payroll period is reopened after the F&F was issued | The statement is superseded by a new sealed statement; the original is retained and both are visible |
| 52 | An asset is returned after the F&F was paid | A reversing recovery adjustment against the receivable, audited |

### XX.18.5 Self-service and manager self-service

| # | Case | Required behaviour |
|---|---|---|
| 53 | A user has no linked employment | ESS returns an explicit unlinked state with **no capability**; no name or email fallback exists. *(SmartPRS 2: falls back to a name match — SEC-23.)* |
| 54 | Two employees share a name and one has a login | Irrelevant: resolution is by FK (R-XX-150) |
| 55 | A privileged admin opens ESS | They see **their own** record; scope does not widen a `/me/*` route (R-XX-148) |
| 56 | An employee attempts to raise a request for a colleague | There is no field to carry the colleague; the request is rejected by the deserialiser. *(SmartPRS 2: succeeds — SEC-02.)* |
| 57 | An employee changes their email, then a payslip is issued | The change takes effect only after OTP confirmation on the **existing** channel; the payslip goes to the address in force at issue |
| 58 | A manager views last quarter's team | The hierarchy resolves as at that date; a since-transferred employee still appears for the period they reported |
| 59 | A manager is themselves the subject of a request | Self-approval is refused by the workflow node |
| 60 | A manager's reportee is transferred mid-approval | The approver is re-resolved per the workflow's declared re-evaluation policy; the decided steps stand |
| 61 | An ESS write storm from one account | Per-user and per-tenant throttles with lockout and an operator alert |

---

## XX.19 Tests — exact-value regression scenarios

Each must have an automated test asserting exact values. `P0` blocks release.

### XX.19.1 Security and structure (all P0)

| ID | Scenario | Assertion |
|---|---|---|
| `T-XX-01` | `POST /api/v3/me/requests/advance` with a body containing `employee_id`, `employment_uuid` and `employee` | All three keys rejected by the strict deserialiser; the created request's subject equals the session's employment; **no route in `/me/*` binds a subject identifier** (OpenAPI sweep) |
| `T-XX-02` | A user with `tenant` data scope calls every `/me/*` route | Every response is scoped to their own employment; scope does not widen any of them |
| `T-XX-03` | Route-policy sweep across every route in §XX.14 | Every route has an explicit policy binding; a route without one fails the build |
| `T-XX-04` | Fault injection: throw inside the authorisation resolver | Every route **denies** |
| `T-XX-05` | Cross-tenant isolation, generated per aggregate and per endpoint in this part | Tenant A can never read or write a tenant B candidate, document, token, revision, transfer or exit case |
| `T-XX-06` | A transfer row naming another tenant's company | Rejected at the persistence layer, not at the service layer *(DEF-31)* |
| `T-XX-07` | A user whose `users.employment_id` is null calls ESS | Explicit unlinked state; no name or email fallback anywhere in the resolver *(SEC-23)* |
| `T-XX-08` | Static analysis over `Domain\Recruitment`, `Domain\Documents`, `Domain\Lifecycle` | No bare business literal; no stage, status, document-type, reason or role name as a string constant *(Law 1)* |
| `T-XX-09` | CI grep for DDL outside `database/migrations` | Zero hits; the public document routes execute no schema calls *(§XX.1.7, Law 2)* |

### XX.19.2 Recruitment and conversion

| ID | Scenario | Assertion |
|---|---|---|
| `T-XX-10` | `rejected → hired` where the matrix has no such row | Refused, naming the transition; the candidate's stage is unchanged; no event is emitted |
| `T-XX-11` | Offer-stage guard with recommendations `['HIRE','NO_HIRE']` | Blocked; the trace names the failing clause and the round *(BR-REC-020)* |
| `T-XX-12` | Two live employees named "Ravi Kumar"; hire a third candidate of that name | A **third** employment is created with a distinct code; the conversion report shows `person_match = new_person` *(NEW-EMP-077)* |
| `T-XX-13` | Convert a candidate carrying all 30 legacy attributes | Every attribute appears in exactly one `CarryPlan` list; `blocked` is empty; the report is retrievable from the employment |
| `T-XX-14` | Convert with a required-at-conversion field absent | Blocked; **no employment, no assignment, no status period, no seat consumed** exists afterwards |
| `T-XX-15` | Candidate position "Sr. Collections Officer" with no matching designation | Conversion halts with a proposal; no `designations` row is created until confirmed *(BR-REC-015)* |
| `T-XX-16` | Two concurrent conversions of two candidates in one tenant | Distinct employee codes; no gap reuse; both transactions commit |
| `T-XX-17` | Retry a conversion with the same idempotency key | The original result; exactly one `candidate_conversions` row |
| `T-XX-18` | Republish the pipeline while a candidate sits in `screening` | The candidate remains on the bound version; the funnel for the closed period is unchanged |
| `T-XX-19` | Rename a stage in a new version | Historic `candidate_stage_events` and every funnel figure for past periods are byte-identical *(R-XX-004)* |
| `T-XX-20` | Off-roll conversion | A `person` and an `employment` exist; the compliance checklist is instantiated; `consumes_seat` follows the employment type *(BR-REC-016)* |

### XX.19.3 Documents

| ID | Scenario | Assertion |
|---|---|---|
| `T-XX-21` | Sign an NDA, then edit the template and re-render | The sealed artefact's bytes and `sealed_content_hash` are unchanged *(NEW-DOC-104)* |
| `T-XX-22` | The offer acceptance page | The rendered document body is present in the response; CTC, joining date and every merged value the template contains are visible *(NEW-DOC-103)* |
| `T-XX-23` | Present an offer token to the NDA route, and vice versa | Both refused; `verdict_code = wrong_type`; both logged |
| `T-XX-24` | Present a token after acceptance | `verdict_code = consumed`; no second acceptance is written |
| `T-XX-25` | Present a token after its expiry | `verdict_code = expired`; a re-issue request is offered |
| `T-XX-26` | Re-send a document | A new token exists; the old token's `revoked_at` is set; the old token is refused |
| `T-XX-27` | Sign with a mismatched typed name, and with the consent tick absent | Both refused server-side; nothing is written *(NEW-DOC-102)* |
| `T-XX-28` | 200 token presentations in 60 seconds from one IP | Throttled; `document.token_abuse_detected` emitted |
| `T-XX-29` | A signed document's evidence bundle | Contains presented content hash, IP, user-agent hash, OTP verification instant, consent version, and the audit ledger entry id |
| `T-XX-30` | Issue where the storage write succeeds and the DB write fails | No document row; the orphan object is reclaimed; no delivery occurred |

### XX.19.4 Lifecycle — exact values

| ID | Scenario | Assertion |
|---|---|---|
| `T-XX-31` | Probation: joining 15-Jan-2026, policy 6 months, no override | `probation_end_on = 14-Jul-2026`; the trace names the policy version |
| `T-XX-32` | Scheduler down 3 days across the 30-day milestone | Exactly one reminder per missed milestone on resumption; no duplicates on the following day *(BR-EMP-045)* |
| `T-XX-33` | Confirmation with a 10 % increase effective the confirmation date | Compensation record created with the correct effective date; the confirmation letter is issued **once**; `employment.confirmed` emitted once |
| `T-XX-34` | Re-approve an already-approved confirmation | Refused by the workflow; **no second letter** *(BR-REQ-029, spine S8)* |
| `T-XX-35` | The §XX.6.3 arrears case: ₹6,00,000 → ₹7,20,000 effective 16-Apr-2026, run Sep-2026, `calendar_days` | `arrears = ₹45,000.00` exactly; months payable `4.5000000`; the trace matches §XX.16 |
| `T-XX-36` | The same case with `proration_basis_code = working_days` | A different, exact, asserted figure produced with **no code change** |
| `T-XX-37` | The same case with `minimum_payable = ₹100.00` and a computed arrear of ₹42.00 | Suppressed **and reported**; the report row exists |
| `T-XX-38` | A decrement revision | Retro effect is negative; a recovery obligation is created; the F&F and payroll both see it *(BR-EMP-050)* |
| `T-XX-39` | Transfer effective 01-Jun-2026 executed on 20-May-2026 | On 31-May the assignment is the old one; on 01-Jun it is the new one; **no job ran** *(Part X R-X-048)* |
| `T-XX-40` | Payroll for May run in July, after a June transfer | May's payslip uses May's branch, cost centre and statutory profile *(Law 3)* |
| `T-XX-41` | Inter-state transfer preview | The statutory delta table lists every head with `<from rule pack>` values, the pack version and the first affected period; an unresolvable head blocks approval |
| `T-XX-42` | Notice: required 30, served 16, basic ₹25,000.00, divisor 30 | `shortfall = 14`; `recovery = ₹11,666.67` exactly |
| `T-XX-43` | The same with a 14-day waiver | `recovery = ₹0.00`; the waiver, its reason and its approver appear on the statement |
| `T-XX-44` | F&F with an outstanding loan of ₹40,000 and a net settlement of ₹25,000, policy `full_or_convert_to_receivable` | ₹25,000 recovered, ₹15,000 converted to a receivable, net ₹0.00; the assertion passes; the conversion is audited *(R-XVIII-046)* |
| `T-XX-45` | F&F run-level assertion with a deliberately corrupted line | The run **aborts**; no statement is sealed *(Law 7)* |
| `T-XX-46` | Exit an employee, then save their profile form | Status remains `exited`; the seat remains released *(DEF-04)* |
| `T-XX-47` | Exit with an open blocking clearance item and no override | Settlement refused, naming the item and its owner |
| `T-XX-48` | Exit with the override permission | Settlement proceeds; the override, its reason and its actor appear on the sealed statement and in the ledger |
| `T-XX-49` | Post-exit retention sweep | Each record class is purged, anonymised or retained exactly per its class; the sweep is audited and its last success date is visible |
| `T-XX-50` | Reporting-line resolution as at a past date after two transfers | The manager who held the reportee in that period, not the current one *(`D-…` §Q5)* |

**R-XX-177** Every business rule stated in this part carries at least one automated test.
*Origin:* TD-12 — this area currently has **one** unit test file with four assertions, all on
greeting template rendering; and DEF-39 — *"test coverage is essentially zero"* for transfers,
increments, self-onboarding, archive/restore and **every permission gate**.

---

## XX.20 Open items — PRODUCT OWNER DECISION REQUIRED

| # | Question | Recommendation | Trade-offs |
|---|---|---|---|
| **D-XX-01** | **What is the authoritative person identity key, and what is its precedence?** (§XX.3.4; `H-…` PO-04, `D-…` PO-04) | **PAN → UAN → email blind index → mobile blind index**, with PAN and UAN as *confirming* keys and email/mobile as *suggesting* keys that raise a human confirmation, never an automatic link | PAN is not held for every worker at hire, particularly off-roll field agents, so it cannot be mandatory. Email and mobile are shared within families and reused by agencies, so treating them as confirming would merge distinct people. Requiring human confirmation on every suggested match costs recruiter time and is the only defensible default |
| **D-XX-02** | **Do off-roll agents consume a licensed seat, and are they compliance-tracked?** (§XX.3.6; `H-…` PO-03) | **Compliance tracking: yes, unconditionally.** Seat: **yes, at a distinct off-roll rate** | Today they consume neither, while being the population RBI recovery-agent norms target — *"simultaneously a compliance hole and a revenue leak"*. Charging for them is a price increase against actual (not stated) behaviour for every existing customer, and belongs at renewal, not at migration. Compliance is not conditional on the commercial answer |
| **D-XX-03** | **Does an inter-state transfer mid-month split the payroll period for statutory purposes?** (§XX.7.3) | **Yes, with a per-head split rule**, defaulting to *"the establishment in force on the last day of the period owns the return"* | The simple rule mis-states one month's contribution split at each transfer, which some auditors will challenge. True pro-rata is more accurate and materially more work in both the payroll engine and the filing layer. Making the rule per head **and** per tenant contains the cost |
| **D-XX-04** | **May a full and final be paid before clearance completes?** (§XX.8.5) | **Yes, under a distinct permission held by few, with a mandatory reason printed on the statement and reported monthly** | Indian practice frequently requires settlement inside a statutory window that clearance cannot always meet. An easily-reached override becomes the norm and the gate stops meaning anything — hence the separate permission and the monthly report |
| **D-XX-05** | **Were any NDA rows ever created through the product, and is any current compliance score based on a real signature?** (§XX.1.1; `H-…` PO-01) | **Treat every NDA component of every existing compliance score as unearned until the R-XX-172 report is produced per tenant.** Re-collect NDAs on SmartPRS 3 rather than migrating unverified rows | The score is presented to banks and in RBI-facing audits. Migrating unverified rows carries a false compliance claim into the new platform; re-collecting is operationally heavy — every agent must re-sign — but it is the only position that survives an audit question |
| **D-XX-06** | **Are existing token-based offer acceptances and NDA signatures legally relied upon, and must they be re-papered?** (§XX.17.1; `H-…` PO-02) | **Re-paper anything relied upon.** Migrate the rest as `evidence_grade = legacy_insufficient`, visibly marked | The evidential gaps are cumulative: no document shown, no identity proof, a mutable document, no audit entry, no expiry, no single use. If any acceptance has been used in a dispute or a bank submission this is a live commercial risk, not a design defect. Re-papering costs goodwill with employees who have already signed |
| **D-XX-07** | **What is the rating scale, and what must it drive?** (`H-…` PO-07) | A **1–5 scale with named anchors, per tenant**, driving increment eligibility and band (§XX.13.4–5), promotion eligibility, confirmation and incentive gates | There is no scale in code today — `rating` is unbounded `numeric` — and *"nothing downstream consumes `final_rating`"*. Wiring appraisal to money makes the appraisal process consequential, which is correct and also raises the governance bar: calibration, moderation and an appeal route become necessary rather than optional |
| **D-XX-08** | **Is a compensation revision applied automatically on its effective date?** | **Yes** (R-XX-102). Retain a per-tenant "hold for explicit release" flag for customers who want a manual gate | SmartPRS 2 is deliberately manual (BR-EMP-049), and some finance teams rely on that gate. Automatic application removes a whole class of "the increment was approved in April and applied in August" disputes; the hold flag preserves the manual posture for those who want it, without a code branch |
| **D-XX-09** | **What is the default probation duration, and does it differ by employment stage?** | Ship **no platform default**; require each tenant to publish a probation policy at go-live | SmartPRS 2 defaults to 6 months in code. Shipping a default means some tenant inherits a duration nobody chose, which then appears in confirmation letters. Requiring a decision costs one configuration step at onboarding |
| **D-XX-10** | **Which day basis counts notice period — calendar, working or payable days?** | **Calendar days by default**, per-policy overridable | Calendar days match most Indian appointment letters and are what employees expect. Working days are more generous to the employee and materially change the shortfall recovery. The choice must be visible on the F&F statement either way, because it is the single most disputed number in a separation |
| **D-XX-11** | **What retention period applies to rejected candidates' data?** | Recommend **6 months after the terminal decision**, per-tenant configurable, with the R-XX-176 volumes shown before the choice is fixed | Longer retention improves the talent pool's value and is the reason recruiters want it. It is also the product's most exposed PII class, retained indefinitely today with no consent expiry and no purge. The number should be chosen with the volume visible, not in the abstract |
| **D-XX-12** | **Post-exit retention: how long is an ex-employee's record kept, and in what form?** | Statutory registers, payroll records, the settlement statement and letters: **statutory retention**. Contact and identity data: the shortest defensible period, then anonymise. Biometric templates: **purge on exit** | `data_retention_months` (84) exists in `defaults()` and is *"absent from `save()`'s validation array"*, so it *"can never be changed"* (NEW-CFG-102) — meaning no customer has ever set a retention period. Anonymising early breaks the ex-employee access audit and some statutory lookups; retaining everything is the current, indefensible, position |
| **D-XX-13** | **Does the conversion block when the statutory identity (PAN/UAN/bank) is absent, or does it warn?** | **Warn at conversion, block at first payroll** — the employment is created, the compliance file opens with the items outstanding, and spine S11's preview gate blocks the run | Blocking at conversion stops a business hiring someone who has genuinely not yet received a PAN. Warning only, with no downstream gate, reproduces the current state where *"a newly hired agent starts with an empty compliance file"*. The two-stage position is the only one that is both operationally realistic and defensible |
| **D-XX-14** | **Does an exit reason's semantics affect gratuity, notice and rehire automatically?** | **Yes for notice and rehire** (declared on the reason's semantics). **No for gratuity** — that is a rule-pack question, `<from rule pack>`, never a tenant switch | Letting a tenant configure gratuity eligibility by exit reason invites an unlawful configuration. Notice and rehire are genuinely employer policy. The split must be explicit or customers will ask for the wrong one |
| **D-XX-15** | **Who is the named compliance owner for the exit-related content of the India rule pack** — gratuity, bonus, statutory notice minima, final-settlement timing? | Required before any F&F ships. This is spine D-03 applied to this part | Without a named owner the `<from rule pack>` placeholders in §XX.8.4 have nobody to fill them, and R-XX-121's statutory-delta preview has no baseline to compare against |

---

## XX.21 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XX-001 – R-XX-037 | Recruitment and the ATS: pipelines, stages, transition matrix, requisitions, candidate entity, sourcing, résumés, interviews, scorecards, checklists, BGV, drives, campaigns (§XX.2) | 37 |
| R-XX-038 – R-XX-054 | Candidate → employee conversion: the derived map, nothing dropped, collection points, identity by id, the transaction, off-roll (§XX.3) | 17 |
| R-XX-055 – R-XX-082 | Offer, NDA and letter engine: document types, templates, the sealed artefact, what the signer sees, identity and signature, token security, delivery and archival (§XX.4) | 28 |
| R-XX-083 – R-XX-097 | Probation and confirmation (§XX.5) | 15 |
| R-XX-098 – R-XX-110 | Increment, promotion and the declarative retro effect (§XX.6) | 13 |
| R-XX-111 – R-XX-123 | Transfer, including the statutory consequences of a state change (§XX.7) | 13 |
| R-XX-124 – R-XX-144 | Resignation, exit, notice, clearance, full and final, documents and retention (§XX.8) | 21 |
| R-XX-145 – R-XX-158 | Employee Self-Service, and the five structural mechanisms (§XX.9) | 14 |
| R-XX-159 – R-XX-166 | Manager Self-Service (§XX.10) | 8 |
| R-XX-167 – R-XX-170 | API, events and explainability (§XX.14–XX.16) | 4 |
| R-XX-171 – R-XX-176 | Migration reports required before cutover (§XX.17.2) | 6 |
| R-XX-177 | Test coverage (§XX.19) | 1 |
| **Total** | | **177** |

**Tables:** 46 specified or extended across 31 blocks (§XX.11) — 21 configuration artefacts
and their version children, 17 operational aggregates, 8 append-only evidence, event and log
tables.
**Product Owner decisions:** 15 (§XX.20).
**Section 127 configuration tests:** 34, all zero-code (§XX.12).
**Regression scenarios:** 50 named, 9 of them P0 (§XX.19).

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Pipeline stages, transitions, interview modes, recommendations, requisition priorities, rejection reasons, document types, letter types, placeholder keys, probation durations, reminder offsets, reminder audiences, increment bases, arrears divisors and proration bases, notice days and divisors, clearance items, exit reasons, retention periods and every threshold are configuration or SPX. **No stage, status, document-type, component or role name may appear as a literal in code.** §XX.12 lists 34 customer requirements, all zero-code | `T-XX-08`: static analysis over `Domain\Recruitment`, `Domain\Documents`, `Domain\Lifecycle`; publish-time symbol-table checks on every SPX artefact |
| **2 — no schema change at runtime** | All 46 tables are migration-owned. There is no successor to `RecruitmentController::ensure()/addCols()`, `MasterController::ensureCols()`, `ApprovalService::ensureAuditCols()`, `LetterController::sendAcceptLink()`'s `ALTER`, or — most importantly — `OfferAcceptController::ensureCols()` and `NdaSignController::ensureCols()`, which run DDL **on every unauthenticated public page hit**. Candidate and employee extensibility is `entity_field_values`, never a column | `T-XX-09`: CI grep for DDL outside migrations; `migrate:fresh` parity with production structure |
| **3 — nothing overwritten** | Pipelines, evaluation forms, document types, templates, probation policies, retro policies, notice policies and checklist templates are versioned artefacts. Stage events, signature events, token access logs and probation events are **append-only**. Scorecards, revisions and sealed documents are **superseded, never updated**. Every assignment change is a dated row, so historical payroll and reporting retain the old assignment | `AsAtService` answers "what was this employee's branch / manager / grade / CTC / stage on date D?" in one call; `T-XX-19`, `T-XX-40`, `T-XX-50` |
| **4 — every number explains itself** | Scorecard aggregates, probation dates, revision amounts, retro effects, notice shortfalls, recoveries and every F&F line carry a trace naming inputs, formula version, rule version, statutory pack version, condition matched and arithmetic performed (§XX.16). The **conversion report** applies the same discipline to a business operation rather than a number (R-XX-044) | R-XX-170: a trace-less result throws; no service return path may omit one |
| **5 — authorisation fails closed** | Every route in §XX.14 has an explicit policy binding. Self-service is structurally bounded by five independent mechanisms (§XX.9.2), not by per-endpoint checks — which is precisely what failed in SmartPRS 2, where the rule was implemented for one module of nine. Listings return only in-scope rows. Public document routes are typed, tenant-scoped, single-use, expiring, revocable, throttled, CSRF-protected and access-logged | `T-XX-01` – `T-XX-07`; route-policy sweep; fault injection must deny; the SEC-01/SEC-02 endpoint shapes are asserted **absent** |
| **6 — tenancy is structural** | `tenant_id NOT NULL` on all 46 tables and in every unique index, applied by the persistence layer. No document, token, template, org unit or company reference is resolved without it — the specific failure in DEF-31, where `to_company_id` moves an employee across tenants | `T-XX-05`, `T-XX-06`: generated cross-tenant isolation tests per aggregate and per endpoint |
| **7 — money is exact** | Offered CTC, revision amounts, retro effects, notice recovery, asset recovery, leave encashment and every F&F line are computed by Part VIII formulas in integer paise with a declared rounding mode and rounding point; `DECIMAL(18,4)` at rest with an explicit currency. The F&F asserts exactly that the sum of lines equals the net, and aborts the run otherwise | `T-XX-35` – `T-XX-38`, `T-XX-42` – `T-XX-45`; no float arithmetic in any domain namespace of this part |
