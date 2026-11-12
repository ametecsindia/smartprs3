# Employees tab audit against SmartPRS 2 — 10 Sep

Scope: Onboarding board, Recruitment, Background Verification, Documents, Roster, Off-roll Agents,
Transfers, Probation + Probation Settings, Import Employees, Approvals Inbox, Notifications, Mobile
Devices.

**10 Sep, closed out:** the five screens this doc used to list as "not touched (9 Sep screens)" —
Directory list/Add/Import layout, Self-onboarding, Teams, Exit & F&F (ID Cards was separately given a
Role scope this same pass) — were audited directly against the live code for Organization-Configuration
wiring and found already compliant, no fix needed:

* **Directory / Add Employee / Self-Onboarding** all render every Company/Branch/Department/Team/
  Designation/Reporting Manager/Cost Code field through the one shared `employeeFieldControl()`
  (`public/app.php`), which for `control: lookup` fields (declared so in `config/employee-fields.json`)
  renders a `<select>` from `employeeLookups()` — the live `companies`/`branches`/`departments`/`teams`/
  `designations`/`employees` tables. One control function for all three screens, so they cannot drift
  apart; nothing is free-typed.
* **Import Employees** matches by configurable header aliases (`config/employee-import.json`) and commits
  all-or-nothing — already documented as fixed in the "10 Sep" note below, just not cross-referenced here.
* **Teams** (`?r=teams`) — Company is a `<select>` from the live companies register; Manager / Team Leader
  are picked from employees holding the matching designation (`Modules::holdsDesignation`), never typed.
* **Exit & F&F** (`?r=settlement`) — Company/Branch/Department/Designation/Team on the settlement header
  all come from `orgValueOf($store, $employment, ...)`, the same resolver every other org-scoped screen uses.

No manual/hardcoded organization field was found on any of the five.

## Shared by every screen here

* `surface/actions/people-export.php` + `config/people-exports.json` — one POST action; each screen has
  `surface/partials/people-<screen>-data.php` that computes the rows for the screen AND the file, so the
  Excel (`Sheet::xlsx`) and CSV (`FieldReports::csv`) are exactly the table shown. ALL-CAPS headers.
* `surface/partials/people-export-buttons.php` — the two buttons, carrying the screen's filter.
* `orgFilterBar()` (ALL first) on every people list; icon-only clear-background row actions; Yes/No badges.
* `surface/Notifications.php` now merges `config/notifications.d/*.json` events (drop-in, like modules.d);
  `config/notifications.d/people.json` declares transfer.approved / transfer.acknowledged /
  probation.reminder / probation.overdue / probation.confirmed. Nothing is transmitted until a transport
  exists — the queue is what the Notifications screen shows.
* Migration `2026_03_02_001200_people_tab_columns.php` (applied): `documents.deleted_at`;
  `offroll_agents` email, email_verified_at, mobile_verified, bank_details, upi, doc_photo, doc_id_proof,
  doc_pan, doc_dra, doc_pcc, doc_agreement, earnings_token.

## Per screen

**BGV** — `surface/Bgv.php` now writes PRS2's real columns (chk_identity…chk_references, verified_on,
revalidate_months, next_due) instead of a JSON blob; legacy JSON rows still read. `config/modules.d/people.json`
supersedes the `bgv` register: six checks, badge colours (clear green / flag red / pending amber / na gray),
status tiles, employee lookup by code+name. The desk `?r=bgv` (route block) has the org filter, export,
due-soonest ordering, red past-due / amber due-within-`due_soon_days` (config/bgv.json), delete per row.
Not done: next-due highlighting inside the generic module list (would need the shared module engine).

**Documents** — org filter, search, "show who is missing an expected document" (against Employee fields'
expected documents), expiring-first ordering, soonest-expiry column, per-document delete
(`surface/actions/document-delete.php`, retires with deleted_at), export. Fixed: the Add Document file
input was named `file` while the action read `doc` — uploads never attached. `config/documents.json`.

**Onboarding** — org filter, status chips keep the filter, Transfer badge, export. A hire from Recruitment
starts the New joiner board automatically (recruitment candidate page).

**Recruitment** — Department dropdowns (Raise Requisition, Hire) wired to the Departments register (were
departments+teams merged); Hire form gained Company and Employee stage (probation default);
`Recruitment::hire()` writes employer_company_id / department_id / designation_id / branch_id beside the
names; pipeline Excel/CSV. Bulk WhatsApp, drives, interviews, one-click hire, salary simulator were present.

**Transfers** — Transfer type (Branch / Department / Company) on the form; department list from the
register; `Transfers::apply()` writes branch_id / department_id and a company move clears manager and
team; on approval the order is issued once (`issueOrder`: accept_token + order_sent_at) and the employee
gets a queued `transfer.approved` message with the acknowledgement link; `surface/actions/transfer-acknowledge.php`
(own row, token, or HR naming the signed copy); per-row process tracker (`Transfers::tracker()`); org filter,
status chips, export, print button on the order. Fixed: from_company stored the company id, timeline detail
stored arrays ("Array" warning). Not done: a PDF of the order (the product prints letters via the browser;
no letter PDF class exists) and a login-free public acknowledgement page (needs a pre-auth route in the
shared front controller) — the link lands on `?r=transfers&ack=…&t=…` for a signed-in employee.

**Probation** — settings gained Tell HR and Congratulate the employee (config/probation.json defaults,
`Probation::saveConfig`, both screens, history columns); `Probation::remind()` queues one reminder per
employee per milestone to HR and the manager, one overdue nudge per end date, and the congratulations
note for today's confirmations — run on every visit, deduped. Days column red when overdue; export.

**Import Employees** — heading matching now ignores case/punctuation and takes `config/employee-import.json`
aliases (Employee Code, Date of Joining, Mobile No…); commit is ALL-OR-NOTHING: any refused row and nothing
is written (the screen says so and points at the failure report). Test updated for the rule.

**Roster** — Excel/CSV of the month grid (`*` marks a roster row). PRS2's Week-off status is not ported:
attendance reads shifts, and an "off" day would need the calendar's roster cell — noted for the time section.

**Off-roll Agents** — register unchanged (field-reports section). New `surface/screens/offroll-kyc.php`
(sidebar: Field Force → Off-roll KYC & Earnings): six KYC document slots with upload, email verification
mark, mobile verified, bank, UPI, per-month earnings ledger with entries priced from the payout type and
rate (`surface/OffrollAgents.php`, exact paise), approve/reject by a second person, approved net + pending.
`config/offroll-agents.json`. Not done: the public token live-earnings page (pre-auth route).

**Approvals Inbox** — inline approve/reject with remarks for workflow requests, plus pending leave requests
(leave.request.decide) and pending transfers (employee.update) in the same inbox; type chips; profile links;
export. Leave decisions still land on the Leave screen (shared action).

**Notifications** — type chips (ALL first), unread highlighting with read_at, mark-read and jump to the
related screen (`config/notification-routes.json`, `surface/actions/notification-read.php`,
`Notifications::markRead/routeFor`), recipient column for tenant readers, export.

**Mobile Devices** — status chips (ALL first), Push-enabled Yes/No column, icon action buttons, export.

## Tests

`tests/people_tab_test.php` (37): off-roll pricing/ledger/KYC, transfer tracker, BGV real columns + legacy
read, notification drop-ins/dedupe/routes/markRead. `tests/employee_import_test.php` updated (42).
