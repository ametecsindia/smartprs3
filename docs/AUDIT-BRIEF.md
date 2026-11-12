# Screen-by-screen parity audit against SmartPRS 2 — the brief (10 Sep)

Read `docs/AGENT-BRIEF.md` first (products, seven laws, drop-in mechanism, dev server, checks).
This brief is the AUDIT variant: you are not building a section from nothing, you are bringing
EXISTING SmartPRS 3 screens up to SmartPRS 2's format and function, and wiring every field.

## The owner's standing rule

Every sub-menu must have SmartPRS 2's FUNCTIONALITY AND INTERFACE, built from PRS2's screens, with
the customisation mapped to Organization Setup, and EVERY FIELD WIRED to its real source (a
register, the attendance settlement, the payroll register, the leave ledger…) — never a free-text
stand-in for something the product already knows. Also, from the owner's corrections this week:

* every filter dropdown lists **ALL** first (use `orgFilterBar()` / `orgFilter()` / `orgFilterApply()`
  for company / branch / department / designation / team / employee);
* every dated list takes the **period picker** (`reportPeriod()` + `periodBar()` in `public/app.php`:
  Month / Year / Financial Year / YTD / MTD / Custom dates) — see `surface/screens/attrition.php` and
  `surface/partials/list-report.php` for the pattern (data partial shared by screen and export);
* every summary/list has **Excel + CSV export** of exactly the table shown (`Sheet::xlsx`,
  `FieldReports::csv`), and a chart where a chart says something (`chartBars()`);
* add forms appear on demand (an "Add" button), edit/delete per row, no duplicate rows, retired
  rows hidden, unique codes per tenant, ALL-CAPS column headers on sample files;
* photo/document fields are upload fields; Yes = green, No = red; icon-only clear-background action
  buttons; the ⓘ screen guide on every page (`config/screen-help.d/<section>.json` routes map);
* the word is **employee**, never "employment", in anything a user reads.

## How to audit one PRS2 screen

1. Find PRS2's screen: `grep -n "'<id>'" /mnt/user-data/uploads/smartprs2/app/Http/Controllers/AppController.php`
   → the JS function that renders it (read ±250 lines: columns, filters, buttons, modals, totals);
   the help entry `config/screen-help.json` → `screens.<id>` (`f` features, `s` steps) is the checklist.
   Server logic: the matching controller under the same folder (e.g. `LeaveController.php`).
2. Find ours: `config/screen-help.d/*.json` `routes` maps our route/module key → PRS2 id; sidebar items
   are in `config/navigation.json` + `config/navigation.d/*.json`. Hand-built screens live either in
   `surface/screens/<route>.php` or as an `elseif ($route === '<route>')` block in `public/app.php`.
   Sign in with curl (AGENT-BRIEF) and fetch the page; read the source.
3. Write the gap list: PRS2 feature → present / missing / different. Then implement the missing ones,
   configuration first (`config/*.json`, `config/modules.d`, `config/navigation.d`), drop-in screens and
   actions second, engine classes in `surface/` third. You MAY edit the route block for YOUR screens in
   `public/app.php` — nothing else in that file — and you must `php -l` it after every edit.
4. A field is wired when its options come from the register that owns them (companies, branches,
   departments, designations, teams, employees, leave types, shifts, pay cycles…), figures come from
   the engine that computes them, and saving writes the schema's real column. A new column is a
   migration file (copy `database/migrations/2026_03_02_001100_prs2_register_fields.php`), applied with
   `php tools/db/migrate.php`, then noted in your report so the owner runs it too.
5. Do NOT touch the screens updated on 9 Sep: Reports hub, Datasets, Headcount, Attendance Summary,
   Leave Summary, Salary Register, PF/ESI Summary, Birthdays, Attrition, Activity Logs, Salary &
   Commission Ledger, Daily Attendance / Attendance Report (muster), PF & ESI, TDS, Salary Advance,
   Working Shifts, Pay Cycle, Directory (Employees list/Add/Import), Self-onboarding, ID Cards, Exit &
   F&F (Settle), Late Policy and Salary Components registers, Branches/Departments/Designations/Teams
   registers, Headcount.

## Before you report back

`php -l` every PHP file you touched · `php tests/run.php <yours>` and `php tests/run.php navigation` and
`php tests/run.php csrf` · `php tools/gates/gate.php` PASS · `php tools/modules-writable.php` if you
declared/changed modules · curl each route you touched: 0 warnings. Do NOT run check.php, smoke, scope
or exposure (the lead runs them once).

## Your final message

Per screen: PRS2 feature list → what was already there, what you added (files), what you deliberately
did not and why. Migrations added. Tests and counts. Terse and concrete.
