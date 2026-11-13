# CLAUDE.md — SmartPRS 3

Rules for this repository. Every person's Claude reads this file, so the whole team gets
the same style and the same limits without being told. Change it like code: review it,
and add a line every time somebody has to correct the same thing twice.

---

## What this is

SmartPRS 3 — a configuration-driven HR, attendance, payroll and compliance platform for
Indian businesses. A complete rebuild of SmartPRS 2, not a refactor. Four clients are live
on SmartPRS 2 today and will be migrated onto this.

**The governing principle: CHANGE CONFIGURATION, NOT CODE.** Customer HR policy, salary
formulas, attendance rules, leave rules, statutory applicability, approval hierarchy,
payslip design, custom fields and alerts are all configuration. None of them is ever a
PHP change.

---

## The seven laws — never break these

1. **No business literal in code.** No tax slab, no PF rate, no threshold, no customer name.
2. **No schema change while running.** No `Schema::create` or `ALTER` at request time. Ever.
3. **Nothing is overwritten.** Everything is versioned and effective-dated. Corrections
   supersede; they never replace.
4. **Every number explains itself.** A figure on a screen can produce its own derivation.
5. **Authorisation is server-side, declarative, and fails closed.** A guard that is unsure
   says no.
6. **Tenancy is structural**, in the database, not asserted in code.
7. **Money is exact.** `DECIMAL(18,4)`, read as a string. Never a float.

Each law has a mechanical gate. **Disabling a gate needs the lead architect's sign-off and
is logged.** A gate that does not block is only a suggestion.

---

## Owner rules

- **Administrator has NO restrictions anywhere in the application** (owner, 7 Oct 2026, `D-OWN-01`).
  Admin approves/decides everything, including what Admin itself raised or applied for. Never add a
  maker-checker or "raiser cannot decide" block that catches Admin; other roles keep the two-person
  rule unless their role is ticked under Self-approval.
- **Do not change working code or features unless asked** (owner, 16 Oct 2026). Change only what the request
  names. Rework that undoes earlier fixes is how a product never gets published.
- **Company time zone for every task** — Organization Configuration → Companies → Time zone. Never ask IST/UTC.
- **Related entries are linked both ways** (e.g. a manual punch ↔ its regularization), and a fix also corrects
  the data already on file.
- **Statutory = current Indian Government rules**, verified from an official source (PIB / Ministry of Labour /
  gazette), held in `config/*.json`. Never report a value the application does not actually read.
- **ESIC**: when the Salary structure sets no ESI rule, ESIC applies as per Government rules (`config/esi.json`).
- **PF / ESIC Applicable are derived, never typed**: from the employee's salary in force today (increments count from
  their effective date) per `config/statutory-applicability.json` `government` — `governmentStatutory()` in app.php.
  Saved employees follow automatically. "As per Government rules" rows need no calculation.
- **Org dropdowns are dependent everywhere** — Company / Branch / Department / Designation / Role / Team narrow
  each other on every screen and every row. Tag script-built ones with `data-org` and the row with
  `data-org-scope`; the shared cascade does the rest.
- **Removed by the owner — do not bring back**: the "CTC includes the employer's contributions" box (Salary
  structure); the Bonus Register, Minimum Wages and Statutory Configuration menus.
- **Dates are dd-mm-yyyy** on every screen and export.
- **Password minimum is 8 characters, permanently, everywhere** (owner, 22 Oct / 5 Nov / 13 Nov 2026) — `Session::PASSWORD_FLOOR = 8`. Never raise it to 12, including for go-live or client installs.

---

## Structure

```
src/Platform/     30 engine modules. PSR-4 root is SmartPRS\
surface/          the application layer — screens, controllers, wiring
public/app.php    the front controller
packs/            signed content packs (statutory, module packs)
database/         migrations — the ONLY place a table is created
tests/            primitives (tests/run.php) + pest
tools/            gates, conformance, db, discovery, smoke, scope, exposure
docs/             the blueprint (38 parts), registers, build journal, heartbeat
```

**Gates refuse `src/App`.** Application code lives in `surface/`, engines in `src/Platform/`.
An engine never reaches upward into the surface.

---

## Commands

```
composer check              everything, in order — run this before you say you are done
composer gates              the seven laws, mechanically
composer test               primitives + pest
composer analyse            phpstan
composer deptrac            layers + modules
composer smoke              walk the console, assert nothing complains
php tools/exposure.php      assert no screen leaks another employment's data
php tools/scope.php --role=administrator --tenant-wide
composer db:migrate         --fresh
composer serve              localhost:8000
```

If `composer check` cannot run, say so rather than working around it.

---

## Before you finish any change

- Write the failing test **first**. Confirm it fails for the right reason.
- One concern per change. Never touch two modules in one go.
- Run `composer check`. All of it.
- Add an entry to `docs/build-journal.json` — what you built, what you found wrong, what
  you got wrong first.
- Update `docs/heartbeat.json` — where you stopped, what is next, what the checks say.

---

## Never

- No business literal in code — see Law 1.
- No customer name anywhere, including comments and file names.
- No empty catch block. Report and rethrow, or comment the exact condition being tolerated.
- No new dependency without asking a human.
- No table created outside `database/migrations`.
- No `float` for money.
- No permission check that permits when it is unsure.
- No customer data in a prompt — employee records, salaries, borrower details.

---

## Open items to reverse before this runs anywhere but a local machine

These are deliberate local-development shortcuts. They are listed here so they cannot be
forgotten, and each one should become a gate rather than a note.

- A seeded `admin@smartprslocal` account exists — remove it.
- No screen assigns the manager role yet; it is reachable only by setting the column directly.
- `composer install` has not been run on the Laragon machine, so phpstan and both deptrac
  checks cannot run. Three of ten checks are skipped for that reason alone.

---

## Where to write things down

| What | Where |
|---|---|
| What you built and what you found | `docs/build-journal.json` |
| Where work stopped and what is next | `docs/heartbeat.json` |
| A decision and why | `docs/registers/REGISTER-DECISIONS.md` |
| A requirement | `docs/registers/REGISTER-REQUIREMENTS.md` |
| Parity against SmartPRS 2 | `docs/PARITY.md` |
| A schema difference from SmartPRS 2 | `docs/SCHEMA-CORRECTIONS.md` |

Written into the repository, not into a chat. Claude can read one and not the other, and
so can the next person.

---

## Reference

SmartPRS 2 lives at `C:\laragon\www\smartprs2` and `smartprs-l3`. **Read only.** Never
change anything in either. They serve four live clients.

The blueprint is `docs/00-INDEX.md` and its 38 parts. The spine is `docs/SPINE.md` — when
two parts disagree, the spine wins.
