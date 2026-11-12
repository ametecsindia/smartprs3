# HR Letters + Communication — section notes

Files: `config/navigation.d/letters-comm.json`, `config/modules.d/letters-comm.json`,
`config/communication.json`, `config/letters.json` (+ `increment`, `nda`),
`config/screen-help.d/letters-comm.json`, `config/how-it-works.d/letters-comm.json`,
`surface/Messaging.php`, `surface/Greetings.php`, `surface/Notices.php`,
`surface/partials/letters-kind.php`, `surface/screens/letters-{offer,increment,warning,relieving,nda,templates}.php`,
`surface/screens/{send-message,notice-board,greetings}.php`, `surface/actions/{send-message,greeting-queue}.php`,
`database/migrations/2026_03_02_000500_letters_comm.php`, `tests/letters_comm_test.php`.

## HR LETTERS

**PRS2 had**: six sub-menus — Offer / Increment / Warning / Relieving / NDA letters (each a
register: employee + saved template + issued-on + status, with Preview-PDF and Email row
actions) and Letter Templates (a register of reusable bodies with `{{employee_name}}`-style
placeholders, samples auto-filled per type).

**Built**: one engine, six sidebar items.
- `letters-offer|increment|warning|relieving|nda` are one-line wrappers over
  `surface/partials/letters-kind.php`: the issued register for that kind (scope-filtered),
  an employment picker that opens the existing Letters screen pre-selected
  (`?r=letters&e=…&t=<kind>`), a View/print page for an issued letter (`&view=<id>`,
  refused if the letter is of another kind or the viewer's scope does not cover the person).
  The route cannot carry a query string (`Navigation::forViewer` url-encodes the whole
  route), hence wrappers rather than `?r=letters&t=offer`.
- `increment` and `nda` templates added to `config/letters.json` (PRS2 had them; PRS3 did
  not). The increment letter prints the employment's CURRENT ctc — record the revision on
  the employment first; there is no separate increment amount to quote and a letter quoting
  a figure payroll does not pay is exactly what the engine refuses.
- `letters-templates` lists every template with placeholders, derived permissions, issued
  count, customised flag, and links to Issue / Change wording (`configure-letter`).

**Not ported, deliberately**: emailing a letter (no transport in this build — see
Communication); the e-signature "accept link" and signed status (no signing service; the
signed copy is filed under Document Tracker); PRS2's free-text template register (a
placeholder naming a field nobody has is refused at save time here instead, via
`Letters::templateProblems`). Letters render as text with print/save-as-PDF; no server PDF.

## COMMUNICATION

**PRS2 had**: send-message (scope all/company/department/team/designation/branch/type/
employee; channels email/SMS/WhatsApp; a mail queue worker), notice-board (title/body/
company/posted-on/status), messages (broadcast log: one row per send with a recipient
count), wa-templates, wa-settings (Interakt), sms-templates (DLT), sms-settings, greetings
(birthday/anniversary/late-login wording, send hour, hide birth year, test send) and
greetings-log.

**Built**:
- `send-message` (hand-built): scope = the shared Organization Setup bar
  (`orgFilterBar/orgFilterApply`, ALL in every box), channel tick-boxes in the same bar so
  *Preview recipients* shows per channel who resolves to an address and who does not (named);
  template pick from `sms_templates` / `wa_templates` pre-fills the body; POST re-resolves
  from what was posted, queues ONE ROW PER RECIPIENT AND CHANNEL to `messages_log` with
  status `queued`, audits `message.queued` with the batch id, PRG with a flash that says no
  transport is configured and how many pairs had no address. Channel → address column is
  `config/communication.json` (whatsapp falls back to mobile).
- `messages-log` module superseded: per-row kind / recipient / channel / to / subject /
  status / batch / queued-by (migration adds the columns).
- `notice-board` (hand-built): current / upcoming / expired / draft from `Notices::split()`;
  add/edit/delete via the new `notices` module (table from the migration, with
  valid_from/valid_to). **`surface/Notices.php::current()`** is the hook for the Dashboard.
- `greetings` (hand-built): due today + next 7 days from DOB/DOJ (29 Feb → 28 Feb in a
  common year; anniversaries from `min_years`), wording from `config/communication.json`
  rendered by the letter engine (a blank refuses), *Queue* button per row → `greeting-queue`
  action (re-derives the due entry from the record, refuses a second queue for the same
  person/occasion/date, audits `greeting.queued`), and the greetings log (messages_log,
  kind=greeting) on the same screen — PRS2's greetings-log folded in.
- `wa-templates` superseded to the richer `wa_templates` table (purpose, language,
  category, variables, status); `sms-templates` new; `wa-settings` / `sms-settings` kept as
  shipped, now in the sidebar.

**Not ported, deliberately**: any actual sending (no transport configured — every row is
`queued`, the screens say so, a provider reads the queue later); the greetings scheduler,
send hour and "send me a test" (no scheduler; the due list + Queue button is the manual
equivalent); the late-login email (belongs to Time); editing greeting wording on-screen
(it is config; a Customisation overlay is the upgrade path); PRS2's employment-type scope
(not one of the six Organization Setup axes).

## TODO / for the lead
- ~~Wire `(new Notices($store))->current()` into the Dashboard~~ — DONE (10 Sep, found already wired:
  `public/app.php` dashboard section renders a "Notice board" card from `(new Notices($store))->current()`
  with a link to `?r=notice-board`). Doc was stale.
- When a transport exists: a worker that reads `messages_log` where status = queued and
  sets sent / failed; nothing else changes.
