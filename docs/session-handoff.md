# SmartPRS 3 — session handoff

Started 2026-09-09. **This file was rewritten fresh on 2026-09-11** to start the next session
clean — the full build narrative through the 10 Sep burst (Roles & Permissions rewrite, Payslip
Format Role filter, Salary Structure Calc. mode, ID Card Visitor handling) is preserved verbatim
in `docs/HANDOFF-2026-09-10b.md`, and the narrative for 10–11 Sep (docs audit, Salary Structure
regression, ID Card Format saved-format dropdown, late-arrival tier badges) is preserved
verbatim in `docs/HANDOFF-2026-09-11.md`. This file keeps only what still binds and what is
actually next. Everything below is true of the code committed to `C:\laragon\www\SmartPRS3`.

---

## 1. Standing instructions — these bind, always

- **`C:\laragon\www\smartprs2` is READ-ONLY.** Reference it, copy from it, never write to it. The
  owner has said this twice.
- **The owner does not run commands.** Not `migrate`, not `composer`, not `php tools\...`.
  Anything that needs a terminal has to either be unnecessary or degrade gracefully with the
  reason on screen. This is why several features have a file-store fallback (§5).
- **Deferred by the owner, do not "fix" unasked:** `Session::PASSWORD_FLOOR` stays at 8, and the
  `admin@smartprslocal` account stays. Both are reported by the health screen and by a banner on
  every page.
- Governing sentence for the whole rebuild: **CHANGE CONFIGURATION, NOT CODE.** Reinforced hard
  on 11 Sep (§5c below) — when a display/behaviour needs a number, boundary, colour or threshold,
  the answer is a new tenant-configurable field, never a number picked by whoever is building the
  feature and never a question thrown back to the owner if a config field can carry it instead.
  "This needs a decision from you" is the wrong response whenever "make it a configurable field"
  is available — the owner has said so explicitly.
- **Never spawn a subagent on a shared file (`public/app.php` above all) while you have your own
  uncommitted local edits to it.** A subagent's own `device_stage_files` call overwrites the
  local copy with whatever is currently on the device, silently discarding your uncommitted
  work — this happened and cost a full reconstruction, twice, on 10 Sep. Commit and
  device-verify your own edits first; run multiple subagents on the same shared file
  sequentially, never in parallel; tell each one to re-stage and diff before its final commit and
  merge onto unfamiliar-but-plausible content rather than force-overwrite.
- **`device_commit_files` reporting "written" is not proof the write landed.** This project has
  hit silent reverts repeatedly. Always re-`device_stage_files` the same path immediately after a
  commit and diff/grep it before trusting it landed.
- **A claim that something "doesn't exist in config" needs the same direct-file-check discipline
  as everything else.** On 11 Sep a claim was made ("PRS3's late_policy has only a single
  grace_min cutoff, no tier/break-budget fields") that turned out to be false — the fields
  (`l1_min`/`l2_min`/`l3_min`/`break_budget` and their `_cut` counterparts) were already declared
  in `config/modules.json`'s **`fields` dict** and already had real DB columns via an
  already-applied migration. The mistake was checking only the module's `columns` array (what
  shows on the edit screen) and not the full `fields` dict (everything actually declared). The
  owner caught this by pushing back rather than accepting the claim — don't rely on that
  happening again. Before ever telling the owner a config field/capability "doesn't exist", grep
  the actual `fields` dict for that module in `config/modules.json`, not just what the edit
  screen currently shows.

## 2. The seven laws (SPINE §S1) — every review is against these

1. No business literal in code.
2. No runtime schema change — the application never adds a table or a column.
3. Nothing overwritten: versioned and effective-dated.
4. Every number explains itself.
5. Authorisation server-side, fails closed.
6. Tenancy is structural, not a where-clause somebody remembers.
7. Money is exact — never a float, anywhere, including previews.

Recurring lesson, earned repeatedly: **a check that silently checks nothing is worse than a
missing check.** NOT CHECKED / UNKNOWN / NOT PROVEN are reported states, never blanks.

## 3. Environment

**Owner's machine** (reachable through the desktop bridge; no `device_bash` on this device — only
`device_list_dir` / `device_stage_files` / `device_commit_files` / `device_request_folder_access`,
so every edit is build-in-the-container-then-stage-then-commit, never edit-in-place):

- App: `C:\laragon\www\SmartPRS3`, served at `http://localhost/SmartPRS3/public/app.php`
- MySQL 8.4, database `smartprs3`, root has no password; the app account is `smartprs3` and
  holds **no DDL** by design.
