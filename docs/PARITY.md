# SmartPRS 2 → SmartPRS 3 — feature parity inventory

Measured from `C:\laragon\www\smartprs2` on 2026-09-05 (read-only).

| | SmartPRS 2 | SmartPRS 3 today |
|---|---|---|
| Routes | 408 | ~14 |
| Application modules | 74 | 5 (partial) |
| Controllers | 90 (`AppController.php` = 1.2 MB) | 1 front controller |
| Migrations / tables | 50 migrations, **127 tables** | 169 tables, all in migrations |
| Service classes | 27 | 12 surface classes |

**Coverage: roughly 7%** when this was written. Since then the schema is fully ported and 79 module
screens, 16 reports and 5 approval workflows are live — see the build journal.

> **Correction, 7 Sep.** This document originally said SmartPRS 2 has 91 tables. It has **127**.
> The other 39 are created by `Schema::create(...)` inside controllers at request time, so no
> reading of the migrations can find them. See `SCHEMA-CORRECTIONS.md` §6.

---

## 1. Built in SmartPRS 3 (with corrections applied)

| SmartPRS 2 module | SmartPRS 3 | Correction made in the rebuild |
|---|---|---|
| `employees` | Employments list | — (thin: see gap list below) |
| `attendance`, `attendance-corrections` | Attendance + regularisation | Night shift groups by shift-start date, not punch date. Regularisation ADDS a punch, never edits the device's. |
| `leaves` | Leave request/approve/ledger | Balance computed at the date asked about. Accrual posted per month, idempotent. Debit only on approval. |
| `salary-runs`, `payroll`, `payslip` | Register + payslip | Month close with control total, fingerprint, reproducibility check. Corrections never overwrite. Arrears carried. |
| `bank-pack` | Bank advice | Refuses rather than dropping an employment with no bank details. Reconciles to the run, not to itself. |
| `approvals` (partial) | Maker–checker on close | Closer cannot approve. Bank file refuses an unapproved run. |
| `ess` (partial) | Employee view | Payslip read from the closed run, never recomputed. |
| `statutory-config` (machinery only) | Statutory engine | Signed packs, flag-driven wage base. **Content blocked on a compliance owner.** |
| `activity_log` | Audit trail | Hash-chained, tamper-evident. |

## 1a. Ported on 2026-09-09 (dedicated screens, not generic module CRUD)

| SmartPRS 2 | SmartPRS 3 | Correction |
|---|---|---|
| `attendance-bulk` | `?r=attendance-bulk` | No name-matching when two employments share a name; declared date formats; upload writes claims, approval writes punches; rows withdrawn, never deleted |
| `attendance-report` | `?r=attendance-report` (grid + daily rows, filters, configurable columns) | One settlement (the register's); empty days classified by the rules or ON_LEAVE; **no demo punches** |
| `today` | `?r=today` | Own employment only, from Scope; no name fallback |
| `absence` | `?r=absence` | Absent is the rules' verdict, not a fourth copy; ships OFF; dedupe per employment-day |
| sidebar | 16 PRS2 sections | every screen and module placed |
| `recruitment` | `?r=recruitment` | Stages move one step; hired is never a drag destination; reject needs a reason; hire creates the employment and closes the requisition |
| `probation` | `?r=probation` | Confirmation is a timeline event, not a column flip |
| `employee-import` | `?r=employee-import` | Preview before commit; rows by field code; error file kept |
| `idcard` / `emp-photo` | `?r=id-cards`, `?r=configure-id-card` | PDF built without a library; format is versioned customisation |
| `transfers` | `?r=transfers` | Maker ≠ checker; applied on the effective date; TRANSFERRED written to the employment timeline with from/to |
| regularisation queue | `?r=attendance-corrections` | one queue, scoped |

## 2. Not built — 65 modules

**Payroll & compensation**
`salary-lines` · `salary-simulate` · `live-salary` · `increments` · `incentive` · `points` · `schemes` · `loans` · `fin-year` · `computed` · `calc-guide`

**Attendance & devices**
`pending-punches` · `biometric-config` (eTimeOffice, 33 KB service) · `mobile-devices` · `device` · `iclock`

**Employee lifecycle**
`self-onboarding` (15 routes, 58 KB controller) · `onboarding` · `performance` · `documents-mgr` · `my-photo` · `code-of-conduct` · `nda` · `offer` (ported 2026-09-09: `employee-import`, `probation`, `transfers`, `idcard`, `emp-photo`, `letters` had been)

**Recruitment** — core pipeline ported 2026-09-09 (`recruitment`); still open:
`offroll-agent` (10 routes, 27 KB) · hiring drives · `lead` · recruit messaging

**Requests & workflow**
`requests` (16 routes, **108 KB controller**) · `approvals` · `transfers`

**Statutory & compliance**
`statutory` · `statutory-report` · `compliance` · `compliance-alerts`

**Communications**
`wa-templates` · `send-message` · `notifications` · `mail-settings` · `mail-log` · `greetings` · push

**Commercial / SaaS**
`saas` (12) · `billing` (12) · `my-subscription` · coupons · licensing · editions · `signup` · `quote` · `demo` / `teamdemo` · `activate` · tenant billing

**Platform & admin**
`master` (72 KB controller) · `users` · `settings` · `branding` · `reports` · `dashboard` · `search` · `kb` · `help` · `api-keys` · `updates` (release server + client updater) · `install` · `state` · `data` · `tests` · `super`

---

## 3. What this means

The vertical slice that exists is deep and correct — the seven laws hold through it,
and each screen has been probed against deliberately broken inputs. But depth on one
path is not the product. The next phase is breadth: the 69 modules above, sequenced.

## 4. Open question — sequencing

Three ways to proceed:

- **A. Breadth first.** Every module gets a working screen against the real schema,
  shallow but present, then depth per module. You see the whole system early.
- **B. By business priority.** Recruitment → requests/workflow → employee lifecycle
  → reports/dashboard, each finished before the next.
- **C. Migrate the schema first.** Port all 50 tables and the real data model,
  then rebuild screens on top. Slowest to a demo, strongest foundation.

## 9 Sep 2026 — sidebar parity audit

Every one of SmartPRS 2's 120 screens (the ids in `ScreenHelpController.php`) now has a SmartPRS 3
route or module screen behind a sidebar item, with two deliberate exceptions:

| PRS2 screen | Decision |
|---|---|
| `admin-landing` (Landing Page CMS) | Not ported — SmartPRS 3 has no public marketing site to publish to. |
| `greetings-log` | The log is the lower half of the Greetings screen; the sidebar item opens it there. |

Per-section notes (what PRS2 had, what was ported, what was deliberately not and why) live in
`docs/sections/*.md`: time, leave-comp, statutory, perf-learn, letters-comm, field-reports, admin,
extras (Roster, Assets, Salary Calculation Guide, ZKTeco Devices), platform (SaaS Platform).
