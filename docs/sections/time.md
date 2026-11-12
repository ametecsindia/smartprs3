# Time & Attendance — SmartPRS 2 parity notes

Section key `time`. Files: `config/navigation.d/time.json`, `config/modules.d/time.json`,
`config/screen-help.d/time.json`, `config/how-it-works.d/time.json`,
`database/migrations/2026_03_02_000100_time_device_and_overtime_columns.php`,
`surface/screens/punch.php`, `surface/actions/self-punch.php`, `tests/time_section_test.php`.

## PRS2 sub-menu → PRS3, screen by screen

| PRS2 id | PRS2 has | PRS3 | State |
|---|---|---|---|
| att-daily | today's rows: Employee, Branch, Team, Manager, Leader, First In, Last Out, Total, Present/Absent; "n / m present"; **Punch In / Out** button (GPS) | `?r=daily-attendance` → attendance-report `v=daily` | Punch button: NEW `?r=punch` (below). Rest: gaps documented below |
| att-report | From/To + Branch/Team/Manager/Leader filters; columns …Shift, First In (late tier badge), Last Out, Total, Break (over-budget red), IN, OUT, Rating, Show Logs; CSV + PDF | `?r=attendance-report` — From/To + Company/Branch/Department/Designation/Team/Employee (ALL), configurable columns, drill-down with sessions/trace/rating, CSV | Better than PRS2 except columns/PDF below |
| att-manual | single punch (employee, date, time, in/out) + bulk Excel/CSV upload with templates, batches, Admin approve/reject, row delete | `?r=manual-entry` → attendance (pick employment → Record a punch) + `?r=attendance-bulk` (batches, approve/reject/withdraw) | Parity. `attendance-bulk` was not on any menu — ADDED to Time & Attendance |
| att-correction | employee raises: date, "what went wrong", correct in, correct out, reason; approver approves/rejects; payroll-locked pill | `?r=attendance-corrections` queue (approve/reject); raising is on `?r=attendance&id=` one punch at a time | Gap documented below |
| absence-config | enabled, send hour, working days, notify employee/manager, Run now | `?r=absence` — enabled, notify employee/manager, Run now for a day, log | Send hour: no scheduler exists in PRS3 (the sweep is run, not scheduled). Working days come from the published rules — deliberate |
| biometric-devices / att-zkteco | DEVICE_FIELDS: name, brand, model, verification type, serial, company, location, connection method, IP, port, comm key, direction, status, notes; ZKTeco screen adds a per-row **Sync** (pull from IP:port) | module `devices` — SUPERSEDED by `config/modules.d/time.json` with every DEVICE_FIELD (migration adds brand, model, device_type, connect_mode, ip_address, port, notes) | Row "Sync": NOT ported — PRS3 has no LAN puller (no SDK, no dependency); devices push (Pending Punches / API keys) or export a file (Import punches). `last_sync_at` is read-only and set by those |
| biometric-setup | per device: enabled, label, branch, provider (push / eTimeTrackLite / eTimeOffice / generic), serial, URL/endpoint/corp id/user/password, sync frequency, employee-id prefix, 1 or 2 readers with machine ids, generic API mapping; Test / Sync now buttons | module `biometric-config` — SUPERSEDED with all of those fields (migration adds branch_id, serial_number, sync_interval_min, api_config); edit needs `tenant.settings.update`, passwords never echoed | Test connection / Sync now: NOT ported (same reason as above) |
| geofence (map) | list + map, click to zoom, Sample CSV / Import CSV | `?r=geofence-map` — rules drawn to scale, org filters, OSM links | CSV import of rules: not ported; the register form and Bulk import of the module engine cover it |
| geofence-list | employee (picked), start, lat, lng, radius, outside | module `geofence` — SUPERSEDED: employee is now a lookup (was a raw id box), min/max on lat/lng | Parity |
| shifts, late-policy | — | left alone as instructed | — |
| overtime | register: employee, company, OT date, hours, multiplier 1/1.5/2, amount (auto from CTC if blank), status pending/approved/paid | NEW module `overtime` over the ported `overtime` table (migration adds employer_company_id; `rate` carries the multiplier) | Amount auto-compute: not ported (see OT note) |

## New sidebar items (`config/navigation.d/time.json`)

* `punch` → `?r=punch` (hand-built, `ess.attendance.punch`) — SmartPRS 2's Punch In / Out. Own employment only
  (`Scope::own`), direction decided server-side from today's open in-punch, browser GPS optional, source `web`,
  GPS kept as the punch's `external_id` (`gps:lat,lng`) for a geo-fence rule to judge.
  **10 Sep, corrected:** the "no role holds it" TODO below was stale — `ess.attendance.punch` is granted to
  administrator, manager and viewer in `surface/Authorisation.php` (lines ~86/188/194). Verified both paths
  render for a bound account.
