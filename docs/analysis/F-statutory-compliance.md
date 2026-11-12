# AS-IS ANALYSIS — INDIA STATUTORY ENGINE & REGULATORY COMPLIANCE MODULE

> **Standing caveat on every number in this document.** Every rate, ceiling,
> threshold, slab and date recorded here is **the value the SmartPRS 2 code
> currently uses**. Nothing here asserts that any value is the legally correct
> one for any period, state or establishment. Every one of them requires
> independent verification against the applicable Act, rule or state
> notification before it is relied upon for filing or payment.

> **Caveat on the source copy.** The read-only tree at `/home/claude/prs2/` is
> **incomplete for `resources/views/`** — 39 views referenced by controllers are
> absent, including `login`, `landing`, `dashboard` and `agent-audit-body`.
> Missing-view findings are therefore *not* reported as defects; where a view is
> load-bearing to a claim, the claim is marked UNVERIFIABLE IN THIS COPY.

---

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Line range | Purpose |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 9763–9900 | §07.5 Statutory configuration |
| " | 17301–17905 | §10.5 Compliance module — the regulatory core |
| `BUSINESS_RULES_CATALOGUE.md` | 291–337 | `BR-CMP-001`…`BR-CMP-713` index |
| " | 816–846 | `BR-STAT-001`…`BR-STAT-702` index |
| " | 3901–4390 | BR-STAT rule bodies |
| " | 7711–7810+ | BR-CMP rule bodies |
| " | 13321–13462 | §4 Rules that are money-critical (123 rules) |

