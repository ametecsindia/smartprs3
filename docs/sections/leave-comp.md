# Leave + Compensation & Claims — section notes

Two sidebar sections built from one drop-in set named `leave-comp`.

## What SmartPRS 2 had

**Leave**: `leave-apply` (apply / balances / approve-reject), `leave-types`, `holidays`.

**Compensation & Claims**: `commissions` (entries with collection evidence, gross − TDS = net),
`incentive-schemes` (percent / fixed / open pay types, scope, window, caps), `commission-calc`
(bulk compute: flat / slab / per-portfolio, target gate, preview, commit with TDS 194H),
`expenses` and `advance` (request + hierarchy approval), `overtime`, `loans` (EMI plan, auto
deduction, statement per run), `clawbacks`, `bonus-enc`, `increments` (old→new CTC, %, letter,
apply-to-record), `exits` (Exit & FnF checklist), `bank-pack` (per-bank month-end pack: payout
register, TDS annexure, GST invoice).

## What was built

* `config/navigation.d/leave-comp.json` — Leave: Leave → route `leave`, Leave Types →
  `setup-leave-types`, Holidays → `setup-holidays` (all three screens already existed).
  Compensation: 14 items (see the file). `overtime` points at the module the Time section owns.
* `config/modules.d/leave-comp.json` — SUPERSEDES `expenses`, `advances`, `loans`, `commissions`,
  `incentive-schemes`, `clawbacks`, `bonus`, `increments` with PRS2's fields: employee lookup by
  code + name, org scopes with ALL, option lists, decimal amounts, `decided_by` / `decided_at`
  read-only. Statuses are the ones `surface/Recoveries.php` reads (`disbursed` / `recovering`
  recover; `approved` is not yet money owed).
* `database/migrations/2026_03_02_000200_compensation_columns.php` (applied) — scheme pay/scope/
  window/cap columns, commission evidence + gross/TDS columns, `loans.start_month`,
  `increments.new_designation`.
* `config/compensation.json` — claim status vocabularies, commission rate types, default TDS rate,
  loan line prefix and recovering statuses, the CSV permission.
* `surface/Compensation.php` — `commission()` (percent / fixed / slab-of-whole-figure / open, with
  an achievement gate), `tds()`, `schedule()` (EMI plan, last instalment = remainder),
  `recoveredByPeriod()` (what the closed runs took, reversed months excluded), `rateText()`.
  Money and Rational throughout; every result carries its `working`.
* Screens: `commission-calc` (scheme + month + org filter → figures → preview with working →
  create entries; the commit RECOMPUTES from the scheme row so no rate travels through the form),
  `claims` (pending expense claims / advances → approve / reject with reason; PRG; audit
  `claim.decided`; self-approval refused; an open `workflow_requests` chain for the same row is
  advanced first and the row moves only when the chain closes), `loan-schedule` (plan vs actual per
  month, outstanding = opening − recovered), `bank-pack` (per closed run, `BankAdvice::generate`,
  CSV download via `bank-pack-csv`).
* Actions: `commission-commit`, `decide-claim`, `bank-pack-csv`.
* `tests/leave_comp_test.php` — 42 assertions.

## Deliberately not ported, and why

* **Bank pack's GST invoice and TDS 26Q annexure** — the lead defined the SmartPRS 3 Bank Payout
  Pack as the bank credit file per closed run; the per-bank invoice/annexure is a Statutory &
  Compliance concern (TDS returns already has a module) and needs the company GSTIN block.
* **Increment "apply to record" and auto letter** — writing the new CTC onto the employee row is a
  superseding, effective-dated salary change that belongs to Salary Structure; letters are on HR
  Letters. The register records old/new/%/effective/status only.
* **Commission proof upload, Accounts "confirm money arrived" stage, bulk CSV import** — the
  calculator takes figures typed per employee instead of a CSV; `status: confirmed` exists in the
  vocabulary but no separate Accounts screen. Add when a customer asks.
* **Scheme announcements (email / WhatsApp / notice board), per-person claim caps enforcement** —
  caps are stored on the scheme but not enforced at commit.
* **The decision reason** is in the audit trail (`claim.decided`), not on the row — the ported
  tables have no column for it and `remarks` is the claimant's note.
* **Per-portfolio rate formula** of the calculator — one scheme carries one portfolio; make one
  scheme per portfolio.