* `attendance-bulk` → `?r=attendance-bulk` (existing screen, was unreachable from the menu).
* `overtime` → `?r=m&k=overtime`.

## OT computation note

`Attendance::settledDays()` → `DaySettlement::overtimeMinutes = max(0, workedMinutes − scheduledMinutes)`
(`src/Platform/Attendance/DaySettlement.php:111`), where scheduled minutes are the shift window's length
and worked minutes are the paired sessions minus the break budget. The Attendance report shows it per day
(drill-down "Overtime") and per employment (muster "OT" column). The register row's `hours` is that figure
÷ 60; `rate` is the multiplier; `amount` is typed. PRS2's auto-amount (`hours × multiplier × CTC-derived
hourly rate`) needs a divisor policy (working days per month, hours per day) that is not in any PRS3 config
yet — add `config/overtime.json` and a small `Surface\Overtime::value()` with `Money` when payroll starts
paying OT; until then the module records and approves, payroll does not read it.
Permissions: `attendance.overtime.view/approve` exist in the catalogue but are held by no role, so the
module uses `attendance.day.view` / `attendance.punch.create.manual`; swap once the lead grants them.

## Gaps the lead can close in shared files (not editable from this section)

**10 Sep, re-audited against the live code** — items 1 and 2 below were already fixed by the time this
audit ran and are struck; the doc had not been updated to say so. Re-verify against the file before acting
on anything in this list, this doc has been stale before.

1. ~~Daily view columns~~ — DONE. `config/attendance-report.json` declares `company`, `branch`, `department`,
   `team`, `team_leader`, `designation`, `reporting_manager`; `AttendanceReport::daily()` (~line 394) emits
   all of them via `$lookups`. Only `rating` is still absent from the daily grid (it lives on the drill-down
   instead, set per day) — genuinely open if PRS2's column-level rating is wanted here too.
2. ~~Present count~~ — DONE. `public/app.php` (`?r=attendance-report`, `v=daily`) prints a
   `"<?= $mPresent ?> / <?= $mWorkingRows ?> present"` badge in the `<h2>`.
3. **PDF download** — PRS2 `attPdfUrl`. `public/app.php` attendance-report has CSV only (`muster-csv`).
   Nothing in PRS3 renders PDF without a dependency except `IdCardPdf`; reuse that writer or leave CSV.
4. **Late tier badges / break over budget** — PRS2 colours First In by L1/L2/L3 and Break red beyond the
   budget. PRS3 daily rows carry `late_minutes` and already show a `badge-amber` "late Nm" chip, but not
   tiered by L1/L2/L3, and break time is never coloured; the tiers live in `late_policy` rows
   (`config/modules.json` late-policy) and are applied by payroll, not surfaced here.
5. ~~Attendance correction raise form~~ — DONE. `action=correction-raise` (`public/app.php`) is exactly the
   suggested shape: type (`config/attendance-corrections.json`'s `types`) + optional Correct IN + optional
   Correct OUT, raised by the employee for their own record (`$scope->own()`) or by anyone holding
   `attendance.punch.create.manual` for somebody in their scope; each filled time becomes its own
   `Regularisation::request()` call with the type folded into the reason. The form lives inside "Raise a
   correction" on `?r=attendance-corrections` itself. Doc was stale.
6. **Absence send hour** — needs a scheduler; PRS3 has none (`surface/Absence.php` runs on demand).
7. **Manual single punch by name** — PRS2 types a name; PRS3 picks the employment first (`?r=attendance`)
   then records. One extra click, scope-checked; left as is.

## Deliberately not ported

* Device **Sync / Test connection** (LAN pull, SOAP, cloud APIs): no SDK, no HTTP client dependency, and
  the offline Laragon box cannot reach them. Push + file import are the two interfaces every device has.
* Geofence CSV import: the module engine's form covers the register; bulk rules are rare.

## Checks run

`php -l` on every file; `php tests/run.php time_section` (112 assertions), `navigation` (150), `csrf` (21);
`php tools/gates/gate.php` PASS; `php tools/modules-writable.php` 0 rejected / 0 contradicting;
curl of `daily-attendance attendance-report manual-entry attendance-corrections absence punch-import
geofence-map api-keys attendance-bulk punch m&k=devices m&k=biometric-config m&k=pending-punches
m&k=geofence m&k=overtime` (+ `&add=1` forms): 0 warnings.
