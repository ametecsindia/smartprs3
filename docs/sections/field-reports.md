# Field Force and Reports & Analytics — from SmartPRS 2

## What SmartPRS 2 had

**Field Force** (`field`): `escalations`, `agent-auth`, `dra-certs`, `complaints`, `compliance-alerts`
(and `offroll-agents` under Employees). Off-roll agents, agent authorisations and DRA certificates
were `MASTER_MAP` registers; escalations and complaints too. Compliance alerts and geofence belong
to the statutory and time sections here.

**Reports & Analytics** (`reports`): `reports` (a dataset preview — employees / payslips / leaves /
attendance — with a company filter, a month, and Export CSV, served by `ReportController`),
`attrition` and `activity-logs` (computed read-only screens).

## What is built here

Everything is in files named `field-reports`; no shared file was edited.

### Sidebar (`config/navigation.d/field-reports.json`)

Field Force: Escalation Desk (→ existing `escalation-desk`), Off-roll Agents (module), Agent
Authorisations (`agent-auth` screen), DRA Certificates (`dra-certs` screen), Complaints (existing
module). Reports & Analytics: Reports (existing hub), six list reports, Attrition (→ `attrition`,
owned by the performance section), Activity Logs (→ existing `trail`).

### Modules (`config/modules.d/field-reports.json`)

`offroll-agents` and `agent-authorizations` SUPERSEDE the thin entries in `modules.json`: company
and branch lookups from Organization Setup, vendor / payout type / rate / DRA / PCC for agents;
employee lookup, bank (lookup from the Banks register), portfolio, letter number, valid from / to
for authorisations. Migration `2026_03_02_000600_field_force_columns.php` adds
`offroll_agents.branch_id` and `agent_authorizations.valid_from` (applied; grants re-applied).

### Screens

* `surface/screens/agent-auth.php` — authorisations joined to the employment, Organization Setup
  filter with ALL, ordered expiring-within-the-window first, then expired (most recent first), then
  valid, then undated; band counts; Edit links into the module; CSV.
* `surface/screens/dra-certs.php` — every live employment's `dra_status` / `dra_expiry` banded the
  same way; per-row link to `?r=employees&edit=<id>#add-employment`; CSV.
* `surface/screens/report-<key>.php` × 6 (headcount, attendance-summary, leave-summary,
  salary-register, pf-esi-summary, birthdays) — three-line files including
  `surface/partials/list-report.php`; the rows come from `surface/partials/list-report-data.php`,
  which the CSV action includes too, so the file and the page cannot differ.
* `surface/actions/field-report-csv.php` — one POST action for every CSV above; audited.
* `surface/FieldReports.php` — the pure computations: expiry bands and ordering, headcount,
  attendance and leave summaries, salary register from the closed run's own lines (paise), PF/ESI
  summary, birthdays, CSV. Money cells stay `Money`; day/hour quantities go through
  `Amount::quantity`; counts are ints.

### Configuration (`config/field-reports.json`)

`expiry_window_days` (30), `employee_edit_anchor`, `applicable_values` (what counts as inside PF /
ESI), and the six reports with label, question, permission, whether they take a month, and the
headcount groupings.

### Why list reports are not rows in `config/reports.json`

`Reports.php` is a one-table GROUP BY engine with no Organization Setup filter and no CSV, and the
hub route lives in `public/app.php`, which is shared. The PRS2 reports are per-employee registers
with a scope filter and an export, so they are declared in their own file and rendered by one
partial — still configuration, still one screen per row. Folding an org filter and CSV into
`Reports.php` is the upgrade path once that file's owner agrees; nothing here would need to move.

## Not ported, and why

* PRS2's raw dataset dumps (every column of `employees` / `payslips` / `leaves` / `attendance`)
  — Audit Reports (statutory) already exports registers by table; the PRS2 dumps are that with
  fewer controls.
* Attrition — owned by the performance section; only the sidebar item is here. Until that route
  exists `php tests/run.php navigation` reports the item as a dead link.
* Off-roll agent KYC documents (`offrollKyc` row action) — the documents module holds files per
  person; a per-agent document drawer is a documents-section concern.
* PRS2 `dra-certs` as its own table (certificate number, institute, training track) — PRS3
  already carries `dra_status` / `dra_expiry` on the employment and the employee form records
  them; a second table for the same fact is the thing Law 3 forbids. Certificate number / institute
  are the next two employee fields in `config/employee-fields.json`, not a new register.

## 9 Sep — Attendance summary rebuilt; period picker, charts and Excel on every summary

* `surface/screens/report-attendance-summary.php` no longer reads the legacy `attendance` table. It folds
  `AttendanceReport::musterWide()` (the same settlement the register pays from) through
  `surface/AttendanceSummary.php`, with every column declared in `config/attendance-summary.json`
  (status counts, payable days with weights, late/worked/break/overtime hours, regularisation).
* Period: `reportPeriod()` / `periodBar()` in app.php — Month, Year, Financial Year (start month from
  Organization Setup → Financial Year), YTD, MTD, Custom dates. Filters: every Organization Setup level with
  ALL. Group by employee / company / branch / department / designation / team. Tick the columns to show.
* Chart: `chartBars()` — a stacked inline-SVG bar per row, no library. Export: Excel (`Sheet::xlsx`) and CSV
  of exactly the table (`surface/actions/attendance-summary-export.php`, same data partial as the screen).
* Every list report (`surface/partials/list-report.php`) now has the chart, the Excel button, and — where
  `period: true` in `config/field-reports.json` — the period picker (leave summary). Salary register and
  birthdays stay month-based by nature. Pay ledger and the muster take the picker too (muster: month / MTD /
  custom, since a muster grid is at most two months wide).

## 9 Sep (later) — the rest of the Reports family

* Reports hub: every declared report now takes the Organization Setup filters (through the employees its
  rows belong to) and, where `date_column` is declared, the period picker; bar chart; Excel + CSV.
  `attendance-by-status` read a legacy `attendance` table nothing writes — replaced by `punches-by-source`
  over `attendance_logs`; the dashboard's "Attendance open" tile now counts pending regularisations.
* Datasets (`surface/screens/report-datasets.php`, `config/report-datasets.json`) — SmartPRS 2's "preview any
  dataset and export it": employees, payslips (from the register's runs, not a payslips table), leave
  records, punches, regularisations, loans, advances. Scope + filters + period; preview 200; export all.
* Attrition, PF & ESI / TDS summaries and Activity Logs each gained the period picker, a chart where a
  chart says something, and Excel / CSV of exactly the table shown, all through a shared data partial so
  the screen and the download cannot disagree.