- Signed in as `admin@smartprslocal` / `12345678`, on the **Local development tenant**, which has
  **no employees, branches, departments or designations**. Screens that look empty there are
  usually correct, not broken — check before "fixing".

**Build/test container**: MariaDB (`service mariadb start; sleep 7`), database `smartprs3`,
tenant 3 with 17 employees and real branches/departments/designations. `vendor/` is present on
the owner's machine only.

## 4. Delivery pattern (follow it)

1. Build/edit in the cloud workspace.
2. `php -l` on every touched PHP file; `python3 -c "import json; json.load(open(...))"` on every
   touched JSON config file; `node --check` on any embedded `<script>` JS (strip `<?= ?>`/`<?php
   ?>` tag pairs to null/empty first — Node can't parse PHP tags). For pure computational logic
   (formulas, tier/threshold resolution), a small standalone script using
   `ReflectionMethod::setAccessible(true)` to call private static methods directly with
   hand-written assertions is faster than standing up the full app harness and was used this
   session for the late-tier badge logic (18 assertions, all passing, before shipping).
   `php tools/check.php` (15 checks; `phpstan` is a known FAIL, ~11k findings awaiting
   `vendor/bin/phpstan analyse --generate-baseline` on the owner's machine — everything else must
   be green) when the container is set up for it.
3. Append `docs/build-journal.json`, rewrite `docs/heartbeat.json` (use its
   `correction_to_prior_entry` field honestly whenever a prior turn's claim turns out wrong — see
   §1's last bullet).
4. Before every `device_commit_files`: `device_list_dir` the target path fresh, immediately
   before the call, to get a current `expectedMtimeMs`. Use a brand-new, never-reused filename
   under `/mnt/user-data/outputs/` per commit. `force: true` is safe only when the rejected
   byte-size exactly matches a known-good prior baseline (confirms a stale mtime, not real newer
   content on the device). After every commit, `device_list_dir` again and confirm the byte count
   matches the locally-committed file exactly — don't trust "written" alone (see §1).
5. Verify live in the owner's Chrome when the change is visible (no Chrome verification was done
   this pass — see §5 caveat below).

## 5. Where things stand (as of 2026-09-11)

Everything through the full module port, the PRS2-parity pass, the 10 Sep burst (`composer
db:migrate` fix, decimal-field fix, Roles & Permissions per-code rewrite, Payslip Format
Designer's Role filter + header toggles, Salary structure's Calc. mode column, ID card Visitor
handling, Assets merge) — all built, `php -l` clean, and device-verified. Full narrative and
file-by-file detail for that period: `docs/HANDOFF-2026-09-10b.md`.

**What happened 10–11 Sep** (full narrative: `docs/HANDOFF-2026-09-11.md` and the last several
entries of `docs/build-journal.json`, entries 168–171):

- **Full `docs/sections/*.md` parity/org-config re-audit** (10 sections) against the live code,
  the standing instruction being "keep auditing all modules for SmartPRS2 parity and
  Organization-Configuration wiring without waiting for confirmation." Found the code itself was
  fine everywhere; found **six stale doc claims** that had already been fixed in code but not
  updated in the docs, and corrected each with a struck-through note rather than silently
  deleting the old claim (preserves the audit trail): the `ess.attendance.punch` permission (was
  claimed missing, already granted in `Authorisation.php`), the daily "n/m present" count on
  Daily Attendance (already implemented), the daily view's Branch/Team/Team Leader columns
  (already in `config/attendance-report.json`), the attendance correction-raise form (already
  built exactly as the doc's own suggested design), the Dashboard notice board wiring (already
  wired), and `tests/schema_test.php`'s hard-coded table count (already asserts the current
  count, 189, in both places it's checked). Also closed out `docs/sections/people.md`'s "not
  touched" list for five Directory/onboarding/ID-card/Teams/Exit screens after directly
  confirming each already routes every org field through `employeeFieldControl()` /
  `employeeLookups()` / `Modules::holdsDesignation()` / `orgValueOf()` — no hardcoded org field on
  any of them.
- **Salary Structure (`?r=structure`) regression found and fixed.** The compact-row/Edit-button
  split and wrapping grid layout — explicitly rejected by the owner twice earlier in the project
  — had silently come back, most likely from a later edit pass working off a stale base copy of
  `app.php`. Fixed: single always-visible `buildRow()` (no more `_editing` state, no more
  compact/edit dispatcher), `.cr-grid` changed from wrapping `display:grid` to a
  non-wrapping horizontally-scrollable flex row (`flex-wrap:nowrap; overflow-x:auto`), Remove
  button folded into the row as its last cell, dead `appliesTo`/`summaryRate` helpers removed.
  **This is the second time this exact class of regression has hit this project** (the nav menu
  was the first, flagged by the owner earlier) — a screen fixed once and not revisited since is
  worth a direct live-file re-check before being assumed still correct, not just a `git log`-style
  assumption that a past fix is still standing. Assets/`asset-issue.php` was checked in the same
  pass and confirmed NOT regressed — no unnecessary rework was done there.
- **ID Card Format (`?r=configure-id-card`) — saved-format dropdown.** Matches the Payslip
  Designer's pattern: a `<select id="ic-switch">` above the "Applies to" fieldset listing the
  default format plus every named/scoped saved format, navigating via `onchange`, plus a "New
  format" button shown while editing a named entry. The underlying substance (named/scoped
  formats, most-specific-wins, an edit-in-place form) was already built — this closed the one
  missing piece, the literal dropdown. Deliberately NOT a rebuild of the Payslip Designer's full
  client-side JS rail/SPA — server-rendered navigation matches everything else already on this
  screen and the form is much smaller; the larger pattern would have been disproportionate.
