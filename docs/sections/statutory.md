# Statutory & Compliance — SmartPRS 2 parity notes

Section key `statutory`. Files: `config/navigation.d/statutory.json`, `config/modules.d/statutory.json`,
`config/screen-help.d/statutory.json`, `config/how-it-works.d/statutory.json`, `config/gratuity.json`,
`config/statutory-bonus.json`, `config/compliance-calendar.json`, `config/audit-reports.json`,
`database/migrations/2026_03_02_000300_statutory_registers.php`, `surface/StatutoryReports.php`,
`surface/partials/statutory-head-summary.php`, `surface/screens/{pf-esi,tds,gratuity,statutory-bonus,compliance-alerts,audit-reports,statutory-config}.php`,
`surface/actions/{compliance-filed,audit-csv}.php`, `tests/statutory_section_test.php`.

Built ON `surface/Statutory.php` (the pack engine) and beside `surface/Compliance.php` (credential expiries,
`?r=compliance`, untouched). No rate, slab, ceiling, factor or due day is in PHP.

## PRS2 sub-menu → PRS3

| PRS2 id | PRS2 has | PRS3 | State |
|---|---|---|---|
| pf-esic | contribution register projected from CTC with rates typed into a settings row (PF 12%, ESI 0.75/3.25, ₹21k) + challan PDFs | `?r=pf-esi` — per employee per month what the CLOSED runs deducted under PF and ESI; the rate comes from the signed pack. With only the synthetic pack in force the screen states that and shows no figure. NEW module `pf-esi-register` (UAN, PF number, ESI IP number, applicable yes/no/exempt, from-dates) | Projection from typed rates: deliberately NOT ported — that is the "values attributed to nobody" Statutory.php refuses. Challan PDF: not ported, no signed rates to print |
| tds | annual TDS projected from CTC with slabs, std deduction, 87A, cess, 194H in the page; deductee register; 24Q PDF | `?r=tds` — TDS deducted per employee from closed runs. NEW module `tds-declarations` (PAN, regime, 80C/80D, HRA, projected TDS, status). Existing `tds-rules` module linked from Statutory Configuration | Slab projection: NOT ported for the same reason as PF; slabs belong in a signed pack. Commission 194H is Compensation's `Compensation::tds` |
| pt | PT report per employee (monthly/annual PT by state slab) | menu item `pt` → existing module `pt-slabs`; PT actually deducted per employee is the closed run's `D-PT` line on each payslip; `heads.PT` in compliance-calendar.json names those codes and the PT due date is on Compliance Alerts | Per-employee PT projection: not ported (slab lookup on a projected gross); the closed run is the figure |
| gratuity | per-employee accrual (4y 240d+) with a "how calculated" note | `?r=gratuity` — eligibility (5 years, part year ≥ 6 months counts), last drawn basic × 15/26 × years, cap; every constant `config/gratuity.json`; basic from the newest closed run's flagged lines, else structure % of gross, else gross — basis stated per row; org filter + as-at date; Money/Rational only | Parity+ |
| bonus | statutory bonus report (bonus wage/mo, annual at 8.33% and 20%) | `?r=statutory-bonus` — per employee per FY from closed runs: eligible months (≤ ₹21k), wage counted (≤ ₹7k), bonus at both ends of the band, working + per-month detail; `config/statutory-bonus.json`. NEW module `bonus-register` (Form C: declared/paid) | Parity+. Existing `bonus` module (bonus_encashment, Payroll group) left alone |
| tds-returns | quarterly tracker: company, quarter, deductees, deducted, deposited, balance, due, status | module `tds-returns` SUPERSEDED: adds company, financial year, filed_on, acknowledgement, remarks (migration) | Computed "balance" column: not ported (the register engine has no computed columns); deducted and deposited are both listed |
| compliance-alerts | DRA/PCC/BGV expiries (field-force) | `?r=compliance-alerts` — the STATUTORY due-date register: PF ECR 15th, ESI 15th, PT 20th, TDS deposit 7th, 24Q quarterly, per period per company, state overdue/due/upcoming/filed; "Mark filed" records a `compliance_filings` row (never overwritten; duplicate refused). Credential expiries stay on `?r=compliance` and are linked | PRS2's screen was credential expiries, which PRS3 already had; the brief asked for the due-date register, so this key is that, with a link across |
| posh | confidential IC complaints: subject, complainant, company, respondent, incident date, description, status, IC comment | NEW module `posh` over `posh_complaints`; view `employee.document.view`, so managers/viewers cannot open it; never bulk-exported | Row-level "complainant sees own": not ported (register engine has no row ownership) |
| min-wages | state, zone, category, monthly min, effective from, status | NEW module `min-wages` over `min_wages` (+ daily min, notification ref) | Parity. The "Minimum-wage check report" (Reports) not ported here |
| audit-reports | RBI recovery-agent audit PDFs with per-agent selection | `?r=audit-reports` — every statutory register with row count and CSV, org-filtered (`surface/actions/audit-csv.php`, audited); POSH listed as confidential, no export; computed registers link to their screens | Different by instruction: statutory registers, not agent PDFs. Computed-register CSV: not ported — open the screen and print |
| statutory-config | editable PF/ESI/PT/TDS rates | `?r=statutory-config` — READ-ONLY: packs in force per head (signed / synthetic), the three rule files rendered, links to the pf-esi / tds-rules / pt-slabs registers | Editing rates in a settings row: deliberately not ported (law 1; rates are signed packs) |

## Migration (applied)

`2026_03_02_000300_statutory_registers.php`: tables `pf_esi_registrations`, `tds_declarations`, `posh_complaints`,
`min_wages`, `statutory_bonus_register`, `compliance_filings` (tenant FK, employer_company_id, status, remarks,
timestamps, deleted_at); columns on `tds_returns`: financial_year, filed_on, acknowledgement, remarks, deleted_at.
Application account re-granted with `php tools/db/grant-application-account.php --apply` (new tables need it).

## Deliberately not done

* No statutory FIGURE is projected from a rate anywhere. Every rupee on these screens is a closed-run line or
  a gratuity/bonus computation whose constants are in a config file. Until a compliance owner signs a pack the
  PF & ESI and TDS screens say so and show totals of nothing.
* Bonus: the 30-working-days rule is stated, not judged (attendance is the Time section's word).
* Gratuity: DOJ from `Employment::joinedOn`; demo employees mostly lack it, so the demo shows "not eligible" with
  the reason.

## Shared files touched (one number each)

* `tests/schema_test.php` 176→182 tables, `tests/tenant_isolation_test.php` 154→160 tenant-scoped tables — the
  counts those suites assert so a new table is noticed. Each new table has one NOT NULL natural-key column
  (employee_id / subject / state / obligation) because the generated isolation fixture inserts only NOT NULL
  columns and an all-nullable table gives it nothing to insert. On this dev database the six columns were
  ALTERed by hand to match the migration after it had already run; a fresh migrate produces them directly.

## TODO for the lead

* `tools/db/grant-application-account.php --apply` must run on every environment after this migration.