Doc sections 3186–3302 of the CPBTD (named in the task as "DRA / PCC compliance
tracking") were located at a different offset in the shipped file; the equivalent
material was read at 17301–17905 and cross-checked against
`BUSINESS_RULES_CATALOGUE.md:291–337`.

### 0.2 Source files read in full

| File | Lines | Read |
|---|---|---|
| `app/Services/StatutoryConfig.php` | 589 | complete |
| `app/Http/Controllers/StatutoryConfigController.php` | 476 | complete |
| `app/Http/Controllers/StatutoryController.php` | 173 | complete |
| `app/Http/Controllers/ComplianceController.php` | 661 | complete |
| `app/Http/Controllers/CodeOfConductController.php` | 183 | complete |
| `app/Http/Controllers/OffrollAgentController.php` | 557 | complete |
| `app/Console/Commands/ScanCompliance.php` | 32 | complete |
| `app/Http/Controllers/SettingsController.php` | 205 | complete |
| `app/Services/EffectiveDated.php` | 133 | complete |
| `app/Services/PayrollLock.php` | 98 | complete |
| `database/migrations/2026_07_26_000500_create_statutory_configs.php` | 37 | complete |
| `database/migrations/2026_07_22_000000_add_dra_pcc_declared.php` | 35 | complete |
| `database/migrations/2026_08_27_000100_widen_employee_dra_pcc_status.php` | 89 | complete |
| `tests/Unit/StatutoryConfigTest.php` | 127 | complete |
| `tests/manual/check_pt.php` | 89 | complete |
| `tests/manual/check_statutory_config.php` | 142 | complete |
| `tests/manual/pt_extract.php` | 155 | complete |
| `resources/views/statutory-pdf.blade.php` | 53 | complete |
| `resources/views/agent-audit-pdf.blade.php` | 40 | complete |
| `resources/views/agent-audit-bulk-pdf.blade.php` | — | **absent from this copy** |

### 0.3 Source files read in the relevant slices (leaked statutory logic)

| File | Lines read | What is there |
|---|---|---|
| `app/Http/Controllers/AppDataController.php` (3090) | 245–270, 383–420, 1170–1350, 1532–1560, 1600–1830, 1845–2000, 2002–2130, 2133–2340, 2900–2950 | **the entire statutory engine** — PT slabs, PF/ESI/EPS/EDLI, TDS, challan PDFs, employer cost |
| `app/Http/Controllers/AppController.php` (12533) | 11158–11180, 12080–12245 | client-side duplicate of every statutory rate and of the TDS slab walk; Statutory Rate Settings modal |
| `app/Http/Controllers/PayrollGenController.php` (2845) | 1280–1340, 1560–1660, 1970–2040, 2075–2200 | statutory context assembly, `esi_lock`, OT divisor |
| `app/Http/Controllers/MasterController.php` | 168–345, 480–585, 740–935 | compliance registers, `min_wages`, BGV `next_due`, Code-on-Wages 50% check, a 4th DRA gate |
| `app/Http/Controllers/ReportController.php` | 110–180, 310–331 | minimum-wage check, wage register, compliance scorecard |
| `app/Http/Controllers/IncentiveController.php` | 118–215 | 194H TDS on incentives, DRA + points gates |
| `app/Http/Controllers/RequestController.php` | 1360–1440 | DRA gate + compliance-score gate on commission approval |
| `app/Http/Controllers/CalcGuideController.php` | 55–165 | statutory rates restated as **hard-coded prose** |
| `app/Http/Controllers/KbController.php` | 205–215 | statutory rates restated as **hard-coded prose** |
| `app/Services/UpdateClient.php`, `updater/updater.php`, `version.json`, `routes/web.php`, `routes/console.php`, `config/filesystems.php`, `app/Services/FeaturePermissions.php`, `database/migrations/2026_01_01_000100`, `…000300`, `…000500`, `…000600` | targeted | ship mechanism, routes, schedules, disks, register schemas |

---

## 1. Business capability inventory

### 1.1 Statutory rate configuration (two screens, two engines)

There are **two separate rate stores** with **different capabilities**, and the
one the customer actually uses is the weaker one.

| | **Statutory Rate Settings** (legacy, universal) | **Statutory Configuration** (F1, newer) |
|---|---|---|
| Store | `statutory_settings` — **one JSON blob per tenant** | `statutory_configs` — many effective-dated scoped rows |
| Screen | SPA modal, `AppController.php:12118+` | SPA screen, `AppController.php:3026+` |
| Endpoint | `/app/settings` + `/app/settings/save` | `/app/statutory-config*` |
| Effective dating | **none** | yes, on the payroll-period date |
| Scope | tenant only | branch > location > company > state > tenant |
| History | **none — overwritten in place** | yes, insert-only |
| Finalised-period guard | **none** | yes (`EffectiveDated::guardFinalisedPeriod`) |
| Audit trail | **none** | yes (`AuditTrail::log`) |
| Preview | none | yes (rupee impact on one month) |
| Reaches | *every* rate consumer in the system | **only** `computeSlip` / `computeSlipFromComponents` + 3 reports |
| Guard | `hasAnyRole(['super_admin','admin'])` (`SettingsController.php:137`) | `FeaturePermissions::guard('statutory.config')` → admin, hr_manager |

The consequence runs through this whole document: **all the governance was built
on the door nobody uses.**

### 1.2 Provident Fund

`AppDataController::statutory()` (`:2059-2092`) produces employee PF, employer
PF, the EPS/EPF split and EDLI. Employer figures are **not** payslip deductions —
they feed the ECR/challan PDF and the payslip's "employer cost" strip.
Probation/internship suppress PF entirely (`:2114-2117`).

### 1.3 ESI

Threshold test on **gross**, both shares rounded **up** to the next rupee
(`:2077-2078`). A half-year "contribution-period lock" keeps ESI running past the
ceiling until Apr–Sep / Oct–Mar ends (`esiPeriodLock()`, `:2011-2046`).

### 1.4 Professional Tax

State-aware monthly slab on gross. 16 built-in state tables + 10 PT-free states
compiled as PHP `private const` (`:1852-1871`). Maharashtra February uplift and a
Maharashtra female exemption are special-cased. Configuration can override or add
a state's slab (`pt_state_slabs`) or declare a state PT-free (`pt_free_states`).

### 1.5 TDS / income tax

New Regime only. Annual slabs + standard deduction + 87A rebate + cess, all
config-driven; marginal relief applied by *one* of the two callers. Separate flat
194H commission rate and a no-PAN higher rate. Outputs: a monthly payslip TDS
figure, a Form 24Q statement PDF, a 26Q commission-deductee register PDF, and a
**synthetic** quarterly filing history.

### 1.6 Labour Welfare Fund

A flat rupee amount deducted every month when enabled. **No state variation, no
periodicity, no employer share, and no UI to switch it on.**

### 1.7 Gratuity, bonus, minimum wages

- **Gratuity** — a provision *report* only (`StatutoryController::report('gratuity')`),
  plus a 4.81%-of-Basic accrual line on the payslip employer-cost strip. Never paid,
  never stored, no ceiling.
- **Bonus** — a Payment-of-Bonus-Act *register* report only. Never paid by payroll.
- **Minimum wages** — a free-form `min_wages` master (state/zone/category/
  monthly_min/effective_from) and one report that **ignores every one of those
  columns**.

### 1.8 Statutory reports and returns

PF ECR/challan, ESIC challan, Form 24Q, 26Q commission-deductee register — all
PDFs computed **live from employee CTC**, not from the payroll that was actually
run. Plus gratuity / PT / bonus JSON reports and a wage register (Form B).

### 1.9 Regulatory compliance module (collections / recovery agents)

Tracks, per employee: DRA (IIBF) certification, PCC (police clearance), BGV
(background verification + periodic re-verification), Code of Conduct
acknowledgement, bank/portfolio authorisation, borrower complaints, references/
guarantors, NDA. Produces a daily expiry-alert digest, an in-app alert list, a
0–100 compliance score, and a per-agent "RBI Recovery-Agent Compliance File" PDF.

### 1.10 Money-point gates

Three configurable gates fire when money is approved:
`dra_gate` (off | warn | block), `points_gate_min`, `incentive_min_compliance`.
They apply to incentive commit, commission approval and off-roll earning approval.

### 1.11 Off-roll agents

A parallel population (`offroll_agents`) with its own KYC document slots, an
earnings ledger with approve/reject, and a public token-secured live-earnings
page. **Outside the compliance module entirely; DRA/PCC are free text.**

---

## 2. Data model

### 2.1 Tables

| Table | Provenance | Notes |
|---|---|---|
| `statutory_settings` | **RUNTIME** — `SettingsController::ensureTable()` `:77-88`. **No migration exists.** | `id, tenant_id, value LONGTEXT (JSON), timestamps`. The single most important configuration table in the product is created by application code on first read. |
| `statutory_configs` | migration `2026_07_26_000500_create_statutory_configs.php` **AND** runtime `StatutoryConfig::ensureSchema()` `:76-93` | Dual provenance; the two definitions agree. |
| `employees` (statutory cols) | migration `2026_01_01_000100_create_employees_table.php:133-142` | `pf_applicable` bool, `esi_applicable` enum(auto,yes,no), `pt_state`, `uan`, `pan`, `dra_status`, `dra_expiry`, `pcc_status`, `pcc_expiry`, `pcc_deadline` |
| `employees.dra_declared` / `pcc_declared` | migration `2026_07_22_000000_add_dra_pcc_declared.php` — **also** self-healed at runtime by `AppDataController::ensureEmployeeColumns()` | `varchar(8)`: 'Yes' \| 'No' \| null |
| `employees.dra_status` / `pcc_status` widening | migration `2026_08_27_000100_widen_employee_dra_pcc_status.php` | ENUM → VARCHAR(20), values lower-cased, `'overdue'` rolled back to `'pending'`; also back-fills `dra_expiry`, `pcc_expiry`, `pcc_deadline` if absent |
| `payslips` | migration `2026_01_01_000300_create_attendances_table.php:53-70` | `earnings` JSON, `deductions` JSON, `gross`, `total_ded`, `net`. **No rate snapshot of any kind.** `calc_note` added at RUNTIME by `PayrollGenController.php:2553-2560`. |
| `dra_certs` | **RUNTIME ONLY** — `MasterController::ensureCols()` `:496-577` from the def at `:314-320`. **No migration.** | `employee_id, employee, company_name, cert_no, institute, track, issue_date, expiry, status`. Every column nullable, **unindexed**. |
| `bgv` | migration `2026_01_01_000500_create_loans_table.php:68-77` — **but `next_due`, `verified_on`, `revalidate_months`, `chk_*` are RUNTIME** (`MasterController.php:196`) | See DEFECT-F-14. |
| `agent_authorizations` | migration `2026_01_01_000500:28-38` | `employee_id, bank, portfolio, auth_no, valid_to, status` |
| `complaints` | migration `2026_01_01_000500:53-64` | `employee_id, source, channel, severity, description, status, date` |
| `employee_references` | migration `2026_01_01_000100:168-182` | |
| `code_of_conduct_ack` | **TWO CONFLICTING DEFINITIONS.** Migration `2026_01_01_000600_create_salary_runs_table.php:94-101` (`tenant_id, employee_id NOT NULL, acknowledged, ack_date`) vs RUNTIME `CodeOfConductController::ensure()` `:24-70` (`tenant_id, user_id, employee, acknowledged, ack_date`). The runtime path patches the migration's table by adding `user_id`/`employee` and issuing `ALTER TABLE … MODIFY employee_id … NULL` (MySQL only). | See DEFECT-F-15. |
| `min_wages` | **RUNTIME** — `MasterController` def `:327-332` | `state, zone, category, monthly_min, effective_from, status` |
| `tds_returns` | **RUNTIME** — def `:174-183` | `company_name, quarter, deductees, tax_deducted, deposited, due_date, status` — a manual filing tracker, unconnected to the synthetic 24Q history |
| `overtime`, `policies`, `posh_complaints`, `helpdesk`, `documents`, `escalations` | RUNTIME (`ensureCols`) except `escalations` (migration) | |
| `offroll_agents` | **TWO DEFINITIONS.** Migration `2026_01_01_000500:14-26` (`rate decimal(12,2)`) vs RUNTIME `OffrollAgentController::ensure()` `:32-65` (`rate decimal(14,2)` + 12 more columns) | |
| `agent_earnings` | **RUNTIME ONLY** — `OffrollAgentController::ensureEarnings()` `:73-95` | `agent_id, entry_date, kind, qty, base_amount, amount, status, created_by, decided_by, decided_at, remarks` |
| `activity_logs` | migration `2026_05_30_025626` | receives `dra_override`, `incentive_dra_invalid`, `incentive_low_compliance` |
| `kb_topics` | migration `2026_01_01_000900` | supplies Code-of-Conduct text, **queried with no tenant filter** |

### 2.2 Runtime schema creation — critical finding

**Seven** tables in this area exist only because application code creates them:
`statutory_settings`, `dra_certs`, `min_wages`, `tds_returns`, `agent_earnings`,
`policies`, `posh_complaints`; plus runtime *columns* on `bgv`,
`code_of_conduct_ack`, `offroll_agents`, `payslips` and `employees`.

Consequences, all confirmed in source:

1. **Nothing is indexed.** `MasterController::ensureCols()` `:554-577` creates
   every column `nullable()` with **no index**. `dra_certs.employee_id`,
   `dra_certs.expiry`, `bgv.next_due`, `code_of_conduct_ack.user_id` and
   `agent_earnings.agent_id` are unindexed on any runtime-built install.
2. **No foreign keys, no NOT NULL, no defaults, no uniqueness** — a `dra_certs`
   row can exist with a null `employee_id` and a null `status`.
3. **Schema drifts per install**, so `Schema::hasColumn()` guards are needed
   everywhere, and behaviour differs by install age
   (`ComplianceController.php:47-49`, `:127`, `:585`).
4. **`migrate:fresh` on a clean database produces a DIFFERENT schema from an
   upgraded install** — the migration `bgv` has no `next_due`, so BGV
   re-verification alerts silently never fire until someone opens the BGV master
   screen once.

---

## 3. Business rules register

Legend: **HC** = hard-coded in PHP; **CFG-blob** = editable in `statutory_settings`;
**CFG-scoped** = editable, effective-dated and scoped via `statutory_configs`;
**Dead** = stored but never read.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want it different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-STAT-001 | PF employee = `pf_rate`% of min(Basic+DA, `pf_wage_cap`) | Basic, DA, rates | always (except probation) | deduct | tenant + scope | `AppDataController.php:2064-2069` | N | CFG-blob + CFG-scoped | Y |
| BR-STAT-002a | Employer PF = same rate on the same base | as above | always | employer cost | tenant | `:2070` | N | CFG-blob | Y (employer may elect to pay on full wages) |
| BR-STAT-002b | EPS = min(8.33% of min(pfBase, **15000**), **1250**) | pfBase | always | employer split | — | `:2067`, `:2071` | **Y — 8.33, 15000, 1250 all literals** | **NO** | Y |
| BR-STAT-002c | EPF employer = employer PF − EPS | | | | | `:2072` | N | — | N |
| BR-STAT-002d | EDLI = **0.5%** of min(pfBase, **15000**) | | | employer cost | | `:2073`; duplicated `:1542` | **Y** | **NO** | Y |
| NEW-F-01 | EPF administrative charges (A/c 2, and A/c 22) | | | | | **absent** | — | **NOT IMPLEMENTED** | Y |
| NEW-F-02 | VPF (voluntary PF above the statutory rate) | | | | | **absent** | — | **NOT IMPLEMENTED** | Y |
| BR-STAT-003 | Probation/internship ⇒ PF = PT = TDS = 0; ESI, LWF, Conveyance still apply | `employment_stage` | stage ∈ {probation, internship} (lowercased, trimmed) | zero three deductions | all employees | `:2114-2117`, `:2284-2287` | **Y — the two stage names are literals** | **NO** | Y |
| BR-STAT-004 | ESI applies on the FULL gross when gross ≤ `esi_threshold` and gross > 0 | gross | `0 < gross ≤ threshold` | deduct both shares | tenant + scope | `:2075-2078` | N | CFG-blob + CFG-scoped | Y |
| BR-STAT-004b | ESI both shares rounded **UP** to the whole rupee | | | `ceil()` | | `:2077-2078` | **Y** | **NO** | Y |
| BR-STAT-005 | ESI contribution-period lock: Apr–Sep / Oct–Mar; once contributing, keep contributing to period end | payslip history | prior slip in period has a deduction key containing "esi" with value > 0 | force ESI on | all | `:2011-2046`; caller `PayrollGenController.php:1306-1308` | **Y — period boundaries (4,9,10) are literals; the lookup is a substring match on the deduction NAME** | **NO** | Y |
| BR-STAT-007 | PT = state-wise monthly slab on gross, from `employees.pt_state` | gross, pt_state, month, gender | state matched by `str_contains` | first matching band's amount | per employee | `ptForGross()` `:1925-2000` | slabs HC as `private const` `:1854-1871`, overridable | CFG-scoped (`pt_state_slabs`) | Y |
| BR-STAT-008 | Maharashtra February uplift to **₹300** when the slab amount ≥ **200** | state, month | `key==='maharashtra' && amt>=200 && month ends '-02'` | set 300 | MH only | `ptWalkSlab()` `:1911-1913` | **Y — 'maharashtra', 200, 300, '02' are all literals** | **NO** | Y |
| BR-STAT-009 | Maharashtra female PT exemption up to `pt_female_exempt_upto` | gender, state, gross | gender ∈ {female,f,woman,women} AND state contains 'maharashtra' AND gross ≤ ceiling | PT = 0 | MH only | `:1933-1941` | **state name and gender token list are literals**; toggle + ceiling are config | partly CFG-blob | Y |
| BR-STAT-010 | An explicit `pt_state_slabs` entry outranks the built-in PT-free list | config | state key is a substring of pt_state | use the configured slab | | `:1959-1963` before `:1965-1978` | N | CFG-scoped | N |
| NEW-F-03 | 10 PT-free states are compiled in | state | `str_contains(pt_state, freeName)` | PT = 0 | | `PT_FREE_STATES` `:1852` | **Y** | additive only via `pt_free_states` (**cannot remove a built-in**) | Y |
| NEW-F-04 | Unknown/blank state ⇒ the hard-coded **Telangana-shaped** fallback slab (0 / 150 / 200 at 15000 / 20000) | gross | `pt_state` empty or unmatched | fallback slab | | `:1986-1999` | **Y — `$r['pt_slabs']` can never be populated (not a `defaults()` key, so the whitelist strips it)** | **NO** | Y |
| BR-STAT-011 | TDS = New Regime slabs on (annual base − `std_deduction`), 87A to nil, + cess | annual CTC/wage gross | | monthly TDS | tenant | `salaryTdsMonthly()` `:1824-1843`, `newRegimeTax()` `:1752-1766` | N | CFG-blob (slabs **only** via the legacy screen) | Y |
| BR-STAT-012 | 87A is a **hard cliff** inside `newRegimeTax`; marginal relief is applied only by `salaryTdsMonthly` | taxable | taxable > rebate | cap tax at (taxable−rebate)×(1+cess) | | relief `:1836-1840`; cliff `:1755` | N | — | — (defect) |
| BR-STAT-013 | Gratuity provision = (15 ÷ **26**) × monthly Basic × floor(years); eligible at **5+** years | Basic, DOJ | `years >= 5` | report only | all | `StatutoryController.php:55-57` | **Y — 15, 26, 5 are literals; no ₹20,00,000 ceiling; no 15/30 non-covered variant** | **NO** | Y |
| NEW-F-05 | Gratuity accrual on the payslip employer strip = **4.81%** of Basic | Basic | always | display | | `AppDataController.php:1543` | **Y** | **NO** | Y |
| BR-STAT-014 | Bonus register: eligible when Basic ≤ **21000**; bonus wage = min(Basic, **7000**); rate clamped **8.33–20** | Basic, DOJ, `bonus_pct` | | report only | all | `StatutoryController.php:119-152` | **Y — 21000, 7000, 8.33, 20 literals**; only the rate is config | rate CFG-blob | Y |
| BR-STAT-014b | Bonus FY months for a joiner = `ceil(diffInDays(doj, fyStart+1y) / 30.44)`, clamped 0–12 | DOJ | `doj > fyStart` | prorate | | `:142-151` | **Y — 30.44 literal** | **NO** | Y |
| BR-STAT-015 | Statutory bonus is **never paid** by payroll — the register is informational | | | | | no writer exists | — | NOT IMPLEMENTED | Y |
| BR-STAT-016 | Commission TDS 194H = `comm_tds_rate`%; no PAN ⇒ max(comm, `no_pan_tds_rate`) | CTC, salary_type, comm_pct, PAN | `commBase > 0` | deduct / report | tenant | `:1655-1656`, `:1712-1715`, `IncentiveController.php:124-131` | rate config; **`comm_pct` fallback of 30% is a literal** (`:1708`, `:1730`) | CFG-blob | Y |
| BR-STAT-017 | LWF = flat `lwf_employee` rupees per month when `lwf_enabled` | rates | enabled | deduct | tenant | `:2119`, `:2301-2304` | N | **Reachable ONLY via the raw `/app/statutory-config/save` API** — no UI, not in `SettingsController::save()`'s validated key list | Y |
| BR-STAT-018 | Conveyance is a **DEDUCTION** computed exactly like PF: `conveyance_rate`% of min(Basic+DA, pf cap) | | enabled AND rate > 0 | deduct | tenant + scope | `:2082-2083` | N | CFG-blob + CFG-scoped | Y (the name says allowance; the code deducts) |
| BR-STAT-019 | Statutory-config saves are INSERT-only; history is the table | | | | | `StatutoryConfig.php:394-405` | N | — | N |
| BR-STAT-020 | Payload keys whitelisted to `array_keys(defaults()) + ['pt_state_slabs','pt_free_states']` | | | drop unknown keys | | `:368-372` | N | — | N |
| BR-STAT-021 | PT bands must climb with the open band (upto ≤ 0) last; any negative value rejects the whole save | | | sort / reject | | `cleanSlabs()` `:422-458` | N | — | N |
| BR-STAT-022 | With zero override rows the engine is byte-identical to pre-F1 | | `!hasAnyRows()` | return input untouched | | `:121-123`; tested `StatutoryConfigTest.php:60-64` | N | — | N |
| BR-STAT-023 | A statutory-config failure must never break payroll — catch, log, return input | | any Throwable | fail soft | | `:140-144` | N | — | N |
| BR-STAT-701 | Narrowest scope wins: branch > location > company > state > tenant | ctx | | merge broadest→narrowest | | `SCOPES` `:45`, `mergedOverrides()` `:196-206` | N | — | N |
| BR-STAT-701b | Resolution is per **(scope, kind)**, so a PT row cannot shadow a PF row | | | | | `:189` | N | — | N |
| BR-STAT-701c | State/location matching is **loose substring** both ways | | `a===b \|\| contains(a,b) \|\| contains(b,a)` | | | `looseMatch()` `:231-240` | N | — | Y — "Andhra" matches "Andhra Pradesh" **and** any state whose name contains it |
| BR-STAT-702 | PT slabs entered as `[upto, amount]`; `upto = 0` means "and above" | | | | | `ptWalkSlab()` `:1897-1916` | N | — | N |
| BR-STAT-201 | Statutory PDFs (PF/ESI/24Q/26Q) are computed from **CTC**, not from the payroll that ran | CTC | | render | all non-deleted employees, no status/company/month filter | `statutoryPdf()` `:1639-1749` | — | **NO** | Y |
| BR-STAT-202 | 24Q filing history is synthetic: total ÷ 4 per quarter, current quarter `Pending`, **all six prior quarters hard-coded `Filed`** | | | | | `tdsReturnsHistory()` `:1769-1813` | **Y** | **NO** | Y |
| NEW-F-06 | Quarterly 24Q due dates: Q1→31 Jul, Q2→31 Oct, Q3→31 Jan, Q4→31 May | quarter start month | | display | | `:1794-1799` | **Y — the only statutory due dates anywhere in the codebase** | **NO** | Y |
| NEW-F-07 | Minimum-wage check compares CTC/12 against **`MAX(monthly_min)` across every `min_wages` row for the tenant** — state, zone, category, `effective_from` and `status` are all ignored | CTC | `min > 0` | report shortfall | all employees | `ReportController.php:136-155` | Y (the aggregate) | **NO** | Y |
| NEW-F-08 | Code-on-Wages advisory: Basic+DA < **50%** of wage gross ⇒ a warning string on saving a salary structure; never blocks | components | `pct < 50` | warn | per structure | `MasterController.php:865-886` | **Y — 50 literal; test CTC of 1200000 is a literal** | **NO** | Y |
| NEW-F-09 | Overtime hourly rate = CTC / 12 / **26** / **8**; default multiplier **2** | CTC, hours | amount empty | compute | | `MasterController.php:757-762`; also `PayrollGenController.php:2165` | **Y** | **NO** | Y |
| NEW-F-10 | `employees.pf_applicable` and `employees.esi_applicable` are captured, imported, exported and **printed on the payslip** — and never read by the engine | | | **nothing** | | written `AppDataController.php:2940-2944`, displayed `:389-398`, printed `payslip-pdf.blade.php:66`; **zero readers in `statutory()` / `computeSlip*` / `PayrollGenController`** | — | **Dead** | Y — this is the only per-employee statutory-applicability control in the product |
| BR-CMP-001 | DRA validity, two-source cascade (`dra_certs` then legacy `employees.dra_*`) | | | | | see §6 DEFECT-F-08 — **five** divergent implementations | Y | **NO** | Y |
| BR-CMP-002 | DRA gate at money points: `off` \| `warn` \| `block` | `dra_gate` | approve incentive/commission/off-roll earning | skip / warn+audit / refuse | tenant | `IncentiveController.php:184-196`, `RequestController.php:1371-1392`, `OffrollAgentController.php:249-262` | N | CFG-blob | Y (per-portfolio / per-bank gating) |
| BR-CMP-003 | Compliance score = **25** DRA + **20** PCC + **20** BGV + **15** NDA + **20** no-open-complaints, capped 0–100 | | | integer 0–100 | per employee | `scoreFor()` `:289-330` | **Y — all five weights and all five conditions are literals** | **NO** | Y |
| BR-CMP-003b | If the `complaints` table does not exist, **+20 is awarded anyway** | | | | | `:325-327` | Y | **NO** | Y |
| BR-CMP-004 | Incentive eligibility threshold = `incentive_min_compliance`, default **60**; **never blocks**, only writes `incentive_low_compliance` to `activity_logs` | | `score < min` | audit only | | `RequestController.php:1418-1436`, `ReportController.php:310-331` | default 60 in `defaults()` `:56` but **absent from `SettingsController::save()`'s validated keys** ⇒ unsettable through any UI | **NO (UI); yes via the raw F1 API — but that value is read from the flat blob, so an F1 override row would never be read**| Y |
| BR-CMP-005 | Alert window 30 days; buckets expired (< 0), critical (≤ 7), soon (≤ 30) | expiry dates | `days <= window` | bucket | tenant | `WINDOW_DAYS=30` `:31`; `:76-81` | **Y — 30, 7 and the three bucket names are literals** | screen only, per-request `?days=`, **not persisted, no upper bound** | Y |
| BR-CMP-006 | Four alert sources: `employees.dra_expiry`, `employees.pcc_expiry`, `dra_certs.expiry`, `bgv.next_due` | | | | | `:102-143` | **Y — the item type list is a closed set of literals** | **NO** | Y |
| BR-CMP-007 | BGV next due = `verified_on` + `revalidate_months` | | both set | compute on save | | `MasterController.php:745-753` | N | per-row | N |
| BR-CMP-008 | Daily digest at **07:30**, one email per HR/Admin recipient per tenant, top **25** rows | | rows exist AND recipients resolve | queue mail | all tenants | `routes/console.php:143-146`; `notify()` `:188-245` | **Y — 07:30, 25, the three summary labels** | **NO** | Y |
| BR-CMP-009 | Digest recipients fail **closed** when role pivots resolve nothing | | | send to nobody | | `:269-272` | N | — | N |
| BR-CMP-011 | The agent audit checklist: 12–13 fixed items in 6 fixed groups | | | ok / warn / bad | per agent | `agentAuditData()` `:556-629` | **Y — every item, every threshold, every group name** | **NO** | Y |
| BR-CMP-011b | "Disciplinary actions" is the literal string `'0'` with state `'ok'`, no data source | | always | print | | `:629` | **Y** | **NO** | Y |
| BR-CMP-012 | Bulk audit capped at **150** agents | | | `array_slice` | | `:414` | **Y** | **NO** | Y |
| BR-CMP-013 | Authorisation validity tests `valid_to` only — `agent_authorizations.status` (`active`\|`expired`\|`revoked`) is never read | | `valid_to` empty or ≥ today | ok | | `:619` | Y | **NO** | Y — a **revoked** authorisation with a future `valid_to` reads Compliant |
| BR-CMP-014 | References minimum **2** for field staff (1 = warn, 0 = bad) | | | | | `:592-594` | **Y** | **NO** | Y |
| BR-CMP-015 | Borrower-complaint window = last **90** days | | | count | | `:623` | **Y** | **NO** | Y |
| BR-CMP-016 | One CoC acknowledgement row per user, **overwritten** on re-acknowledgement | | | upsert | | `:150-176` | N | — | Y — no version, no history |
| BR-CMP-017 | CoC content = `kb_topics WHERE category = 'Code of Conduct'`, **no tenant filter** | | | render | **global** | `:101-108` | **Y — the category string** | KB rows only | Y |
| BR-CMP-029 | Off-roll agents are outside compliance scanning, scoring and audit | | | | | no code path | — | NOT IMPLEMENTED | Y |
| NEW-F-11 | Off-roll DRA gate reacts only to a **blank** `offroll_agents.dra` free-text field — no expiry, no verification, no certificate | | field is `''` | block/warn | | `OffrollAgentController.php:249-262` | Y | **NO** | Y |
| NEW-F-12 | Off-roll agent earnings are paid **with no TDS and no 194H treatment** | | | | | `agent_earnings` has no tax column; `computeEarning()` `:102-119` | — | NOT IMPLEMENTED | Y |
| NEW-F-13 | The "tamper-evident hash" on the audit PDF = first 16 hex of `sha256(ref \| employee_id \| today)` | | | print | | `:644` | Y | **NO** | Y — it covers none of the findings |
| NEW-F-14 | `employees.pcc_deadline` is selected by the scan and **never evaluated** | | | nothing | | selected `:57`, no reader | — | **Dead** | Y |
| NEW-F-15 | `employees.dra_declared` / `pcc_declared` self-declarations are **never read** by the compliance module | | | nothing | | migration `2026_07_22_000000`; no reader | — | **Dead** | Y |
| NEW-F-16 | `dra_certs.track` (`grad_50` \| `ug_100`, the IIBF 50/100-hour rule) is stored, never validated, never cross-checked against `training_records` | | | nothing | | `MasterController.php:315-320` | — | **Dead** | Y |
| NEW-F-17 | `pt_amount` (labelled "Professional Tax / month (₹)" in **both** rate screens) is written, validated, previewed — and **read by nothing** | | | nothing | | `SettingsController.php:35,144`; `StatutoryConfigController.php:60`; `AppController.php:12153` | — | **Dead** | Y |

**Counts: 63 rules recorded (36 confirmed against the catalogue's BR-STAT-*/BR-CMP-* IDs; 17 new NEW-F-* IDs the catalogue missed or under-stated).**

---

## 4. Calculations and formulas

All line references are `app/Http/Controllers/AppDataController.php` unless stated.

### 4.1 The base salary split (no components defined) — `computeSlip()` `:2102-2105`
```php
$gross   = round($ctc / 12, 2);
$basic   = round($gross * 0.5, 2);      // 50% — literal
$hra     = round($basic * 0.4, 2);      // 40% of basic — literal
$special = round($gross - $basic - $hra, 2);
```
Worked: CTC ₹6,00,000 → gross 50,000.00 · basic 25,000.00 · HRA 10,000.00 ·
special 15,000.00.

### 4.2 Provident Fund — `statutory()` `:2064-2073`
```php
$pfCap      = (float) ($r['pf_wage_cap'] ?? 15000);
$pfRate     = (float) ($r['pf_rate'] ?? 12);
$pfBase     = min(max($pfWage, 0.0), $pfCap);
$epsBase    = min($pfBase, 15000.0);              // literal 15000
$pfEmployee = round($pfBase * $pfRate / 100, 2);
$pfEmployer = round($pfBase * $pfRate / 100, 2);
$eps        = min(round($epsBase * 8.33 / 100, 2), 1250.0);   // literals 8.33, 1250
$epfEmployer= round($pfEmployer - $eps, 2);
$edli       = round($epsBase * 0.5 / 100, 2);                 // literal 0.5
```
- **Basis** — `$pfWage` is `basic` in `computeSlip` (`:2107`) and `basic + da` in
  `computeSlipFromComponents` (`:2273`). DA is detected by **name**:
  `str_contains($name,'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $name)`
  (`:2269`).
- **Rounding** — 2 dp, half-up.
- Worked, Basic 25,000, defaults: pfBase 15,000 · employee 1,800.00 ·
  employer 1,800.00 · EPS min(1,249.50, 1250) = **1,249.50** · EPF employer
  550.50 · EDLI 75.00.
- **Not present:** admin charges (A/c 2 / A/c 22), VPF, the "restrict-to-ceiling
  vs pay-on-full-wages" employer election, international-worker rules.

### 4.3 ESI — `statutory()` `:2075-2078`
```php
$esiThr      = (float) ($r['esi_threshold'] ?? 21000);
$esiEligible = ($gross > 0 && $gross <= $esiThr) || (!empty($ctx['esi_lock']) && $gross > 0);
$esiEmployee = $esiEligible ? (float) ceil($gross * $r['esi_employee_rate'] / 100) : 0.0;
$esiEmployer = $esiEligible ? (float) ceil($gross * $r['esi_employer_rate'] / 100) : 0.0;
```
- **Basis** — `$gross` (which in `computeSlipFromComponents` is `wageGross`,
  i.e. gross **minus reimbursements**, `:2273`).
- **Rounding** — `ceil()` to the whole rupee, on **both** shares.
- Worked, gross 20,000: employee ceil(150.00)=**150** · employer ceil(650.00)=**650**.
  Gross 20,050: employee ceil(150.375)=**151** · employer ceil(651.625)=**652**.
- **The two challan PDFs use `round(...,2)` instead** (`:1688-1689`) — see
  DEFECT-F-05.

### 4.4 ESI contribution-period lock — `esiPeriodLock()` `:2011-2046`
```php
if ($m >= 4 && $m <= 9)  $periodStart = "$y-04";
elseif ($m >= 10)        $periodStart = "$y-10";
else                     $periodStart = ($y-1)."-10";
if ($month <= $periodStart) return false;      // first month → fresh determination
// scan payslips in [periodStart, month) — up to 24 rows, newest first
foreach ($ded as $k => $v)
    if (stripos($k, 'esi') !== false && (float)$v > 0) return true;
```
Called only when the plain rule would drop ESI (`PayrollGenController.php:1306`).
**No tenant filter on the `payslips` query** (`:2029`) — safe only because
`employee_id` is a global PK. The lookup is a **substring match on the deduction
line's display name**: a tenant that renames "ESI" to "State Insurance" silently
loses the lock.

### 4.5 Professional Tax — `ptForGross()` `:1925-2000` + `ptWalkSlab()` `:1897-1916`

Resolution order, exactly as coded:
1. **MH female exemption** (before any slab): if `pt_female_exempt` on (default) AND
   gender ∈ `['female','f','woman','women']` AND `str_contains(state,'maharashtra')`
   AND `gross <= pt_female_exempt_upto` → **0.00**.
2. **Configured `pt_state_slabs`**, first key that is a substring of the state.
3. **PT-free list** (built-in 10 + configured additions), first substring match → **0.00**.
4. **Built-in `PT_STATE_SLABS`**, first key that is a substring of the state.
5. **Fallback** `$r['pt_slabs']` — *unreachable*, so always the hard-coded
   `[15000→0, 20000→150, ∞→200]`.

Band walk: first band where `upto <= 0 || gross <= upto` wins. Then the MH
February rule: `if key==='maharashtra' && amt>=200 && substr($month,5,2)==='02' → 300`.
Rounded to 2 dp.

Worked: gross 20,000, Maharashtra, male, month 2026-02 → band `[0,200]` → 200 ≥
200 and month is 02 → **₹300.00**. Same employee in 2026-03 → **₹200.00**.

**Annual PT** in the report (`StatutoryController.php:95-98`) special-cases
Maharashtra to `200 × 11 + 300 = 2,500`, but computes `$mpt × 12` for every other
state — so Tamil Nadu's ₹208 monthly equivalent reports **₹2,496/yr**, and Kerala's
₹208 top band likewise, against half-yearly statutory bases.

### 4.6 New Regime income tax — `newRegimeTax()` `:1752-1766`
```php
if ($taxable <= (float) $r['rebate_87a_limit']) return 0.0;   // HARD CLIFF
$tax = 0; $prev = 0;
foreach ($r['tds_slabs'] as $slab) {
    $upto = (float)$slab['upto']; $rate = (float)$slab['rate'] / 100;
    $cap  = $upto > 0 ? $upto : PHP_INT_MAX;
    if ($taxable > $prev) { $tax += (min($taxable,$cap) - $prev) * $rate; $prev = $cap; }
    if ($upto <= 0) break;
}
return round($tax * (1 + (float) $r['cess_rate'] / 100), 2);
```
Marginal relief exists **only** in `salaryTdsMonthly()` `:1836-1840`:
`$tax = min($tax, ($taxable - $rebate) * (1 + cess/100));`

Worked at taxable ₹12,00,001 with shipped defaults:
- `newRegimeTax` → slab tax 0 + 20,000 + 40,000 = 60,000 → × 1.04 = **₹62,400.00**
- `salaryTdsMonthly` → relief caps it at 1 × 1.04 = **₹1.04**, monthly **₹0.09**

The payslip deducts ₹0.09/month. The Form 24Q PDF (`:1732`) and the quarterly
history (`:1779`) call `newRegimeTax` **directly** and report ₹62,400. See
DEFECT-F-06.

### 4.7 Monthly salary TDS — `salaryTdsMonthly()` `:1824-1843`
```php
$taxable = max(0.0, $annualCtc - $std);
$tax     = newRegimeTax($taxable, $r);
if ($taxable > $rebate) $tax = min($tax, ($taxable - $rebate) * (1 + cess/100));
return round(max(0.0, $tax) / 12, 2);
```
**Basis** — `$annualCtc` = the full CTC in `computeSlip` (`:2111`) but
`wageGross × 12` in `computeSlipFromComponents` (`:2281`). Divisor is a flat 12
in every month, with no year-to-date true-up.

### 4.8 194H commission TDS — `:1712-1715`
```php
$commBase = $type==='Commission' ? $annual
          : ($type==='Salary + Commission' ? $annual * ((comm_pct ?: 30)/100) : 0);
$effRate  = $e->pan ? $commRate : max($commRate, $noPanRate);
$tds      = round($commBase * $effRate, 2);
```
The `?: 30` fallback means an employee flagged "Salary + Commission" with no
`comm_pct` is assumed **30% commission**.

### 4.9 Conveyance deduction — `:2082-2083`
`round(min(Basic+DA, pf_wage_cap) × conveyance_rate / 100, 2)`, only when
`conveyance_enabled` and rate > 0. Labelled "allowance" in both rate screens;
it is a **deduction**.

### 4.10 LWF — `:2119`, `:2301`
`$lwf = !empty($r['lwf_enabled']) ? (float)($r['lwf_employee'] ?? 0) : 0.0;`
A flat rupee amount every month. No state, no periodicity, no employer share.

### 4.11 Gratuity provision — `StatutoryController.php:55-57`
```php
$years = $e->doj ? Carbon::parse($e->doj)->diffInDays(now()) / 365.25 : 0;
$elig  = $years >= 5;
$grat  = $elig ? round((15 / 26) * $s['basic'] * floor($years)) : 0;
```
Rounded to whole rupees. Worked: Basic 25,000, 7.4 years →
(15/26) × 25,000 × 7 = **₹1,00,962**. No ₹20,00,000 ceiling; no 15/30 variant for
establishments outside the Act; `$s['basic']` is recomputed as 50% of gross,
ignoring `salary_components`.

### 4.12 Gratuity accrual on the payslip — `payslipEmployerCost()` `:1543`
`round($basic * 4.81 / 100, 2)` — a second, unrelated gratuity formula.

### 4.13 Statutory bonus — `StatutoryController.php:119-152`
```php
$pct    = max(8.33, min(20.0, $rates['bonus_pct'] > 0 ? $rates['bonus_pct'] : 8.33));
$elig   = $basic > 0 && $basic <= 21000;
$wage   = $elig ? min($basic, 7000.0) : 0.0;
$months = doj > fyStart ? clamp(ceil(diffInDays(doj, fyStart+1y)/30.44), 0, 12) : 12;
$bonus  = $elig ? round($wage * $months * $pct / 100) : 0.0;
```
Worked: Basic 15,000, full year, 8.33% → 7,000 × 12 × 0.0833 = **₹6,997**.
The note claims "use the state minimum wage if higher" — **the code never
consults `min_wages`**.

### 4.14 Compliance score — `scoreFor()` `:289-330`
`min(100, 25·draOk + 20·pccOk + 20·bgvOk + 15·ndaOk + 20·noOpenComplaints)`.
The `$tid` parameter is accepted and **never used** — all five sub-queries filter
on `employee_id` alone.

### 4.15 Expiry bucketing — `scan()` `:76-81`
```php
$days   = Carbon::today()->diffInDays($d->startOfDay(), false);   // signed
if ($days > $windowDays) return;
$bucket = $days < 0 ? 'expired' : ($days <= 7 ? 'critical' : 'soon');
```

### 4.16 Scoped-override merge — `StatutoryConfig::mergedOverrides()` `:157-209`
One query returns every in-force row for the tenant, oldest first. Each row is
filtered by its own scope predicate. `$winner[scope|kind] = $row` — oldest-first
means the newest row of each `(scope, kind)` pair wins. Payloads are then merged
in the order `tenant, state, company, location, branch`, so branch wins.
`pt_state_slabs` merges **per state key**; every other key replaces outright
(`mergeValues()` `:216-228`).

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Item | How | Effective-dated? | Scoped? | Audited? |
|---|---|---|---|---|
| `pf_wage_cap`, `pf_rate` | Statutory Rate Settings **and** Statutory Configuration | blob no / F1 yes | blob no / F1 yes | blob **no** / F1 yes |
| `esi_threshold`, `esi_employee_rate`, `esi_employer_rate` | both screens | as above | as above | as above |
| `pt_female_exempt`, `pt_female_exempt_upto` | both screens | as above | as above | as above |
| `std_deduction`, `rebate_87a_limit`, `cess_rate`, `no_pan_tds_rate` | both screens | as above | as above | as above |
| `comm_tds_rate` | both screens | as above | as above | as above |
| `bonus_pct`, `conveyance_enabled`, `conveyance_rate` | both screens | as above | as above | as above |
| **`tds_slabs`** (the New Regime slab table) | **legacy screen ONLY** | **no** | **no** | **no** |
| PT slab for one state (`pt_state_slabs`) | Statutory Configuration → slab editor | yes | yes | yes |
| Declare a further state PT-free (`pt_free_states`) | same | yes | yes | yes |
| `dra_gate`, `points_gate_min`, `incentive_payout_lag`, `late_email_enabled`, `sandwich_rule`, `lop_basis`, `weekly_off_day`, `sat_off_mode` | legacy screen | no | no | no |
| Alert window (per request, not persisted) | `GET /app/compliance-alerts?days=N` | — | — | — |
| BGV re-verification interval | per `bgv` row (`revalidate_months`) | — | per row | — |
| Code of Conduct **text** | KB articles, category `Code of Conduct` — **global, shared by every tenant** | no | **no** | no |
| `min_wages` rows | master screen | column exists, **never read** | column exists, **never read** | — |
| Compliance registers (`dra_certs`, `bgv`, `agent_authorizations`, `complaints`, `policies`, `overtime`, `tds_returns`) | master screens (generic CRUD) | — | — | — |

### 5.2 (b) What a customer CANNOT change without a code release — the SmartPRS 3 backlog

**Statutory arithmetic**
1. EPS rate **8.33%**, EPS cap **₹1,250**, EPS/EDLI wage ceiling **₹15,000**, EDLI **0.5%** (`:2067`, `:2071`, `:2073`).
2. EPF administrative charges — **do not exist**.
3. VPF / voluntary PF above the statutory rate — **does not exist**.
4. The employer's "restrict to ceiling vs pay on full wages" election — **does not exist**.
5. ESI **round-up** convention (`ceil`) — hard-coded, and inconsistent with the challan PDFs.
6. ESI contribution-period boundaries (months 4 and 10) — hard-coded (`:2019-2025`).
7. The ESI lock's **deduction-name substring** (`'esi'`) — a rename breaks it.
8. Maharashtra February uplift: state name, the ₹200 trigger, the ₹300 amount, the `'02'` month.
9. Maharashtra female exemption: state name and the gender token list.
10. The 10 built-in PT-free states — **additive config only; a built-in cannot be removed**.
11. The 16 built-in state PT slabs — overridable per state, but the *list of built-ins* is fixed.
12. The unknown-state PT fallback slab (`$r['pt_slabs']` is unreachable through the whitelist).
13. Gratuity: **15/26**, the **5-year** eligibility, the missing **₹20,00,000** ceiling, the missing 15/30 non-covered variant.
14. The second gratuity formula, **4.81%** of Basic, on the payslip.
15. Bonus: eligibility ceiling **₹21,000**, bonus-wage cap **₹7,000**, the 8.33–20 clamp, the **30.44**-day month proration.
16. Bonus never links to `min_wages` despite the report saying it should.
17. Probation/internship exemption keyed on the two literal strings `'probation'`, `'internship'`.
18. The 50% Basic / 40% HRA default split.
19. The 30% `comm_pct` fallback for "Salary + Commission".
20. Overtime divisor **26 days × 8 hours** and the default **2×** multiplier.
21. Code-on-Wages **50%** Basic+DA advisory threshold.
22. The 24Q quarterly due dates — **the only statutory due dates in the codebase**.
23. **No compliance calendar at all** — no PF ECR due date, no ESI contribution due date, no PT payment dates (state-specific), no TDS deposit date, no annual-return dates, no LWF remittance dates.

**Statutory applicability / jurisdiction**
24. `pf_applicable` and `esi_applicable` per employee are **inert** (NEW-F-10).
25. There is **no establishment / registration entity** — no PF code, no ESI code, no PT registration, no shops-and-establishments registration anywhere in the schema.
26. There is **no jurisdiction model**. See §7 answer to Q4.
27. LWF has no state dimension, no periodicity, no employer share, **and no UI**.
28. Any statutory head beyond the seven built in (PF, ESI, PT, TDS, LWF, bonus, gratuity) requires code.

**Rate governance**
29. The legacy rate blob has no effective dating, no history, no audit trail and no finalised-period guard — and it is what every consumer except `computeSlip*` reads.
30. `tds_slabs` cannot be effective-dated or scoped.
31. `incentive_min_compliance`, `data_retention_months`, `contact_window_start`, `contact_window_end`, `lwf_enabled`, `lwf_employee` are in `defaults()` but **absent from `SettingsController::save()`'s validated key list** — unsettable through any UI.
32. Payslips store **no rate snapshot** — a payslip cannot be explained or re-derived.

**Compliance module**
33. The compliance item type list (DRA / PCC / BGV) is a closed set of literals; a customer cannot add "Aadhaar re-KYC", "driving licence", "insurance", "vendor agreement expiry", "training certificate".
34. The audit checklist — all 13 items, 6 groups, every threshold.
35. The compliance score weights (25/20/20/15/20) and each component's pass condition.
36. The alert buckets (< 0 / ≤ 7 / ≤ 30) and the 30-day window (not persistable).
37. Digest time 07:30, top-25 truncation, recipient roles, one-email-per-recipient.
38. `agent_authorizations.status` is never consulted.
39. References minimum = 2; complaint window = 90 days; bulk cap = 150.
40. There is **no renewal workflow, no agent-facing notification, no evidence attachment, no re-acknowledgement trigger, and no working-status block** for a non-compliant agent.
41. Code of Conduct: no versioning, no history, no signature, no IP capture, and the text is global rather than per-tenant.
42. `policies.ack_required` exists and **nothing reads it**.
43. `dra_certs.track` (the IIBF 50/100-hour rule) is inert.
44. Off-roll agents are outside compliance entirely and their earnings carry no TDS.

---

## 6. Defects, conflicts and technical debt

| # | Type | What it is | Evidence | Business impact | Severity |
|---|---|---|---|---|---|
| **DEFECT-F-01** | DEFECT | **`pf_applicable` / `esi_applicable` are inert.** Both are captured on the employee form, accepted by the import template, exported, and **printed on the payslip** ("ESI: Applicable / Auto"). Neither is read by `statutory()`, `computeSlip()`, `computeSlipFromComponents()` or `PayrollGenController`. | writers `AppDataController.php:2940-2944`; display `:389-398`; payslip `payslip-pdf.blade.php:66,120`; **zero readers** (grep across `app/`) | An employer who marks an employee PF-exempt or ESI-not-applicable still has PF/ESI deducted. This is the only per-employee statutory-applicability control the product offers, and it does nothing. | **CRITICAL** |
| **DEFECT-F-02** | CONFLICT | **The governance is on the wrong door.** Effective dating, scoping, history, the finalised-period guard and the audit trail all live on `statutory_configs`. The Statutory Rate Settings modal writes `statutory_settings` with none of them — and that blob is what every consumer except `computeSlip*` reads. | `SettingsController::save()` `:135-204` (no `AuditTrail`, no `EffectiveDated`, no `PayrollLock`); vs `StatutoryConfigController::save()` `:147-207` | Change the PF rate today and **every past month you regenerate uses the new rate**, silently, with no record of who changed it or when. The F1 guard cannot prevent it. | **CRITICAL** |
| **DEFECT-F-03** | DEFECT | **No rate snapshot on the payslip.** `payslips` stores amounts only. | migration `2026_01_01_000300:53-70` | A payslip cannot be reproduced or defended. In a PF/ESI inspection or an employee dispute there is no record of which rate, cap, slab or PT band produced the figure. | **CRITICAL** |
| **DEFECT-F-04** | CONFLICT | **Statutory challan PDFs do not reflect the payroll that ran.** `statutoryPdf()` recomputes every employee from `ctc` with no `$ctx` — no `pt_state`, no month, no scoped overrides, no LOP, no components, no salary run. It selects **all non-deleted employees** with no `status` filter (resigned staff included), no company filter and no period filter, then labels the output `now()->format('F Y')`. | `:1639-1749`; `computeSlip((float)$e->ctc, $rates, $stage)` at `:1669`, `:1686` — third arg only, **no ctx** | The PF ECR and ESIC challans a customer downloads and files do not equal the PF and ESI actually deducted. | **CRITICAL** |
| **DEFECT-F-05** | CONFLICT | **ESI rounding disagrees between the payslip and the challan.** The engine uses `ceil()`; the ESIC challan PDF uses `round(...,2)`. | engine `:2077-2078` vs PDF `:1688-1689` | Every ESI challan is understated by up to ₹0.99 per employee per share against what was deducted; totals will not reconcile with ESIC. | **HIGH** |
| **DEFECT-F-06** | CONFLICT | **Payslip TDS and the Form 24Q statement disagree by up to ₹62,400.** `salaryTdsMonthly()` applies 87A marginal relief; `statutoryPdf('tds')` and `tdsReturnsHistory()` call `newRegimeTax()` directly and do not. | relief `:1836-1840`; raw calls `:1732`, `:1779` | An employee at taxable ₹12,00,001 has ₹0.09/month deducted while the 24Q return declares ₹62,400. | **CRITICAL** |
| **DEFECT-F-07** | DEFECT | **The 24Q filing history is fabricated.** Six quarters are emitted; the current one is `Pending` and **every prior quarter is hard-coded `Filed`** with `deposited = perQ`, regardless of whether anything was filed or deposited. Total tax is simply `annualTotal / 4` per quarter. | `tdsReturnsHistory()` `:1785-1810`, `$status = $i === 0 ? 'Pending' : 'Filed'` `:1800` | The product tells a customer their TDS returns are filed. They are not. This is a fabricated compliance record on a tax screen. | **CRITICAL** |
| **DEFECT-F-08** | CONFLICT | **Five divergent implementations of "is this agent's DRA valid?"** — (1) `draValid()` `:339-369` uses the **latest** `dra_certs` row and never falls back to legacy if any row exists; (2) `scoreFor()` `:293-299` uses **any** verified unexpired row and ignores legacy entirely; (3) `agentAuditData()` `:563-576` uses the latest row **and** falls back to legacy on failure; (4) `MasterController::draGateWarning()` `:899-912` uses **any** verified unexpired row, no legacy; (5) `OffrollAgentController` `:253` tests only whether a **free-text** field is blank. | as cited | The same agent can be blocked from an incentive, reported COMPLIANT on the RBI audit PDF, and scored 0/25 on the scorecard — simultaneously. | **CRITICAL** |
| **DEFECT-F-09** | DEFECT | **Fabricated data point on a regulatory report.** "Disciplinary actions" prints the literal `'0'` with state `'ok'` and no data source. | `:629` | A report presented as an RBI compliance file asserts a fact it has not checked. | **HIGH** |
| **DEFECT-F-10** | DEFECT | **The "tamper-evident hash" is not tamper-evident.** `substr(sha256($ref.'|'.$e->id.'|'.today), 0, 16)` — deterministic from three public values, covers none of the findings, chained to nothing. Two reports with different contents on the same day produce the same hash. The footer claims "hash-chained audit log"; the report neither writes to nor reads the real `activity_logs` chain, and generating an audit report is **not logged at all**. | `:644`; ComplianceController contains no `AuditTrail`/`Audit::` call | A false assurance printed on a regulatory document. | **HIGH** |
| **DEFECT-F-11** | DEFECT | **Code-of-Conduct check degenerates to "has anybody acknowledged?"** Both `when()` clauses in the user lookup are conditional. If the employee has no linked user and no email, `$userId` is null, the `when($userId,…)` filter is dropped, and the query becomes "does any acknowledgement exist in this tenant?" — true as soon as one person acknowledges. Worse: when `users.employee_id` does not exist **and** the employee has no email, the user lookup itself has **no `where` clause** and returns the first user id in the table. | `:598-611` | Every employee without an email is reported "Acknowledged" on the RBI audit file. | **CRITICAL** |
| **DEFECT-F-12** | CONFLICT | **Two independent verdicts printed side by side, using different rules for the same facts.** The checklist verdict accepts `pcc_status='submitted'` and `bgv.status='completed'` and a legacy-only DRA as `ok`; `scoreFor()` awards 0 for all three. A report reading "COMPLIANT · 35%" is reachable. | checklist `:579-586`, `:563-576`; score `:293-313` | The customer's own compliance report contradicts itself. | **HIGH** |
| **DEFECT-F-13** | DEFECT | **`agent_authorizations.status` is never read.** Validity is `valid_to` only. | `:619` | A **revoked** bank authorisation with a future `valid_to` reads as Compliant on the RBI audit file. | **HIGH** |
| **DEFECT-F-14** | DEFECT | **BGV re-verification alerts never fire on a fresh install.** The `bgv` migration has no `next_due` column; the scan is guarded by `Schema::hasColumn('bgv','next_due')`. The column appears only after `MasterController::ensureCols()` runs, i.e. after someone opens the BGV master screen. | migration `2026_01_01_000500:68-77`; guard `:127`; column def `MasterController.php:196` | A documented compliance control is silently inactive on `migrate:fresh` installs. | **HIGH** |
| **DEFECT-F-15** | CONFLICT | **`code_of_conduct_ack` has two incompatible schemas.** Migration: `employee_id` NOT NULL, no `user_id`. Runtime: `user_id`, `employee` string, no `employee_id`. The controller reconciles them with a raw MySQL-only `ALTER TABLE … MODIFY employee_id … NULL` inside a swallowed try/catch. | migration `2026_01_01_000600:94-101` vs `CodeOfConductController.php:24-70` | On a non-MySQL install, or where the ALTER lacks privilege, every acknowledgement insert fails and the failure is swallowed. | **HIGH** |
| **DEFECT-F-16** | DEFECT | **`pcc_deadline` is dead.** It is selected by `scan()` and never evaluated. The widening migration explicitly justifies retiring the stored `'overdue'` status on the grounds that "Compliance Alerts already derives overdue from PCC Deadline / PCC Expiry". It does not. | selected `:57`; no reader; migration `2026_08_27_000100:26-29`, `:60-66` | The migration **destroyed** existing `'overdue'` PCC data (rolled back to `'pending'`) in favour of a derivation that was never implemented. | **HIGH** |
| **DEFECT-F-17** | DEFECT | **The minimum-wage check uses `MAX(monthly_min)` across every row.** State, zone, category, `effective_from` and `status` on `min_wages` are all ignored. It also compares CTC/12 (gross) against the minimum, not Basic+DA. | `ReportController.php:136-155` | A tenant holding a Delhi skilled rate and a Bihar unskilled rate compares every employee to the highest. The register is decorative. | **HIGH** |
| **DEFECT-F-18** | DEFECT | **Compliance alerts have no role guard.** `alerts()` returns the full DRA/PCC/BGV expiry list — names, codes, companies and **email addresses** — for every employee in the tenant, to **any authenticated user**. `canManage` is returned as a UI hint only. `?days=` has no upper bound. The catch block returns HTTP 200 with the **raw exception message**. | `:152-168` | See FINDING-SEC-01. | **HIGH** |
| **DEFECT-F-19** | DEFECT | **`scan()` covers every employee, not field agents.** No `status` filter (resigned and inactive staff are alerted on) and no field-agent filter. The class docblock asserts `employees` "already carries … `is_field_agent`, `agent_code`, `portfolio`" — **none of those three columns exists anywhere in the schema**, so `agentCode` and `portfolio` are always empty strings in every alert row and every digest. | docblock `:15-16`; select `:57`; row `:90-91`; grep: 5 hits, all inside this file | Digest noise; the promised field-agent scoping does not exist. | **MEDIUM** |
| **DEFECT-F-20** | DEFECT | **An early return kills two of the four alert sources.** If neither `dra_expiry` nor `pcc_expiry` exists as a column, `scan()` returns empty **before** the `dra_certs` and `bgv` passes. | `:47-49` | An install using only the structured `dra_certs` register gets no alerts at all. | **MEDIUM** |
| **DEFECT-F-21** | DEFECT | **BGV "periodic re-verification" bucketing is Carbon-version-dependent.** `$due->diffInDays($today) <= 30` omits the `absolute` argument. Carbon 2 defaults `absolute = true` (always positive); Carbon 3 defaults `false` (signed). Under Carbon 3 a re-verification due 2 years out yields −730 and is flagged `warn`. Laravel 11 accepts `nesbot/carbon ^2.72\|^3.0`. | `:589`; contrast the deliberate `false` at `:76` | Every future BGV re-verification shows "Attention" on the RBI audit file, on some installs only. | **MEDIUM** |
| **DEFECT-F-22** | DEFECT | **`pt_amount` is dead but editable in both screens.** It is in `defaults()`, validated on save, offered as "Professional Tax / month (₹)" in the legacy modal and as "…— fallback only" in the F1 form. Nothing reads it. | `SettingsController.php:35`, `:144`; `StatutoryConfigController.php:60`; `AppController.php:12153`; **no reader** | An admin sets PT to ₹150 and nothing changes. | **MEDIUM** |
| **DEFECT-F-23** | DEFECT | **`$r['pt_slabs']` — the unknown-state fallback — can never be set.** It is not a key of `defaults()`, so the whitelist strips it, and it is not in `SettingsController::save()`'s validated list. | fallback `:1986`; whitelist `StatutoryConfig.php:368-372` | The fallback for every employee without a `pt_state` is permanently the hard-coded Telangana-shaped slab. | **MEDIUM** |
| **DEFECT-F-24** | DEFECT | **A malformed PT band silently produces PT = ₹0, and a test enshrines it.** `ptWalkSlab()` initialises `$amt = 0.0` and skips any row with fewer than two elements, so a configured slab of `[[15000]]` returns 0 for every gross. | `:1899-1910`; **asserted as correct** in `tests/manual/check_pt.php:80` ("a short band row is skipped, leaving 0") | A typo in a slab silently zeroes PT for a whole state, and the test suite blesses it. | **HIGH** |
| **DEFECT-F-25** | DEFECT | **The F1 override layer reaches only two call sites.** `applyScope()` is invoked from `computeSlip()` `:2101`, `computeSlipFromComponents()` `:2159` and the three `StatutoryController` reports. Every other consumer reads the flat blob: challan/24Q PDFs (`:1642`), incentive TDS (`IncentiveController.php:128`), letters (`LetterController.php:151`), the money gates (`RequestController.php:1373,1421`; `OffrollAgentController.php:251`), the scorecard (`ReportController.php:314`), late-arrival (`LateArrivalService.php:62`), and the entire SPA (`window.__RATES`). | as cited | Effective dating and scope are invisible outside the payslip. A state-scoped ESI threshold does not even affect the `esi_lock` decision, which reads the raw blob (`PayrollGenController.php:1306`). | **HIGH** |
| **DEFECT-F-26** | DEFECT | **A mid-month effective date silently defers a month.** `EffectiveDated::toDate(null)` returns **today** (`:126-132`), and `applyScope` resolves on `month.'-01'`. A row saved on 15 Sep with no explicit date gets `effective_from = 2026-09-15` and does **not** apply to September payroll. | `EffectiveDated.php:126-132`; `StatutoryConfig.php:129-131`, `:359` | The admin sees "Saved. In force from 2026-09-15." and September pays the old rate with no warning. | **MEDIUM** |
| **DEFECT-F-27** | CONFLICT | **The SPA duplicates every statutory rate and re-implements the tax walk in JavaScript.** `spRates()` carries a second copy of all defaults; `tdsTax()` re-implements `newRegimeTax()`; the PF/ESI register recomputes `basic = gross × 0.5`, uses `Math.round` for ESI (not `ceil`), ignores components, ignores `pt_state`, and ignores probation. The TDS card's caption still reads "₹50k std deduction, 87A ≤ ₹7L" against actual defaults of ₹75,000 and ₹12,00,000. | `AppController.php:11161`, `:11167-11177`, `:12081-12086`, `:12113` | The on-screen contribution register disagrees with both the payslips and the challans. Stale captions misstate the tax basis. | **HIGH** |
| **DEFECT-F-28** | CONFLICT | **Two more copies of the rates exist as hard-coded prose** in the in-product help. `KbController.php:211` and `CalcGuideController.php:88,126,136,161` state PF 12%/₹15,000, ESI 0.75%/3.25%/₹21,000, bonus ₹21,000/₹7,000/8.33–20%, OT ÷26÷8 as fixed text. They do not follow the configured rates. | as cited | A customer who lowers the ESI threshold still reads "≤ ₹21,000" in the product's own explanation of their payslip. | **MEDIUM** |
| **DEFECT-F-29** | DEFECT | **PT annualisation is wrong outside Maharashtra.** The PT report multiplies the monthly figure by 12 for every state, special-casing only Maharashtra's ₹2,500. Tamil Nadu and Kerala monthly figures are documented in-source as "half-yearly slabs shown as monthly equivalents". | `StatutoryController.php:95-98`; slab comments `:1860`, `:1863` | Annual PT liability is misstated for at least four states. | **MEDIUM** |
| **DEFECT-F-30** | DEFECT | **`$month` is undefined in two of the three statutory reports.** The gratuity and bonus branches reference `$month ?? now()->format('Y-m')`; `$month` is assigned only inside the `pt` branch. | `StatutoryController.php:52`, `:134` vs `:81` | Overrides in those two reports always resolve as of *today*, never as of a period. Suppressed by `??`, so it is silent. | **LOW** |
| **DEFECT-F-31** | DEFECT | **DRA gate runs before the approver authorisation check.** In `RequestController::decide()` the gate fires at `:1371`; the "you are not the approver" check is at `:1397`. | as cited | A non-approver can probe any employee's DRA status and expiry date via the 422 error text. | **LOW** |
| **DEFECT-F-32** | DEFECT | **Bulk audit prints every agent under the first agent's letterhead.** `$first = $agents[0]` supplies brand and company for the whole document. | `:426-433` | Mixed-company bulk runs produce misbranded regulatory documents. | **LOW** |
| **DEFECT-F-33** | DEFECT | **`scoreFor($employeeId, $tid)` ignores `$tid`.** All five sub-queries filter on `employee_id` alone. `ReportController.php:324` calls it with `null`. | `:289-330` | Dead parameter; the method trusts its caller for tenant isolation. | **LOW** |
| **DEFECT-F-34** | DEBT | **`tests/manual/pt_extract.php` is a hand-maintained copy of production PT code**, with a comment instructing the developer to re-extract on every change. It is currently in sync (verified byte-for-byte on `PT_FREE_STATES` and `PT_STATE_SLABS`). | `tests/manual/check_pt.php:11-20`; `pt_extract.php:1-25` | The only behavioural test of the PT engine will silently stop testing the real code the first time someone forgets. | **MEDIUM** |
| **DEFECT-F-35** | DEBT | **`statutory_settings` — the tenant's entire statutory configuration — is created by application code, not a migration.** | `SettingsController::ensureTable()` `:77-88` | Not visible to `migrate:status`; not in the schema dump; not backed up as schema. | **MEDIUM** |
| **DEFECT-F-36** | DEBT | **Six `defaults()` keys are unsettable through any UI** because they are absent from `SettingsController::save()`'s validated key list: `lwf_enabled`, `lwf_employee`, `incentive_min_compliance`, `data_retention_months`, `contact_window_start`, `contact_window_end`. Two of them (`contact_window_*`) implement the RBI lawful borrower-contact window; one (`incentive_min_compliance`) gates money; two are a statutory deduction. | `SettingsController.php:43-44,56,61-63` vs the `validate()` list `:141-164` | A statutory deduction and a regulatory control are frozen at their compiled defaults. | **HIGH** |
| **DEFECT-F-37** | DEBT | **`guardFinalisedPeriod` fails open** on any exception (`EffectiveDated.php:118-120`), and `PayrollLock::isPeriodFinalised` fails open on any read error and on a `payroll_runs` table without period columns. | `PayrollLock.php:47-51`, `:69-71` | The only protection against retro-changing a paid month is best-effort. | **MEDIUM** |
| **DEFECT-F-38** | DEBT | **Every compliance register is unindexed** on a runtime-built install, including `dra_certs.employee_id`, `dra_certs.expiry`, `bgv.next_due`, `code_of_conduct_ack.user_id`, `agent_earnings.agent_id`. `notify()` additionally does `SELECT DISTINCT tenant_id FROM employees` with no index guarantee, every morning. | `MasterController::ensureCols()` `:554-577`; `:192` | Nightly scan degrades linearly with headcount across all tenants on one server. | **MEDIUM** |
| **DEFECT-F-39** | DEBT | **No throttle or dedup on the digest.** `runNow()` (admin/HR) re-queues the entire digest on every call, and the daily job re-sends an identical digest every morning until someone renews the certificate. | `:171-182`, `:227-241` | Alert fatigue; mail-reputation risk. | **LOW** |
| **DEFECT-F-40** | DEBT | **`ScanCompliance` has no error handling.** Three lines, no try/catch, no logging beyond the console line, and it returns `SUCCESS` regardless. | `ScanCompliance.php:24-31` | A silently failing daily compliance job. | **MEDIUM** |
| **DEFECT-F-41** | DEFECT | **The subject line always says "(DRA/PCC)"** even when every row is a BGV re-verification. | `:233` | Cosmetic but misleading on a compliance notification. | **LOW** |
| **DEFECT-F-42** | DEBT | **No compliance calendar exists.** The only statutory due dates in the entire codebase are four hard-coded 24Q dates. `tds_returns.due_date` is typed by hand and is not connected to the synthetic 24Q history. | `:1794-1799`; `MasterController.php:174-183`; exhaustive grep for due-date logic | A payroll product for Indian employers has no PF/ESI/PT/TDS remittance calendar. | **HIGH** |
| **DEFECT-F-43** | CONFLICT | **`offroll_agents` has two schemas** (`rate decimal(12,2)` in the migration, `decimal(14,2)` at runtime, plus 12 runtime-only columns). `agent_earnings` exists only at runtime. | migration `2026_01_01_000500:14-26` vs `OffrollAgentController.php:32-95` | Schema drift between fresh and upgraded installs. | **MEDIUM** |
| **DEFECT-F-44** | DEFECT | **Off-roll agent earnings carry no TDS.** `computeEarning()` returns a gross payout; `agent_earnings` has no tax column. The on-roll commission path deducts 194H; this one does not. | `:102-119`, `:78-95`; contrast `IncentiveController.php:124-131` | Commission payments to off-roll agents leave the system untaxed. | **HIGH** |

---

## 7. Security findings

| # | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **SEC-F-01** | **Compliance alerts are readable by any authenticated tenant user.** `ComplianceController::alerts()` has no role guard. It returns employee names, codes, companies, **email addresses**, certification types and expiry dates for the whole tenant. `?days=` is unbounded (`?days=100000` returns every record with any date). The catch block returns HTTP 200 with the **raw exception message**, leaking table/column names on error. | `:152-168` | Trivial — any logged-in employee. | Bulk PII disclosure of the field force; error text aids further probing. | **HIGH** |
| **SEC-F-02** | **Uploaded KYC documents are written to the PUBLIC disk.** `$request->file('file')->store('offroll-docs','public')` with `'visibility' => 'public'` and `public_path('storage')` symlinked. PAN cards, address proofs, **PCC certificates**, DRA certificates and signed agreements are served by the web server with no authentication. The auth-guarded `serveDoc()` route is bypassed by the direct URL. | `OffrollAgentController.php:450`; `config/filesystems.php:39-43`, `:73`; guard at `:460-482` | Requires knowing the 40-char random filename — security by obscurity. Any URL that leaks (browser history, referer, proxy log, shared link, backup) is permanently public. | Disclosure of government identity documents and police clearance certificates. | **HIGH** |
| **SEC-F-03** | **Statutory rate changes are unauditable.** `SettingsController::save()` writes the tenant's PF rate, ESI rates, TDS slabs and every money gate with **no audit trail, no history, no before/after and no effective date**. It is `updateOrInsert` on a single row. | `:198-201`; no `AuditTrail`/`Audit::` call anywhere in the file | Requires `admin`. | An admin can change the PF rate, run payroll, change it back, and leave no trace. This is the single highest-value fraud vector in the payroll module. | **HIGH** |
| **SEC-F-04** | **Money gates fail open by design.** `draValid()` returns `['ok' => true, 'note' => 'DRA check unavailable']` on any exception; the DRA gate in `RequestController`, `IncentiveController` and `OffrollAgentController` is each wrapped in a swallowing try/catch; `guardFinalisedPeriod` and `isPeriodFinalised` both fail open. | `ComplianceController.php:363-366`; `RequestController.php:1391-1393`; `OffrollAgentController.php:259-261`; `EffectiveDated.php:118-120`; `PayrollLock.php:69-71` | An induced database error (lock timeout, connection exhaustion) pays the commission and unlocks the closed period. | Control bypass on money approval. | **MEDIUM** |
| **SEC-F-05** | **Fabricated compliance assertions in exported documents.** The RBI audit PDF asserts "Disciplinary actions: 0 · Compliant" from a literal, prints a "tamper-evident hash" that is not, cites a "hash-chained audit log" it never touches, and (via DEFECT-F-11) reports "Code of Conduct: Acknowledged" for employees who never acknowledged. The 24Q screen reports six quarters as "Filed" from a literal. | `:629`, `:644`, `:598-611`; `:1800` | No exploit needed — this is the default output. | A customer files or presents documents whose assertions the system did not verify. Regulatory and reputational exposure for Ametecs. | **HIGH** |
| **SEC-F-06** | **Code of Conduct text is global and cross-tenant.** `kb_topics WHERE category='Code of Conduct'` with **no tenant filter**. | `CodeOfConductController.php:101-108` | Any actor able to write global KB rows changes the undertaking every tenant's staff sign. | One tenant's policy text becomes another's legal undertaking. | **MEDIUM** |
| **SEC-F-07** | **The acknowledgement artefact is weak and overwritable.** One row per user, updated in place. No version of the acknowledged text, no history, no IP, no user agent, no signature. Re-acknowledging destroys the prior date. | `:150-176` | — | The register cited as "Signed undertaking of adherence" cannot show what was signed or when a prior version was signed. | **MEDIUM** |
| **SEC-F-08** | **Off-roll agent earnings link: a permanent bearer token in a URL.** `earn_token = Str::random(48)`, stored in plaintext, no expiry, no rotation, no revocation, emailed as a link, and the page renders the agent's full month earnings. | `:288-292`, `:316-361` | Anyone with the URL, forever. | Earnings disclosure. | **MEDIUM** |
| **SEC-F-09** | **DRA status disclosed to non-approvers.** The gate's 422 message ("DRA cert expired on YYYY-MM-DD") is returned before the approver check. | `RequestController.php:1371` vs `:1397` | Any user who can hit the decide endpoint. | Minor PII/compliance-status leak. | **LOW** |
| **SEC-F-10** | **Raw exception messages returned to clients.** `StatutoryController::report()` returns `$e->getMessage()` with HTTP 200; `ComplianceController::alerts()`, `runNow()`, and every `OffrollAgentController` and `CodeOfConductController` action do the same. | `StatutoryController.php:170`; `ComplianceController.php:166`, `:180`; `OffrollAgentController.php:138`, `:225`, etc. | Any authenticated user; some paths any user. | Schema and path disclosure. | **MEDIUM** |
| **SEC-F-11** | **`agentAuditSamplePdf` and `publicSampleAuditPdf` are the same generator**, one authenticated and one public (`throttle:20,1`). The public variant uses fictional data, but the authenticated one renders the tenant's real branding into a document containing wholly fabricated compliance findings including a hard-coded `score => 92`. | `:445-460`, `:466-480`, `:483-538` | Admin/HR. | A customer-branded document asserting fictitious compliance is downloadable and indistinguishable from a real one except for an amber banner. | **MEDIUM** |

---

## 8. Documentation-vs-source discrepancies

| # | Doc claim | Source | Verdict |
|---|---|---|---|
| 1 | CPBTD §5.5 item 5: "*Side effect: `pt_slabs`, `lwf_enabled` and `lwf_employee` are not saveable this way either, because they are not keys of `defaults()`.*" | `lwf_enabled` and `lwf_employee` **are** keys of `defaults()` (`SettingsController.php:43-44`), so they **pass** the F1 whitelist and **are** saveable through `POST /app/statutory-config/save` with `kind=lwf`. Only `pt_slabs` is genuinely excluded. | **Doc wrong.** LWF is reachable via the F1 API (though not via any UI), and `computeSlip` reads it after `applyScope`, so such a row would take effect. |
| 2 | CPBTD §5.4 table: "`pt_slabs` absent → hardcoded Telangana fallback (**and it can never be present** — §4.7d)" | Correct, and stronger than stated: it is excluded from **both** save paths. | **Doc correct.** |
| 3 | CPBTD §5.4 note on the `pt` kind: "`pt_amount` *(dead — nothing reads it)*" | Confirmed by exhaustive grep. | **Doc correct** — but it under-states the impact: the field is prominently editable in **both** rate screens as "Professional Tax / month (₹)". |
| 4 | CPBTD §5.9: `resources/views/agent-audit-body.blade.php` and `agent-audit-bulk-pdf.blade.php` are cited with specific line references. | Neither file exists in this source copy. | **UNVERIFIABLE IN THIS COPY.** 39 views are missing from the tree; not reported as a defect. |
| 5 | CPBTD §5.2: "Implemented **three** times, with three different fall-through semantics." | There are **five**: the three cited plus `MasterController::draGateWarning()` `:899-912` and the off-roll blank-string check `OffrollAgentController.php:253`. | **Doc under-counts.** |
| 6 | `BR-CMP-703`: "Missing PCC by its deadline causes an automatic **₹1,000 deduction**." | No such deduction exists. `pcc_deadline` has no reader at all. The catalogue's own "Recorded risk" cell for this rule is corrupted text from an unrelated section. | **Doc wrong — the rule does not exist in code.** |
| 7 | `BR-CMP-702`: "'PCC overdue' is derived from the deadline, not stored — **IMPLEMENTED**." | Not implemented. `pcc_deadline` is selected and never evaluated. The migration that retired the stored `'overdue'` value cited this derivation as its justification. | **Doc wrong, and the error caused data loss.** |
| 8 | `ComplianceController` docblock `:15-16`: "The employees table already carries: dra_status, dra_expiry, pcc_status, pcc_expiry, pcc_deadline, **is_field_agent, agent_code, portfolio**." | The last three exist nowhere in the schema. | **Source comment wrong** (CPBTD §5.4 already flags this; confirmed). |
| 9 | `StatutoryConfig` docblock `:24-28` and `StatutoryConfigController` `:27-31`: "only PROFESSIONAL TAX genuinely varies by state in law." | Contradicted by the same codebase: `min_wages` is keyed by state/zone/category, and LWF is described in `SettingsController.php:43` as "state-specific". | **Source comment internally inconsistent**; and the design decision it justifies (single-state-dimension scoping) is a SmartPRS 3 constraint. |
| 10 | `StatutoryConfigController` docblock `:34-36`: "the **16** built-in state slabs and **10** PT-free states become editable". | Counts confirmed (`:1854-1871` = 16 keys; `:1852` = 10 entries). But "editable" is only true additively — a built-in **PT-free** state can be given a slab, yet a built-in **slab** state cannot be made PT-free by configuration, because `pt_state_slabs` is checked **before** the free list (`:1959-1978`). | **Doc over-states.** Asymmetric override. |
| 11 | CPBTD §5.6: "Note it uses the **full CTC** — no LOP, no components, no commission — so the previewed net will not equal any real payslip; only the *delta* is meaningful." | Confirmed (`StatutoryConfig.php:557-558`). | **Doc correct.** |
| 12 | CPBTD §5.5 item 2 presents the finalised-period guard as protecting rate changes. | It protects only `statutory_configs` writes. The legacy blob — which every non-payslip consumer reads — has no guard, no date and no history. | **Doc materially misleading by omission.** |
| 13 | `BR-STAT-201` "Statutory PDF basis — PARTIALLY IMPLEMENTED". | Understated: the PDFs use no `$ctx` at all, so PT is wrong for every non-Telangana employee, scoped overrides are ignored, resigned employees are included, and ESI rounding differs from the engine. | **Catalogue under-states severity.** |
| 14 | `StatutoryController` note (`:109`): "Slab values are **indicative** — verify against current state law before filing." And `statutory-pdf.blade.php:47`: "Indicative figures — verify against your registered statutory rates before filing." | Present and appropriate. | **Correct and worth preserving into SmartPRS 3.** |
| 15 | `StatutoryController` bonus note (`:164`): "Bonus wage capped at ₹7,000/month (**use the state minimum wage if higher**)." | The code caps at the literal 7000 and never consults `min_wages` (`:139`, with the in-code comment "cap kept at ₹7,000 here"). | **UI text describes behaviour the code does not have.** |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| Rate storage — `statutory_settings` blob | **REPLACE** | Ungoverned, undated, unaudited, unversioned, and the primary read path. Cannot be retrofitted. | Convert each tenant's blob into effective-dated rows in the new rate store, dated from the tenant's earliest payroll run. |
| Rate storage — `statutory_configs` (F1) | **MIGRATE** | The scoping/dating/precedence model is sound and tested. Its flaw is reach, not design. | Rows map 1:1 to the new store's `(scope, kind, effective_from, payload)`. |
| Rate resolution — `applyScope` / `mergedOverrides` | **REDESIGN** | Precedence and per-kind resolution are correct; the loose substring state match, the single scope-value column, and the two-call-site reach are not. | None. |
| `SettingsController::defaults()` as the shipped rate set | **REPLACE** | A PHP array is not a versioned, effective-dated, jurisdiction-aware statutory content pack. | Seed the first content pack from these values, marked "unverified — requires legal review". |
| PF calculation | **REDESIGN** | Formula shape is right; EPS/EDLI constants, the missing admin charges, VPF and the ceiling election must become configuration. | None. |
| ESI calculation + contribution-period lock | **REDESIGN** | The half-year rule is genuinely valuable and correctly conceived. The name-substring lookup and hard-coded period boundaries must go; the lock must read a structured flag on the payslip, not a display label. | Backfill an `esi_contributed` boolean on historical payslips from the existing deductions JSON. |
| Professional Tax slab engine | **REDESIGN** | Right idea (state slabs, config overrides, February and gender specials) executed as compiled constants with substring matching and asymmetric override. | Built-in slabs and PT-free states become seed data in the content pack; existing `pt_state_slabs`/`pt_free_states` override rows migrate as tenant overrides. |
| PT state matching (`str_contains` both ways) | **REPLACE** | Substring matching over a free-text `pt_state` is unsafe (e.g. any state name containing a shorter one). | Map every distinct `employees.pt_state` value to a state code; a mapping report is required. |
| TDS engine | **REPLACE** | See §10 Q5. New Regime only, CTC-based, no declarations, no proofs, no perquisites, no other-income, no previous-employer, no true-up, no Form 16. Not a TDS engine. | Nothing to migrate — no declaration or proof data exists. |
| 24Q / 26Q statements and filing history | **REPLACE** | Fabricated filing status; disagrees with the payslip; not derived from actual payroll. | Discard the synthetic history. Retain `tds_returns` rows as manually-entered history. |
| PF/ESI challan PDFs | **REPLACE** | Computed from CTC, not from the run. Must be generated from stored payslip lines. | None. |
| Gratuity | **REDESIGN** | Two unrelated formulas (15/26 report, 4.81% accrual). Needs ceiling, covered/non-covered variants, and a link to F&F. | None (nothing stored). |
| Bonus register | **REDESIGN** | Correct shape; every parameter hard-coded; never pays; never consults minimum wages. | None. |
| Minimum wages | **REPLACE** | The register has the right columns and the consumer ignores all of them. | Retain `min_wages` rows; rebuild the matcher on state/zone/category/skill/effective date. |
| LWF | **REPLACE** | Flat monthly amount with no state, periodicity, employer share or UI. | None (unreachable, so no data exists). |
| Per-employee statutory applicability (`pf_applicable`, `esi_applicable`) | **REDESIGN** | The columns and the UI already exist and users have populated them; only the engine is missing. | **The data is already there and is currently being ignored — migrating it will change payroll outcomes. Requires a reconciliation report before cutover.** |
| Establishment / registration entity | **NEW — does not exist** | No PF code, ESI code, PT registration, S&E registration anywhere. | Must be created and back-populated per company/branch. |
| Statutory calendar / due dates | **NEW — does not exist** | Four hard-coded 24Q dates is the entirety of it. | New. |
| Compliance expiry scanning | **REDESIGN** | The scan/bucket/digest pattern is right; the item types, thresholds, sources and windows must become configuration. | Existing `dra_certs`, `bgv`, `employees.*_expiry` rows migrate into a generic `compliance_items` model. |
| Compliance item types (DRA/PCC/BGV) | **REPLACE** | A closed literal set. Must become customer-definable item types with their own validity rules, renewal periods, evidence requirements and gate behaviour. | Three seeded item types reproduce today's behaviour exactly. |
| Compliance score | **REDESIGN** | Fixed 25/20/20/15/20 weights and five fixed conditions. Must become a weighted rule set over the configurable item types. | None (derived). |
| DRA validity determination | **MERGE** | Five divergent implementations must collapse to one. | None. |
| Agent audit report | **REDESIGN** | Right artefact, wrong internals: a fabricated item, a fake tamper hash, a defective CoC lookup, a mis-branded bulk mode, two contradictory verdicts, and no audit-log entry on generation. | None. |
| Code of Conduct acknowledgement | **REPLACE** | No version, no history, no signature, no IP, no re-ack trigger, global text, and a defective read path. | Existing acks migrate as "v0 acknowledged on `<date>`, text version unknown". |
| Money gates (`dra_gate`, `points_gate_min`, `incentive_min_compliance`) | **REDESIGN** | The concept is exactly right and is a genuine product differentiator. Needs per-portfolio/per-bank scope, blocking configurable per gate, no fail-open, and `incentive_min_compliance` must be settable. | None. |
| Off-roll agent compliance | **NEW — does not exist** | The population most exposed to RBI agent norms is outside the module. | `offroll_agents.dra`/`pcc` free text needs manual triage into structured items. |
| Off-roll agent earnings | **REDESIGN** | No TDS, no 194H, no GST on vendor payouts, runtime-only table. | `agent_earnings` migrates; historical rows have no tax split to recover. |
| Public sample audit PDF (marketing) | **RETAIN** | Legitimate sales asset; keep it clearly and unmistakably marked. | None. |
| `min_wages`, `policies`, `tds_returns`, `overtime` masters | **MIGRATE** | Useful registers with the wrong (or no) consumers. | Rows migrate; consumers are rebuilt. |
| Generic runtime table creation (`ensureCols`) | **REMOVE** | Produces unindexed, constraint-free, per-install-divergent schemas for regulatory registers. | Every runtime-created table needs a real migration written from its current shape. |

---

## 10. SmartPRS 3 requirements implied

### Statutory content and versioning
1. Statutory parameters must live in a **versioned, effective-dated content pack** that Ametecs publishes independently of an application release. A PT slab change must be shippable as data, not as PHP (today it is `private const PT_STATE_SLABS` — see Q9).
2. Every statutory parameter must carry `(jurisdiction, effective_from, effective_to, source_reference, verified_by, verified_on)`. No parameter may be presented as legally correct without a recorded verification.
3. The rules engine must support **as-of resolution on the payroll period date, never on "now"** — including for every consumer, not just the payslip (DEFECT-F-25).
4. A payroll run must **snapshot the complete resolved rate set** into the run and into each payslip, and re-running a period must reuse that snapshot unless a supervisor explicitly re-resolves (DEFECT-F-03).
5. Every rate change must be **append-only, attributed, timestamped, diffed and audited** — the legacy blob's `updateOrInsert` with no trail must not survive (DEFECT-F-02, SEC-F-03).
6. A change whose effective date falls in a finalised period must be **refused, not fail-open** (DEFECT-F-37).
7. The impact preview must be retained and extended to the **actual** payroll basis (components, LOP, commission), not full CTC.

### Jurisdiction and applicability
8. A first-class **Establishment** entity: legal entity → establishment → branch, each carrying its PF code, ESI code, PT registration, LWF registration, S&E registration, state and district. Statutory applicability must resolve from the establishment an employee is posted to.
9. **Per-scheme applicability determination per employee per period**, with a recorded reason: voluntary coverage, international worker, excluded employee, exempted establishment, above-ceiling exclusion, apprentice. `pf_applicable` / `esi_applicable` must actually drive the engine (DEFECT-F-01), and migrating the existing populated values will change payroll outcomes — a reconciliation report is mandatory before cutover.
10. Scope resolution must key on **structured identifiers** (state code, establishment id, branch id), not free-text substring matching (`looseMatch`, `str_contains`).
11. A jurisdiction must be able to **remove** an inherited rule, not only add one (today a built-in PT-free state cannot be re-declared free once a slab exists, and a built-in PT-free state cannot be removed from the list at all).

### Formula engine
12. The engine must express, as configuration: a **wage base** built from component flags (not component *names* — today DA is found by `str_contains($name,'dearness')` and the ESI lock by `stripos($k,'esi')`); a **ceiling**; a **rate**; a **rounding rule per contribution** (ESI needs round-up, PF needs 2dp — today one is `ceil` in the engine and `round` in the challan, DEFECT-F-05); and an **employee/employer split**.
13. It must support **contribution sub-splits** (EPS/EPF/EDLI) with their own independent ceilings and caps, and **administrative charges** with percentage-or-minimum semantics (A/c 2's 0.5%-or-₹500 shape is expressible nowhere today).
14. It must support **period-locked eligibility** — a determination fixed at a period boundary and carried forward (the ESI half-year rule) — as a generic construct, driven by a structured flag on the prior payslip rather than by matching a display label.
15. It must support **slab tables with a month/period modifier** (the Maharashtra February ₹300 uplift must be a data attribute of the slab, not `if ($key === 'maharashtra')` in PHP).
16. It must support **attribute-based exemptions** (gender, age, disability, category) as declarative predicates with a configurable ceiling — the Maharashtra female exemption is one instance of a general pattern.
17. It must support **periodicity other than monthly**: half-yearly (TN, Kerala PT), annual caps (MP, MH ₹2,500), and June/December-style LWF remittances. Today every slab is coerced to a "monthly equivalent" and annualised as `× 12`, which is wrong for at least four states (DEFECT-F-29).
18. Malformed configuration must **fail loudly**, never silently produce zero (DEFECT-F-24), and no test may assert silent-zero as correct behaviour.
19. Exactly **one** implementation of each formula. Today PT/PF/ESI/TDS each have 3–5 implementations (PHP engine, PHP challan, JavaScript SPA, hard-coded help prose, extracted test copy) that disagree (DEFECT-F-05, -F-27, -F-28, -F-34). The SPA must render server-computed figures.

### TDS engine (build, do not port)
20. Both **Old and New regimes**, with a per-employee, per-FY regime election, a switch-date rule, and default-regime handling.
21. An **investment-declaration** and **proof-submission** lifecycle with per-section limits (80C, 80D, 80CCD(1B), 24(b), HRA…), a declaration window, a proof window and a lock date.
22. **HRA exemption** computed from actual rent, metro/non-metro status and Basic+DA; **LTA**; **perquisites**; **other income**; **previous-employer income and TDS**.
23. **Cumulative year-to-date TDS with monthly true-up**, plus surcharge bands with their own marginal relief. Today TDS is `annual ÷ 12` in every month with no true-up.
24. **87A marginal relief must be applied inside the tax function**, once, so every consumer agrees (DEFECT-F-06).
25. **Form 16 Part A/B, Form 12BA, Form 12BB** and a real **24Q** derived from actual deductions and deposits — with filing status recorded, never assumed (DEFECT-F-07).
26. Deductee-level 26Q with challan reference, BSR code and deposit date.

### Statutory calendar and returns
27. A **compliance calendar** driven by the content pack: PF ECR, ESI contribution, PT payment (per state), TDS deposit, quarterly returns, LWF remittance, annual returns — each with a jurisdiction-specific due date, an owner, a reminder ladder and a filed/not-filed state. Today four hard-coded 24Q dates are the entirety of it (DEFECT-F-42).
28. Every statutory output (challan, ECR, return) must be generated **from stored payroll results**, never recomputed from CTC (DEFECT-F-04), and must reconcile line-for-line to the payslips it claims to summarise.
29. The system must **never assert a filing status it has not observed** (DEFECT-F-07, SEC-F-05).

### Gratuity, bonus, minimum wages
30. Gratuity: one formula, configurable divisor (15/26 vs 15/30), configurable eligibility years, a configurable ceiling, covered/non-covered establishment handling, a link to full-and-final settlement, and a single accrual basis (today 15/26 in the report and 4.81% on the payslip are unreconciled).
31. Bonus: configurable eligibility ceiling, bonus-wage cap, minimum and maximum percentage, set-on/set-off, allocable surplus, **and a payment path** — a register that never pays is not a bonus module (BR-STAT-015).
32. The bonus wage cap must resolve against the **matched** minimum wage when higher, as the product's own UI text already promises.
33. Minimum wages must match on **(state, zone, category, skill level, effective date, active status)** — the register already carries these columns and the consumer ignores every one (DEFECT-F-17) — and compare against **Basic+DA**, not CTC/12.

### Compliance module
34. **Customer-definable compliance item types.** An item type carries: name, applies-to predicate (role, department, engagement type, on-roll/off-roll, state), validity source (issue date + validity period, or an explicit expiry), renewal lead time, evidence requirement, alert ladder, and gate behaviour. Today DRA/PCC/BGV are a closed set of PHP literals (BR-CMP-006).
35. **Configurable alert ladders per item type** — bucket boundaries, window, escalation path — persisted, not a per-request query string with no upper bound (BR-CMP-005, DEFECT-F-18).
36. **Configurable compliance scoring**: weights and pass conditions per item type, with a **single** definition of "valid" shared by the score, the gate and the report (DEFECT-F-08, -F-12). A missing table must never award points (BR-CMP-003b).
37. **Evidence attachment** on every compliance record, with the file stored **privately** and served only through an authorised route (SEC-F-02), plus who uploaded it and when.
38. **A renewal workflow**: raise a renewal task at the lead time, assign it, notify **the agent as well as HR**, track submission, and record the outcome.
39. **Configurable consequences of non-compliance**, per item type and per action: warn / block / require override-with-reason. The gate points must extend beyond money to allocation, attendance and field work. No gate may **fail open** (SEC-F-04).
40. **Versioned policy acknowledgement**: policy → version → text → publication date → per-user acknowledgement (with version, timestamp, IP, user agent) → **history of all acknowledgements** → automatic re-acknowledgement demand when the version bumps. `policies.ack_required` must be honoured (BR-CMP-016, DEFECT-F-11, SEC-F-06, SEC-F-07). Policy text must be **tenant-owned**.
41. Compliance reports must be **generated from observed evidence only**. No literal may be printed as a finding (DEFECT-F-09). Report generation must itself be **audit-logged**, and any integrity marker must be a hash **of the report content**, chained, or must not be claimed at all (DEFECT-F-10).
42. **Off-roll and vendor agents must be first-class subjects** of the compliance model, with structured DRA/PCC records, and their payouts must run through the same statutory treatment as on-roll commission — 194H TDS, PAN/no-PAN, GST on vendor invoices (BR-CMP-029, DEFECT-F-44).
43. Compliance data must be **role-guarded** (SEC-F-01) and every register **indexed** (DEFECT-F-38).
44. Every register in this area needs a **real migration** with indexes, constraints and foreign keys. Runtime schema creation must not survive into SmartPRS 3 (DEFECT-F-35, -F-38, -F-43).

### Deployment
45. Statutory content updates must reach on-premise installs **without an application release and without an on-site admin pressing a button** — see Q9. This is the single most important non-functional requirement in this area.

---

## 11. Answers to the nine questions

### Q1 — Complete inventory of rates, ceilings, thresholds, slabs and due dates

> Every value below is **the value the code currently uses**. None is asserted to be legally correct. All require verification.

**Legend:** (a) hard-coded in PHP · (b) stored in config/DB and editable · (c) seeded but effectively immutable

| Value used | Meaning | Source | Class |
|---|---|---|---|
| **PF** | | | |
| 15000 | `pf_wage_cap` default | `SettingsController.php:32` | (b) both screens |
| 12 | `pf_rate` default, % each side | `:33` | (b) both screens |
| **15000** | EPS/EDLI statutory wage ceiling | `AppDataController.php:2067` | **(a)** |
| **8.33** | EPS % of EPS base | `:2071` | **(a)** |
| **1250** | EPS monthly cap ₹ | `:2071` | **(a)** |
| **0.5** | EDLI % | `:2073` | **(a)** |
| **15000** | EDLI fallback ceiling (payslip employer strip) | `:1542` | **(a)** — duplicate |
| — | EPF admin charges (A/c 2), EDLI admin (A/c 22), VPF | absent | **not implemented** |
| **ESI** | | | |
| 21000 | `esi_threshold` default (gross ≤) | `SettingsController.php:34` | (b) both screens |
| 0.75 | `esi_employee_rate` default % | `:34` | (b) |
| 3.25 | `esi_employer_rate` default % | `:34` | (b) |
| **ceil** | rounding of both ESI shares | `AppDataController.php:2077-2078` | **(a)** |
| **round(,2)** | rounding used by the ESIC **challan** | `:1688-1689` | **(a)** — conflicts with the above |
| **Apr–Sep / Oct–Mar** | ESI contribution periods (months 4, 10) | `:2019-2025` | **(a)** |
| **24** | payslip rows scanned by the period lock | `:2032` | **(a)** |
| **Professional Tax — 16 built-in state slabs**, `AppDataController.php:1854-1871`, all **(c)** (overridable per state, but the list itself is fixed) | | | |
| telangana | 15000→0 · 20000→150 · above→200 | `:1855` | (c) |
| andhra | 15000→0 · 20000→150 · above→200 | `:1856` | (c) |
| maharashtra | 7500→0 · 10000→175 · above→200 | `:1857` | (c) |
| karnataka | 24999→0 · above→200 | `:1858` | (c) |
| west bengal | 10000→0 · 15000→110 · 25000→130 · 40000→150 · above→200 | `:1859` | (c) |
| tamil nadu | 3500→0 · 5000→23 · 7500→53 · 10000→115 · 12500→171 · above→208 | `:1860` | (c) — in-source note: "half-yearly slabs shown as monthly equivalents" |
| gujarat | 12000→0 · above→200 | `:1861` | (c) |
| madhya pradesh | 18750→0 · 25000→125 · 33333→167 · above→208 | `:1862` | (c) — "annual ₹2,500 cap spread monthly" |
| kerala | 1999→0 · 2999→20 · 4999→30 · 7499→50 · 9999→75 · 12499→100 · 16666→125 · 20833→166 · above→208 | `:1863` | (c) — "half-yearly slabs as monthly equivalents" |
| bihar | 25000→0 · 41666→83 · 83333→167 · above→208 | `:1864` | (c) |
| odisha | 13304→0 · 25000→125 · above→200 | `:1865` | (c) |
| assam | 10000→0 · 15000→150 · 25000→180 · above→208 | `:1866` | (c) |
| jharkhand | 25000→0 · 41666→100 · 66666→150 · 83333→175 · above→208 | `:1867` | (c) |
| chhattisgarh | 13333→0 · 16666→150 · 20833→180 · 25000→190 · above→200 | `:1868` | (c) |
| punjab | 20833→0 · above→200 | `:1869` | (c) |
| goa | 15000→0 · 25000→150 · above→200 | `:1870` | (c) |
| **PT — 10 built-in PT-free states**: delhi, uttar pradesh, haryana, rajasthan, himachal, uttarakhand, chandigarh, jammu, ladakh, arunachal | `:1852` | **(c)** — a state can be **added** to the free list by config; a built-in **cannot be removed** |
| **PT — other** | | | |
| **300 / 200 / '02' / 'maharashtra'** | February uplift trigger, amount and state | `:1911-1913` | **(a)** |
| 1 | `pt_female_exempt` default (on) | `SettingsController.php:36` | (b) |
| 25000 | `pt_female_exempt_upto` default ₹ | `:37` | (b) |
| **'maharashtra'** + **['female','f','woman','women']** | female-exemption state and gender tokens | `AppDataController.php:1936-1938` | **(a)** |
| **15000→0 · 20000→150 · ∞→200** | unknown-state fallback slab | `:1988-1992` | **(a)** — `$r['pt_slabs']` is unsettable |
| 200 | `pt_amount` default ₹ | `SettingsController.php:35` | **(b) but DEAD — no reader** |
| **2500 = 200×11 + 300** | Maharashtra annual PT in the report | `StatutoryController.php:97` | **(a)** |
| **TDS / income tax (New Regime only)** | | | |
| 75000 | `std_deduction` default ₹ | `SettingsController.php:38` | (b) both screens |
| 1200000 | `rebate_87a_limit` default ₹ | `:39` | (b) both screens |
| 4 | `cess_rate` default % | `:40` | (b) both screens |
| 5 | `comm_tds_rate` (194H) default % | `:41` | (b) both screens |
| 20 | `no_pan_tds_rate` default % | `:42` | (b) both screens |
| 400000→0 · 800000→5 · 1200000→10 · 1600000→15 · 2000000→20 · 2400000→25 · above→30 | `tds_slabs` default | `:64-72` | **(b) — legacy screen ONLY; cannot be effective-dated or scoped** |
| **30** | `comm_pct` fallback % for "Salary + Commission" | `AppDataController.php:1708`, `:1730` | **(a)** |
| **12** | months divisor for monthly TDS | `:1842` | **(a)** |
| **Statutory due dates — the ONLY ones in the codebase** | | | |
| **31 July** | 24Q Q1 due | `:1795` | **(a)** |
| **31 October** | 24Q Q2 due | `:1796` | **(a)** |
| **31 January** | 24Q Q3 due | `:1797` | **(a)** |
| **31 May** | 24Q Q4 due | `:1798` | **(a)** |
| — | PF ECR, ESI contribution, PT payment (per state), TDS deposit, LWF remittance, annual returns | **absent** | **not implemented** |
| **LWF** | | | |
| false / 0 | `lwf_enabled` / `lwf_employee` | `SettingsController.php:43-44` | **(b) via the raw F1 API only — no UI, absent from `SettingsController::save()`'s validated keys** |
| **Gratuity** | | | |
| **15 / 26** | gratuity divisor | `StatutoryController.php:57` | **(a)** |
| **5** | eligibility years | `:56` | **(a)** |
| **365.25** | days per year | `:55` | **(a)** |
| **4.81** | payslip gratuity accrual % of Basic | `AppDataController.php:1543` | **(a)** |
| — | ₹20,00,000 ceiling; 15/30 non-covered variant | **absent** | **not implemented** |
| **Bonus** | | | |
| **21000** | eligibility ceiling on Basic ₹/month | `StatutoryController.php:138` | **(a)** |
| **7000** | bonus wage cap ₹/month | `:139` | **(a)** |
| 8.33 | `bonus_pct` default % | `SettingsController.php:55` | (b) both screens |
| **8.33 / 20** | clamp floor and ceiling % | `StatutoryController.php:120` | **(a)** |
| **30.44** | days/month for joiner proration | `:146` | **(a)** |
| **1 April** | financial-year start | `:125` | **(a)** |
| **Salary structure defaults** | | | |
| **0.5 / 0.4** | Basic = 50% of gross, HRA = 40% of Basic | `AppDataController.php:2103-2104` | **(a)** |
| **50** | Code-on-Wages Basic+DA advisory threshold % | `MasterController.php:883` | **(a)** |
| **26 / 8 / 2** | OT divisor days, hours, default multiplier | `MasterController.php:757-762`; `PayrollGenController.php:2165` | **(a)** |
| **Compliance thresholds** | | | |
| **30** | `WINDOW_DAYS` alert window | `ComplianceController.php:31` | **(a)** — `?days=` per request, unbounded, not persisted |
| **7** | critical bucket boundary (days) | `:80` | **(a)** |
| **0** | expired bucket boundary | `:80` | **(a)** |
| **25 / 20 / 20 / 15 / 20** | compliance score weights (DRA/PCC/BGV/NDA/complaints) | `:296,305,311,318,324` | **(a)** |
| 60 | `incentive_min_compliance` default | `SettingsController.php:56` | **(b) but unsettable — absent from the validated key list** |
| 'warn' | `dra_gate` default | `:57` | (b) legacy screen |
| 0 | `points_gate_min` default | `:58` | (b) legacy screen |
| 0 | `incentive_payout_lag` default (months, clamped 0–12) | `:59`; clamp `IncentiveController.php:153` | (b) |
| **2** | minimum references/guarantors | `ComplianceController.php:594` | **(a)** |
| **90** | borrower-complaint lookback (days) | `:623` | **(a)** |
| **150** | bulk audit agent cap | `:414` | **(a)** |
| **25** | digest row truncation | `:206` | **(a)** |
| **07:30** | daily scan time | `routes/console.php:144` | **(a)** |
| **30** | BGV "warn" horizon (days) | `:589` | **(a)** — and Carbon-version-dependent |
| 84 | `data_retention_months` default (7 years) | `SettingsController.php:61` | **(b) but unsettable** |
| '08:00' / '19:00' | `contact_window_start` / `_end` (RBI lawful contact window) | `:62-63` | **(b) but unsettable** |
| **92** | hard-coded score on the sample audit PDF | `ComplianceController.php:525` | **(a)** |

### Q2 — Is any statutory rule effective-dated / versioned by financial year?

**Partially, and only in the layer almost nothing reads.**

- `statutory_configs` rows carry `effective_from` and are resolved against the **payroll period date** (`StatutoryConfig.php:129-131`, `:254-258`), newest-in-force per `(scope, kind)` winning (`:189`). This is genuine effective dating and it is unit-tested (`StatutoryConfigTest.php:75-81`).
- `statutory_settings` — the blob edited by the Statutory Rate Settings modal, and the **only** source read by the challan PDFs, the 24Q, the money gates, the incentive TDS, the scorecard and the entire SPA — has **no date, no version and no history**. It is `updateOrInsert` on one row (`SettingsController.php:198-201`).
- **Nothing is versioned by financial year.** FY appears only as a label for money records and as the bonus/YTD window boundary (1 April). No rate carries an FY.
- `tds_slabs` — the single most impactful tax parameter — is editable **only** through the undated screen (`StatutoryConfigController::FIELDS` has no `tds_slabs` entry, `:65-70`).

**If a rate changes mid-year:**

| | Via `statutory_configs` (F1) | Via `statutory_settings` (the screen customers use) |
|---|---|---|
| **(i) Future payroll** | Correct: applies from the effective date onward. *But* an empty `effective_from` defaults to **today**, and resolution is on `month-01`, so a change saved mid-September first applies in **October**, silently (DEFECT-F-26). | Applies immediately to the next run. |
| **(ii) Already-run payroll** | Untouched — stored `payslips` rows are not recomputed. | Untouched — stored rows are not recomputed. |
| **(iii) A re-run of an earlier month** | Correct: the earlier month re-resolves to the rate in force then. | **WRONG. The new rate is applied to the old month.** There is no history to fall back to, and nothing warns. |

The finalised-period guard (`EffectiveDated::guardFinalisedPeriod` → `PayrollLock`) protects **only** the F1 save path (`StatutoryConfigController.php:158-161`, `:258-260`). `SettingsController::save()` has no such guard. It also fails open on any exception (`EffectiveDated.php:118-120`) and on any `payroll_runs` read error (`PayrollLock.php:69-71`).

Because payslips store **no rate snapshot** (`payslips` migration `2026_01_01_000300:53-70`), a re-run cannot be reconciled against the original and the original cannot be explained.

### Q3 — Professional Tax: representation, coverage, uncovered states, exemptions

**Representation.** A PHP `private const` map, state-name-fragment → ordered list of `[gross upto, monthly amount]` pairs, where `upto <= 0` means "and above"
(`AppDataController.php:1854-1871`). The band walk takes the first band where
`upto <= 0 || gross <= upto` (`:1897-1916`). Overrides use the identical shape,
validated and normalised by `StatutoryConfig::cleanSlabs()` (`:422-458`): keys
lower-cased, rows with fewer than two elements dropped, **any negative value
rejects the whole save**, closed bands sorted ascending, open band forced last.

**Coverage.** **16 states with slabs, 10 declared PT-free — 26 named jurisdictions in total.** India has 28 states and 8 union territories. Not covered at all: Andaman & Nicobar, Dadra & Nagar Haveli and Daman & Diu, Lakshadweep, Puducherry, Manipur, Meghalaya, Mizoram, Nagaland, Sikkim, Tripura, and every UT not in the free list.

**State matching is a bidirectional substring test.** In `ptForGross` it is
`str_contains($employeeState, $slabKey)` (`:1960`, `:1975`, `:1981`); in scope
resolution it is `looseMatch()` — `a === b || contains(a,b) || contains(b,a)`
(`StatutoryConfig.php:231-240`). This is why the key is `'andhra'` rather than
`'andhra pradesh'`. It is also unsafe in general: any state name that contains a
shorter key matches it.

**An uncovered state falls through to the hard-coded fallback** `[15000→0,
20000→150, ∞→200]` (`:1988-1992`) — i.e. **an employee in Manipur is charged
Telangana's PT**, not zero. The intended override, `$r['pt_slabs']`, is **not a
key of `defaults()`**, so the F1 whitelist strips it (`StatutoryConfig.php:368-372`)
and `SettingsController::save()` does not validate it. It can never be set. The
PT report labels these employees "— (default slab)" (`StatutoryController.php:100`),
which is the only visible signal.

**A customer CAN add an uncovered state** by saving a `pt_state_slabs` entry for
it (`saveSlab()` `:246-309`), and an explicit slab **outranks** the built-in
PT-free list (`:1959-1963` runs before `:1965-1978`) — that is how a state which
begins levying PT is switched on. The override is **asymmetric**: a state can be
added to the PT-free list, but a built-in **free** state cannot be removed from
it (only shadowed by giving it a slab), and a built-in **slab** state cannot be
made free (the slab is checked first).

**Gender exemption:** yes, but hard-wired to Maharashtra. `pt_female_exempt`
(default on) and `pt_female_exempt_upto` (default ₹25,000) are config, but the
state name `'maharashtra'` and the gender token list
`['female','f','woman','women']` are literals (`:1936-1938`). It is applied
**before** the slab lookup, so it overrides a configured slab too.

**Category exemption:** **none.** No senior-citizen, disability, armed-forces or
occupation-based exemption exists.

**Special month:** yes, exactly one. Maharashtra's February uplift to ₹300 for
the top band (`:1911-1913`), keyed on the state name, the ₹200 trigger and the
literal month `'02'`. It survives a configured Maharashtra slab, by design. No
other periodicity is expressible: TN, Kerala and MP slabs are stored as
"monthly equivalents" of half-yearly or annual bases (in-source comments at
`:1860`, `:1862`, `:1863`), and the PT report annualises them as `monthly × 12`
for every state except Maharashtra (`StatutoryController.php:95-98`).

### Q4 — Is there any concept of jurisdiction driving statutory applicability?

**No jurisdiction model exists. There is one weak state dimension and one dead flag.**

What exists:
1. `employees.pt_state` — free text, drives **only** the PT slab lookup.
2. `statutory_configs.scope ∈ {branch, location, company, state, tenant}` with `scope_value` — a **single string column** holding a state name, a branch **id**, or a branch **city**, matched by substring (except branch, which is an integer compare). It reaches **only** `computeSlip` / `computeSlipFromComponents` and three reports (DEFECT-F-25).

What does **not** exist:
- **No establishment entity.** No PF establishment code, no ESI code, no PT registration number, no LWF registration, no shops-and-establishments registration anywhere in the schema.
- **No employee → establishment posting.** `employees.branch_id` and `company_id` exist, but neither carries any statutory registration.
- **No applicability determination.** `employees.pf_applicable` (bool) and `employees.esi_applicable` (auto|yes|no) exist, are captured in the employee form, accepted by the import template, written by self-onboarding, exported, and **printed on the payslip** — and **no code reads them** (DEFECT-F-01). PF is deducted for everyone (except probation/internship); ESI is decided purely by the gross threshold.
- **No coverage-threshold logic.** The 20-employee PF trigger, the 10/20-employee ESI trigger, ESI-implemented-area geography, exempted establishments, contract-labour registration — none exist.
- **No jurisdiction-driven scheme selection.** Which schemes apply is a global constant: PF, ESI, PT, TDS, LWF, always, everywhere.

The design intent is explicit and is itself the limitation. `StatutoryConfig.php:24-28` and `StatutoryConfigController.php:27-31` both state: *"only PROFESSIONAL TAX genuinely varies by state in law. PF, ESI and TDS are central Acts and identical across India, so their scope rows exist for a company's own business choices, never because the law differs."* The same codebase contradicts this — `min_wages` is keyed by state/zone/category, and LWF is annotated "state-specific" (`SettingsController.php:43`) — and it is contradicted in practice by establishment-level PF/ESI registration, exemption, and area-notification differences.

**Effectively: a single flat tenant-level configuration with one bolted-on state dimension for PT.**

### Q5 — TDS/income tax: what exists, what regime, what is missing. Bluntly.

**What exists**

| Piece | Where |
|---|---|
| New Regime annual slab walk (`upto`/`rate`, `0` = and above), 87A cliff-to-nil, cess multiplier | `newRegimeTax()` `:1752-1766` |
| Monthly salary TDS = `newRegimeTax(annual − std_deduction) ÷ 12`, with 87A marginal relief | `salaryTdsMonthly()` `:1824-1843` |
| 194H commission TDS: flat rate, `max(rate, noPanRate)` when PAN is absent | `:1712-1715` |
| Form 24Q statement PDF, 26Q commission-deductee register PDF | `statutoryPdf()` `:1717-1739`, `:1694-1716` |
| A quarterly filing "history" | `tdsReturnsHistory()` `:1769-1813` |
| A manual `tds_returns` filing tracker (runtime table, unconnected to the above) | `MasterController.php:174-183` |
| A duplicate slab walk in JavaScript | `AppController.php:11167-11177` |

**Regime handling: New Regime only, tenant-wide, with no election.** There is no
Old Regime, no per-employee regime choice, no default-regime rule and no
switch-date logic. The slab table is a single tenant-wide array; a tenant cannot
hold both regimes, and the slabs cannot be effective-dated or scoped.

**The gap, stated plainly. This is not a TDS engine; it is a slab calculator.**

1. **No Old Regime.** Employees who are better off under it cannot be paid correctly.
2. **No investment declarations.** No 80C, 80D, 80CCD(1B), 80E, 80G, 24(b) — no declaration screen, no table, no field.
3. **No proof submission or verification.** No declaration window, no proof window, no lock date, no rejection path.
4. **No HRA exemption.** Rent paid, metro status and Basic+DA are nowhere in the tax path — even though HRA is computed on the payslip.
5. **No LTA, no perquisites, no Form 12BA.**
6. **No other income, no house-property loss, no previous-employer income or TDS** (Form 12B).
7. **No year-to-date true-up.** TDS is `annual ÷ 12` in every month. A mid-year joiner, an increment, a bonus, an arrear or an exit produces a wrong monthly deduction that is never corrected. There is no "shortfall recovered in the remaining months of the year" logic at all.
8. **The base is CTC, not taxable salary.** `salaryTdsMonthly($ctc)` (`:2111`); the component path uses `wageGross × 12` (`:2281`). Employer PF, gratuity accrual and non-taxable components are not removed; taxable perquisites are not added.
9. **No surcharge.** No 10/15/25/37% bands, no surcharge marginal relief. Every high earner's TDS is understated.
10. **87A is a hard cliff inside the tax function; relief is applied by only one of two callers.** The payslip and the 24Q disagree by up to ₹62,400 for the same employee (DEFECT-F-06).
11. **No Form 16 (Part A or Part B), no Form 12BB, no Annexure II.**
12. **No challan, BSR code, deposit date, or deductee-wise deposit mapping.**
13. **The 24Q "history" is fabricated** — total ÷ 4 per quarter, current quarter `Pending`, all six prior quarters hard-coded `Filed` and `deposited` (DEFECT-F-07).
14. **No lower/nil deduction certificate (197), no 206AB higher-rate-for-non-filers, no TDS on any other section** (192A, 194C, 194J…).
15. **No admin override of a computed TDS figure**, despite the docblock at `:1821-1822` promising "a payroll admin can still override".

For an Indian payroll product, items 1, 2, 3, 4, 7, 9 and 11 are each independently disqualifying.

### Q6 — Are PF/ESI wage bases derived from component flags, or from hard-coded component names?

**From hard-coded component NAMES, by string matching. This is the single most brittle mechanism in the statutory engine.**

| Base | How it is derived | Evidence |
|---|---|---|
| **Basic** | `str_contains(strtolower(code . ' ' . name), 'basic')` | `computeSlipFromComponents()` `:2173` |
| **DA** | `str_contains($name,'dearness') \|\| preg_match('/(^\|[^a-z])da([^a-z]\|$)/', $name)` | `:2269` |
| **PF wage** | `min(Basic + DA, pf_wage_cap)` — where Basic and DA are the strings above | `:2273` → `:2066` |
| **PF wage (no components)** | `basic = gross × 0.5` — a hard-coded split | `:2103`, `:2107` |
| **Basic fallback** | if no component matched 'basic', **the first earning in the map** becomes the PF base | `:2260-2262` |
| **ESI base** | `wageGross` = gross − reimbursements | `:2220`, `:2273` |
| **Reimbursement** | `category === 'reimbursement'` **(a real flag)** OR `str_contains($text,'reimburs')` | `:2181-2188` |
| **HRA** (display) | `stripos($name,'hra') \|\| stripos($name,'rent')` | `:2316` |
| **ESI period lock** | `stripos($deductionKey, 'esi') !== false` on the stored payslip JSON | `:2036` |
| **Earning classification** | keyword list `['commission','incentive','bonus','overtime','arrear','ex-gratia','ex gratia','payout','variable']` | `:1620-1624` |

There **is** a `salary_components` table with structured columns —
`ctype` (earning|deduction), `category` (fixed|variable|reimbursement), `base`
(fixed|pct_gross|pct_basic|balance), `calc_value`, `seq`, **and a `taxable`
boolean** (`MasterController.php:242-247`). Only `ctype`, `base`, `calc_value`,
`seq` and `category` (for reimbursement) are used. **`taxable` is never read.**
There is **no** `pf_applicable`, `esi_applicable`, `pt_applicable`, `lwf_applicable`
or `esi_base` flag on a component.

Concrete failure modes, all live:
- A component named **"Basic Pay"** matches; **"BASIC SALARY"** matches; **"Consolidated Pay"** does not — and PF then falls back to *the first earning in the map*, silently.
- The DA regex matches **"DA"**, **"D.A."** and **"Dearness Allowance"** — and also any name where "da" is bounded by non-letters, e.g. **"Sunday-Allowance"** (`day-` is fine, but `-da-` is not; the boundary class is `[^a-z]`, so digits and punctuation both open the match).
- Renaming the ESI deduction line to **"State Insurance"** silently disables the entire ESI contribution-period lock (BR-STAT-005).
- A **non-taxable** component cannot be excluded from the TDS base except by naming it "reimbursement".

**SmartPRS 3 requirement:** every statutory base must be assembled from
**explicit per-component boolean flags** — `include_in_pf_wage`,
`include_in_esi_wage`, `include_in_pt_gross`, `include_in_lwf_wage`,
`include_in_gratuity_wage`, `include_in_bonus_wage`, `taxable` — never from the
component's display name.

### Q7 — Minimum wages, gratuity and bonus: present, partial or absent?

| | Verdict | Detail |
|---|---|---|
| **Minimum wages** | **PARTIAL — register present, enforcement absent** | The `min_wages` master (runtime table, `MasterController.php:327-332`) has exactly the right columns: `state`, `zone`, `category`, `monthly_min`, `effective_from`, `status`. **The single consumer ignores every one of them**: `ReportController.php:136-155` takes `MAX(monthly_min)` across all tenant rows and compares it to **CTC/12** for every employee. So a tenant holding a Delhi skilled rate and a Bihar unskilled rate compares everyone to the highest. There is **no payroll enforcement** — nothing blocks or warns when a payslip falls below the minimum. The bonus register's own note says "use the state minimum wage if higher"; the code caps at the literal ₹7,000 and never reads the table (`StatutoryController.php:139,164`). Compliance basis should be Basic+DA, not gross. |
| **Gratuity** | **PARTIAL — two unreconciled provisions, no payment** | (1) A provision report: `(15/26) × monthly Basic × floor(years)`, eligible at 5+ years, rounded to whole rupees (`StatutoryController.php:55-57`). Basic is recomputed as 50% of gross, **ignoring `salary_components`**, so a company with a different structure gets a wrong Basic. No ₹20,00,000 ceiling. No 15/30 variant for establishments outside the Act. No death/disability waiver of the 5-year rule. (2) A **different** accrual on the payslip employer-cost strip: `Basic × 4.81%` (`AppDataController.php:1543`). The two never reconcile. **No gratuity is ever calculated at exit, stored, approved or paid**; there is no link to full-and-final settlement. |
| **Bonus** | **PARTIAL — register present, payment absent** | `StatutoryController::report('bonus')` (`:113-165`) implements eligibility (Basic ≤ ₹21,000), the bonus-wage cap (₹7,000), the 8.33–20% clamp, and FY proration for joiners. Only the rate is configurable; every other parameter is a literal. **Nothing pays it** (BR-STAT-015) — the note tells the user to go and create Bonus & Encashment entries by hand. No set-on/set-off, no allocable surplus, no available surplus, no minimum-vs-maximum determination, no Form C/D register, no link to minimum wages. |

### Q8 — The compliance module

**Item types.** Exactly **three**, all compiled in: **DRA** (IIBF Debt Recovery
Agent certification), **PCC** (police clearance), **BGV re-verify**. They are
literal `$kind` strings passed to a closure (`ComplianceController.php:102-103`,
`:122`, `:141`). Four **sources** feed the three types: `employees.dra_expiry`,
`employees.pcc_expiry`, `dra_certs.expiry`, `bgv.next_due`.

The audit report adds six further checked items — photograph, PAN, UAN, ID card,
references, Code of Conduct acknowledgement, bank authorisation, borrower
complaints, disciplinary actions — but these are checked **only** at report time,
never scanned, never alerted on, and every one is a hard-coded case in
`agentAuditData()` `:556-629`.

**Extensible by a customer? No — categorically not.** Adding "Aadhaar re-KYC",
"driving licence expiry", "insurance renewal", "vendor agreement expiry" or
"training certificate validity" requires editing `ComplianceController.php` and
shipping a release. There is no item-type table, no registry, no plugin point.
`MasterController` will happily create a new *register table* from a def, but
nothing scans it.

**Due dates and alerts.**
- BGV is the only computed due date: `next_due = verified_on + revalidate_months`, calculated on save (`MasterController.php:745-753`). `revalidate_months` is per row — the only configurable renewal period in the module.
- DRA and PCC expiry dates are **typed in by hand**. There is no issue-date-plus-validity derivation, despite the UI labelling the field "Expiry / Revalidation".
- `pcc_deadline` — the column that would express "certified within N days of engagement" — is **selected and never evaluated** (DEFECT-F-16). The migration that retired the stored `'overdue'` PCC status justified doing so on the basis of this derivation; the derivation does not exist, so the migration **destroyed** data.
- Bucketing: `days = today.diffInDays(expiry, false)`; ignore if `days > window`; `expired` if `days < 0`, `critical` if `≤ 7`, else `soon`. Window default 30. All four numbers are literals (`:31`, `:76-81`).
- Delivery: `Schedule::command('compliance:scan')->dailyAt('07:30')->withoutOverlapping()->onOneServer()` (`routes/console.php:143-146`) → `notify()` → for every tenant with at least one non-deleted employee, scan, build a plain-text body from the **top 25** rows, and queue **one email per HR/Admin recipient** via `MailService`. Recipients resolve through Spatie pivots and **fail closed** if nothing resolves (`:269-272`) — a good decision. No dedup, no throttle, no snooze: the same digest goes out every morning until someone renews. **The agent is never notified about their own expiry.**
- The in-app screen (`alerts()`) accepts `?days=N` with no upper bound, does not persist it, and **has no role guard** (SEC-F-01).

**Evidence and audit trail — thin to absent.**
- The compliance registers store **metadata only**: `dra_certs` has `cert_no`, `institute`, `track`, `issue_date`, `expiry`, `status` — **no attachment**. `bgv` has `agency`, `checks`, `status` — **no report file**. A `documents` table exists and is **not linked** to either.
- The only real audit trail is `activity_logs`, which receives exactly three compliance events: `dra_override` (`MasterController.php:917-928`), `incentive_dra_invalid` (`RequestController.php:1381-1389`), `incentive_low_compliance` (`:1425-1433`). All three are `insert`-and-swallow.
- **Nothing in `ComplianceController` writes an audit record.** Generating a regulatory audit PDF for an agent — or 150 of them — leaves no trace.
- The audit PDF's "tamper-evident hash" covers none of the report's content (DEFECT-F-10) and the footer's claim of a "hash-chained audit log" is unsupported.
- Code-of-Conduct acknowledgement records a date and a display name. No version of the acknowledged text, no history (re-acknowledging overwrites), no IP, no user agent, no signature (`:150-176`).
- The audit report's signature block is three ruled lines with **no signatory captured**.
- There is **no compliance dashboard** beyond the alert list, and no history of past compliance state — only "as at today".

### Q9 — What would Ametecs have to do TODAY to ship a PT slab change to 200 on-prem customers?

**Traced end to end. There are two paths and neither is acceptable.**

**Path A — the real one: a code change and a full application release.**

1. Edit `PT_STATE_SLABS` in `app/Http/Controllers/AppDataController.php:1854-1871`. It is a **`private const` compiled into a controller**.
2. Edit the hand-maintained copy in `tests/manual/pt_extract.php` — the file carries an explicit instruction to re-extract on every change (`tests/manual/check_pt.php:11-20`). Miss this and the only behavioural PT test silently stops testing production code.
3. Update the affected expectations in `tests/manual/check_pt.php`.
4. Consider `AppController.php:11161`, `KbController.php:211` and `CalcGuideController.php:88,136` if any user-visible text quotes the changed figure.
5. Build a release package and publish it to the update server (`UpdateServerController`), bumping `version.json` (`{"product":"smartprs","version":"2026.6.2","channel":"stable"}`).
6. **Each of the 200 installs must then be updated individually.** The flow (`app/Services/UpdateClient.php`, `updater/updater.php`) is:
   - `POST /app/updates/check` — an **admin presses "Check for updates"** in the SPA (`routes/web.php:244`, `throttle:10,1`). **There is no scheduled auto-check** — `routes/console.php` schedules `compliance:scan`, `backup:clean` and the error digest, and **nothing for updates**.
   - The install must be **online** and hold a valid licence key + machine fingerprint (`UpdateClient::check()` `:109`).
   - `POST /app/updates/apply` — the admin presses install (`throttle:5,10`). `UpdateClient` downloads and verifies the package but deliberately does **not** install ("PHP cannot reliably overwrite the very files it is executing… on Windows/IIS it cannot overwrite them at all while they are open").
   - `updater/updater.php` launches as a separate process: `artisan down` → back up the current tree to `storage/app/updates/backup_<stamp>` → replace top-level directories → **`artisan migrate --force`** (`updater.php:307`) → post-update checks → `optimize:clear` → `artisan up`. Any failure rolls the tree back.

   So shipping a PT slab change means **replacing the entire application and running database migrations on 200 production servers**, each initiated manually by that customer's own administrator, each on their own timetable, most on Windows/IIS or Laragon/XAMPP.

7. **Realistic outcome:** the slab change reaches customers over weeks or months, on 200 different dates, some never. A statutory change with a fixed statutory effective date will be applied late — or not at all — on an unknown subset of installs, and Ametecs has no telemetry showing which.

8. **Worse: the change is not effective-dated.** `PT_STATE_SLABS` is a constant. Once the release lands, **regenerating any earlier month uses the new slab**. There is no version, no date, and no snapshot on the payslip to fall back on (DEFECT-F-03).

**Path B — the F1 escape hatch, per tenant, by hand.**

An admin or HR manager at each install can open **Statutory Configuration**, use
the slab editor, and save a `pt_state_slabs` override with an effective date
(`POST /app/statutory-config/slab`, `StatutoryConfigController::saveSlab()`
`:246-309`). This **is** effective-dated, scoped, audited and history-kept, and
an explicit slab outranks the built-in.

Why this does not solve the problem:
- It is **200 separate manual data-entry operations by 200 different people**, none of whom is a statutory expert, on a screen that requires the `statutory.config` capability (`FeaturePermissions::MAP` `:41-44`, roles `admin`/`hr_manager`) and the F1 feature to be enabled for that edition.
- Ametecs has **no mechanism to push a configuration row** to an install. There is no configuration channel, no content feed — the update server ships **application packages only**.
- A save whose effective date falls in a finalised month is **refused** unless the actor is a Super Admin (`EffectiveDated::guardFinalisedPeriod`), so a back-dated statutory change requires escalation at each site.
- It fixes the **payslip** only. The PF/ESI/24Q challan PDFs, the SPA's own contribution register, the money gates and the compliance scorecard all read the flat blob and never see the override (DEFECT-F-25).
- Each admin must transcribe the bands correctly; a typo of the form `[[15000]]` sets that state's PT to **₹0 silently** (DEFECT-F-24).

**Conclusion.** *Today, a Professional Tax slab change is a PHP code change
shipped as a full application release, installed manually and individually on 200
production servers by 200 customer administrators, with database migrations, with
no scheduled check, with no effective dating, and with no way for Ametecs to
verify it landed.* This is the strongest single argument in this analysis for the
SmartPRS 3 governing principle, and it yields the area's highest-priority
non-functional requirement: **statutory content must be a signed, versioned,
effective-dated data pack, delivered over a channel independent of the
application release, applied without a site administrator's intervention, and
verifiable centrally.**

---

## 12. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **[DECISION REQUIRED] Every statutory value in §Q1 needs legal verification and dating.** Not one of the ~90 values in this codebase carries a source citation, an effective date or a verification record. Ametecs must commission a qualified review of all of them, and SmartPRS 3 must refuse to ship a statutory parameter without `(source_reference, effective_from, verified_by, verified_on)`.

2. **[DECISION REQUIRED] `pf_applicable` / `esi_applicable` are populated but ignored.** Making them work will change payroll outcomes for existing customers who have set them. Options: (a) honour them from a cutover date with a mandatory pre-cutover reconciliation report; (b) migrate all values to "auto" and re-collect; (c) honour them and reprocess. **This decision must be made before any migration is designed.**

3. **[DECISION REQUIRED] Does SmartPRS 3 commit to Old Regime + full declaration/proof TDS?** This is the largest single build item in this area (§Q5, items 1–11). If the answer is no, the product cannot honestly be sold as an Indian payroll system.

4. **[DECISION REQUIRED] Which PT jurisdictions must SmartPRS 3 ship?** Today 26 of ~36 are named, and an uncovered state silently gets Telangana's slab rather than zero or an error. What is the correct behaviour for an unmapped jurisdiction: refuse to run payroll, charge zero, or require explicit configuration?

5. **[DECISION REQUIRED] How does statutory content reach on-prem installs?** See Q9. Options: signed content packs over a dedicated channel with a scheduled pull; a mandatory phone-home; or an accepted lag. This is the highest-value non-functional decision in this area and it drives the SmartPRS 3 architecture.

6. **[DECISION REQUIRED] The fabricated assertions must be removed or made real, now.** The 24Q "Filed" status (DEFECT-F-07), the audit report's "Disciplinary actions: 0 · Compliant" (DEFECT-F-09), the "tamper-evident hash" (DEFECT-F-10), and the Code-of-Conduct false positive (DEFECT-F-11) all cause SmartPRS 2 to make regulatory assertions it has not verified. These are live in production. Does Ametecs remediate SmartPRS 2, or accept the exposure until SmartPRS 3?

7. **[DECISION REQUIRED] Migration `2026_08_27_000100` destroyed PCC `'overdue'` data** on the stated basis that the deadline-derived equivalent already existed. It does not (DEFECT-F-16). Is that data recoverable from backups, and should it be restored?

8. **[DECISION REQUIRED] Do gratuity and bonus become payable, or stay as registers?** Both are complete calculators with no payment path (BR-STAT-015). If they become payable, they need approval workflow, F&F integration, ledger posting and their own statutory treatment.

9. **[DECISION REQUIRED] What is the compliance module's target regulatory scope?** It is built for RBI recovery-agent norms specifically. Is SmartPRS 3's compliance engine general-purpose (any expiring credential for any industry), or does it stay collections-specific with a general engine underneath? This determines whether item types are a first-class configurable entity.

10. **[DECISION REQUIRED] Off-roll agents.** They are the population most exposed to RBI agent norms and are entirely outside compliance, with untaxed payouts (BR-CMP-029, DEFECT-F-44). Do they become full employees of the compliance model, a parallel vendor model with its own contractor-compliance rules, or out of scope?

11. **[OPEN] Which of the five DRA-validity rules is correct?** (DEFECT-F-08). Specifically: does an *expired* structured certificate fall back to a legacy `dra_status='verified'` (as the audit report allows) or not (as `draValid` refuses)? This changes who gets paid.

12. **[OPEN] Should `pcc_status='submitted'` count as compliant?** The audit report says yes (`:579`); the score says no (`:303`). One of them is wrong.

13. **[OPEN] Is the ESI contribution-period lock's behaviour correct in edge cases** — a rejoiner mid-period, a transfer between establishments, a retrospective arrear that pushes an earlier month over the ceiling? The current implementation reads only stored payslip deduction *names* and has no concept of establishment.

14. **[OPEN] What is the correct annualisation for half-yearly PT states** (TN, Kerala) and annual-capped states (MP, MH)? The current "monthly equivalent × 12" is wrong for at least four states (DEFECT-F-29), and the underlying slabs are stored in a shape that cannot express the real periodicity.

15. **[OPEN] Should the Code of Conduct be tenant-owned?** It is currently global KB content shared by every tenant (SEC-F-06), which is almost certainly wrong for a legal undertaking staff sign.

16. **[OPEN] Retention and deletion.** `data_retention_months` (default 84) exists in `defaults()`, is unsettable through any UI, and **has no consumer**. `BR-CMP-311` records "No business record is ever removed on age — NOT IMPLEMENTED". What is the DPDP-compliant retention policy for compliance evidence and statutory records?