- **Late-arrival tier badges (L1/L2/L3) and break-over-budget highlighting — built fully
  tenant-configurable, no fixed numbers.** This followed the owner's explicit correction
  (§1, last-but-one bullet, and the standing-instruction addition at the top of §1): rather than
  asking the owner to hand over threshold numbers, the numbers and the badge colours themselves
  were made config fields.
  - `config/modules.json`'s `late-policy` module: `l1_min`/`l2_min`/`l3_min`/`break_budget`
    (pre-existing fields, now actually exposed on the `columns` array and wired to a consumer)
    plus four brand-new fields — `l1_badge`, `l2_badge`, `l3_badge`, `break_over_badge` — each a
    `select` over the app's six standard badge colours (`Amber`/`Gray`/`Blue`/`Purple`/`Red`/
    `Green`, matching the existing convention in `config/assets.json`'s `statuses`), defaulting
    to `Amber` for tiers 1–2 and `Red` for tier 3 and the break-budget badge. The pre-existing
    `_cut` fields (a separate payroll-deduction feature) were deliberately left alone — nothing
    was asked about payroll consequences this pass, only about the Daily Attendance display.
  - `surface/AttendanceReport.php::daily()` gained an optional 6th parameter,
    `array $latePolicies = []` (backward compatible — old call sites still work), and three new
    private static helpers: `latePolicyFor()` (resolves the policy for an employee's company,
    falling back to a scope-less default row), `lateTierBadge()` (walks l1→l2→l3, returns the
    badge colour of the highest tier the actual late-minutes figure clears, or null), and
    `breakOverBadge()` (returns the break badge colour only when break minutes exceed
    `break_budget`, or null when `break_budget` is 0/unset — i.e. the feature is off by default
    for any tenant that hasn't configured it). Each emitted daily row now carries
    `late_tier_badge` and `break_over_badge`.
  - `public/app.php`'s daily attendance view: passes `liveRows($store, 'late_policy')` into
    `daily()`; the late badge now reads `badge-<late_tier_badge ?? 'amber'>` instead of a
    hardcoded `badge-amber`; a new `break_time` column branch wraps the cell in a coloured badge
    when `break_over_badge` is non-null.
  - Verified via an 18-assertion standalone `ReflectionMethod`-based test (boundary conditions at
    each tier, gap-tier configuration, company-scoped vs default-fallback resolution) — all
    passing — plus `php -l` clean on every touched file (one PHP if/elseif chain got broken and
    re-fixed mid-edit; caught by `php -l` before shipping, not after).

**No live-Chrome verification was done for the 10–11 Sep work** (unlike most of the project to
date) — everything was verified via `php -l`, JSON validation, `node --check` where relevant, and
the reflection-based standalone test described above, plus byte-exact `device_list_dir` diffing
after every commit. A fresh session should do a live click-through of Salary Structure, ID Card
Format, and Daily Attendance (with a `late_policy` row actually configured with thresholds) before
assuming the pixels match the code.

**No open action items remain from the standing backlog** as of 2026-09-11 — see
`docs/heartbeat.json`'s `next`/`stopped_at` fields, written the same session. Everything raised
this session (docs audit corrections, the Salary Structure regression, the ID Card Format
dropdown, the late-tier/break-budget feature) is shipped and device-verified.

**Sidebar sub-menus dictated so far** (the owner dictates a section at a time; undictated
sections show "Sub-menus to be assigned"): Hiring & Onboarding, People, Time & Attendance,
Organization Setup, Main, Payroll. Still awaiting the owner's sub-menus: Leave, Compensation &
Claims, Statutory & Compliance, Performance & Rewards, Learning & Knowledge, HR Letters, Field
Force, Communication, Reports & Analytics, Administration.

**Migrations shipped but NOT run on the owner's machine** (the owner will not run `migrate` — see
§1). Behaviour until each runs is a file-store fallback + banner, or the fields simply aren't
offered:

