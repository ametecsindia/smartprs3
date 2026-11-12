# Building a SmartPRS 3 module section from SmartPRS 2 — the brief

You are porting ONE section of the sidebar from SmartPRS 2 into SmartPRS 3 so that every
sub-menu SmartPRS 2 had exists here with the same functionality and interface, built
from configuration wherever possible. Read this whole file before touching anything.

## The two products

* SmartPRS 2 (READ ONLY, never modify): `/mnt/user-data/uploads/smartprs2/app/Http/Controllers/`
  * `AppController.php` (1.2 MB, JS inside PHP). Screens are JS functions (`liveSalaryScreen`,
    `dailyAttendanceScreen`, …) dispatched from `go(id)` around line 3280; generic register
    screens are declared in `MASTER_MAP` (around line 10740) with fields, labels, hints and
    options. `grep -n "'<screen-id>'" AppController.php` finds a screen; read ±200 lines.
  * `ScreenHelpController.php` — the guide text of every screen (what it is for, features,
    steps, tip, roles). The screen ids there are the sidebar ids.
  * Other controllers (`LeaveController.php`, `IncrementController.php`, …) hold server logic.
* SmartPRS 3 (this repo, `/home/claude/SmartPRS3`): plain PHP 8.4, no framework, no Composer
  packages. `public/app.php` is the front controller (routes as `<?php elseif ($route === 'x'): ?>`
  blocks, POST handlers as `if ($action === 'x')`). `surface/` holds one class per capability.
  `config/*.json` holds every rule. `CLAUDE.md` holds the seven laws — READ IT.

## The seven laws (mechanical gates refuse violations)

1. No business literal in code — rates, slabs, thresholds, names live in config JSON.
2. No schema change at runtime — a new table or column is a file under `database/migrations/`
   (copy the shape of `2026_03_01_001600_shift_scopes.php`), applied with `php tools/db/migrate.php`.
3. Nothing overwritten — corrections supersede with dates and names.
4. Every number explains itself.
5. Authorisation server-side, fails closed — check `$may('permission')` at the top of every screen
   and action file and print/deny.
6. Tenancy structural — always go through `$store` (already tenant-scoped).
7. Money is exact — `SmartPRS\Platform\Money\Money::of('123.45')`, `->plus()`, `->format()`,
   integer paise via `Money::ofMinor()`. NEVER `(float)`, `floatval`, `number_format` on money.
   A gate scans for float casts — `(int) explode('.', $s)[0]` for a day count is the pattern.

Also: no new dependencies; no customer names anywhere; no empty `catch` (comment the exact
condition on the line inside, or `// gate:allow-swallow <reason>` on the catch line ONLY when
the catch really swallows); csrf: every `<form method="post">` must contain
`<input type="hidden" name="csrf" value="<?= e($session->token()) ?>">` within its first
400 characters.

## HOW YOU SHIP — the drop-in mechanism (do not edit shared files)

Never edit `public/app.php`, `config/navigation.json`, `config/modules.json`,
`config/screen-help.json`, `config/how-it-works.json`, `surface/Authorisation.php`, or any
file another section owns. Everything you build goes into files named after YOUR section:

