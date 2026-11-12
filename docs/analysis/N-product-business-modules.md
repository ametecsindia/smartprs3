# AS-IS ANALYSIS — PRODUCT, BUSINESS LAYER AND MODULE INVENTORY

Area: product overview · the business problem · intended users and customers · the
operational process automated · business actors and roles · user journeys · the
commercial model · the module inventory · the collection/recovery vertical · the
glossary · the repository and technology baseline.

---

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Lines read | What was taken from it |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 1–434 (Part I — Orientation) | Product overview, business problem, technology stack, repository structure, the claimed 24-module inventory |
| same | 435–2246 (Part II — Business model, actors, journeys) | Business model, operational process, business actors, role reality check, user journeys, operational workflow, commercial model, BR-* rules, glossary, registers |
| same | 57430–57478 (§35 Recommendations) | Prior remediation roadmap R1–R23 |
| same | 57478–57525 (§36 Final completeness verification) | Coverage counts, unresolved items U1–U6 |
| same | 57525–57585 (§37 Glossary) | Terminology |
| `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 41–350 (system context, component architecture) | Actor/integration map, architectural style characterisation |
| same | 992–1949 (end-to-end workflows) | 14 workflows in 16-step form |
| same | 1949–2401 (state machines) | Consolidated status register, ~45 status columns |
| same | 3312–3693 (ADR) | ADR-01…ADR-09 |
| `DOCUMENTATION_AUDIT_CHECKLIST.md` | 1–194 (full) | Declared coverage and declared gaps |

### 0.2 Source files read (under `/home/claude/prs2/`)

| File | Lines | Read |
|---|---:|---|
| `README.md` | 87 | full |
| `composer.json` | 77 | full |
| `version.json` | 5 | full |
| `config/smartprs.php` | 132 | full |
| `config/app.php` | 126 | full |
| `routes/web.php` | 696 | **full — both halves** |
| `routes/api.php` | 43 | full |
| `routes/console.php` | 265 | schedule block + Artisan closures |
| `app/Http/Controllers/OffrollAgentController.php` | 557 | **full** |
| `app/Http/Controllers/CodeOfConductController.php` | 183 | **full** |
| `app/Http/Controllers/ComplianceController.php` | 661 | `scan()` :37–130, `scoreFor()` :285–330, `draValid()` :334–360, `agentAuditData()` :541–660 |
| `app/Http/Controllers/MasterController.php` | 1224 | `def()` registry :30–480, `ensureCols()` :496–520, save hooks :740–760 |
| `app/Http/Controllers/AppController.php` | 12533 | `ROLE_MAP` :15–21, `PLAN_GROUP_KEYS`/`resolvePlanModules()` :25–75, `PERM_NAV` :655–676, `currentPerms/navModule` :677–700, `applyPlanGate()` :707–722, `applyPerms()` :764–789, nav-injection sites :960–1070, 1488–1530 |
| `app/Http/Controllers/BillingController.php` | 1031 | `GST`/`COMPANY_FEE`/`cycleMonths`/`cycleDiscount`/`finYear`/`priceFor` :30–140 |
| `app/Http/Controllers/BankPackController.php` | 374 | docblock + `ensureInvoices()` :1–60 |
| `app/Http/Controllers/UserController.php` | 355 | `ASSIGNABLE` :25–36, `canManage()` :37–40 |
| `app/Http/Controllers/AppDataController.php` | 3090 | seat-check call sites :815, :3042; archive/backup :2495–2665; directory filter :259; archive listing :508–560 |
| `app/Services/SubscriptionService.php` | 272 | docblock, `GRACE_DAYS`, `SEAT_FREE_STATUSES`, `seatUsage()` :81–120, `canAddEmployees()` :127–150 |
| `app/Services/Edition.php` | 181 | `isOnPrem()`, `level()` :93–97, `SAAS_ONLY`/`DNA`/`L2_MODULES` :101–118, `hiddenNavIds()` :119–133 |
| `app/Services/StatutoryConfig.php`, `SettingsController.php` | 589 / 205 | `dra_gate` default/validation :57, :154, :170 |
| `database/seeders/*.php` (all 7) | 748 | **full** |
| `database/migrations/` | 49 files | full name listing; `add_company_id_to_users_table` and `ensure_runtime_feature_tables` read in full |
| `resources/prototype/app.html` | 986 lines / 234 KB | **the SPA shell — present in this tree.** `SCREENS`, `ROLES`, `PLATFORM`, `roleAllows()`, `applyRole()`, and the complete 107-id nav extracted |
| Directory surveys | — | 84 controllers + 2 API controllers (line counts), 32 services (line counts), 18 commands, 2 jobs, 6 middleware, 1 provider, 1 support class, 12 models, 59 blade views, 17 test files, 59 distinct runtime-created table names |

### 0.3 The single most important discovery of this pass

**`resources/prototype/app.html` EXISTS in this tree** (234 KB, 986 lines, `ls resources/prototype/`). The prior analysis's largest declared limitation — recorded as U4, `D-BIZ-08`, `Q-BIZ-01`, `FINDING-DEV-01` and repeated in four documents as *"the application cannot boot from this source tree"* — **is not true of the tree we are analysing.** The navigation layer, the screen registry and the prototype's own role filter are all now directly verifiable, and are analysed in §1.9 and §6. Every claim the prior dossier marked `INFERRED` for navigation can be upgraded.

---

## 1. Business capability inventory

### 1.1 What SmartPRS 2 is, in one paragraph

SmartPRS is a vertical HR / payroll / attendance / workforce-compliance platform built by
Ametecs India Private Limited (Hyderabad) and sold to **Indian debt-collection and recovery
agencies**. Its product thesis is that a recovery agency carries two compliance burdens on
the same person record — the ordinary Indian labour/tax burden (PF, ESI, PT, TDS, gratuity,
bonus) and the RBI recovery-conduct burden (DRA certification, police verification, background
verification, code-of-conduct undertaking, bank authorisation, borrower complaints) — and that
one system should hold both, so the biometric punch that decides an agent's Loss of Pay and
the DRA certificate that decides whether that agent may work at all live in the same database
and can be evidenced from the same audit report. It ships from one codebase in two commercial
shapes: hosted multi-tenant SaaS billed per employee seat, and an on-premise perpetual licence
in three nominal editions (L1/L2/L3) with an AMC.

**Verified** against: `DemoCompanySeeder.php:39-53` (`companies.type = 'collections'`,
"Demo Collections Group" / "Apex Collections Pvt. Ltd."); `config/smartprs.php:12,22`
(the two switches); `Edition.php:101-118` (the DNA nav set is the collections feature list);
`MasterController.php:301-320` (escalations / agent-auth / dra-certs registries);
`ComplianceController.php:541-660` (the RBI audit report).

### 1.2 The business problem, stated from the code

The code demonstrably attacks five problems. Each is stated here only where a code artefact
proves the intent.

1. **Attendance truth for people who are not at a desk.** Seven ingest paths converge on
   `attendance_logs`: ZKTeco/eSSL ADMS push (`routes/web.php:92-105`), the authenticated SBB
   batch API (`routes/api.php:35`), the SmartEPT HMAC webhook (`routes/api.php:38-41`),
   eTimeOffice cloud pull (`config/smartprs.php:114-122`), eTimeTrackLite SOAP,
   the generic API connector, and in-app/mobile self-punch (`routes/web.php:360-361`).
   Plus bulk CSV upload with an approval gate (`routes/web.php:352-356`) and
   correction/regularisation (`routes/web.php:586-590`).
2. **Payroll correctness under Indian statute.** PF/ESI/PT/TDS/LWF are computed in code, with
   a tenant-editable rate surface (`routes/web.php:349-350, 580-584`;
   `app/Services/StatutoryConfig.php`).
3. **Certification expiry.** `compliance:scan` runs daily at 07:30
   (`routes/console.php:143-146`) over `employees.dra_expiry` / `pcc_expiry` and the structured
   `dra_certs` register (`ComplianceController.php:37-130`, `:106-120`).
4. **Churn.** Requisition → sourcing → hiring drive → interview → offer (token page,
   `routes/web.php:58-59`) → NDA e-sign (`:62-64`) → self-onboarding portal (`:67-74`) →
   HR verification console (`:673-687`) → employee row.
5. **Multi-entity operation.** Tenant → many companies; the oldest is auto-flagged
   `is_master`, transfers move people between them under approval and are applied on the
   effective date by `transfers:apply` daily at 06:30 (`routes/console.php:190-194`).

**What the product deliberately does not do** — and this is a scoping fact SmartPRS 3 must
inherit or consciously reverse:

- **No collections.** There is no borrower, no account, no allocation, no dialler, no
  disposition. The collected amount enters only as free text on a commission claim, and the
  bank/portfolio is the free-text `portfolio` field (`BankPackController.php:24-27`, which
  states this explicitly). Ametecs sells a separate product, SmartDCM, for collections.
- **No accounting.** No general ledger. `commission_payments` and `bank_invoices` are
  single-purpose disbursement/billing registers.
- **No statutory filing.** Registers and challan-ready PDFs are produced; the filing is manual.
  **Confirmed at source:** the complete `Schedule::` list in `routes/console.php` is 12 tasks —
  `compliance:scan`, `backup:clean`, `backup:run`, `errors:digest`, `billing:alerts`,
  `transfers:apply`, `demo:reset` ×2, `attendance:sync-etimeoffice`, `greetings:send`,
  `probation:notify`, `absence:notify` — and **not one concerns a statutory filing due date.**

### 1.3 Intended users and business customers

| Party | What they are | Where they live in code |
|---|---|---|
| **Ametecs platform staff** | The vendor. Sell, provision, license, support, publish releases. | `users.tenant_id IS NULL`; `/admin/staff` (`routes/web.php:290-293`) |
| **The subscribing agency (tenant)** | The commercial customer. Owns the workspace. Data Fiduciary under DPDP. | `tenants` row; `users.tenant_id` |
| **A company inside the tenant** | A registered legal entity in the promoter's group. Payroll, GSTIN, branding and payslips are per company. | `companies` row; `DemoCompanySeeder.php:49-53` |
| **Agency HR / admin staff** | Day-to-day operators. | roles `admin`, `hr_manager` |
| **On-roll field force** | Recovery agents with logins. | role `field_agent`; `employees` |
| **Off-roll field force** | Vendor/commission agents, **not on payroll**, no login, token link only. | `offroll_agents` (runtime-created, `OffrollAgentController.php:34-64`) |
| **Back-office employees** | Telecallers, ops, support. | role `employee` |
| **Accounts / finance** | Confirms money received before commission approval. | role `accountant` — **assignable, never created** (§2.4) |
| **Client banks / auditors** | **Not users.** They are the audience for a PDF. | `ComplianceController::agentAuditPdf()`; `BankPackController` |
| **Candidates / new joiners** | **Not users.** Token links only. | `routes/web.php:58-74, 120` |
| **Prospects / demo visitors** | Pre-purchase. | `routes/web.php:126-159` |

Buyer profile from the lead form: company, designation, city, mobile, official email,
headcount band (1–25 / 26–75 / 76–150 / >150), and "what challenges are you facing".
The `leads` register is Super-Admin-only. Currency INR, `money_scale` 2, `Asia/Kolkata`
(`config/smartprs.php:130-131`).

### 1.4 The end-to-end operational process (answer to question 3)

Stated in business language, with the enforcement status of every joint.

| # | Stage | What the business does | What the software enforces | Evidence |
|---:|---|---|---|---|
| 1 | **Requisition** | HR raises a job opening; Admin approves it | `job_openings.approval_status`; **admin only** to approve. Anything unrecognised, including NULL, reads as `approved` | `routes/web.php:632-635` |
| 2 | **Sourcing** | Talent pool, portal import, hiring drive, bulk WhatsApp campaign | Candidate rows, drives with panel/venue/funnel, Interakt campaigns + delivery webhook | `routes/web.php:607-655`, `:201` |
| 3 | **Interview** | Schedule, feedback, scorecards, outcome | `interviews`, `hiring_drive_candidates.outcome` | `routes/web.php:638-655` |
| 4 | **Offer** | Offer letter emailed as a token link; candidate accepts on a public page | `OfferAcceptController`; no login | `routes/web.php:58-59` |
| 5 | **NDA** | Candidate e-signs a confidentiality undertaking | `NdaSignController`; CSRF-exempt on sign | `routes/web.php:62-64` |
| 6 | **Self-onboarding** | New joiner fills their own record from a phone: OTP, selfie, documents, progressive save | 8 public token routes; HR verification console with correction/verify/approve | `routes/web.php:67-74, 673-687` |
| 7 | **Verification** | DRA certificate, PCC, BGV, ≥2 references, code-of-conduct acknowledgement, bank/portfolio authorisation | Six **flat registry tables**, all CRUD-only (§1.8) | `MasterController.php:107-141, 193-203, 288-320` |
| 8 | **Employee record** | The person becomes an `employees` row | Seat check — **at 3 of 5 doors only** (§6 D-02) | `AppDataController.php:815, 3042`; `RecruitmentController.php:602` |
| 9 | **Login invite** | One user per person, one role each; 48-hour set-password link | `UserController::invite`; `syncRoles([$role])` — **exactly one role** | `routes/web.php:373` |
| 10 | **Biometric enrolment** | Device PIN mapped to `emp_code` / `biometric_id` | Unmapped codes quarantine in `attendance_pending` and replay backdated on mapping | `routes/web.php:526-528, 537-541` |
| 11 | **Probation clock** | 30/15/7-day confirmation reminders | `probation:notify` daily 07:15 | `routes/console.php:253-256` |
| 12 | **Daily attendance** | Punches arrive; late/absence notices go out | `absence:notify` hourly, self-gated per tenant | `routes/console.php:262-266` |
| 13 | **Requests** | Leave, expense, advance, loan, commission, clawback, increment, transfer, bonus, exit | One generic engine: `/app/requests/{module}` | `routes/web.php:424-448` |
| 14 | **Commission money chain** | Agent claims with evidence → **Accounts confirms money arrived** → manager approves | The gate exists; the `accountant` role **cannot be created** (§2.4) | `routes/web.php:432`; `RolePermissionSeeder.php:41-58` |
| 15 | **Cut-off** | Attendance for the period is complete | ✘ **No freeze exists.** Nothing stops a punch or leave approval landing after a draft run | `routes/web.php` has no freeze endpoint; §6 D-01 |
| 16 | **Payroll preview** | HR previews every employee's numbers; nothing written | ✔ | `routes/web.php:474` |
| 17 | **Payroll generate** | Draft run + payslips written atomically; commissions locked; overtime marked paid; loan/clawback ledger rows written | ✔ | `routes/web.php:480` |
| 18 | **HR approval** | draft → hr_approved | ✔ | `routes/web.php:461` |
| 19 | **Final approval** | hr_approved → approved + lock. **Admin only** | ✔ | `routes/web.php:461`; `SalaryApprovalController.php:67` |
| 20 | **Bank pack** | NEFT CSV in generic / ICICI / HDFC / SBI layout; rows with no account/IFSC excluded and listed. **Downloading the file IS the disbursement event** | ✔ but bypasses `applyLineAction()` | `routes/web.php:466` |
| 21 | **Payslips** | PDFs published; employee acknowledges their line with a typed e-signature | ✔ | `routes/web.php:307, 470` |
| 22 | **Statutory outputs** | PF ECR, ESIC challan, 24Q, 26Q (§194H), PT state-wise, gratuity, bonus | ✔ generation | `routes/web.php:308, 485` |
| 23 | **Statutory filing** | Deposit and file with the authority | ✘ **NOT IMPLEMENTED.** No due-date field, no job, no alert anywhere | `routes/console.php` complete schedule |
| 24 | **Bank/NBFC billing** | Payout register + 194H annexure + GST service invoice per bank per month | ✔ (collections-specific) | `routes/web.php:334-335`; `BankPackController.php:10-30` |
| 25 | **Compliance audit** | Per-agent RBI audit PDF; bulk variant for the whole population | ✔ for on-roll; **off-roll agents entirely excluded** | `routes/web.php:487-491`; §1.8 |
| 26 | **Exit** | Exit request → approval → clearance checklist → FnF → status change frees a seat | ✔ — but **archive does not free a seat** despite the product saying it does (§6 D-02) | `SubscriptionService.php:35`; `AppDataController.php:2651` |

**Where the chain breaks today** — three joints the business assumes and the code does not
enforce, plus one the code enforces the opposite way:

| The business assumption | What the code does | Evidence |
|---|---|---|
| "Attendance is frozen before payroll runs" | **No freeze exists.** Only a run past `draft` cannot be regenerated | no freeze route in `routes/web.php`; `payroll_runs.status` machine |
| "No punches = absent" | **Zero punches pays 100%**, deliberately, so a dead device never zeroes a salary. A genuine absconder is paid in full | `PayrollGenController.php:26-29, 1237` (per prior dossier; behaviour consistent with `absence:notify` existing as a *notice* only) |
| "A non-compliant agent cannot be paid" | Payroll does **not** check DRA/PCC at all. A configurable DRA gate **does** exist — but only at three *commission* money points (§1.8.3) | `RequestController.php:1373`; `IncentiveController.php:135`; `OffrollAgentController.php:251` |
| "Filing happens on a calendar" | No due date is stored anywhere in the product | `routes/console.php`; `MasterController.php:175` (`tds-returns` is a manual-status registry) |

### 1.5 The commercial model as implemented (answer to question 5)

#### 1.5.1 The two shapes and two independent switches

| | Hosted SaaS | On-prem perpetual |
|---|---|---|
| `SMARTPRS_EDITION` | `saas` | `l1` \| `l2` \| `l3` |
| `SMARTPRS_DEPLOYMENT` | `saas` | `onprem` |
| Marketing surface at `/` | Landing, signup, quote, demo, policies | **Not registered.** `/` redirects to `/app`; signup/demo/quote/lead/policy/webhook/update-server routes sit inside `if (! $spOnPrem)` blocks |
| Access control | `subscriptions` + `EnsureSubscriptionActive` | `.lic` / License Code + `LicenseGate` |
| Money | Razorpay subscription, quarterly minimum, per-seat + per-company | One-time licence + AMC; Razorpay pay-link, or NEFT/cheque/UPI/cash recorded by the Super Admin |
| Updates | Central | Client pulls a *granted* package after a checksum check, with backup and rollback |

Evidence: `config/smartprs.php:12, 22`; `routes/web.php:22, 27-37, 45-55, 125-167, 172-196, 200-204`.

**These two switches are independent, and the combination `EDITION=l2, DEPLOYMENT=saas`
produces an unbillable, unlockable tenant** (`EditionGuard` 404s the billing screens,
`EnsureSubscriptionActive` does not gate it, `LicenseGate` requires `deployment === 'onprem'`).

#### 1.5.2 Editions — priced in three tiers, delivered as one product

`Edition.php:93-97`:
```php
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int
{
    return 3;
}
```
`Edition.php:119-133` — `hiddenNavIds()` returns `SAAS_ONLY` for **every** on-prem edition;
`DNA` and `L2_MODULES` are retained *"for reference / easy revert"* and hide nothing.

**CONFIRMED AT SOURCE.** The three price points ship a byte-identical product. The only thing
an L1 customer cannot reach is `/admin`, `/super`, `/signup` and `/app/billing` — which no
customer wants. The edition *lock on the licence* is real (activation compares the edition
string exactly), so the vendor can tell which edition a key belongs to while the running
software cannot.

The constants preserve the intended tiering, and they are the definitive statement of what
Ametecs considers the collections vertical:
- `SAAS_ONLY` (8 ids) — platform-dashboard, tenants, plans, subscriptions, invoices, payments, gateways, my-subscription
- `DNA` (12 ids) — **live-salary, pay-ledger, commissions, commission-calc, incentive-schemes, clawbacks, escalations, offroll-agents, agent-auth, compliance-alerts, roster, complaints**
- `L2_MODULES` (30 ids) — recruitment, the five letter types + templates, expenses/advance/loans/bonus-enc/increments, performance/points/awards/tests, training/KB/FAQs/code-of-conduct, WhatsApp settings + templates, attrition + activity-logs, messaging + SMS

#### 1.5.3 SaaS pricing — the exact formula

`BillingController::priceFor()` :117–133, transcribed:
```php
$included   = (int) ($plan->seat_max ?? 0);
$extra      = $included > 0 ? max(0, $seats - $included) : $seats;
$months     = cycleMonths($cycle);      // monthly 1 | quarterly 3 | halfyear 6 | annual 12; unknown → 3
$discount   = cycleDiscount($cycle);    // halfyear 0.10 | annual 0.25 | else 0.0
$companies  = max(1, $companies);
$companyFee = 1000.0 * ($companies - 1);            // COMPANY_FEE :56
$perMonth   = $plan->base_price + $plan->per_user_price * $extra + $companyFee;
$amount     = round($perMonth * $months * (1 - $discount), 2);
$tax        = round($amount * 18.0 / 100, 2);       // GST :35
$total      = round($amount + $tax, 2);
```

Seeded plans (`StandardPlanSeeder.php:33-37`):

| Plan | base/mo | per extra user/mo | seat_max | features stored |
|---|---:|---:|---:|---|
| Starter | ₹1,000 | ₹60 | 25 | 14 module keys |
| Growth | ₹2,500 | ₹50 | 75 | 14 module keys |
| Professional | ₹5,000 | ₹40 | 150 | 14 module keys |

Worked example — Growth, 100 seats, 2 companies, annual:
`extra = 100-75 = 25`; `companyFee = 1000`; `perMonth = 2500 + 50×25 + 1000 = 4750`;
`amount = round(4750 × 12 × 0.75, 2) = 42,750.00`; `tax = 7,695.00`; **total ₹50,445.00**.

#### 1.5.4 Plan-based module gating is switched off twice over

- **Client side:** `applyPlanGate()` (`AppController.php:709-712`) begins
  `// TEMPORARILY DISABLED (rev 34e)` followed by an unconditional `return;`. Everything after
  it is marked `// eslint-disable-line no-unreachable`.
- **Server side:** `resolvePlanModules()` (`AppController.php:40-73`) returns `null`
  (= unrestricted) if **any** feature key is not in `PLAN_GROUP_KEYS`. `PLAN_GROUP_KEYS` is
  10 keys: `employees, attendance, leave, payroll, statutory, requests, field, performance,
  kb, reports`. `StandardPlanSeeder` stores 14: `hiring, people, attendance, leave, payroll,
  compensation, statutory, performance, learning, letters, field, communication, reports,
  administration`. **Seven of the fourteen are unrecognised** (`hiring, people, compensation,
  learning, letters, communication, administration`), so **every seeded plan resolves to
  unrestricted.** The seeder's own docblock calls the mechanism "parked".

> **Doc-vs-source:** the prior dossier says *six* of the fourteen keys are unrecognised
> (`D-BIZ-03`). The actual count is **seven**. Recorded as `DISC-05`.

#### 1.5.5 Seats — what a customer actually buys

| Rule | Behaviour | Evidence |
|---|---|---|
| A seat is | **an active on-roll employee across the whole workspace**, all companies pooled | `SubscriptionService.php:16-21, 87-97` |
| What frees a seat | `status` NULL or not in `exited, inactive, resigned, terminated, left, absconded`; plus soft-delete | `SEAT_FREE_STATUSES` :35; `seatUsage()` :90-96 |
| What does **not** free a seat | **`archived_at` ("Old data") is not filtered** — an archived employee still bills | `seatUsage()` :87-97 (no `archived_at` clause) vs `AppDataController.php:2651-2655` (archive writes only `archived_at`) |
| Licensed ceiling | `subscriptions.seats` → `tenants.seats_licensed` → on an enforced on-prem install, the `.lic` `device_limit` **wins** | `seatUsage()` :99-114 |
| Enforced at | add-employee form, one other `AppDataController` door, recruitment hire — **three call sites total** | `grep canAddEmployees` → `AppDataController.php:815, 3042`; `RecruitmentController.php:602` |
| **Not** enforced at | **the employee import wizard and self-onboarding injection** | no `canAddEmployees` call in `EmployeeImportController`, `EmployeeImportService` or `SelfOnboardingController` |
| Failure mode | **fails open** — no tenant, `licensed <= 0`, or any exception → allow | `canAddEmployees()` :130-133; `seatUsage()` :115-117 |
| Off-roll agents | **never counted.** An agency can run 500 off-roll agents on a ₹1,000/month 25-seat Starter plan | `offroll_agents` is not in `seatUsage()` |
| Company ceiling | **strict from day one**, ₹1,000/company/month | `canAddCompany()` :180-199 |

#### 1.5.6 Lifecycle, coupons, quotations, on-prem licence

- `active` → `grace` (7 days, full access, banner) → `locked` (only a user holding the `admin`
  role can sign in). Any exception → state `none`, never gated. `GRACE_DAYS = 7`.
- Coupons: `percent` or flat ₹, applied **after** the cycle discount, tax recomputed on the
  discounted amount. **A coupon discounts only the first invoice** — `subscriptions.amount`
  keeps the full rate, with no in-product warning that renewal will jump.
- Quotations: GST quotation PDF + secure pay link, valid 15 days, discount locked in. Invoice
  series `PRS-<FY>-<MM>-<nnnn>` shared by SaaS and on-prem licence invoices; quotations in
  their own `PRS-Q-` series.
- **Credit sales are a first-class flow**: the Super Admin records Paid / Partial / Due with
  amount, mode, UTR/cheque reference and a credit due date, and the workspace is provisioned
  immediately (`routes/web.php:281`).
- On-prem: perpetual licence, one key one server (machine-fingerprint bound), first 12 months
  maintenance included. **AMC is sold at 20%/year in the licence agreement; `licences.amc_percent`
  defaults to 18 and is never read by any calculation** — AMC invoicing is entirely manual.
- **No free trial exists.** The pre-purchase surfaces are a passkey-gated shared demo
  (`/demo`, default 2 hours, clamped 1–72), three edition demos (`/app1|2|3`), a team demo
  (`/teamdemo`), and a 7-day money-back guarantee on a first payment.

### 1.6 Actors and roles (answer to question 2)

#### 1.6.1 Every actor the product serves

Nine role strings are tested somewhere in `app/`. **Five** are declared in
`config/smartprs.php:88-94`, **five** are seeded by `RolePermissionSeeder`, **five** are
assignable inside a tenant (`UserController::ASSIGNABLE`), **two** at platform level
(`StaffController::ROLES`), and **three** vocabularies exist that do not coincide.

| Role | What they actually do | How well the system serves them today |
|---|---|---|
| `super_admin` | The vendor. Sells, provisions, licenses, supports, publishes releases, issues licence keys, records offline payments, manages coupons and the landing CMS. | **Well served.** Exclusive `/super` portal, a `platform-dashboard` landing screen, cross-tenant scope, and a complete SaaS console. **But:** the shell's own nav filter gives Super Admin **only** the 9 PLATFORM screens plus `ai-assistant` — they cannot see a tenant screen in the sidebar (§1.9.3). |
| `admin` | The agency's own top authority: the MD or Operations Head. Owns the workspace, the subscription, the companies, the users, the final payroll approval, the licence code. | **Well served, over-privileged.** Sole holder of: final payroll approval, bulk employee delete, bulk attendance delete, licence-code entry, subscription renewal, sign-in during lock-out. Bypasses the client permission matrix **unconditionally** (`applyPerms()` :769) and the shell's role filter (`roleAllows()` returns `true` for `'Admin'`). |
| `hr_manager` | The software's centre of gravity. Hires, onboards, verifies DRA/PCC, maintains the register, clears pending punches, chases absences, decides leave and corrections, generates payroll, produces statutory and compliance outputs. | **Best served role in the product.** 86 of 107 nav screens; present in 63 of 70 `denyUnlessRole` lists. **Cannot** do final payroll approval, bulk deletes, or the Accounts confirmation step. |
| `field_agent` | The person the whole RBI regime is about. Punches, claims commissions, holds a DRA certificate and PCC, is authorised to a bank portfolio, is the subject of the audit PDF. | **Poorly served and structurally invisible.** Seeded permission set is `attendance.view` — **byte-identical to `employee`**. **Named in zero server write guards.** Its one functional effect is knowledge-base article filtering. The shell gives it 22 screens. |
| `employee` | Telecallers, back-office, support. Self-service only. | **Adequately served** for its narrow purpose. 13 screens in the shell. One explicit `denyUnlessRole` inclusion (document self-upload). Everything else is self-scope. |
| `accountant` | The money-verification control: confirms collections actually arrived before a commission is approved. | **Not served at all.** See §2.4 — the role can be selected but never created. |
| `platform_support` | Ametecs support staff. Intended as the least-privileged platform role. | **Wrongly served.** Created with `tenant_id = NULL`, which is the *widest* data scope in the product; **checked nowhere**; labelled "Admin" in the SPA. |
| `manager` (reporting manager) | Approves their team's leave, expenses, corrections, probation. | **Served as data, not as a role.** The tier the entire approval engine routes through is `employees.reporting_manager_id`, a column — not a role. It cannot be granted or revoked. |
| `hr` | A generic HR user distinct from `hr_manager`. | **Phantom.** Checked in 3 places, creatable nowhere. Almost certainly a typo-tier duplicate. |
| `auditor` / `recruiter` / `supervisor` | Job functions named in help text and the FAQ. | **Not roles.** The audit *audience* (a bank, an auditor) is deliberately not a user — they receive a PDF. |

#### 1.6.2 The role reality check — three vocabularies that do not coincide

```
config/smartprs.php:88-94    super_admin  admin  hr_manager  field_agent  employee
    ("locked" — and read by NOTHING; grep "smartprs.roles" → 0 hits outside the file)

RolePermissionSeeder:41-58   super_admin  admin  hr_manager  field_agent  employee

UserController::ASSIGNABLE                admin  hr_manager  field_agent  employee  accountant
StaffController::ROLES       super_admin                                            platform_support

prototype ROLES map                              HR Manager  Field Agent  Employee
    (no Admin key — roleAllows() short-circuits; no Accountant key → falls through to `true`)

Actually checked in app/     super_admin  admin  hr_manager  (field_agent: 0)  employee
                             accountant   manager  hr        (platform_support: 0)
```

The entire server-side role vocabulary of the write guard is **four lists** across ~70 call
sites: `['admin']`, `['admin','hr_manager']`, `['admin','hr_manager','accountant']`,
`['admin','hr_manager','employee']` — plus `super_admin`, implicitly allowed by the guard itself.

**`field_agent` — the product's headline persona — appears in no write guard at all.**

**BR-AUTH-001 confirmed:** both assignment surfaces call `syncRoles([$role])`. Exactly one
role per user. Every `hasAnyRole([...])` list is a union across mutually exclusive
alternatives — there is no "HR Manager who is also Accounts".

#### 1.6.3 Non-human actors

Biometric terminals (ZKTeco/eSSL over unauthenticated ADMS, identified only by serial number,
auto-registering on an unknown SN); the Smart Biometric Bridge (Windows service, `X-Api-Key`
with an `ingest` scope, tenant always from the key never the body); SmartEPT (HMAC-SHA256 over
the raw body, converts ATOM offsets to app-timezone wall clock before ingest); eTimeOffice /
eTimeTrackLite / the generic API connector; the Ametecs update server and licence server;
Interakt (WhatsApp) and a generic SMS gateway with **no provider adapter at all**; Razorpay;
and the scheduler + queue worker, without which "notification email, late-arrival alerts and
other background work silently never send".

### 1.7 The definitive module inventory (answer to question 1)

#### 1.7.1 Verifying the claimed 24

**The number 24 cannot be verified, because there is no module registry in the code.** No
constant, table, config key or class enumerates "modules". The prior dossier's 24 is an
analyst's grouping of the controller set. What the code actually contains is **six different,
mutually inconsistent module vocabularies**:

| Artefact | Count | Location |
|---|---:|---|
| `AppController::PERM_NAV` — the role-permission matrix groups | **16** | `AppController.php:655-676` |
| `PLAN_GROUP_KEYS` — what plan gating recognises | **10** | `AppController.php:34-37` |
| `StandardPlanSeeder` feature keys — what plans actually store | **14** | `StandardPlanSeeder.php:28-32` |
| `Edition` nav sets — `SAAS_ONLY` 8 + `DNA` 12 + `L2_MODULES` 30 | **50 nav ids** | `Edition.php:101-118` |
| `MasterController::def()` — self-creating registry types | **57** | `MasterController.php:30-480` |
| The SPA shell nav | **107 screen ids** | `resources/prototype/app.html` |
| Marketing site | **"16 modules"**, stated in six places | `landing.blade.php` |

The `PERM_NAV` 16 is the closest thing to a canonical module list in code, and it is the one a
customer's permission matrix is actually expressed in: `dashboard, employees, attendance,
leave, payroll, commissions, expenses, loans, statutory, performance, field, kb, reports,
assets, settings, saas`.

**Verdict on "24": it is a documentation artefact, not a product artefact.** The functional
grouping below is what the code supports, at 30 modules. It corrects three material
mis-groupings in the prior 24: (a) Commissions/Schemes/Clawbacks is *the* collections module
and was buried inside "Loans, Advances & Incentives"; (b) Off-roll Agents has no module at all
in the prior list despite being a separate person model with its own KYC, ledger and public
portal; (c) Masters & Registries — 57 self-creating registry types in a 1,224-line controller —
was reduced to "Departments & Org Structure".

> **PRODUCT OWNER DECISION REQUIRED (Q-MOD-01):** the licence agreement sells "the SmartPRS
> module catalogue at the time of sale". That catalogue is not in this repository, and six
> in-code lists disagree. SmartPRS 3 needs **one** module registry that the permission matrix,
> the plan gate, the edition gate, the licence and the price list all read from.

#### 1.7.2 The inventory

Legend for **Maturity**: `production` = works as presented · `partial` = works with a named
gap · `prototype` = present but not fit for the claimed purpose · `dead` = unreachable or
never written. **Kind**: `CORE` = generic Indian HR/payroll · `VERTICAL` = collections-specific ·
`PLATFORM` = vendor commercial machinery.

| # | Module | Business purpose | Primary controller(s) / service(s) | Route prefix | Tables | Maturity | Kind |
|---:|---|---|---|---|---|---|---|
| 1 | **Authentication & Session** | Sign in, branded per-company portals, Super Admin portal, forgot/reset, first-password | `AuthController` (543) | `/login`, `/super`, `/c/{slug}`, `/forgot-password`, `/reset-password`, `/app/first-password` | `users`, `user_password_resets`* | production | CORE |
| 2 | **Users, Roles & Permissions** | One login per person, one role each; a client-side permission matrix | `UserController` (355), `StaffController` (114), `FeaturePermissions` | `/app/users`, `/admin/staff` | `users`, `roles`, `permissions`, `model_has_roles` | **prototype** (matrix is `display:none` only) | CORE |
| 3 | **Tenancy & Companies** | One tenant → many legal entities; the oldest is `is_master` | `SaasController` (588), `MasterController` `companies` | `/app/saas/tenants`, `/app/master/companies` | `tenants`, `companies` | partial (scope is inert — §6 D-03) | CORE |
| 4 | **Employee Master** | The person record both compliance regimes hang off | `AppDataController` (3090), `EmployeeFieldRules` (613) | `/app/employees/*`, `/app/data` | `employees`, `employee_archives`, `employee_references` | production | CORE |
| 5 | **Self-Onboarding** | New joiner fills their own record from a phone: OTP, selfie, documents | `SelfOnboardingController` (1132) | `/self-onboard/{token}/*`, `/app/self-onboarding/*` | `self_onboarding`*, `self_onboarding_docs`*, `self_onboarding_otps`* | production | CORE |
| 6 | **Employee Import / Export** | Bulk load at go-live; upload → map → preview → commit | `EmployeeImportController` (124), `EmployeeImportService` (821), `EmployeeExportController` (327) | `/app/employee-import/*`, `/app/employees/export` | `employee_import_jobs` | partial (**bypasses the seat cap**) | CORE |
| 7 | **Masters & Registries** | 57 self-creating registry types behind one CRUD engine | `MasterController` (1224) | `/app/master/{type}` | 57 tables, most runtime-created | production (as CRUD) | CORE + VERTICAL |
| 8 | **Biometric Devices** | Device setup, mapping, pending-punch quarantine, API keys | `BiometricConfigController` (721), `PushController` (286), `DeviceController` (239), `ApiKeyController` (546) | `/iclock/*`, `/app/biometric-config/*`, `/app/api-keys`, `/app/pending-punches` | `biometric_configs`*, `attendance_pending`*, `api_keys`, `biometric_unmapped`* | partial (**`/iclock` unauthenticated + runtime DDL**) | CORE |
| 9 | **Attendance Capture** | Seven ingest paths → one `attendance_logs` | `PunchIngestService` (403), `ETimeOfficeService` (784), `ETimeTrackLiteService` (313), `GenericApiService` (458), `SmarteptWebhook` (337), `ShiftResolver` (160) | `/api/v1/*`, `/iclock/*`, `/app/attendance/punch` | `attendance_logs`*, `attendance_pending`* | **production — the best-built subsystem** | CORE |
| 10 | **Attendance Correction** | Employee raises a regularisation; HR decides | `AttendanceCorrectionController` (182), `AttendanceCorrectionService` (484) | `/app/attendance-corrections/*` | `attendance_corrections` | production | CORE |
| 11 | **Attendance Reporting & Bulk** | Registers, PDFs, ratings, bulk CSV with an approval gate | `AttendanceReportController` (977), `AttendanceBulkController` (390) | `/app/attendance-report/*`, `/app/attendance-bulk/*` | `attendance_logs`*, `attendance_ratings`* | production | CORE |
| 12 | **Leave** | Apply, balances per type, hierarchy approval | `LeaveController` (501) | `/app/leaves/*` | `leaves`, `leave_types` | partial (**LOP leave is paid**) | CORE |
| 13 | **Requests & Approvals** | One engine for 10 request modules + a unified inbox + threshold escalation | `RequestController` (1929), `ApprovalService` (381) | `/app/requests/{module}`, `/app/approvals` | `expenses`, `advances`, `loans`, `commissions`, `clawbacks`, `increments`, `exits`, `bonus_encashment`, `transfers`* | production (guards fail open) | CORE |
| 14 | **Payroll Engine** | Preview → generate → draft run + payslips, with LOP proration and recoveries | `PayrollGenController` (2845) *orchestrator*, `AppDataController` *calculator*, `PeriodResolver` (323, **not wired in**), `PayrollLock` (98) | `/app/payroll/preview`, `/app/payroll/generate`, `/app/salary-simulate` | `payroll_runs`, `payslips`, `loan_recoveries`* | partial (float money, no freeze, several formula defects) | CORE |
| 15 | **Salary Approval & Disbursement** | Two-stage run approval + per-line lifecycle + NEFT bank pack | `SalaryApprovalController` (899) | `/app/salary-runs/*`, `/app/salary-lines/*` | `payroll_runs`, `payslips`, `salary_payments`* | partial (three unguarded listing endpoints) | CORE |
| 16 | **Statutory Compliance** | PF/ESI/PT/TDS/LWF/194H rates, scoped and effective-dated; ECR, challan, 24Q, 26Q, PT, gratuity, bonus | `StatutoryConfigController` (476), `StatutoryController` (173), `StatutoryConfig` (589), `EffectiveDated` (136) | `/app/statutory-config/*`, `/app/statutory/{type}/pdf`, `/app/statutory-report/{type}` | `statutory_configs`, `statutory_settings`*, `settings`* | partial (**PDFs cannot reconcile to payslips**) | CORE |
| 17 | **Loans, Advances & Deductions** | Loan/advance requests with EMI recovery from payroll; ad-hoc deductions ledger | `LoanController` (84), `RequestController`, `MasterController` `deductions` | `/app/requests/loans`, `/app/requests/advance` | `loans`, `advances`, `loan_recoveries`*, `deductions` | partial (**UI-created loans recover forever**) | CORE |
| 18 | **Commissions, Schemes & Clawbacks** | Published incentive schemes → claims with collection evidence → Accounts confirmation → manager approval → payslip or ledger; bounce → auto-clawback | `SchemeController` (1001), `IncentiveController` (371), `RequestController` | `/app/schemes/*`, `/app/incentive/*`, `/app/requests/commissions/*` | `incentive_schemes`*, `commissions`, `clawbacks`, `commission_payments`*, `commission_logs`* | production (**Accounts role uncreatable**) | **VERTICAL** |
| 19 | **Bank / NBFC Payout & Billing Pack** | Per bank per month: payout register, TDS 194H annexure, GST service invoice to the bank | `BankPackController` (374) | `/app/bank-pack/*` | `bank_invoices`*, `commissions` | production (v1 limit: off-roll earnings carry no bank tag) | **VERTICAL** |
| 20 | **Off-Roll Agents** | A second person model: vendor/commission agents not on payroll — KYC, docs, bank, earnings ledger, token-secured public live-earnings page | `OffrollAgentController` (557) | `/app/offroll-agent/*`, `/agent/verify/{token}`, `/agent/earnings/{token}` | `offroll_agents`*, `agent_earnings`* | production (**excluded from all compliance scanning and all seat counting**) | **VERTICAL** |
| 21 | **Recruitment & Hiring Drives** | Requisition → sourcing → interview → offer → hire, plus drives and WhatsApp campaigns | `RecruitmentController` (1271), `HiringDriveController` (377), `RecruitMessagingController` (418) | `/app/recruitment/*` | `recruitment`*, `job_openings`*, `interviews`*, `hiring_drives`*, `hiring_drive_candidates`*, `recruitment_campaigns`*, `recruitment_messages`* | production | CORE |
| 22 | **Letters, Offers, NDA & ID Cards** | Merge a template with employee data → PDF → email → token accept/sign | `LetterController` (521), `OfferAcceptController` (89), `NdaSignController` (103) | `/app/letters/*`, `/offer/{token}`, `/nda/{token}`, `/app/idcard/*` | `letters`* | partial (**`letters-nda` has no backend definition — the NDA leg is dead**) | CORE |
| 23 | **Regulatory Compliance (DRA/PCC/BGV/CoC/Auth)** | Nightly expiry scan + alert email; per-agent RBI audit PDF with verdict, score and reference; bulk and sample variants | `ComplianceController` (661), `ScanCompliance`, `CodeOfConductController` (183) | `/app/compliance-alerts/*`, `/app/compliance/agent-audit*`, `/app/code-of-conduct` | `dra_certs`*, `bgv`*, `agent_authorizations`*, `complaints`*, `code_of_conduct_ack`*, `employee_references` | partial (**off-roll excluded; audit reference not tamper-evident; disciplinary actions hard-coded**) | **VERTICAL** |
| 24 | **Transfers** | Branch↔branch and company↔company moves under approval, applied on the effective date | `TransferController` (209), `TransferService` (217) | `/app/transfers/*`, `/transfer/accept/{token}` | `transfers`* | production | CORE |
| 25 | **Performance, Points, Probation & Onboarding boards** | Review workflow, points ledger and rules, awards, tests, probation confirm/extend, onboarding checklist | `PerformanceController` (136), `PointsController` (265), `ProbationController` (126), `ProbationService` (388), `OnboardingController` (198), `TestController` (292) | `/app/performance/*`, `/app/points/*`, `/app/probation/*`, `/app/onboarding/*`, `/app/tests/*` | `performance_reviews`*, `points_ledger`, `test_questions`*, `test_attempts`*, `onboarding`*, `onboarding_items`* | partial | CORE |
| 26 | **Training, Knowledge Base & In-App Help** | KB articles filtered by the viewer's role; training programmes/records/content; FAQs; per-screen ⓘ help; the Salary Calculation Guide | `KbController` (226), `ScreenHelpController` (2206), `CalcGuideController` (226), `SeedIndustryContent` | `/app/kb`, `/app/help/{screen}`, `/app/calc-guide` | `kb_topics`, `faqs`*, `training_programs`*, `training_subjects`*, `training_records` | production | CORE |
| 27 | **Notifications & Messaging** | In-app bell, email, WhatsApp templates, SMS, broadcast, greetings, absence and late notices | `NotificationController` (65), `SendMessageController` (267), `WaTemplateController` (434), `GreetingController` (189), `AbsenceController` (102), `ConfigController` (447), + `MailService`/`WaService`/`NotificationService`/`GreetingService`/`AbsenceService`/`LateArrivalService` | `/app/notifications/*`, `/app/send-message`, `/app/wa-templates`, `/app/greetings/*`, `/app/absence/*`, `/app/mail-settings` | `notification_log`, `mail_log`*, `wa_log`*, `wa_templates`*, `late_notifications`, `messages_log` | partial (**no SMS provider adapter; push registered but no sender exists**) | CORE |
| 28 | **Reporting & Exports** | Preview + CSV/XLSX over employees, payslips, leaves, attendance; computed read-only screens; financial year | `ReportController` (520), `ComputedController` (166), `FinYearController` (161) | `/app/reports/*`, `/app/computed/{type}`, `/app/fin-year` | reads only | partial (**no workspace-level export**) | CORE |
| 29 | **SaaS Signup, Billing & Subscriptions** | Landing site, leads, demo passkeys, signup, quotations, coupons, Razorpay, invoices, credit sales, tenant self-renewal | `LandingController` (190), `LeadController` (224), `DemoAccessController` (506), `SignupController` (1204), `BillingController` (1031), `TenantBillingController` (527), `CouponController` (176), `PolicyController` (50), + `SubscriptionService`/`CouponService` | `/`, `/signup`, `/demo`, `/quote/{token}`, `/app/billing/*`, `/app/my-subscription`, `/admin/*` | `plans`, `subscriptions`, `invoices`, `payments`, `signups`*, `renewals`*, `coupons`*, `coupon_redemptions`*, `leads`*, `demo_requests`*, `subscription_alerts`* | partial (**seat counting wrong; plan gating dead**) | PLATFORM |
| 30 | **Licensing, Updates & Installer** | Licence issue/activate/revoke/rebind, AMC, release publishing and grants, client self-update with backup and rollback, browser install wizard | `OnpremClientController` (769), `ClientUpdateController` (879), `UpdateServerController` (377), `ReleaseController` (336), `InstallController` (247), + `LicenseService`/`LicenseFile`/`LicenseSigner`/`UpdateClient`, `updater/updater.php` | `/update/*`, `/licence/{token}`, `/admin/onprem/*`, `/admin/releases`, `/app/updates/*`, `/activate`, `/install` | `licences`*, `licence_devices`*, `licence_events`*, `onprem_clients`*, `onprem_payments`*, `releases`*, `release_grants`*, `client_updates`* | partial (**editions cosmetic; update hash check optional**) | PLATFORM |
| — | **Mobile app device gate** | Capacitor WebView wrapper + pre-login device registration and approval | `MobileDeviceController` (260) | `/api/mobile/*`, `/app/mobile-devices/*` | `mobile_devices`* | partial (**status not enforced server-side**) | CORE |
| — | **AI Assistant** | *A nav item with no implementation anywhere.* | none | none | none | **dead** | — |

`*` = table has **no migration**; it is created at request time by application code.
59 distinct table names are created this way (`grep -o "Schema::create('…'" app/`), plus the
generic self-creation in `MasterController::ensureCols()` :496–511, which will create **any**
of the 57 registry tables on first use.

### 1.8 The collection / recovery vertical (answer to question 4)

This is the section that determines the SmartPRS 3 module-pack boundary. For each claimed
industry capability: **is it genuinely industry-specific, or generic HR wearing an industry
label?**

#### 1.8.1 The verdict table

| Capability | What the code actually is | Genuinely industry-specific? | SmartPRS 3 home |
|---|---|---|---|
| **DRA certificate register** (`dra-certs`) | A `MasterController::def()` entry: a flat, self-creating table with `employee, company_name, cert_no, institute, track, issue_date, expiry, status` — plain CRUD, no validation, no issuing-body integration, no renewal workflow (`MasterController.php:314-320`) | **The schema, no. The consuming logic, yes.** The register itself is a generic "credential with an expiry" record. What is industry-specific is (a) the nightly expiry scan, (b) the audit-report line item, (c) the DRA money gate | **Core** = a generic *Credential / Certification* object with issuing body, number, validity and a renewal workflow. **Module pack** = the DRA *instance* (IIBF, tracks) and the money gate |
| **PCC (police clearance)** | Two plain columns on `employees`: `pcc_status`, `pcc_expiry` (+ legacy `pcc_deadline`). Not even a register — a status string and a date (`ComplianceController.php:57`) | **No.** This is a credential-with-expiry, identical in shape to DRA, modelled worse | **Core** — the same Credential object. Nothing here justifies a module pack |
| **BGV** | A `def()` registry with six named check columns (`chk_identity, chk_address, chk_education, chk_employment, chk_criminal, chk_references`), an agency, a status, and a `revalidate_months` → `next_due` auto-computation (`MasterController.php:193-203, 746-753`) | **No.** Background verification is generic pre-employment screening used by every Indian employer above a certain size. The six checks are the standard BGV set | **Core** |
| **Employee references (min 2)** | `employee_references` table, counted in the audit report; ≥2 = ok, 1 = warn, 0 = bad (`ComplianceController.php:591-593`) | **No.** Generic. The *threshold of 2 for field staff* is the industry bit — and it is **hard-coded** | **Core**, with the threshold configurable |
| **Agent authorisation / empanelment** (`agent-auth`) | A `def()` registry: `employee, company_name, bank, portfolio, auth_no, valid_to, status` (`MasterController.php:307-313`) | **Yes — this one is genuinely vertical.** "A named bank has authorised this named person for this named portfolio until this date" has no generic HR analogue. But **it is still only a flat CRUD table**: no alert on `valid_to`, no link to the commission's `portfolio` field, no revocation workflow | **Module pack** — and it needs building properly, not migrating |
| **Code of Conduct** | A read-and-acknowledge screen. The *content* is whatever KB articles sit in the category "Code of Conduct". The *record* is one row per user in `code_of_conduct_ack` with `acknowledged` and `ack_date`. **No version, no re-acknowledgement cadence, no per-role text, no expiry** (`CodeOfConductController.php:99-108, 142-178`) | **No.** This is a generic policy-acknowledgement feature. The only industry content is which KB articles the seeder writes | **Core** — as a *Policy Acknowledgement* capability with versioning and periodic re-ack. The RBI conduct text is content, not code |
| **Off-roll agents** | A **second, parallel person model** with its own KYC document slots, bank/UPI, payout config (per-visit / percent / fixed), an earnings ledger with approve/reject, and a token-secured public live-earnings page needing no login (`OffrollAgentController.php` in full) | **Yes — genuinely vertical, and the strongest vertical asset in the product.** A workforce paid per visit or per rupee collected, who are not employees, hold no seat, and have no login, is exactly the collections labour model | **Module pack**, but the *underlying* need — a non-employee worker with a payout ledger and a credential-less public view — is common to gig/field industries and should sit on a **core "Worker" abstraction** with off-roll as a worker type |
| **Client-bank mapping** | **There is no bank master.** The bank is the **free-text `portfolio` string** carried on each commission entry, and `BankPackController` groups by it. The controller's own docblock says so, and adds "Off-roll agent earnings carry no bank tag — a DELIBERATE v1 limit, stated on screen" (`BankPackController.php:24-27`) | **The need, yes. The implementation, no** — it is a string join | **Module pack**, and it must become a real `Client / Portfolio` entity in SmartPRS 3 |
| **Complaint tracking** | A `def()` registry: `employee, company_name, severity, channel, description, status, date`, employee optional so a complaint may be anonymous (`MasterController.php:107-118`). Consumed as a trailing-90-day count in the score and audit verdict | **The register, no** (a generic complaints/grievance register). **The consumption, yes** — "borrower complaints against this agent in the last 90 days" is a specific RBI-facing measure | **Core** = a generic Case/Complaint register with subject, channel, severity, SLA. **Module pack** = the borrower-complaint type and the audit-window rule |
| **Escalations** | A `def()` registry: `date, company_name, bank, team, issue, severity, priority, status, action_taken` (`MasterController.php:301-306`) | **Yes** — a bank-raised issue against the agency is not a generic HR concept | **Module pack** |
| **RBI agent audit pack** | `agentAuditData()` assembles ~14 items in 6 groups from data already held, scores 0–100, produces `COMPLIANT` / `COMPLIANT — WITH OPEN ITEMS` / `NON-COMPLIANT`, on the company's own letterhead, with a bulk variant and a public sample (`ComplianceController.php:541-660`) | **Yes — this is the single most defensible piece of vertical IP in the product.** It is the artefact the agency hands the bank | **Module pack**, and it must be rebuilt with the defects fixed (§6 D-06) |
| **Nightly DRA/PCC expiry scan** | `compliance:scan` daily 07:30, buckets expired / critical / soon, emails the tenant's recipients (`routes/console.php:143-146`; `ComplianceController.php:37-130`) | **The mechanism, no** — "alert me before a credential expires" is generic. **The applicability, yes** | **Core** = a generic credential-expiry alert engine driven by configuration. **Module pack** = which credentials and what windows |
| **DRA money gate** | Configurable `off` / `warn` / `block` in Settings → Statutory Rates, applied at **three money points**: commission approval (`RequestController.php:1373`), incentive commit (`IncentiveController.php:135`), off-roll earning approval (`OffrollAgentController.php:249-262`). For off-roll agents the gate can only react to a **blank** DRA field, because `offroll_agents.dra` is free text with no expiry column | **Yes, and it is the most sophisticated vertical rule in the product** — a compliance state gating a money event, with a per-tenant severity setting | **Module pack rule**, expressed on a **core** rule engine ("block/warn a money event when a named credential is invalid") |
| **Bank/NBFC payout & billing pack** | Payout register + 194H annexure + GST service invoice per bank per month (`BankPackController`) | **Yes.** An agency billing its client bank for collection services is not HR at all — it is the agency's revenue side | **Module pack** — and arguably a separate product boundary |
| **Live Salary / live earnings** | Month-to-date earnings entry by entry, before payroll runs; plus the token-secured off-roll variant | **No, but commercially load-bearing.** Ametecs treats it as a flagship differentiator; the mechanism is generic | **Core** |
| **"Roster", "FOS", `companies.type = 'collections'`** | Labels and a free-text type column | **No.** Naming only | Core |

#### 1.8.2 The summary answer

**Genuinely industry-specific and worth a module pack (6):**
agent authorisation/empanelment · off-roll agent register and payout ledger ·
client-bank/portfolio mapping and the bank billing pack · escalation desk ·
the RBI agent audit pack · the DRA money gate.

**Generic HR wearing an industry label (7):**
DRA register (a credential) · PCC (a credential, modelled as two columns) · BGV ·
employee references · code-of-conduct acknowledgement (a policy ack) ·
complaint register (a case register) · the expiry-scan mechanism.

**The structural insight for SmartPRS 3:** the vertical is *thin*. Five of the six registries
that carry the industry story — dra-certs, agent-auth, bgv, complaints, escalations,
code-of-conduct — are **rows in a 460-line config array in one controller** and self-creating
flat tables with string columns. There is almost no vertical *logic*; there is vertical
*naming* over generic CRUD, plus three genuinely vertical engines (audit pack, DRA money gate,
off-roll payouts). If SmartPRS 3's core provides (a) a Credential object with expiry and alert
policy, (b) a Policy-acknowledgement object with versioning, (c) a Case/Complaint register,
(d) a Worker abstraction that admits non-employees, and (e) a rule engine that can gate a money
event on a compliance state, then **the entire collections module pack reduces to configuration
plus three engines** — and the same core sells to security agencies, staffing firms, logistics
and facilities management without a rewrite.

#### 1.8.3 Confirmed defects specific to the vertical

| ID | Finding | Evidence |
|---|---|---|
| V-01 | **Off-roll agents are outside every compliance mechanism.** `scan()` queries `employees` only; `scoreFor()`, `draValid()` and `agentAuditData()` all key on `employees.id` / `emp_code`. `offroll_agents` is read by none of them. For an Indian recovery agency this is the population RBI norms most directly target | `ComplianceController.php:51-56, 285-330, 541-560` — no `offroll_agents` reference anywhere in the file |
| V-02 | **Off-roll DRA and PCC have no expiry at all.** The runtime schema declares them `$t->string('dra')` and `$t->string('pcc')` — free text. There is no date column to scan | `OffrollAgentController.php:44-45` |
| V-03 | **Agent authorisation `valid_to` has no alert.** It appears only in the audit PDF. An authorisation can lapse silently while the agent keeps working the portfolio | `MasterController.php:307-313`; no `agent_authorizations` reference in `routes/console.php` |
| V-04 | **The "tamper-evident" audit reference is not tamper-evident.** `$ref = "RAC/{Y}/{EMP_CODE}"`; `$hash = substr(sha256("$ref\|{$e->id}\|{$today->toIso8601String()}"), 0, 16)`. No secret, no persistence — **no table stores an issued report.** A bank cannot verify a presented PDF, and Ametecs cannot prove what a given report said | `ComplianceController.php:643-644` |
| V-05 | **`Disciplinary actions` is hard-coded** to value `'0'`, state `'ok'`. It always passes | `ComplianceController.php:625` |
| V-06 | **The Code-of-Conduct audit check degenerates.** `agentAuditData()` resolves the employee's linked user, then queries `code_of_conduct_ack` with `->when($userId, …)`. When `$userId` is null — an employee with no login, which is most field staff — **the `user_id` filter is dropped entirely** and the check becomes "has *anyone* in this tenant ever acknowledged?" | `ComplianceController.php:596-609` |
| V-07 | **The complaint window is a fixed trailing 90 days**, while the code comment claims "current financial quarter approx" | `ComplianceController.php:617-620` |
| V-08 | **The compliance score tops out at 85 for a fully compliant agent.** The NDA leg is worth 15 and requires `letters.letter_type = 'nda'` with `status = 'signed'`; `letters-nda` has no backend definition, so no NDA can be created through the UI | `ComplianceController.php:312-318`; `letters-nda` is in `PERM_NAV` but has no shell nav item and no `MasterController::def()` entry |
| V-09 | **Off-roll agent documents are stored on the `public` disk** (`->store('offroll-docs','public')`) — PAN, ID proof, address proof, DRA and PCC scans. They are served through an auth-guarded route, but if `storage:link` has been run they are also reachable directly under `/storage/offroll-docs/…` with no authentication | `OffrollAgentController.php:450` |
| V-10 | **The public earnings token never expires and never rotates.** `earn_token = Str::random(48)`, minted on first "send link" and reused forever; `publicEarnings()` looks it up with **no tenant scope and no expiry check** | `OffrollAgentController.php:288-291, 321` |

### 1.9 What the SPA shell actually contains (newly recoverable)

Because `resources/prototype/app.html` is present, the navigation layer is now fact rather than
inference.

#### 1.9.1 The shell's structure
- 986 lines, 234 KB. `AppController::show()` reads it and performs exactly three injections:
  a CSS heredoc before the first `</head>`, a `window.__SMARTPRS` config object, and the
  ~12,000-line JS heredoc before the last `</body>` (`AppController.php:88-91`).
- It declares `SCREENS=`, `ROLES=`, `PLATFORM=`, `roleAllows()`, `applyRole()`, `go()`,
  `render()` and the sidebar markup.
- **107 distinct nav ids** exist as static `data-id` attributes in the shell markup.

#### 1.9.2 The shell's own role filter, transcribed exactly
```js
var PLATFORM = ['platform-dashboard','tenants','plans','subscriptions',
                'invoices','payments','gateways','feature-flags','demo-requests'];

function roleAllows(id){
  var r = window._role || 'Admin';
  if (PLATFORM.indexOf(id) >= 0) return r === 'Super Admin';
  if (r === 'Super Admin')       return PLATFORM.indexOf(id) >= 0 || id === 'ai-assistant';
  if (r === 'Admin')             return true;
  var a = ROLES[r];
  return a ? a.indexOf(id) >= 0 : true;      // ← unknown role ⇒ EVERYTHING
}
```
`ROLES` contains exactly three keys: `'HR Manager'` (86 screens), `'Field Agent'` (22),
`'Employee'` (13). There is no `'Admin'` key (short-circuited above) and **no `'Accountant'`
key** — so a role the SPA labels anything unrecognised falls through to `return true`.

This is the **fourth** independent fail-open in the authorization stack, and it is the one the
prior analysis could not see:

1. `denyUnlessRole()` returns "allowed" on any throwable (server).
2. `ROLE_MAP` misses resolve to `'Admin'` (`AppController.php:93`).
3. `applyPerms()` returns early for `'Super Admin'` and `'Admin'` (`:769`).
4. **`roleAllows()` returns `true` for any role not in the three-key `ROLES` map** (shell).

#### 1.9.3 A behaviour the prior analysis got backwards

`roleAllows()` gives **Super Admin only the 9 PLATFORM screens plus `ai-assistant`** — every
tenant screen is hidden from a Super Admin's sidebar. The prior dossier states Super Admin
reaches *"Everything. All 14 tenant module groups plus the SaaS platform group"* (§5 role table)
and *"There is no screen a `super_admin` cannot reach"* (§4.2). **The server-side statement is
correct** (`denyUnlessRole` allows `super_admin` unconditionally, so every endpoint is
reachable by URL) — but the **navigation** is the opposite of what was documented.

#### 1.9.4 The nav is half static markup, half runtime DOM injection

Diffing the shell's 107 static nav ids against `PERM_NAV`'s 120:

**In `PERM_NAV`, absent from the shell markup (14):**
`bank-pack, bonus, calc-logic, dra-certs, feature-flags, letters-nda, min-wages,
mobile-devices, my-subscription, overtime, pay-ledger, policies, transfers, wa-templates`

None of these strings occurs in `app.html` at all. They are inserted at runtime by the boot JS,
which locates an anchor and does `insertBefore` (`AppController.php:967-1070, 1488-1530`), e.g.:
```php
var anchor = document.querySelector('.nav-item[data-id="fin-year"]')
          || document.querySelector('.nav-item[data-id="settings"]')
          || document.querySelector('.nav-item[data-id="users"]');
```
**Consequence:** ~13% of the product's navigation exists only if a specific *other* nav item is
present in the shell to anchor against. This is a hard coupling between a 234 KB HTML file and a
12,000-line PHP heredoc, in both directions, with no test.

**In the shell, absent from `PERM_NAV` (1):** `ai-assistant` — a nav item shown to Super Admin,
HR Manager, Field Agent and Employee, with **no controller, no route and no renderer anywhere
in the codebase**. It is a nav item for a feature that does not exist.

### 1.10 What SmartPRS 2 does WELL (answer to question 7)

This section matters more than any defect list: it is the preservation contract for SmartPRS 3.
Each item is stated with evidence and with what would be lost if it were dropped.

| # | What it does well | Evidence | What is lost if SmartPRS 3 drops it |
|---:|---|---|---|
| 1 | **Punch ingest is careful, idempotent, tenant-bound engineering.** Seven heterogeneous sources converge on one attendance record. Tenant always comes from the credential, never the payload. Per-punch verdicts (`accepted`/`duplicate`/`pending`/`rejected`). Database-level duplicate protection. Timezone handling is explicit and commented. Backed by 63 real test cases across `SbbIngestTest`, `SmarteptWebhookTest`, `DeviceModeTest`, `IclockTenantHotfixTest`. | `PunchIngestService` (403), `SmarteptWebhook` (337, esp. :31-40 ATOM→wall-clock), `routes/api.php:1-43` (the docblock is a model of design rationale) | The single subsystem in this product that can be refactored with confidence. Rebuilding it from scratch re-earns bugs that took a year and a production incident to find. **Port it, do not rewrite it.** |
| 2 | **Punch quarantine and backdated replay.** A punch whose device code is not yet mapped is held in `attendance_pending` rather than dropped, and when HR maps the code *every held punch is applied at its real timestamp*. | `routes/web.php:537-541`; migrations `create_attendance_pending_table`, `attendance_pending_device_code` | The most common real-world failure (a branch enrols a new joiner on the terminal before HR records them) becomes silent data loss instead of a work queue. |
| 3 | **Preview-before-write on payroll.** `/app/payroll/preview` computes every employee's numbers and writes nothing. | `routes/web.php:474` | The one place the product lets an operator check money before committing it. Non-negotiable. |
| 4 | **Two-stage payroll approval with a real segregation.** draft → hr_approved (HR) → approved (**admin/super_admin only**, HR excluded) + `locked_at`. Plus a per-line lifecycle: hold / review / approve / reject / disburse / acknowledge. | `routes/web.php:461-472`; `SalaryApprovalController.php:67` | The four-eyes control on payroll money. |
| 5 | **The bank pack refuses to send bad rows to the bank.** Rows with no account number or IFSC are excluded and reported separately; four layouts (generic/ICICI/HDFC/SBI); a `# TOTAL` trailer. | `routes/web.php:465-466` | A returned NEFT batch on payday. |
| 6 | **Employee e-signature acknowledgement of a payslip line.** Typed name + confirm, recorded with `ack_name, ack_by, ack_at, ack_ip`. | `routes/web.php:470` | A cheap, real audit artefact that most payroll products lack. |
| 7 | **The commission chain is designed as an evidence chain, not a form.** Scheme → claim carrying customer, account ref, amount collected, date/time, location, mode of collection and a proof upload → **Accounts confirmation gates manager approval** → TDS §194H deducted at entry → edit-after-approval is diff-logged → a paid entry is locked → a bounce auto-clawbacks. | `routes/web.php:427-444`; `ApprovalService::logCommission()` | The product's strongest domain thinking. Preserve the *shape* even where the implementation is defective. |
| 8 | **The RBI agent audit pack.** One click produces a per-agent compliance report on the customer's own letterhead, in 6 groups with per-item ok/warn/bad, an overall verdict, a 0–100 score, and a defined audit window — plus a bulk variant for a whole filtered population and a public sample for the marketing site. | `ComplianceController.php:379-491, 541-660`; `routes/web.php:110, 487-491` | The product's clearest differentiator and the reason a bank-facing agency buys it. |
| 9 | **The off-roll agent live-earnings page.** A commission agent with no login, no app and possibly no email sees their month-to-date earnings entry by entry on a phone, from a private link — with rejected entries hidden and pending entries shown greyed and labelled "awaiting approval". | `OffrollAgentController.php:315-361` | A genuinely humane piece of product design for a low-trust, low-literacy workforce. The "how you earned it" breakdown pre-empts the argument. |
| 10 | **The DRA money gate is a real, configurable compliance-to-money rule.** `off` / `warn` / `block`, per tenant, applied at three money points, and it *never breaks the payment path on error*. | `SettingsController.php:57, 154`; `RequestController.php:1373`; `IncentiveController.php:135`; `OffrollAgentController.php:249-262` | The only implemented example of the thing SmartPRS 3 is being built to generalise. Treat it as the reference case for the rule engine. |
| 11 | **Self-onboarding is a complete, thought-through flow.** Token link → OTP → progressive save → selfie → documents → submit → HR console with per-field correction requests → verify → approve → inject into `employees`. Plus a bulk-invite variant. | `routes/web.php:67-74, 673-687`; `SelfOnboardingController` (1132) | The back-office labour saving that makes the product viable for a high-churn agency. |
| 12 | **Statutory rates are tenant data, scoped and effective-dated** — not constants. Company / state / tenant scope, effective-dated overrides, editable PT state slabs, with a preview endpoint. | `routes/web.php:580-584`; `StatutoryConfig` (589), `EffectiveDated` (136), `StatutoryConfigTest` | Already 80% of the "change configuration, not code" principle, for the hardest domain. **This is the pattern SmartPRS 3 should extend, not replace.** |
| 13 | **The self-healing schema, for its stated reason.** MySQL DDL is not transactional; a migration that dies half-way is unrecoverable on a customer-run Windows box nobody can SSH into. **A real client install died exactly that way on 28 Aug 2026.** `ensure_runtime_feature_tables` is the retrofit, and its docblock names the three exact symptoms it fixes. | `ensure_runtime_feature_tables` migration :7-23; `app/Support/SchemaHelper.php:11-26` | The *mechanism* is wrong (§6 D-04). The *problem* is real and SmartPRS 3 must answer it — an idempotent `schema:ensure` at boot and after every update. |
| 14 | **Availability-biased design, defended in writing at every site.** "A licence check must never take a client's HR system down." "A billing bug must never brick a paying customer's HR system." "Never break real tenants over the demo check." Zero punches pays full salary so a dead device cannot zero a wage. | `SubscriptionService.php:26-31`; `AuthController.php:266-269`; `PayrollGenController.php:26-29` | A deliberate, coherent product philosophy for software that runs unattended in a payroll department. **Keep the bias; add the observability it lacks.** |
| 15 | **The code explains itself.** ~40 dated change annotations (`rev 116 (Ejaz)`, `rev181 — BOUNCE: cheque returned…`), rationale comments at every non-obvious decision, and docblocks that state *why* rather than *what*. `routes/api.php`'s header explains the two authentication models and why they differ. | throughout | This is the reason a forensic pass of this depth was possible at all. It is an asset, and it is the main input to SmartPRS 3's requirements. |
| 16 | **In-app help is real and substantial.** `ScreenHelpController` (2,206 lines) supplies per-screen "how to use it / why it matters / do it right" content; `KbController` filters articles by the viewer's role; `CalcGuideController` documents the payroll engine's own rules inside the app. | `routes/web.php:333, 341, 309-313` | Support cost. The vendor's support policy explicitly excludes onsite visits, and this is what makes that viable. |
| 17 | **Razorpay signature verification is correct on both payment paths, and PDF generation streams** — 17 generation sites, none writes to disk. | prior dossier §18/§19, consistent with `routes/web.php:164-166, 404-406` | Two whole classes of vulnerability this codebase does not have. |
| 18 | **The demo workspace is genuinely safe.** One flag (`tenants.subdomain = 'demo'`) triggers a write guard, hides Administration, mutes outbound email and WhatsApp, and shows a banner — and the workspace is wiped and reseeded within 15 minutes of a passkey window ending, plus a daily 03:30 backstop. | `routes/console.php:204-211`; `OnPremWorkspaceSeeder.php:12-24` | A shared public demo of an HR product that cannot leak or spam. |

---

## 2. Data model

This area's tables are the commercial, tenancy, identity and module-registry tables. Payroll,
attendance and employee tables belong to sibling analyses.

### 2.1 Provenance summary

| Provenance | Count | Meaning |
|---|---:|---|
| Created by a migration | ~100 tables across **49** migration files | Knowable from source |
| **Created at RUNTIME by application code** | **59 distinct table names** via explicit `Schema::create(...)`, **plus any of the 57 `MasterController::def()` registry types** via the generic `ensureCols()` self-creator | **CRITICAL FINDING — see D-04** |

`grep -rhoE "Schema::create\('([a-z_]+)'" app/` yields 59 distinct names. Several appear twice
(`offroll_agents`, `attendance_logs`, `biometric_configs`, `documents`, `kb_topics`,
`onboarding`, `app_settings`, `user_password_resets` ×3) — i.e. **two or three competing
definitions of the same table in different files**, and whichever runs first wins.

### 2.2 Tables in this area, with provenance

| Table | Provenance | Notes |
|---|---|---|
| `users` | migration `create_users_table` + `add_company_id_to_users_table` | **The migration named `add_company_id_to_users_table` adds `tenant_id`, `employee_id` and `status` — it does not add `company_id`.** Verified in full at source. |
| `companies` | migration `create_companies_table` | `type` is free text; `'collections'` in the demo seeder |
| `tenants` | not in the migration list — **runtime or an earlier tree** | `subdomain = 'demo'` is the single flag that makes a workspace a demo |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | migration `create_permission_tables` | `config/permission.php` sets `'teams' => false` — roles are **global, not tenant-partitioned**. One `admin` row shared by every tenant. This is *why* the permission matrix was pushed into the client. |
| `plans` | migration (implied; `StandardPlanSeeder` guards with `hasTable`) | `features` is a JSON array of feature keys |
| `subscriptions`, `invoices`, `payments` | migration | `subscriptions.seats`, `.companies`, `.status`, `.current_period_end` |
| `signups`, `renewals`, `coupons`, `coupon_redemptions`, `leads`, `demo_requests`, `subscription_alerts` | **RUNTIME** | `SignupController`, `CouponService`, `LeadController`, `DemoAccessController` |
| `licences`, `licence_devices`, `licence_events`, `onprem_clients`, `onprem_payments`, `releases`, `release_grants`, `client_updates` | **RUNTIME — all eight licensing tables have no migration at all** | `LicenseService`, `OnpremClientController`, `ReleaseController`, `ClientUpdateController` |
| `offroll_agents` | **RUNTIME** — `OffrollAgentController.php:34-48`, then 11 columns added by `Schema::table` at :57-64 | `dra` and `pcc` are `string` — **no expiry date exists**. `earn_token` added at runtime. |
| `agent_earnings` | **RUNTIME** — `OffrollAgentController.php:78-94` | The only runtime table in this area with a considered schema (typed decimals, statuses, audit columns) |
| `code_of_conduct_ack` | **RUNTIME** — `CodeOfConductController.php:26-35`, plus a live `ALTER TABLE … MODIFY employee_id BIGINT UNSIGNED NULL` at :62-69 to repair a legacy definition | **Two definitions of the same table**: this one, and the `code-of-conduct` entry in `MasterController::def()` :288-293, whose columns are `employee, acknowledged, ack_date` with `employee_id` via `emp_map` — **no `user_id`**. Whichever is created first wins. |
| `dra_certs`, `agent_authorizations`, `bgv`, `complaints`, `escalations`, `posh_complaints`, `helpdesk`, `assets`, `deductions`, `min_wages`, `tds_returns`, `teams`, `geofence`, `shifts`, `late_policy`, `incentive_schemes`, `points_ledger`, `points_rules`, `training_*`, `letters_*`, `roster`, `awards`, `notice_board`, `feature_flags`, `pay_cycle`, `wa_settings`, `sms_settings`, … (57 types) | **RUNTIME, generically** — `MasterController::ensureCols()` :496-511 creates the table with `id / tenant_id / timestamps` and then adds every column in the `def()` `cols` array | **Every column is `string` unless the def says otherwise.** No foreign keys, no unique constraints, no indexes beyond `tenant_id`. |
| `bank_invoices` | **RUNTIME** — `BankPackController.php:38-58` | Typed decimals and a per-tenant-per-FY invoice number, but **no unique constraint on `invoice_no`** |
| `activity_log` (Spatie) | migrations 16–18 | **Never written by the application**, which writes its own `activity_logs`. Migrations 17 and 18 alter a table nothing uses. |
| `employee_archives` | runtime (`AppDataController.php:2640`) | Holds the JSON snapshot taken at archive time |

### 2.3 The tenancy model, as actually built

```
tenants (1) ──< companies (n)
   │
   └──< users        users.tenant_id  (NULL = platform)
   └──< employees    employees.tenant_id + employees.company_id
```
- `App\Models\Scopes\CompanyScope` filters `users.company_id`. **That column does not exist.**
  The global scope therefore adds no predicate and never has.
- `App\Models\Concerns\BelongsToCompany` auto-fills `company_id` on write — inert for the same
  reason, and in any case nothing routes through Eloquent.
- Real isolation is ~490 hand-written `where('tenant_id', …)` / ~400 `when($tid, …)` clauses.
  **`when()` skips the filter when the value is falsy** — and `0` is a meaningful, stored value
  in this schema (platform rows are stored at `tenant_id = 0` by `SettingsController` and
  `ConfigController`).
- `README.md:19-22, 66-70` still documents `CompanyScope` as "Global tenant read-isolation"
  and describes the model as the design.

### 2.4 The `accountant` role — confirmed at source

- Offered in the UI: `UserController::ASSIGNABLE` includes
  `'accountant' => 'Accountant', // rev 116: confirms collections before commission approval`
  (`UserController.php:31`).
- Checked in code: `RequestController::isAccounts()`, the commission payment voucher guard,
  and the bank-pack `canInvoice` flag.
- **Created nowhere.** `RolePermissionSeeder.php:41-58` seeds exactly five roles —
  `super_admin, admin, hr_manager, field_agent, employee` — and `accountant` is not among them.
  `Role::findOrCreate` appears only in `StaffController` (for `super_admin` and
  `platform_support`).
- Consequence chain: `syncRoles(['accountant'])` throws `RoleDoesNotExist` into a swallowing
  `catch`, the screen reports success, the user is saved **with no role**, `ROLE_MAP` misses
  resolve to `'Admin'` (`AppController.php:93`), `applyPerms()` returns early for `'Admin'`
  (`:769`), and the shell's `roleAllows()` returns `true` for an unknown role.
- **Business effect:** the segregation of duties the FAQ sells as the integrity guarantee of the
  commission chain — *"the manager's approve button simply refuses until that confirmation"* —
  collapses to "an Admin confirms and an Admin approves", and the attempt to configure it
  silently produces a full-looking admin account.

---

## 3. Business rules register

Rules owned by this area: commercial, tenancy, role, module-boundary and process-shape.
Payroll, attendance, leave and statutory rules belong to sibling analyses. IDs prefixed `BR-`
are confirmed from the reference catalogue; `NEW-` are rules the catalogue missed.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer might want it different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-SUB-001 | A seat is one active on-roll employee, pooled across all companies in the workspace | `tenant_id`, count | `used + count <= licensed` | allow / refuse with copy | tenant | `SubscriptionService.php:81-150` | Y (the definition) | No | **Y** — some buyers price per company, per branch, or per active *login* |
| BR-SUB-001a | These statuses free a seat: `exited, inactive, resigned, terminated, left, absconded` | `employees.status` | membership test | exclude from `used` | tenant | `SubscriptionService.php:35` | **Y — a PHP const** | No | **Y** — a customer with a `suspended` or `long_leave` status cannot make it free a seat |
| NEW-SEAT-01 | An **archived** employee still consumes a seat, while the product's own error text says archiving frees one | `employees.archived_at` | not filtered | counted in `used` | tenant | `seatUsage()` :87-97 vs `canAddEmployees()` :141 | Y | No | **Y — this is a billing defect, not a preference** |
| NEW-SEAT-02 | The seat cap is checked at 3 of 5 employee-creation doors; the import wizard and self-onboarding injection bypass it | — | — | no check | tenant | `grep canAddEmployees` → 3 sites | Y | No | **Y** |
| BR-SUB-002 | Company ceiling is strict from day one; extra companies are ₹1,000/month | `subscriptions.companies` | `used + 1 <= companies` | refuse, naming the upgrade path | tenant | `canAddCompany()` :180-199 | Y | Only by editing the subscription | Y |
| BR-SUB-003 | active → grace (7 days, full access) → locked (only `admin` may sign in) | period end, status | date arithmetic | gate login | tenant | `SubscriptionService.php:34, 44-79`; `AuthController.php:183-192` | **Y — `GRACE_DAYS = 7`** | No | **Y** — enterprise contracts routinely negotiate 15/30-day grace |
| BR-BIL-001 | Subscription price = (base + per-user × extra + ₹1,000 × (companies−1)) × months × (1 − cycle discount); GST 18% on the discounted amount | plan, seats, cycle, companies | — | compute | platform | `priceFor()` :117-133 | **Y — `COMPANY_FEE = 1000.0`, `GST = 18.0`, the discount table** | Plan fields only | **Y** — GST rate changes by law; company fee is a pricing lever; discounts are per-deal |
| BR-BIL-001a | An unrecognised cycle string silently becomes 3 months at 0% discount | `cycle` | `?? 3` | quarterly | platform | `cycleMonths()` :43-47 | Y | No | Y |
| BR-BIL-002 | A coupon discounts only the first invoice; the subscription keeps the full rate | coupon | — | invoice ≠ subscription amount | platform | `SignupController.php:1068-1070` | Y | No | **Y** — multi-period promotional pricing is a normal ask |
| BR-BIL-003 | Renewal is never automatic. `billing:renew` exists and is **not scheduled** | — | — | manual pay | platform | `routes/console.php` (absent from the schedule) | Y | No | **Y** — auto-debit is a standard SaaS expectation, and the landing page already claims it |
| BR-LIC-001 | One key, one machine. A `.lic` with no fingerprint is refused outright; the file is re-verified on disk at every check | licence, fingerprint | — | allow / demand a new code | install | `AuthController.php:200-270`; `config/smartprs.php:41-52` | Y | `SMARTPRS_LICENCE_ENFORCE`, `SMARTPRS_OFFLINE_LIC` | Y |
| BR-LIC-002 | Every commercial and licensing gate fails open on error | any throwable | `catch` | allow | all | `SubscriptionService.php:26-31, 133-137`; `LicenseGate`, `EditionGuard`, `DemoWriteGuard`, `EnsureSubscriptionActive`, `ApprovalService.php:365-380` | Y | No | **N — keep the bias. Y — it must become observable** |
| NEW-LIC-01 | AMC is sold at 20%/yr; `licences.amc_percent` defaults to **18** and is read by no calculation | — | — | none | platform | `LicenseService.php:39`; `OnpremClientController.php:148` | Y | No | Y |
| BR-TEN-001 | A workspace is a demo **iff** `tenants.subdomain = 'demo'` | subdomain | string equality | write guard + hide Administration + banner + mute comms | tenant | `DemoAccessController::isDemoTenant()`; `OnPremWorkspaceSeeder.php:12-24` | **Y — a literal string** | No | Y |
| BR-TEN-002 | Demo passkey window: default 2 hours, clamped 1–72; reset within 15 min of expiry plus a daily 03:30 backstop | hours | clamp | expire + wipe | platform | `DemoAccessController::pinHours()`; `routes/console.php:204-211` | Bounds hard-coded | Hours are a Super Admin setting | Y |
| BR-TEN-003 | `CompanyScope` filters `users.company_id`, which does not exist — the scope is permanently inert | — | — | no predicate added | global | `app/Models/Scopes/CompanyScope.php`; `add_company_id_to_users_table` migration | Y | No | **N — this is a defect** |
| BR-AUTH-001 | One role per user. Both assignment surfaces call `syncRoles([$role])` | role | — | replace | tenant | `UserController::assignRole()`; `StaffController.php:51, 84` | Y | No | **Y** — "HR Manager who is also Accounts" is a normal small-agency need and is currently impossible |
| BR-AUTH-002 | An unknown or missing role is labelled **"Admin"** in the SPA and skips the permission matrix | role name | `ROLE_MAP[…] ?? 'Admin'` | full-access UI | session | `AppController.php:15-21, 93, 769` | Y | No | **N — defect. Must resolve to least privilege** |
| NEW-AUTH-01 | The **shell's** role filter returns `true` for any role not in its three-key `ROLES` map — a fourth, previously invisible fail-open | role label | `ROLES[r] ? … : true` | full nav | browser | `resources/prototype/app.html`, `roleAllows()` | Y | No | **N — defect** |
| NEW-AUTH-02 | The shell shows a **Super Admin only the 9 PLATFORM screens** plus `ai-assistant`; every tenant screen is hidden from their sidebar | role label | `PLATFORM` membership | hide nav | browser | `roleAllows()` | Y | No | **Y** — vendor support staff routinely need a tenant view |
| BR-AUTH-003 | `denyUnlessRole()` returns "allowed" on any throwable — *"fail open — availability over strictness on pivot errors"* | throwable | `catch` | allow | ~70 sites | `ApprovalService.php:365-380` | Y | No | **N — must fail open *loudly*** |
| NEW-ROLE-01 | The role list the product is sold on is not the list the code enforces: `field_agent` is in zero write guards; `accountant` cannot be created; `manager` and `hr` are checked but unassignable; `platform_support` is assignable but never checked; `config('smartprs.roles')` is read by nothing | — | — | — | global | §1.6.2 | Y | No | **N — defect** |
| BR-REQ-001 | Approval chain: Reporting Manager → the manager's manager → the label "Branch / Company Admin". Amount thresholds: expenses ₹10,000, advances ₹10,000, loans ₹50,000; everything else 0 (never escalates) | amount, `reporting_manager_id` | `amount >= threshold` | escalate one level | tenant | `ApprovalService::modules()` :29-42, `resolveApprover()` :310-352 | **Y — the thresholds are a PHP array** | No | **Y — certainly.** Thresholds are per-customer, and the chain depth is fixed at two levels |
| BR-REQ-001a | When no manager can be resolved the approver is a **string label with a NULL id** — nobody's inbox owns the request | — | no match | `[null, 'Reporting Manager / HR']` | tenant | `ApprovalService.php:311-337` | Y | No | **N — defect** |
| BR-REQ-002 | A commission claim cannot be manager-approved until Accounts confirms the money arrived | claim | `isAccounts()` | gate | tenant | `RequestController.php:57-70` | Y | No | **Y** — some agencies want this optional, or want a value threshold |
| BR-IMP-001 | `ApprovalService::safeRow()` intersects every insert with the table's actual columns — **a missing column is silent data loss, not an error** | row, table | column intersection | drop keys | ~everywhere | `ApprovalService.php:263-266` | Y | No | **N — defect** |
| NEW-VERT-01 | The DRA money gate: `off` / `warn` / `block`, per tenant, applied at commission approval, incentive commit and off-roll earning approval | `dra_gate`, DRA validity | gate value | refuse / warn+audit / ignore | tenant | `SettingsController.php:57, 154`; `RequestController.php:1373`; `IncentiveController.php:135`; `OffrollAgentController.php:249-262` | Rule shape hard-coded; **severity is configurable** | **Yes — Settings → Statutory Rates** | Y — customers will want the same shape for PCC, BGV and authorisation |
| NEW-VERT-02 | The compliance score is `DRA 25 + PCC 20 + BGV 20 + NDA 15 + no open complaints 20`, clamped 0–100 | five checks | — | integer | tenant | `ComplianceController.php:289-330` | **Y — every weight is a literal** | No | **Y — certainly.** Different banks weight differently |
| NEW-VERT-03 | Audit verdict: any `bad` → NON-COMPLIANT; else any `warn` → COMPLIANT — WITH OPEN ITEMS; else COMPLIANT | ~14 item states | counts | verdict string | tenant | `ComplianceController.php:646` | Y | No | **Y** |
| NEW-VERT-04 | The complaint window for the audit is a fixed trailing **90 days** | today | `subDays(90)` | count | tenant | `ComplianceController.php:617` | **Y** | No | **Y** — the comment itself says "financial quarter" was intended |
| NEW-VERT-05 | References threshold: ≥2 ok, 1 warn, 0 bad | count | literals | state | tenant | `ComplianceController.php:593` | **Y** | No | **Y** |
| NEW-VERT-06 | BGV re-verification due date = `verified_on + revalidate_months` | two fields | — | write `next_due` | tenant | `MasterController.php:746-753` | Formula hard-coded; **months is per-row data** | Partly | N |
| NEW-VERT-07 | Off-roll payout: `visit` → rate × qty; `collection` → base × rate / 100; `fixed` → rate; `adjustment`/`deduction` → manual only. A manual amount > 0 always overrides | kind, qty, base, manual | — | rounded amount | tenant | `OffrollAgentController.php:102-119` | **Y — only four payout kinds, one rate per agent** | Rate and type are per-agent data | **Y** — tiered/slab commission, per-portfolio rates, and caps are all normal asks |
| NEW-VERT-08 | The off-roll public earnings page shows approved + pending entries and **hides rejected ones** | status | filter | render | public | `OffrollAgentController.php:332-334` | Y | No | Y |
| NEW-MOD-01 | Every on-prem edition is feature-identical: `Edition::level()` returns a literal `3` and `hiddenNavIds()` returns `SAAS_ONLY` for all of L1/L2/L3 | edition | — | hide 8 nav ids | install | `Edition.php:93-97, 119-133` | **Y** | `SMARTPRS_EDITION` changes nothing but the licence check | **Y — this is a sales-integrity decision, not a preference** |
| NEW-MOD-02 | Plan-based module gating is disabled twice: client-side by an unconditional `return`, server-side because 7 of the 14 seeded feature keys are unrecognised | plan features | key membership | unrestricted | tenant | `AppController.php:34-37, 709-712`; `StandardPlanSeeder.php:28-32` | Y | No | **Y — the landing page sells this capability** |
| NEW-MOD-03 | ~14 nav items exist only as runtime DOM injections anchored to *other* nav items in the shell | shell markup | anchor found | `insertBefore` | browser | `AppController.php:967-1070, 1488-1530` | Y | No | **N — architectural defect** |
| NEW-PROC-01 | **No statutory filing due date exists anywhere in the product.** 12 scheduled tasks, none about a filing | — | — | none | global | `routes/console.php` complete schedule | Y | No | **Y — this is a gap in the product's own compliance promise** |
| NEW-PROC-02 | **There is no attendance freeze.** Nothing prevents a punch or leave approval landing after a draft run and changing the numbers on regeneration | — | — | none | tenant | no freeze route exists | Y | No | **Y — certainly** |

---

## 4. Calculations and formulas

Payroll formulas belong to a sibling analysis. Transcribed here: the commercial, seat,
compliance-score and off-roll payout calculations this area owns.

### 4.1 Subscription price — `BillingController::priceFor()` :117–133
```php
$included   = (int) ($plan->seat_max ?? 0);
$extra      = $included > 0 ? max(0, $seats - $included) : $seats;
$months     = ['monthly'=>1,'quarterly'=>3,'halfyear'=>6,'annual'=>12][$cycle ?? 'quarterly'] ?? 3;
$discount   = ['halfyear'=>0.10,'annual'=>0.25][$cycle ?? ''] ?? 0.0;
$companies  = max(1, $companies);
$companyFee = 1000.0 * ($companies - 1);
$perMonth   = (float) $plan->base_price + (float) $plan->per_user_price * $extra + $companyFee;
$amount     = round($perMonth * $months * (1 - $discount), 2);
$tax        = round($amount * 18.0 / 100, 2);
$total      = round($amount + $tax, 2);
```
**Basis:** per calendar month, multiplied by cycle months. **Rounding:** PHP `round(…, 2)`
(half-up), applied twice — once to `amount`, once to `tax` — then summed. Arithmetic is **PHP
float**, in a file whose own product config declares *"All monetary values use decimal columns
+ integer-safe math. Never float."* (`config/smartprs.php:126-128`).

**Worked example** — Starter, 40 seats, 1 company, half-yearly:
`extra = 15`; `perMonth = 1000 + 60×15 + 0 = 1900`;
`amount = round(1900 × 6 × 0.90, 2) = 10,260.00`; `tax = 1,846.80`; **total ₹12,106.80**.

**Ordering matters and is fixed:** the cycle discount applies to `perMonth × months`
(so it discounts the company fee too), and GST is computed on the **discounted** amount.
A coupon, when present, is applied *after* this and tax is recomputed.

### 4.2 Financial year label — `BillingController::finYear()` :62–68
```php
$y = $d->month >= 4 ? $d->year : $d->year - 1;
return $y.'-'.substr((string)($y + 1), -2);      // Jul 2026 → "2026-27"; Feb 2027 → "2026-27"
```
Hard-codes the Indian April–March year. **Not configurable.**

### 4.3 Seat usage — `SubscriptionService::seatUsage()` :87–114
```
used     = COUNT(employees WHERE tenant_id = ?
                        AND deleted_at IS NULL
                        AND (status IS NULL OR LOWER(status) NOT IN
                             ('exited','inactive','resigned','terminated','left','absconded')))
licensed = subscriptions.seats
        ?: tenants.seats_licensed
        ?: 0
        ; and on an ENFORCED on-prem install, .lic device_limit OVERRIDES the above
```
**No `archived_at` predicate** — see NEW-SEAT-01. `licensed <= 0` → unlimited.

### 4.4 Compliance score — `ComplianceController::scoreFor()` :289–330
```
score  = 0
+25 if EXISTS dra_certs WHERE employee_id = ? AND status = 'verified'
                          AND (expiry IS NULL OR expiry >= today)
+20 if employees.pcc_status = 'verified' AND (pcc_expiry IS NULL OR pcc_expiry >= today)
+20 if EXISTS bgv WHERE employee_id = ? AND status = 'clear'
+15 if EXISTS letters WHERE employee_id = ? AND letter_type = 'nda'
                        AND is_template = 0 AND status = 'signed'
+20 if NOT EXISTS complaints WHERE employee_id = ? AND status IN ('open','pending','in_progress')
     (or if the complaints table does not exist at all)
score  = min(100, max(0, score))
```
**Worked example** — an agent with a valid DRA, verified PCC, clear BGV, no complaints, and no
NDA (because `letters-nda` is unreachable): `25 + 20 + 20 + 0 + 20 = 85`. **A perfectly
compliant agent cannot score above 85 through the UI.** No rounding; integer arithmetic.

Note the *absence* asymmetry: a missing `complaints` table awards the full 20, while a missing
`dra_certs` table awards 0. Absence of evidence is treated as compliance in one leg and
non-compliance in another.

### 4.5 Audit verdict — `ComplianceController::agentAuditData()` :646
```
verdict = fail > 0 ? 'NON-COMPLIANT'
        : (warn > 0 ? 'COMPLIANT — WITH OPEN ITEMS' : 'COMPLIANT')
```
over ~14 items, each contributing exactly one of `ok` / `warn` / `bad`. `Disciplinary actions`
always contributes `ok`.

### 4.6 Audit reference and "hash" — `:643-644`
```php
$ref  = 'RAC/'.$today->format('Y').'/'.strtoupper($code);
$hash = substr(hash('sha256', $ref.'|'.$e->id.'|'.$today->toIso8601String()), 0, 16);
```
`$today = Carbon::today()` (:544), so the ISO string is **midnight** — the hash is stable for a
calendar day and changes daily. No secret. Never persisted.

> **Doc-vs-source:** the prior dossier's `FINDING-BIZ-04` states the hash is *"recomputed on
> every generation (so the same agent's report never carries the same hash twice)"*. That is
> **wrong** — it is deterministic within a day. The finding's substance stands (unkeyed,
> unstored, unverifiable) but the mechanism is misdescribed. Recorded as `DISC-04`.

### 4.7 Off-roll payout — `OffrollAgentController::computeEarning()` :102–119
```php
if ($manual !== null && $manual > 0) return round($manual, 2);   // manual always wins
$rate = (float) ($a->rate ?? 0);
if ($kind === 'visit')      return round($rate * max(0, $qty), 2);
if ($kind === 'collection') return round($base * $rate / 100, 2);
if ($kind === 'fixed')      return round($rate, 2);
return 0.0;                                                       // adjustment, deduction
```
**Worked example** — agent with `payout_type = percent`, `rate = 2.5`, collection of ₹180,000:
`round(180000 × 2.5 / 100, 2) = 4,500.00`.
**Basis:** one flat rate per agent, applied to every entry regardless of portfolio, bank, age of
debt or slab. **Rounding:** `round(…, 2)`, PHP float. A computed amount of 0 is rejected with an
error message rather than stored.

Month-to-date net (`earningsPayload()` :143–184): approved entries sum, `deduction` kinds sum
negative, pending entries accumulate into a separate `pending` figure. The query is
`entry_date >= <first of current month>` with `limit(100)` — **an agent with more than 100
entries in a month silently sees a wrong total on their public page.**

### 4.8 Bank pack GST split — `BankPackController` docblock :16–22
CGST + SGST when the two GSTINs' state codes match; IGST otherwise. Mirrors the SaaS billing
rule. Numbered per tenant per financial year.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Setting | Where | Mechanism |
|---|---|---|
| Statutory rates: PF, ESI, PT, TDS, §194H, no-PAN rate | Settings → Statutory Rates; Statutory Configuration | `settings` / `statutory_configs`, **scoped (company/state/tenant) and effective-dated** |
| PT state slabs | Statutory Configuration → slab editor | `POST /app/statutory-config/slab` |
| **DRA money gate: off / warn / block** | Settings → Statutory Rates | `SettingsController.php:57, 154` |
| Weekly-off day, Saturday-off mode, LOP basis, payslip download mode | Settings → Statutory Rates | `SettingsController.php:170` |
| Pay cycle (calendar month or a cut-off such as 21st→20th) | Masters → Pay Cycle | `pay_cycle` registry |
| Financial year (set active) | `/app/fin-year` | `FinYearController` |
| Departments, designations, branches, banks, teams, holidays, leave types, shifts, late & comp-off policy, overtime, minimum wages, geofence rules, roster, assets, notice board, policies, FAQs, KB articles, training programmes and content, letter templates, points rules, incentive schemes | Masters — **57 registry types** | `MasterController::def()` + a generic CRUD/import/template engine |
| **The compliance registries themselves** — DRA certificates, agent authorisations, BGV cases, complaints, escalations, code-of-conduct acknowledgements | Masters | same engine |
| Company branding, logo, app logo, per-company display name and colours | `/app/branding` | `ConfigController` |
| SMTP senders (per company + tenant default), WhatsApp settings and templates, SMS settings and templates | Settings | `ConfigController`, `WaTemplateController` |
| Greeting configuration and templates; absence-notice send hour | `/app/greetings/config`, `/app/absence/config` | per tenant |
| Probation configuration | `/app/probation/config` | per tenant |
| Users, and one role per user | `/app/users` | `syncRoles([$role])` |
| The role→module permission matrix | Settings → Roles | Stored in the `app_state` JSON blob; **applied only as `display:none` in the browser** |
| Biometric device configuration, device→employee mapping, API keys, SmartEPT webhook endpoints | Settings | per tenant |
| Subscription: seats, companies, cycle, plan (self-service renew/upgrade) | `/app/my-subscription` | Razorpay |
| Deployment behaviour: `SMARTPRS_DEPLOYMENT`, `SMARTPRS_EDITION`, `SMARTPRS_LICENCE_ENFORCE`, `SMARTPRS_OFFLINE_LIC`, `ZKTECO_*`, `ETIMEOFFICE_*`, timezone, ~176 env variables | `.env` | **not a customer surface** — vendor/installer only |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog for this area

Exhaustive for the product/business/module layer. Each is a requirement.

**Commercial and licensing**
1. GST rate (`GST = 18.0`) — a statutory value hard-coded as a PHP const.
2. Additional-company fee (`COMPANY_FEE = 1000.0`).
3. The cycle → months map and the cycle → discount map.
4. The fallback for an unrecognised cycle (3 months, 0%).
5. The pricing *formula shape* — a customer wanting per-branch, per-login or tiered pricing cannot have it.
6. Grace period (`GRACE_DAYS = 7`).
7. Which roles may sign in during a lock-out (hard-coded to `admin`).
8. The seat definition, and the six statuses that free a seat.
9. Whether an archived employee consumes a seat.
10. Coupon scope (first invoice only).
11. AMC percentage — the column exists, defaults to 18, and is read by nothing.
12. Whether renewal can be automatic.
13. Edition → feature mapping. `Edition::level()` is a literal `3`.
14. Plan → module mapping. Disabled in two places.
15. The invoice and quotation number formats.
16. Demo-window bounds (1–72 hours) and the demo flag (`subdomain = 'demo'`).

**Identity, roles and permissions**
17. The role vocabulary. Five roles, hard-coded in four disagreeing places, and the "locked" config list is read by nothing.
18. **One role per user.** A user cannot hold two roles.
19. Creating a new role. `accountant` demonstrates that the UI offering one is not enough.
20. Any server-side permission. The matrix is browser-only `display:none`; the server has four hard-coded role lists across ~70 sites.
21. Making `field_agent` mean anything. It is in zero write guards.
22. Which role is the fallback for an unknown role (currently `'Admin'` — the *most* privileged).
23. Whether a Super Admin can see tenant screens (shell-level, hard-coded).
24. Whether roles are tenant-scoped at all (`config/permission.php` sets `'teams' => false`).

**Approvals and process**
25. Approval thresholds (₹10,000 expenses, ₹10,000 advances, ₹50,000 loans).
26. The approval chain depth (fixed at two: manager, manager's manager).
27. The "Team Leader" tier the docblock names and the code does not implement.
28. Which modules escalate at all.
29. Whether the Accounts confirmation stage applies, or applies above a value.
30. What happens when no approver can be resolved (currently: a label with a NULL id).
31. Whether an approve/reject decision is repeatable (there is no pending-guard on most modules).

**Compliance and the vertical**
32. The compliance score weights (25/20/20/15/20).
33. Which checks contribute to the score at all.
34. The audit verdict thresholds.
35. The complaint window (fixed 90 days).
36. The references threshold (2).
37. The audit report's item set, groups and layout.
38. Adding a credential type. DRA and PCC are special-cased; a customer needing (say) a firearms licence or a driving licence with expiry alerting cannot add one.
39. Alerting on a credential other than DRA/PCC — agent authorisation `valid_to` and BGV `next_due` have no alert.
40. Including off-roll agents in any compliance mechanism.
41. Giving an off-roll agent's DRA/PCC an expiry date at all.
42. The DRA gate's *subject* — it can only gate on DRA, not PCC, BGV or authorisation.
43. Which money events the gate applies to (three, hard-coded).
44. Code-of-conduct versioning or periodic re-acknowledgement.
45. Off-roll payout shapes — four kinds, one flat rate per agent; no slabs, no per-portfolio rates, no caps.
46. The 100-entry limit on the public earnings page.
47. Making "bank/portfolio" a real entity rather than a free-text string.

**Process and calendar**
48. Statutory filing due dates — no field, no job, no alert exists.
49. An attendance freeze before payroll.
50. Whether zero punches means full pay or zero pay.

**Structure**
51. The module list itself — six disagreeing vocabularies, none of them data.
52. The navigation. 107 ids in a 234 KB HTML file plus ~14 injected by DOM surgery from a PHP heredoc.
53. Screen labels and menu order.
54. Adding a screen without editing both `app.html` and a 12,533-line controller.
55. A workspace-level data export (only per-module exports exist).
56. Hard erasure of an employee record (soft delete + archive only) — a DPDP erasure request cannot be honoured in-product.
57. Retention/purge of a workspace after the promised 90 days — no purge job, no retention timer, no `deleted_after` column.

---

## 6. Defects, conflicts and technical debt

Severity: CRITICAL (money, data loss, or a legal/regulatory exposure) · HIGH · MEDIUM · LOW.

| ID | Defect | Evidence | Business impact | Severity |
|---|---|---|---|---|
| **D-01** | **No attendance freeze before payroll.** Nothing prevents a punch or a leave approval landing after a draft run; regenerating silently absorbs it. | No freeze endpoint exists in `routes/web.php`; only a run past `draft` blocks regeneration | The month's numbers are not reproducible. A payroll that was reviewed and one that is regenerated can differ with no trace. | CRITICAL |
| **D-02** | **Archived employees still consume a seat, while the product's own error message says they do not.** `seatUsage()` filters `deleted_at` and `status` but not `archived_at`; archiving writes only `archived_at`; the directory hides archived employees. | `SubscriptionService.php:87-97` vs `:141`; `AppDataController.php:2651-2655`, `:259` | The customer is billed for employees the product will not show them, and is told the opposite in writing. Revenue-affecting in both directions and indefensible in a billing dispute. | CRITICAL |
| **D-03** | **The declared multi-tenancy mechanism has never run.** The migration named `add_company_id_to_users_table` adds `tenant_id`, `employee_id` and `status`. `CompanyScope` filters `users.company_id`. `README.md` still documents the broken mechanism as the design. | migration file read in full; `CompanyScope.php`; `README.md:19-22, 66-70` | Isolation rests entirely on ~490 hand-written filters with no safety net; `when()` is disabled by falsy, and `0` is a real stored tenant value. | CRITICAL |
| **D-04** | **59 tables (plus any of 57 registry types) are created at request time**, several with two or three competing definitions of the same name. Production requires permanent `CREATE`/`ALTER` grants. | `grep Schema::create app/`; duplicates on `offroll_agents`, `attendance_logs`, `biometric_configs`, `documents`, `kb_topics`, `onboarding`, `app_settings`, `user_password_resets`; `MasterController::ensureCols()` :496-511; `CodeOfConductController.php:26-69` incl. a live `ALTER TABLE` | The deployed schema is unknowable from source. Whichever definition runs first wins, and the loser's feature throws `Unknown column`. `code_of_conduct_ack` has two disjoint definitions in this very area. | CRITICAL |
| **D-05** | **The `accountant` role cannot be created**, so the advertised segregation of duties on commission money collapses to "an Admin confirms and an Admin approves" — and the attempt produces a role-less user labelled "Admin". | §2.4 | The control most likely to be relied on in an internal-audit conversation does not exist, and configuring it makes things worse. | HIGH |
| **D-06** | **The RBI audit pack has five substantive defects**: disciplinary actions hard-coded to `'0'`/`'ok'`; the CoC check degenerates to "has anyone in this tenant acknowledged?" for an employee with no login; the complaint window is 90 days while the comment says financial quarter; the reference/hash is unkeyed, unpersisted and unverifiable; and a fully compliant agent tops out at 85 because the NDA leg is unreachable. | `ComplianceController.php:596-609, 617, 625, 643-644`; `scoreFor()` :312-318 | This is the artefact handed to a client bank. A forged report is indistinguishable from a genuine one, and Ametecs cannot prove what any issued report said. | HIGH |
| **D-07** | **Off-roll agents — the population RBI norms most directly target — are outside every compliance mechanism, and their DRA/PCC fields have no expiry column at all.** | `ComplianceController.php` (no `offroll_agents` reference anywhere); `OffrollAgentController.php:44-45` | The product's central compliance promise does not cover half the field force. The DRA gate can only detect a *blank* field for them. | HIGH |
| **D-08** | **Three price points ship a byte-identical product.** `Edition::level()` returns a literal `3`; `hiddenNavIds()` returns the same set for L1/L2/L3; the `EditionGuard` upsell branch is unreachable dead code. | `Edition.php:93-97, 119-133` | Sales-integrity risk. An L1 customer paying the lowest price has L3. | HIGH |
| **D-09** | **Plan-based module gating is dead in two independent places** while the landing page sells it. Client: an unconditional `return` at the top of `applyPlanGate()`. Server: 7 of the 14 seeded feature keys are unrecognised, so every seeded plan resolves to unrestricted. | `AppController.php:34-37, 709-712`; `StandardPlanSeeder.php:28-32` | A promised commercial lever does not exist. | HIGH |
| **D-10** | **The seat cap is bypassed at two of five employee-creation doors** — the import wizard and self-onboarding injection. | `grep canAddEmployees` → `AppDataController.php:815, 3042`, `RecruitmentController.php:602` only | The two doors a customer would use to add people *in bulk* are the two that do not check the cap. | HIGH |
| **D-11** | **Four stacked fail-open layers in authorization**, one of which (the shell's `roleAllows()` returning `true` for an unknown role) was previously invisible. | `ApprovalService.php:377`; `AppController.php:93, 769`; `resources/prototype/app.html` `roleAllows()` | The failure modes correlate: the same half-applied migration this codebase explicitly designs for is what breaks the role pivot. | HIGH |
| **D-12** | **~14 nav items exist only as runtime DOM injections anchored to other nav items**, coupling a 234 KB HTML file and a 12,533-line PHP controller in both directions, untested. | `AppController.php:967-1070, 1488-1530`; diff of `PERM_NAV` vs shell `data-id`s | Removing or renaming an anchor nav item silently deletes an unrelated screen from the sidebar. | HIGH |
| **D-13** | **`ai-assistant` is a nav item for a feature that does not exist** — shown to Super Admin, HR Manager, Field Agent and Employee, with no controller, route or renderer. | `roleAllows()` special-cases it; `grep ai-assistant app/` → 1 hit, in `PERM_NAV`'s absence | A dead menu entry visible to every role including the vendor's own. | MEDIUM |
| **D-14** | **`OnPremWorkspaceSeeder` creates `admin@smartprs.local` with the password `password`** on any on-prem install where it is run, and prints the password. It is **not** gated by `app()->environment('production')` the way `DatabaseSeeder`'s demo path is. `README.md:57-64` publishes the same credential set. | `OnPremWorkspaceSeeder.php:78-90, 100`; `README.md:57-64`; `DatabaseSeeder.php:17-30` | Full tenant-admin access on any install where the documented on-prem unlock step was run. | CRITICAL (security — see §7) |
| **D-15** | **`config/smartprs.php:43` declares version `2026.6.1`; `version.json` declares `2026.6.2`.** | both files read in full | The release pipeline reads one and the licence/update path reads the other. Two version numbers disagreeing is how a release reaches no client. | MEDIUM |
| **D-16** | **`composer.json` requires `php: ^8.3`** while the L3 build target pins PHP 8.2. Shared code must in practice stay 8.2-compatible with nothing enforcing it. | `composer.json:8`; prior dossier §3 | A syntax feature used legitimately per the manifest breaks the on-prem build. | MEDIUM |
| **D-17** | **`livewire/livewire ^3.5` is a declared, installed dependency with no Livewire component anywhere.** `laravel/sail` is installed with no `docker-compose.yml`. `axios` is declared and unused. `resources/js` does not exist in this tree, and the Vite pipeline is decorative. | `composer.json:13, 25`; `ls resources/` → `css, prototype, views` | Dead weight in every build and every security-advisory sweep. | LOW |
| **D-18** | **`larastan` has no `phpstan.neon` and `pint` has no `pint.json`.** `composer analyse` has never been runnable. There is no CI. | `composer.json:57-59`; no `phpstan.neon`, no `.github/` | Nothing prevents any defect in this document from recurring. | MEDIUM |
| **D-19** | **`ApprovalService` is a service physically located in `app/Http/Controllers/`**, imported by `App\Services\FeaturePermissions` (a service importing a controller), and it creates and alters tables inline during HTTP requests. | `app/Http/Controllers/ApprovalService.php` (381 lines) | The load-bearing authorization helper and approval engine is unfindable where a Laravel developer would look. | MEDIUM |
| **D-20** | **`resources/views/welcome.blade.php` is the stock Laravel starter page**, referenced by no route. Two migrations alter `activity_log`, a Spatie table the application never writes. | `ls resources/views`; migrations 16–18 | Dead code that reads as product. | LOW |
| **D-21** | **`SMARTPRS_TEAM_PIN` ships with the live default `'ametecs'`**, documented in config as unlocking *"the UNRESTRICTED personal demos at /teamdemo, /app1, /app2, /app3 (no demo write-guard, no hidden screens)"*, while the edition-demo view says the PIN was removed at rev185. | `config/smartprs.php:26-32` | Either dead config with a live default, or a live bypass. `NOT DETERMINED` — see §11. | HIGH if live |
| **D-22** | **Three customer-facing surfaces use three different names for the middle on-prem edition**: "Advance" (landing band), "L2 Professional" (FAQ + licence agreement), "L2 Advanced" (edition demo footer). | prior dossier `D-BIZ-01`, consistent with `Edition.php` comments | A contract that names a tier the product does not. | LOW |
| **D-23** | **`bank_invoices.invoice_no` has no unique constraint** despite being a per-tenant-per-FY sequential GST invoice number. | `BankPackController.php:38-58` | Concurrent generation collides silently on a legally-numbered tax document. | HIGH |
| **D-24** | **The off-roll public earnings page is capped at 100 entries per month with no indication**, so a high-volume agent's displayed total is silently wrong. | `OffrollAgentController.php:146-148` | The agent's own earnings page — the feature's entire point — under-reports. | MEDIUM |

---

## 7. Security findings

Findings whose primary impact is commercial or process integrity, and those newly discoverable
from the recovered shell and the off-roll controller. Mechanical authorization findings belong
to the auth analysis.

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **S-01** | **A hard-coded tenant-admin credential is created by a documented setup step.** `OnPremWorkspaceSeeder` inserts `admin@smartprs.local` / `password` and grants it the `admin` role, prints the password to the console, and is **not** environment-gated. Its own docblock instructs `php artisan db:seed --class=OnPremWorkspaceSeeder`. `README.md:57-64` publishes the same five demo logins and password. | `OnPremWorkspaceSeeder.php:78-97, 100`; `README.md:57-64` | **Trivial.** Try `admin@smartprs.local` / `password` on any on-prem install. | Full tenant-admin: every employee's salary, PAN, bank details and ID documents; payroll generation; user management. | **CRITICAL** |
| **S-02** | **A fourth, previously undocumented fail-open in authorization.** The shell's `roleAllows()` returns `true` for any role label not present in its three-key `ROLES` map. Combined with `ROLE_MAP` resolving unknown roles to `'Admin'`, an `accountant`, a `platform_support` or a role-less user receives the **entire navigation**. | `resources/prototype/app.html` `roleAllows()`; `AppController.php:93, 769` | Reachable by ordinary use — creating an "Accountant" user is a documented UI action. | The three role states most likely to occur by accident are exactly the three the matrix cannot restrict. | **HIGH** |
| **S-03** | **`platform_support` is a cross-tenant reader of every customer's payroll data.** Created with `tenant_id = NULL` — the widest scope in the product — and checked by zero code paths. The Data Protection policy promises role-based access governs who sees salaries. | `StaffController.php:17-20, 26-28, 49`; `grep platform_support app/` → 1 hit (the constant) | Any Ametecs support account. | The vendor's least-privileged-by-intent role has unrestricted read of every customer's employee, salary and bank data, with no audit distinction from a Super Admin. | **HIGH** |
| **S-04** | **Off-roll agent KYC documents are written to the `public` disk** — PAN, ID proof, address proof, DRA and PCC scans — via `->store('offroll-docs','public')`. They are served through an auth-guarded route, but if `php artisan storage:link` has been run they are also reachable at `/storage/offroll-docs/<hash>.<ext>` with no authentication. The Apache `.htaccess` has no `storage` block; `public/web.config` does. | `OffrollAgentController.php:450`; `public/.htaccess` vs `public/web.config` | Requires guessing a `Str::random` filename — but directory listing, log leakage or a backup exposure defeats that. | Identity documents of people who are not even users of the system. DPDP exposure. | **HIGH** |
| **S-05** | **The off-roll public earnings token never expires and never rotates.** `earn_token = Str::random(48)`, minted once on first "send link" and reused indefinitely; `publicEarnings()` looks it up with **no tenant scope and no expiry check**. | `OffrollAgentController.php:288-291, 321` | Anyone who ever receives or intercepts the link — including after the agent leaves the agency — retains permanent read access. | An ex-agent, or anyone with their old email, keeps seeing live earnings data. | **MEDIUM** |
| **S-06** | **`verifyEmail()` looks up `offroll_agents` by token with no tenant scope and no expiry**, and the token is not cleared on use. | `OffrollAgentController.php:541-548` | Low. | A verification link remains valid forever. | **LOW** |
| **S-07** | **Every commercial and licensing gate fails open, with no log line, no counter and no alert.** Role guard, seat cap, company cap, subscription state, licence gate, edition gate, demo write-guard, plan gate. | `SubscriptionService.php:26-31, 133-137`; `ApprovalService.php:365-380`; `LicenseGate`, `EditionGuard`, `DemoWriteGuard`, `EnsureSubscriptionActive` | Not directly exploitable; triggered by a schema hiccup. | **No commercial limit in this product is a hard limit.** A tenant can be silently uncapped on seats and companies indefinitely, with no signal to anyone. | **MEDIUM** |
| **S-08** | **`SMARTPRS_TEAM_PIN` defaults to the literal `'ametecs'`** and is documented in config as unlocking unrestricted demos with no write-guard and no hidden screens. Whether a live reader remains is `NOT DETERMINED`. | `config/smartprs.php:26-32` | If a reader exists: trivial, publicly documented in the source. | Unrestricted access to the shared demo workspace. | **HIGH if live** |
| **S-09** | **`POST /lead` is CSRF-exempt**, mitigated by a honeypot and `throttle:20,1`. | `routes/web.php:45-48` | Trivial. | The sales pipeline can be polluted at 20 rows/minute per IP. No data exposed. | **LOW** |
| **S-10** | **`/iclock/*` — six routes, unauthenticated, CSRF-exempt, `throttle:1000,1`, writing to the payroll input.** Identity is a caller-chosen `SN` query parameter; an unknown serial auto-registers. | `routes/web.php:92-105` | Anyone who can route to the server. | Attendance is the input to payroll. | **CRITICAL** (owned by the attendance analysis; recorded here for completeness) |
| **S-11** | **`bank_invoices.invoice_no` is unconstrained** on a GST tax invoice series. | `BankPackController.php:38-58` | Concurrency. | Duplicate legal invoice numbers issued to a client bank. | **HIGH** |

---

## 8. Documentation-vs-source discrepancies

| ID | The reference documents say | The source says | Impact on the rebuild |
|---|---|---|---|
| **DISC-01** | *"`resources/prototype/app.html` is absent from the working tree… `/app` cannot boot… every claim about the navigation layer is marked INFERRED."* Repeated as U4, `D-BIZ-08`, `Q-BIZ-01`, `FINDING-DEV-01` across four documents, and named as one of the two material gaps that would "move this dossier to a fully verified system of record". | **The file is present**: `resources/prototype/app.html`, 234 KB, 986 lines. `SCREENS`, `ROLES`, `PLATFORM`, `roleAllows()` and 107 nav ids all extracted and analysed in §1.9. | **Large.** A chapter's worth of INFERRED claims is now verifiable, and it revealed a fourth fail-open (S-02) and a reversed Super Admin nav behaviour (DISC-02) that the prior analysis could not see. |
| **DISC-02** | Super Admin reaches *"Everything. All 14 tenant module groups plus the SaaS platform group"*; *"There is no screen a `super_admin` cannot reach."* | Server-side **correct** (`denyUnlessRole` allows `super_admin` unconditionally). **Navigation-side inverted**: `roleAllows()` gives Super Admin **only** the 9 PLATFORM ids plus `ai-assistant`; every tenant screen is hidden from their sidebar. | A vendor-support workflow assumption in the prior analysis is wrong. |
| **DISC-03** | The 24-module inventory is presented as *"identified from the routing table, the SPA screen registry and the controller set"* with a definitive tone. | There is **no module registry in the code**. Six mutually inconsistent vocabularies exist (16 / 10 / 14 / 50 / 57 / 107). The 24 is an analyst grouping, and it mis-groups the collections module (Commissions/Schemes/Clawbacks buried under "Loans"), omits Off-roll Agents entirely, omits Bank/NBFC Payout Pack, and reduces 57 registry types to "Departments & Org Structure". | Corrected to a 30-module inventory in §1.7, with the ambiguity itself recorded as a SmartPRS 3 requirement. |
| **DISC-04** | `FINDING-BIZ-04`: the audit hash *"is recomputed on every generation (so the same agent's report never carries the same hash twice)"*. | `$today = Carbon::today()`, so `toIso8601String()` is midnight. **The hash is deterministic within a calendar day** and changes daily. | The finding's substance stands; the mechanism is misdescribed. Do not design the fix against the wrong model. |
| **DISC-05** | `D-BIZ-03`: *"6 of the 14 seeded feature keys are not in `PLAN_GROUP_KEYS`"*. | **Seven** are not: `hiring, people, compensation, learning, letters, communication, administration`. `PLAN_GROUP_KEYS` is 10, not 14. | Minor, but the conclusion is unchanged and stronger. |
| **DISC-06** | §3.3: *"The compliance score gates the incentive path only"*; *"no call to `scoreFor`/`draValid` anywhere in `PayrollGenController`"*. | Correct about payroll. **Understates the gate.** `draValid()` (not `scoreFor()`) drives a configurable `off`/`warn`/`block` DRA gate at **three** money points: commission approval, incentive commit, and off-roll earning approval. Severity is a per-tenant setting. | **Material.** This is the product's only implemented compliance-to-money rule and the reference case for SmartPRS 3's rule engine. The prior analysis nearly buried it. |
| **DISC-07** | The Code of Conduct is presented as a compliance register (`code_of_conduct_ack`) with a single definition. | **Two competing definitions of the same table**: `CodeOfConductController.php:26-35` (keyed on `user_id`) and `MasterController::def()` :288-293 (keyed on `employee_id`, **no `user_id`**). Whichever is created first wins; the controller carries a live `ALTER TABLE … MODIFY employee_id … NULL` to repair the other. | A concrete instance of the three-definitions problem, in the compliance area, previously unrecorded. |
| **DISC-08** | The off-roll agent register is described as carrying *"KYC (photo, ID, PAN, DRA, PCC, bank)"* alongside on-roll compliance. | `offroll_agents.dra` and `.pcc` are `$t->string(...)` — **free text with no expiry column**. There is nothing for a scan to read even if one were written. | Changes the fix from "extend the scan" to "model the data first". |
| **DISC-09** | `RolePermissionSeeder` seeds five roles including `field_agent` with *"attendance.view"* — implying a distinct permission set. | Confirmed identical: `field_agent` and `employee` both receive exactly `['attendance.view']`. The two roles are **indistinguishable at the permission layer** despite being sold as different populations. | Confirms, and sharpens, the prior finding. |
| **DISC-10** | The dossier's blade view count is 72. | This tree has **59** `.blade.php` files, and `resources/js` does not exist (only `css`, `prototype`, `views`). | The trees differ. Counts from the prior dossier should not be used as acceptance criteria. |
| **DISC-11** | `README.md` is described as documenting Phase 0 scaffolding. | Confirmed, and worse: it publishes five shared-password demo logins, and `OnPremWorkspaceSeeder` **actually creates one of them** (`admin@smartprs.local` / `password`) on any install where the documented on-prem unlock step is run, without an environment gate. The prior dossier notes `DatabaseSeeder` blocks demo data in production but does not connect it to this seeder. | Upgrades a documentation-hygiene note to a **CRITICAL** security finding (S-01). |

---

## 9. Migration verdict per capability

### 9.1 Module-by-module SmartPRS 2 → SmartPRS 3 disposition (answer to question 9)

Target homes: **Core platform** (identity, tenancy, config, rules, workflow, data, UI shell) ·
**Engine** (payroll, attendance, compliance, approval, notification — configurable computation) ·
**Module pack** (a purchasable vertical bundle) · **Dropped**.

| # | Module | Verdict | SmartPRS 3 home | Migration implication |
|---:|---|---|---|---|
| 1 | Authentication & Session | **REDESIGN** | Core platform | Migrate `users` (email, hash, tenant, status). Add MFA, lockout, and a real reset policy. Password hashes port unchanged. **Delete `admin@smartprs.local` on every install before cutover.** |
| 2 | Users, Roles & Permissions | **REPLACE** | Core platform | Nothing to migrate but the user↔role pairs. The matrix in `app_state` is browser-only and must not be carried. Rebuild as a server-enforced, tenant-scoped, **multi-role** permission model with roles as data. |
| 3 | Tenancy & Companies | **REDESIGN** | Core platform | `tenants` and `companies` port. Isolation becomes structural (a request-scoped resolver every query passes through). **Data audit required first**: find every NULL- and 0-tenant row before migrating. |
| 4 | Employee Master | **REDESIGN** | Core platform | The largest migration. Becomes a **Worker** entity with subtypes (on-roll employee, off-roll agent) and configurable custom fields, replacing the ~30 runtime-added columns and the six-file write mapping. |
| 5 | Self-Onboarding | **RETAIN (port)** | Core platform | Flow shape is good. Port `self_onboarding*` tables. Add rate limiting and magic-byte upload validation. Route documents to private storage. |
| 6 | Employee Import / Export | **MERGE + REDESIGN** | Core platform | Two importers disagree today; merge to one. **Must call the seat check.** Add a workspace-level export (currently absent, and a policy commitment). |
| 7 | Masters & Registries | **REPLACE** | Core platform | **The highest-leverage replacement in the product.** 57 self-creating flat tables driven by a 460-line config array become a metadata-driven entity/registry engine with typed columns, constraints, indexes and real migrations. Data ports table by table with type inference. |
| 8 | Biometric Devices | **REDESIGN** | Engine | `biometric_configs` and mappings port. **`/iclock` must move out of the `web` group, stop auto-enabling unknown serials, and stop running DDL from an unauthenticated request.** |
| 9 | **Attendance Capture** | **RETAIN — port as-is** | Engine | **Preserve.** The best-built subsystem, with 63 real tests. Port `PunchIngestService`, `SmarteptWebhook`, the connectors and the tests together. Refactor only behind the tests. |
| 10 | Attendance Correction | **RETAIN (port)** | Engine | `attendance_corrections` ports. Fix the `approved` vs `applied` ambiguity. |
| 11 | Attendance Reporting & Bulk | **REDESIGN** | Engine | Reports become configuration. Add self-scope to the unguarded endpoint. Resolve the two competing `attendance_pending` schemas before migrating. |
| 12 | Leave | **REDESIGN** | Engine | `leaves`, `leave_types` port. **The LOP-leave-is-paid defect must be fixed in the migration, not carried.** Leave types, accrual and carry-forward become configuration. |
| 13 | Requests & Approvals | **REDESIGN** | Core platform (workflow engine) | All 10 request tables port. The two-level chain and the three hard-coded thresholds become a configurable approval-workflow definition. **Requires a pending-guard** — decisions are currently repeatable. |
| 14 | Payroll Engine | **REDESIGN** | Engine | `payroll_runs` and `payslips` port read-only for history. The calculator moves out of `AppDataController`, off floats, and onto a formula engine. **Add a period freeze.** Wire `PeriodResolver` in — it exists, is designed, and has 9 passing tests. |
| 15 | Salary Approval & Disbursement | **RETAIN + REDESIGN** | Engine | Keep the two-stage + per-line shape and the e-signature. Fix: the bank-file download bypassing `applyLineAction()`, the rejection with no by/at stamp, and the three unguarded listing endpoints. |
| 16 | Statutory Compliance | **RETAIN + EXTEND** | Engine | **`StatutoryConfig` + `EffectiveDated` is the best existing example of "configuration not code" — extend it, do not replace it.** Port `statutory_configs`. **Add filing due dates, a calendar and reminders** (absent today). Reconcile the statutory PDFs to the payslips. |
| 17 | Loans, Advances & Deductions | **REDESIGN** | Engine | Port with the `tenure_months` / `tenure` column defect fixed. Recovery order and capping become configuration. |
| 18 | **Commissions, Schemes & Clawbacks** | **RETAIN (shape) + REDESIGN (implementation)** | **Module pack — Collections** | Port `incentive_schemes`, `commissions`, `clawbacks`, `commission_payments`, `commission_logs`. **The Accounts stage must work** — it needs a real role model, which is a core dependency. Scheme rules become configuration. |
| 19 | Bank / NBFC Payout & Billing Pack | **REDESIGN** | **Module pack — Collections** | Port `bank_invoices`. **The free-text `portfolio` string must become a real Client/Portfolio entity** before migration — this is a data-cleansing project, not a copy. Add a unique constraint on `invoice_no`. |
| 20 | **Off-Roll Agents** | **REDESIGN onto a core abstraction** | Core **Worker** + **Module pack** behaviours | Port `offroll_agents` and `agent_earnings` **into the Worker model** as a worker type. **Give DRA/PCC real expiry dates.** Bring them inside compliance scanning. Move documents to private storage. Rotate/expire the earnings token. Payout config becomes a rule. |
| 21 | Recruitment & Hiring Drives | **RETAIN (port)** | Core platform | Seven tables port. Add a stage transition matrix (there is none today). Reconnect the NDA leg. |
| 22 | Letters, Offers, NDA & ID Cards | **REDESIGN** | Core platform | `letters` ports. Templates become configuration with a merge-field catalogue. **`letters-nda` must be given a backend definition** — it is worth 15 points of the compliance score. Add role guards to the PDF routes. |
| 23 | **Regulatory Compliance (DRA/PCC/BGV/CoC/Auth)** | **SPLIT: MERGE the registries into core, REDESIGN the engines into a pack** | Core (Credential, Policy Ack, Case) + **Module pack** (audit pack, DRA gate, RBI item set) | Port the six registries into core objects: **Credential** (DRA, PCC, and anything a customer adds), **Policy Acknowledgement** (CoC, versioned, re-ackable), **Case/Complaint**, **Authorisation**. Rebuild the audit pack with all five D-06 defects fixed, an **issued-report table**, and an HMAC-keyed reference with a verification endpoint. |
| 24 | Transfers | **RETAIN (port)** | Core platform | `transfers` ports. It has **no base migration** — capture the runtime definition first. |
| 25 | Performance, Points, Probation, Onboarding | **REDESIGN** | Core platform | Port. Points rules, probation periods and review cadences become configuration. Fix the cross-tenant IDOR. |
| 26 | Training, KB & In-App Help | **RETAIN (port content)** | Core platform | `kb_topics`, `faqs`, `training_*` port. **Help content is a genuine asset** — 2,206 lines of it — and must move to a content store, not a controller. |
| 27 | Notifications & Messaging | **REDESIGN** | Engine | Logs port. **Build a real SMS provider adapter** (there is none) and a push sender (tokens are stored, nothing sends). Templates and channel routing become configuration. Instrument delivery — `queued` is currently treated as success. |
| 28 | Reporting & Exports | **REPLACE** | Core platform | Nothing to migrate. Becomes a metadata-driven report builder. **Must include a workspace-level export** — a standing policy commitment the product does not meet. |
| 29 | SaaS Signup, Billing & Subscriptions | **RETAIN + REDESIGN** | Core platform (commercial) | Plans, subscriptions, invoices, payments, coupons, signups port. **Fix seat counting (`archived_at`) and the two bypassed doors before migrating, or the first invoice is wrong.** Pricing constants (GST, company fee, discounts, grace) become configuration. Decide whether plan gating is real. |
| 30 | Licensing, Updates & Installer | **RETAIN + REDESIGN** | Core platform (commercial) | All eight licensing tables port — **none has a migration**, so capture the runtime definitions first. **Make the update package hash check mandatory.** Decide the edition question (D-08). Wire `amc_percent` into an actual calculation or remove it. |
| — | Mobile device gate | **REDESIGN** | Core platform | `mobile_devices` ports. **Enforce approval status server-side** — it is not enforced today. |
| — | Legacy Eloquent models + 10 legacy routes | **REMOVE** | Dropped | 9 domain models pointing at table names that do not exist, imported in `routes/web.php:4-16` and never routed. Delete. |
| — | `ai-assistant` nav item | **REMOVE** | Dropped | A menu entry with no implementation. Delete or build. |
| — | `welcome.blade.php`, `activity_log` migrations 17–18, `livewire`, `sail`, `axios`, `resources/css/smartprs-theme.css`, the Vite pipeline | **REMOVE** | Dropped | Dead. |
| — | SPA shell + 12,533-line `AppController` | **REPLACE** | Core platform (UI) | No migration. The screen registry, nav structure and 107 ids are a **specification input** — extract them as data before deleting the file. |

### 9.2 Capability-level verdict (brief format)

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| Punch ingest | **RETAIN** | The only subsystem with real test coverage and careful, defended design | Port code + tests together; `attendance_logs`, `attendance_pending` (resolve the two schemas first) |
| Statutory rate configuration | **RETAIN + EXTEND** | Already scoped, effective-dated and tenant-owned — the pattern the whole rebuild aims at | `statutory_configs` ports directly |
| Payroll calculation | **REDESIGN** | Float money, no freeze, several confirmed formula defects, calculator in the wrong class | Historic `payroll_runs`/`payslips` port read-only |
| Approval workflow | **REDESIGN** | Right shape, hard-coded thresholds, two-level depth, no pending-guard, fails open | All request tables port |
| Role & permission model | **REPLACE** | Four disagreeing vocabularies, one role per user, browser-only enforcement, four fail-opens | User↔role pairs only |
| Tenant isolation | **REPLACE** | The declared mechanism has never run; 490 hand-written filters | Data audit for NULL/0 tenant rows |
| Masters & registries | **REPLACE** | 57 self-creating flat tables from a config array | Table-by-table with type inference |
| Compliance registries (DRA/PCC/BGV/CoC/complaints) | **MERGE into core objects** | They are credentials, policy acks and cases wearing industry names | Port into Credential / PolicyAck / Case |
| Agent authorisation, escalations, audit pack, DRA gate, off-roll payouts, bank billing pack | **REDESIGN into a module pack** | Genuinely vertical; thin implementations | Port data; rebuild logic |
| Off-roll agent as a person model | **REDESIGN onto core Worker** | The need is general; the implementation is a parallel schema | `offroll_agents` → Worker(type=off_roll) |
| Commercial model (plans, seats, billing, licensing) | **RETAIN + REDESIGN** | Sound shape, defective seat counting, dead edition and plan gating | Fix seats **before** migrating |
| SPA shell + injected JS | **REPLACE** | 1.15 MB of PHP-embedded JavaScript, untestable, half-injected nav | Extract the screen registry as a spec |
| Eloquent model layer | **REMOVE** | Dead and wrong | None |

---

## 10. SmartPRS 3 requirements implied

Numbered, specific, testable. Each names the SmartPRS 2 behaviour that forces it.

### Module and catalogue
1. There must be **exactly one module registry**, held as data, that the permission model, the plan gate, the edition gate, the licence and the price list all read from — because SmartPRS 2 has six disagreeing vocabularies (16 / 10 / 14 / 50 / 57 / 107) and the licence agreement sells a "module catalogue" that exists nowhere.
2. A module must be **installable, licensable and hideable as data**, not by editing a PHP constant — because `Edition::level()` returns a literal `3` and every priced tier ships identically.
3. A module pack must be able to add entities, fields, registries, screens, rules, alerts and reports **without a core code change** — because the collections vertical today is 57 rows in one controller's config array.

### Configuration engine
4. Every commercial constant must be configuration: GST rate, additional-company fee, cycle→months, cycle→discount, unknown-cycle fallback, grace days, seat-free statuses, and the seat definition itself.
5. The configuration engine must support **scoped, effective-dated values** (tenant / company / state / branch), because `StatutoryConfig` + `EffectiveDated` already prove the pattern works and it is the product's best feature.
6. A customer must be able to define a **new entity or registry** with typed columns, constraints and indexes without a migration — replacing `MasterController::ensureCols()`'s generic self-creating table with all-string columns.
7. Custom fields on any entity must be first-class, because SmartPRS 2 grew ~30 runtime columns on `employees` from four different patchers.
8. **No application code may execute DDL at request time.** Schema convergence happens in exactly one idempotent `schema:ensure` at boot and after update — because SmartPRS 2 has 59 runtime-created tables, at least 8 with competing definitions, and a real client install died of a half-applied migration on 28 Aug 2026.

### Rule engine
9. The rule engine must support **"block or warn a money event when a named credential is invalid"**, with per-tenant severity (`off`/`warn`/`block`) and a per-event applicability list — generalising the DRA gate, which today hard-codes DRA at three money points.
10. It must support **weighted scoring rules** whose weights are configuration — because the compliance score hard-codes 25/20/20/15/20 and no customer can reweight for a specific bank.
11. It must support **verdict rules over item states** (any bad → X, else any warn → Y, else Z) with configurable thresholds.
12. It must support **rolling-window count conditions** with a configurable window — because the complaint window is a hard-coded 90 days while the code comment says "financial quarter".
13. It must support **count thresholds with three-state outcomes** (≥2 ok, 1 warn, 0 bad) — because the references rule hard-codes 2.
14. It must support **amount-threshold escalation with configurable levels and depth** — because the approval chain hard-codes ₹10,000 / ₹10,000 / ₹50,000 and a fixed depth of two, while the documentation names a three-tier chain ("Team Leader") that does not exist.
15. Rule evaluation must be **observable**: every gate that allows on error must log, count and alert. SmartPRS 2 has eight fail-open points on a single authenticated request and none of them logs the throwable it swallowed.
16. A rule that fails open must still fail open — the availability bias is correct and must be preserved — but **loudly**.

### Identity, roles and authorisation
17. Roles must be **data, tenant-scoped, and multi-assignable**. SmartPRS 2 allows exactly one role per user (`syncRoles([$role])`) and roles are global (`config/permission.php: 'teams' => false`), which is why the matrix was pushed into the browser.
18. Permissions must be **enforced server-side at the route or policy layer**, so a missing guard is a routing error rather than an invisible hole — because 122 of 433 routes have no authorisation at all and the matrix is `element.style.display = 'none'`.
19. An unknown, missing or unresolvable role must resolve to **least privilege**, never to Admin. SmartPRS 2 resolves it to `'Admin'` in `ROLE_MAP`, skips the matrix for `'Admin'`, and the shell's `roleAllows()` returns `true` for any unknown role — three independent paths to full access.
20. Creating a role from the UI must **actually create it**, and a failure must surface. The `accountant` case is the reference failure.
21. The "reporting manager" tier must be expressible as **either** a role **or** a data relationship, and grantable/revocable — SmartPRS 2 has it only as `employees.reporting_manager_id`.
22. Approval routing must never produce an approver with a NULL id and a string label; an unroutable request must land in a named queue.

### Compliance and the vertical
23. There must be a **generic Credential object** — type, issuing body, number, issue date, expiry, verification status, document — so a customer can add a credential type (firearms licence, driving licence, insurance broker certification) without code. DRA and PCC become instances.
24. The **expiry-alert engine must be driven by configuration**: which credential types, which windows, which recipients, which channels. SmartPRS 2 hard-codes DRA and PCC and ignores agent authorisation `valid_to` and BGV `next_due` entirely.
25. Compliance mechanisms must operate on the **Worker** abstraction, so off-roll agents are included by construction — SmartPRS 2 excludes them from every mechanism because they are a parallel schema.
26. Every credential must have a **date**, not free text. `offroll_agents.dra` and `.pcc` are `string`.
27. **Policy acknowledgement must be versioned and periodically re-acknowledgeable**, with per-role text — SmartPRS 2's CoC is one row per user, no version, no cadence, and its content is whichever KB articles sit in a category.
28. A compliance check must **never degrade to a weaker check when data is missing**; it must return "unknown". SmartPRS 2's CoC audit check drops its `user_id` filter when the employee has no login and becomes "has anyone in this tenant acknowledged?".
29. Absence of evidence must be treated **consistently**: SmartPRS 2 awards a full 20 points when the complaints table is missing and 0 when the DRA table is missing.
30. Every issued compliance report must be **persisted** (agent, timestamp, verdict, score, item states) with an **HMAC-keyed reference** and a verification endpoint — because SmartPRS 2 stores nothing and its reference is an unkeyed sha256 prefix, while the marketing page sells "a tamper-evident reference".
31. No audit item may be hard-coded to pass. `Disciplinary actions` is fixed at `'0'`/`'ok'`.
32. The audit report's **item set, groups, weights and layout must be configuration**, because different banks and future RBI directions will differ.
33. **Client / bank / portfolio must be a real entity**, not a free-text string joined on. `BankPackController` groups by `commissions.portfolio`.

### Process and calendar
34. There must be a **statutory obligation calendar**: obligation, jurisdiction, period, due-date rule, owner, status, evidence, and reminders. SmartPRS 2 has 12 scheduled tasks and **not one** concerns a filing due date; the dates exist only as help-text prose.
35. There must be an explicit, auditable **period freeze** for attendance and payroll, with a named unfreeze action — SmartPRS 2 has none, and a regeneration silently absorbs late data.
36. **Zero-punches behaviour must be a configurable policy** (pay full / pay zero / flag for review), not a hard-coded constant — the current "pay 100%" is a deliberate anti-fragility choice that also pays absconders in full.
37. Payroll generation itself must be audited. SmartPRS 2 logs only regeneration.
38. A rejected artefact and its remark must survive. SmartPRS 2 deletes a rejected run on regeneration.

### Commercial
39. Seat counting must have **one definition, one implementation, and be enforced at every creation door** — SmartPRS 2 has three of five doors and omits `archived_at`, billing customers for employees it will not show them.
40. Whatever "archived" means, the billing rule and the user-facing message must agree.
41. Editions and plans must gate something real, or the tiering must be removed. Today both are dead and both are advertised.
42. AMC percentage must drive an actual calculation or not exist.
43. A **workspace-level data export** must exist — Terms, Privacy and Data Protection all promise one and only per-module exports exist.
44. **Hard erasure** of a person record must be possible, to honour a DPDP erasure request. SmartPRS 2 has soft delete and archive only.
45. **Retention and purge** must be implemented, not just promised — there is no purge job, no retention timer and no `deleted_after` column behind the 90-day commitment.

### Platform hygiene
46. One version number, in one place. `version.json` and `config/smartprs.php` disagree today.
47. Every table must have a migration; the deployed schema must be derivable from source.
48. No table may have two definitions.
49. Money must not be computed in PHP floats — the product's own config already says so and 30 sites do it anyway.
50. Every legal-numbered document series (invoices, quotations, licence invoices, bank invoices, employee codes) must have a unique constraint and transactional issuance.
51. No credential may be created by a seeder or published in a README. `OnPremWorkspaceSeeder` creates `admin@smartprs.local` / `password` on a documented setup step.
52. Uploads must be validated on magic bytes and stored on a private disk. Off-roll KYC documents go to the `public` disk today.
53. Public token links must **expire and be rotatable**. The off-roll earnings token never expires and never rotates.
54. CI must exist — Pest on MySQL, Pint, and a Larastan config — because `composer analyse` has never been runnable and nothing prevents any of these defects recurring.
55. The navigation must be data, not a 234 KB HTML file plus ~14 runtime DOM injections anchored to other nav items.
56. No nav entry may exist without an implementation (`ai-assistant`).

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | What is known | What is needed |
|---|---|---|---|
| **Q-01** | **PRODUCT OWNER DECISION REQUIRED — What is the canonical module catalogue?** | Six in-code vocabularies (16 / 10 / 14 / 50 / 57 / 107), a marketing claim of "16 modules", and a licence agreement referencing a catalogue that is not in the repository. | One authoritative list, agreed commercially, that becomes the SmartPRS 3 module registry. **This blocks the module-pack boundary.** |
| **Q-02** | **PRODUCT OWNER DECISION REQUIRED — Are the three on-prem editions real?** | `Edition::level()` returns a literal `3`; the revert path is deliberately preserved (`BLOCK_DNA`, `BLOCK_L2`, `SAAS_ONLY`/`DNA`/`L2_MODULES` intact). Three price points, one product. | Either implement tiering or stop selling three tiers. Two-line change either way; it is a commercial decision, not a technical one. |
| **Q-03** | **PRODUCT OWNER DECISION REQUIRED — Is plan-based module gating a product capability?** | Dead in two independent places; sold on the landing page; `StandardPlanSeeder`'s own docblock calls it "parked". | Decide, then either build it once (server-side) or remove the claim. |
| **Q-04** | **PRODUCT OWNER DECISION REQUIRED — What is a seat?** | Active on-roll employees pooled across companies; archived employees still bill; off-roll agents never bill; two of five doors bypass the cap; the whole thing fails open. | The pricing metric, in one sentence, before any billing data migrates. |
| **Q-05** | **PRODUCT OWNER DECISION REQUIRED — Should off-roll agents be billable, and should they be compliance-scanned?** | They are potentially the majority of a collections field force, consume no seat, have no expiry dates on their credentials, and are outside every compliance mechanism. | This is simultaneously the largest revenue question and the largest compliance-promise gap in the product. |
| **Q-06** | **PRODUCT OWNER DECISION REQUIRED — Which of the six vertical capabilities are a paid module pack, and which move to core?** | §1.8 recommends: pack = agent authorisation, off-roll payouts, client-bank/billing pack, escalations, audit pack, DRA gate. Core = credentials, policy ack, cases, references, expiry-alert engine. | Confirmation, because it determines whether the core is sellable to security agencies, staffing and logistics without a rewrite. |
| **Q-07** | **PRODUCT OWNER DECISION REQUIRED — Should a user be able to hold more than one role?** | SmartPRS 2 enforces exactly one. The `accountant` control exists precisely because a small agency needs an "HR Manager who is also Accounts" — and cannot have one. | A yes changes the permission model's shape fundamentally. |
| **Q-08** | **`SMARTPRS_TEAM_PIN` — dead config or live bypass?** | Config ships the live default `'ametecs'` and documents it as unlocking unrestricted demos with no write-guard and no hidden screens; a view says the PIN was removed at rev185. | Confirm no reader of `config('smartprs.team_pin')` exists in the shipped build. **Security-blocking.** |
| **Q-09** | **How many installs have `admin@smartprs.local` / `password` active?** | `OnPremWorkspaceSeeder` creates it, is not environment-gated, and its own docblock instructs running it on an on-prem install; the README publishes the credential. | An audit of every deployed install, before anything else. **This is the highest-urgency item in this document.** |
| **Q-10** | **What does `platform_support` mean?** | Creatable, `tenant_id = NULL` (the widest scope in the product), checked nowhere, labelled "Admin" in the SPA. | The intended remit, so it can be given a real scope. |
| **Q-11** | **Who is "Team Leader"?** | `ApprovalService`'s docblock names a three-tier chain; the code implements two levels; "Team Leader" is neither a role nor a column. | Whether it should be a designation, a `teams` FK, or was aspirational. |
| **Q-12** | **Do the audit report's parameters match the published RBI directions?** | The landing page builds a campaign around directions "Effective 1 October 2026" and lists five obligations, all of which the audit checks. The referenced artefacts (`/recovery-compliance-2026.html`, the manual PDF) are not in this repository. | The published directions, so the item set can be verified rather than assumed. |
| **Q-13** | **Is the live schema what the code builds?** | 59 runtime-created tables, at least 8 with competing definitions, and no live dump was ever taken (prior U3). | `mysqldump --no-data` from a production install. **This blocks every data-migration estimate in §9.** |
| **Q-14** | **Does the test suite pass?** | 17 test files, 132 cases, no CI, and the vendor's own session report says the 23 SBB tests were never run. | `php artisan test` against MySQL. It matters because the punch-ingest tests are the one asset §9 proposes to port wholesale. |
| **Q-15** | **Which tree is authoritative?** | This tree has 59 blade views, no `resources/js`, and **does** contain `resources/prototype/app.html`. The prior dossier's tree had 72 views, `resources/js`, and did **not** contain the prototype. | Confirmation of which tree SmartPRS 3 is being specified against. Counts from the prior dossier must not be used as acceptance criteria until this is settled. |
| **Q-16** | **Is the on-prem "self-service licence move once per year" a product feature or a support process?** | The licence agreement promises it; only a Super-Admin-only `/admin/onprem/{id}/deactivate` exists. | Whether to build it or reword the agreement. |
| **Q-17** | **Should `ai-assistant` be built or removed?** | A nav item shown to four roles with no implementation of any kind. | A product decision, currently presented to customers as a feature. |

---

*End of analysis.*