| Migration | What it adds |
|---|---|
| `..._000800_create_salary_component_rules` | component rules table |
| `..._000900_add_employee_register_columns` | ~30 columns on `employees` |
| `..._001000_create_employee_field_values` | values beside the record |
| `..._001100_create_payslip_formats` | payslip formats (+ `retired_at`) |
| `..._001200_org_setup_fields` | PRS2 fields on companies/branches/departments/designations |
| `..._001300_create_attendance_bulk_rows` | bulk attendance batches |
| `..._001100_prs2_register_fields` | `late_policy`'s tier/break-budget columns (already applied — this is the migration behind §5's late-tier feature) |

After any migration runs: `php tools/db/grant-application-account.php --apply` — the app account
holds table-by-table privileges, so a new table stays invisible to it until grants are
re-applied. `laragon-setup.bat` does both.

## 5a. Roles & Permissions grid — rows without a selection are correct, not a bug

Raised 2026-09-10: on the Roles & Permissions screen, rows like Background Verification,
Directory, Probation (under People/Hiring & Onboarding) and Import Employees show a plain
"● View" / "● Edit" badge instead of the four radio buttons every other row has.

**Reason.** Since the 10 Sep rewrite the grid's grain is the *permission code*, not the menu item
(`surface/RoleMatrix.php`, `surface/screens/roles.php`). Walking the sidebar top to bottom, the
**first** menu item to use a given code gets the editable row (radio buttons in View / Edit /
Full Access / No Access). Every *later* menu item that happens to declare that exact same code
gets a read-only badge instead, with a note naming where it's actually set — e.g. Background
Verification, Directory and Probation are all gated by `employee.view`, the same code as
Onboarding Status above them, so only Onboarding Status's row is editable; Import Employees
shares `employee.create` with Add Employee, so only Add Employee's row is editable.

This is deliberate, not a rendering bug: a second editable row for the same code would need a
second `<input name="grid[role][employee.view]">` radio group with the *same* field name as the
first — the browser would only submit one of them, silently dropping whichever the form happens
to order last, and `RoleMatrix::save()` would have no way to know they disagreed. Since both
menu items are already enforced by the identical `$may('employee.view')` check, the permission
genuinely can only hold one state at a time; a second set of controls would let the owner think
he's setting Background Verification independently of Onboarding Status when he cannot be.

**If the owner wants these independently permission-able** (e.g. a role that can see the
Directory but not Background Verification), that needs new, distinct permission codes minted for
each screen, with each screen's own `$may()` check switched to its new code — a small change to
the permission catalogue and to each affected screen's gate, not a fix to this grid. Not started;
flag it back to the owner rather than silently reworking the grid to fake independence it can't
enforce.

## 6. The next piece of work (decided, not started)

**Wire the component rules into the payroll run.** The owner's decision, in his words: *"all the
rules apply from the salary structure for all salaries calculation based on the department,
company, branch… There will be no other rules apart from the salary structure page."*

So: for each employee, resolve the rules by company → branch → department → designation →
employee (most specific wins) and compute the payslip from them.

**Two things the grid must gain first**, or the run cannot be correct:

- the flags `pf_base`, `esi_base`, `pt_base`, `prorates`, `taxable` — without them the statutory
  wage bases and proration are undefined;
- a `sequence`, because a percent-of component must be computed after what it is a percentage of.

`Register::computeMonth()` (`surface/Register.php`, ~line 470) currently builds ONE `$components`
list for the whole run and hands it to `PayrollRun`. Per-employee component sets mean either
grouping employees by resolved set and running the engine per group, or extending `PayrollRun` to
take components per input. Neither is done. Do not start it without re-reading how `close()`
stores `structure_effective_from`, or a reprint will stop reproducing.

Two risks to raise with the owner once this reaches a checkpoint: (1) `ComponentRules`' catalogue
has no per-component statutory flags yet, so PF/ESI/PT wage-base resolution finds nothing once
`ComponentRules` governs a period; (2) a `'manual'`-calculation rule should cleanly REFUSE the
whole close with a named reason rather than the engine silently dropping it.