| What | Where |
|---|---|
| Sidebar items | `config/navigation.d/<section>.json` → `{"section": "<section key>", "items": [ ... ]}` (or a JSON list of such blocks to add to several sections). An item is either a MODULE screen: `{"key": "expenses", "module": "expenses", "label": "Expense Claims", "icon": "fa-receipt", "permission": "employee.view"}` (rendered as `?r=m&k=expenses`), or a HAND-BUILT screen: `{"key": "gratuity", "route": "gratuity", "label": "Gratuity", "icon": "fa-coins", "permission": "employee.view"}` where `surface/screens/gratuity.php` exists. Section keys: main, hiring, people, time, leave, payroll, compensation, statutory, performance, learning, letters, field, communication, reports, admin, setup. Items already present in a section are kept; yours are appended. Icons are Font Awesome 6 free (`fa-...`). |
| Register screens (list + search + paging + Add/Edit/Delete form + "Customise this module") | `config/modules.d/<section>.json` → `{"modules": [ {module}, ... ]}` in exactly the shape of entries in `config/modules.json` — read `expenses`, `shifts`, `teams`, `escalations` there first. Keys: `key`, `group`, `label`, `description`, `table`, `columns` (list columns), `search`, `fields` (per column: `label`, `help`, `required`, `options` [list → dropdown], `type` (`color`/`file`/`time`/`date`/`textarea`), `lookup` `{table, value, show}` (org lookups for `employer_company_id`/`branch_id`/`department_id`/`designation_id`/`team_id` are automatic; for people use `{"table":"employees","value":"id","show":["emp_code","name"]}`), `empty` ("ALL"), `min`/`max`, `readonly`), optional `permissions` `{view, edit}` (default employee.view / employee.update), optional `unique` (columns unique per tenant; a `code` column is unique automatically). Rows with `deleted_at`/`is_active=0`/`status` inactive|retired|archived are hidden and never offered. Prefer a module screen to a hand-built one whenever PRS2's screen is a register with a form. |
| Hand-built screens (anything the register engine cannot express: dashboards, calculators, approvals, multi-step flows) | `surface/screens/<route>.php`. Included INSIDE the layout, in the front controller's scope: you have `$store`, `$may('perm')`, `$session`, `$scope`, `$audit(...)`, `$customisation`, `$modules`, `$employeeFields`, `$leave`, `$attendance`, `$calendar`, `$register`, `$notifications`, `$payCycles`, `$tenantId`, `$company`, helpers `e()`, `post()`, `liveRows($store,'table')`, `employeeLookups($store)`, `orgFilter()/orgFilterOptions()/orgFilterApply()/orgFilterBar()`, `orgValueOf()`, `Employment::code/label/joinedOn/ctc`, `Money`. Start the file with `<?php if (!$may('…')): ?><div class="card"><div class="problem">Denied…</div></div><?php return; endif; ?>`. Markup conventions: `.page-header` with `<h2>`, `.card`, `.row`, `.table-wrap table`, `.btn .btn-outline .btn-primary .btn-sm`, `.badge badge-green/red/gray/amber`, `.note`, `.problem`, `fieldset.form-section > legend` for form sections. Look at `public/app.php` around `$route === 'pay-cycles'` for a complete example of a list + Add/Edit/Delete screen. |
| POST handlers | `surface/actions/<action>.php`, included after the CSRF check for a signed-in session, same scope. Check permission first. On success set `$_SESSION['flash_notice']` (or `flash_problem`) and `header('Location: ?r=<route>'); exit;` (Post→Redirect→Get — a refresh must never repeat a save). Call `$audit('<verb>', '<type>', $id, $before, $after, 'why')`. |
| Logic beyond a screen | `surface/<Name>.php` (`namespace SmartPRS\Surface; final class Name`), constructor takes `RowStore $store` (+ config path). Autoloaded. |
| Rules and vocabularies | `config/<name>.json` — every slab, rate, threshold, list. |
| Tables/columns you need that do not exist | `database/migrations/2026_03_02_00NN00_<what>.php` (use `Schema::hasTable/hasColumn`; nullable columns; `tenant_id` FK on new tables exactly like `2026_02_01_000400_port_attendance_tables.php` does). Then `php tools/db/migrate.php`. Existing tables: `mysql -u root smartprs3 -e "show columns from <table>"`. Most PRS2 tables were already ported — check first: `mysql -u root smartprs3 -N -e "show tables"`. |
| Screen guide (the ⓘ on every page) | `config/screen-help.d/<section>.json` → `{"routes": {"<your route or module key>": "<prs2 screen id>"}, "screens": {"<prs2 screen id>": {...}}}`. The PRS2 text is already in `config/screen-help.json` for 120 screens — you usually only need `routes` mapping your routes/module keys onto PRS2 ids. |
| How-it-works line | `config/how-it-works.d/<section>.json` → `{"items": {"<sidebar item key>": "one line"}}`. |
| Tests | `tests/<section>_test.php` using the primitives (`group()`, `ok()`, `same()`, `throws()`); look at `tests/pay_cycles_test.php`. Cover the logic, not the HTML. |
| Notes | `docs/sections/<section>.md`: what PRS2 had, what you built, what you deliberately did not and why. |

## Dev server for checking your screens

MariaDB: `service mariadb start`. Server: `cd /home/claude/SmartPRS3/public && nohup php -S 127.0.0.1:8411 >/dev/null 2>&1 &`
(if port 8411 is already answering, it is running). Sign in with curl:
```
J=/tmp/cj-<section>.txt; rm -f $J
T=$(curl -s -c $J http://127.0.0.1:8411/app.php | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b $J -c $J -d "csrf=$T&action=signin&email=admin@smartprslocal&password=12345678" http://127.0.0.1:8411/app.php >/dev/null
curl -s -b $J "http://127.0.0.1:8411/app.php?r=<route>" | grep -c "Warning\|Fatal\|Deprecated"   # must be 0
```
The demo tenant has 16+20 employees (codes SP-0xx, AM-0xx), companies/branches/departments, punches Jan–Mar 2026.

## Before you report back — all must pass

```
php -l <every php file you touched>
php tests/run.php <your test name>
php tests/run.php navigation          # your menu items point at routes that exist
php tests/run.php csrf
php tools/gates/gate.php              # the seven laws — PASS or you are not done
php tools/modules-writable.php        # if you declared modules: 0 REJECTED BY THE DATABASE, 0 contradicting
curl each of your routes as above: 0 warnings
```
Do NOT run `php tools/check.php` or the scope/exposure sweeps (they take minutes and other
agents share the machine).

## Your final message (returned to the lead, not the owner)

List: sidebar items added (key → route); modules declared; screens/actions/classes written;
migrations added (and whether applied); tests and their counts; anything PRS2 had that you did
not port and exactly why; anything you had to leave as a TODO. Be terse and concrete.
