# Extras — the last SmartPRS 2 sub-menus (section files `extras`)

## What SmartPRS 2 had

* **Roster** (Employees): a MASTER_MAP register — employee, company, team, date, shift,
  status (scheduled / present / absent / week-off). One row per employee per day; "a roster
  entry overrides the employee's default shift for that date".
* **Assets**: a register — asset tag, type, company, issued-to, issue date, status
  (issued / returned / damaged / lost). No cost, no serial, no who-issued stamp.
* **Salary Calculation Guide** (Payroll, `calc-logic`): prose sections + demo FAQs served as
  JSON from a controller, with worked rupee examples typed in ("26 days", "12%").
* **ZKTeco Devices** (Attendance, `att-zkteco`): the device register filtered to ZKTeco with a
  per-row "Sync" that pulled punches over LAN from IP:port.

## What was built

Drop-ins only: `config/navigation.d/extras.json` (three section blocks), `config/modules.d/extras.json`,
`config/screen-help.d/extras.json`, `config/how-it-works.d/extras.json`.

| Item | Route | What |
|---|---|---|
| Roster | `surface/screens/roster.php` (time) | month grid employee × day → shift code (bold = roster row, faint = scoped Working Shift), org filter bar, assign a shift to one person or everyone in the filter for a date range, cancel a row. `surface/Roster.php::shiftFor($employment, $date)`: the most recent live row covering the date wins (newest `created_at`, then the higher id), else `Shifts::for`. Action `surface/actions/roster-assign.php` (op assign / cancel), PRG, audited. Vocabulary `config/roster.json`. Table `rosters` (new). |
| Assets | module `assets` (admin) | supersedes the six-column module: code (`asset_id`, unique), category (`asset_type`), make/model, serial, purchase date, cost (DECIMAL 18,4, behind `employee.salary.view`), assigned to (employee lookup), assigned on, returned on, condition, status (in stock / assigned / under repair / retired), notes. Columns added by migration. |
| Assets — Issue & Return | `surface/screens/asset-issue.php` (admin) | issue / return / send for repair / retire along `config/assets.json` transitions; stamps issued_by/at and returned_by/at; `?emp=<id>` is the "assets held" list for Exit & FnF clearance (`Assets::heldBy`). Cost totals via `Amount::of` → `Money`, never a float. Action `surface/actions/asset-move.php`, PRG, audited. |
| Salary Calculation Guide | `surface/screens/calc-logic.php` (payroll) | nine steps — pay-cycle window, payable days (attendance-rules priorities and status worth, late_policy rows), gross and proration (cost basis), each component's rule, deductions and net, statutory heads (signed packs: rule, calculation, wage-base flag, source reference), recoveries, gratuity, bonus. `surface/CalcGuide.php` builds the sections from `$structure->versionOn`, `$payCycles->rules()`, `config/attendance-rules.json`, live `late_policy` rows, `$statutory->packsByHead`, `config/gratuity.json`, `config/statutory-bonus.json`. Every number is read; each section names its source. Read-only, prints via the browser. |
| ZKTeco Devices | `surface/screens/zkteco-devices.php` (time) | `devices` rows whose brand is `config/zkteco.json` `brand`; the push URL (request scheme + host + `ingest_path` = `/api.php/api/v3/attendance-punches`, headers and body example); per serial the punch count and last `punch_at` from `attendance_logs.device_sn` (one `page()` + `count()` per device); configuration steps and troubleshooting from the config; links to Biometric Devices, API Keys, Pending Punches, Punch Import. |

Migration `2026_03_02_000800_extras_tables.php`: creates `rosters` (tenant FK), adds to `assets`
`make_model, serial_no, purchase_date, cost DECIMAL(18,4), condition, notes, issued_by, issued_at,
returned_by, returned_at, deleted_at`. Applied; application account re-granted.

Tests `tests/extras_test.php` (94 assertions): roster precedence (range vs later one-day
override, older row never wins, equal-instant tie-break by id, cancel, refusals, grid), asset
code uniqueness through `Modules::problems` (case-insensitive, retired rows release the code,
self-edit is not a clash), issue/return state and stamps, held-by, cost sum from a
DECIMAL(18,4) string, repair; the guide quotes every rate/threshold from the files and a changed
rate changes the guide; a regex asserts no structure/statutory number is a literal in
`CalcGuide.php`; config cross-checks (transitions land on declared statuses, register options
equal the config vocabularies).

## Deliberately not ported

* Roster **status** (present / absent / week-off) — attendance status is decided by the
  published attendance rules from punches, not typed on a roster; a week-off is the working
  calendar's. The roster only says which shift.
* ZKTeco **Sync (LAN pull from IP:port)** — SmartPRS 3 has one programmatic surface (`/api/v3`);
  the server does not open sockets to devices. The screen tells the device (or its push
  middleware) where to push, and the offline path is Punch Import. Native ADMS (`/iclock/cdata`)
  parsing would be a new endpoint in `public/api.php`, which this section does not own.
* Calc guide **FAQs and worked rupee examples** — every one of PRS2's was a literal; a worked
  example here would be a payslip trace, which the closed run already stores per line.
* `Shifts::for` / `Attendance` are not changed to consult the roster (shared files). `Roster::shiftFor`
  is the hook; wiring it into day settlement is a one-line change in the attendance owner's
  file — TODO for the lead.