After this: Generate Payroll screen, Salary Approval screen, Payslip screen (per
`docs/heartbeat.json`'s numbering, tasks #19–#21).

## 7. Also open

- **phpstan baseline** — `vendor/bin/phpstan analyse --generate-baseline` on the owner's machine.
  deptrac has still never run anywhere.
- Running workflow instances through the engine (would replace `Surface\Requests`, which works
  today).
- A notification transport (needs a real SMTP host).
- Statutory content — blocked on a named compliance owner. The pack in the build is synthetic
  and states no law.
- Three decision points remain empty: `attendance.grace`, `attendance.calendar.weekly_off`,
  `payroll.component.eligibility`.
- The `late_policy` `_cut` fields (`l1_cut`/`l2_cut`/`l3_cut`/`break_cut`) are declared and have
  DB columns, but nothing consumes them yet — that's the payroll-deduction side of the late/break
  policy, deliberately not wired this pass (only the Daily Attendance display side was asked
  for/built). Flag before payroll wiring reaches this area.
- `public/diag.php` on the owner's machine is a disabled stub; it can be deleted.
- **`composer db:migrate`'s `--fresh` removal is not yet confirmed solid by an actual run** —
  re-read `composer.json` + `tools/db/migrate.php` before ever telling the owner to run it again.
  No backup/undo exists for a past `--fresh` run inside the app; only a prior `mysqldump` or MySQL
  binlogs (`C:\laragon\data\mysql-8.4\binlog.*`, needs someone with terminal access — Shashank)
  can recover data already lost to it, and binlogs rotate, so this is time-sensitive.
- Owner has said he wants genuinely custom/dynamic roles eventually (not just the 3 this build
  enforces) — a separate, bigger build (new user-role-assignment + enforcement plumbing), not
  started.
- No live-Chrome click-through was done for the 10–11 Sep work — see §5's caveat. Worth doing
  early in a new session, before building anything further on top of Salary Structure, ID Card
  Format, or Daily Attendance.

## 8. Traps this session actually fell into

- **A claim that a config field "doesn't exist" was wrong** because only the module's `columns`
  array was checked, not the full `fields` dict — see §1's last bullet. The single costliest
  mistake of the 10–11 Sep period; caught only by the owner's pushback, not by self-review.
- **An `elseif` got turned into a new `if` mid-edit**, breaking a single `if/elseif/elseif/else/
  endif` chain in `app.php`'s daily attendance column loop into an unclosed block. Caught by
  `php -l` before shipping — exactly the discipline that's supposed to catch this class of error,
  and did.
- **A UI regression (Salary Structure's compact/Edit split) came back after being fixed once and
  explicitly signed off twice.** Second occurrence of this exact pattern in the project (the nav
  menu was the first). Lesson restated in §5: a screen not revisited recently is worth a direct
  live-file check before assuming a past fix still holds, especially after any edit pass that
  might have worked off a stale base copy of a large shared file like `app.php`.
- **`$field + ['type' => …]`** — PHP's union keeps the LEFT key, so it silently discarded the
  customer's chosen type. `array_merge`.
- **`(float)` on a preview amount** — the gate caught it. Law 7 has no exception for a number
  that is only being looked at.
- **MySQL names the wrong column** on a prepared-insert truncation (it reports by the table's
  ordinal). `SqlStore::put()` now attaches the shape of the row — column, type, size, never the
  values — to any refused write.
- **Shipped files can lag the build.** Compare sizes with `device_list_dir` before assuming an
  installation matches.
- **`write()` is refused on a tenant-scoped store** (it would delete other tenants' rows). Use
  `put()` per row; delete by retiring.
- **`?r=…&id=` means an employment everywhere.** A screen that answers `id` with something else
  makes the scope sweep report a false leak, which teaches people to ignore the sweep. The
  payslip designer uses `?format=`.
- The owner's tenant being empty makes correct screens look broken. Check the tenant before
  debugging.

## 9. Reading order for a new session

1. `docs/heartbeat.json` — where things stand, and what is next.
2. `docs/build-journal.json` — the last few entries carry the reasoning (168–171 for 10–11 Sep).
3. `public/app.php` — the front controller; every screen is in it.
4. `surface/` — one class per capability, each with a header explaining why it exists.
5. `docs/HANDOFF-2026-09-11.md` — the full narrative behind §5's 10–11 Sep bullet list.
6. `docs/HANDOFF-2026-09-10b.md` — only if you need the build narrative behind that further back.
