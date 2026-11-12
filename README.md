# SmartPRS 3 — a configuration-first HR, attendance and payroll platform for India

A working application, not a slice of one. Employees, attendance (including punches
pushed from a biometric device), leave, payroll with an approval and a reversal,
payslips, full and final settlement, letters, compliance, approvals, an audit trail,
84 generic CRUD modules, an accounts screen, an HTTP API, and a salary structure whose
components can be declared as expressions.

**Governing sentence: CHANGE CONFIGURATION, NOT CODE.** Modules, reports, workflows,
letters, compliance checks, navigation, the dashboard and the salary structure are all
declarations in `config/*.json`, each with one engine behind it and a versioned,
effective-dated per-tenant overlay. A customer who wants one more approver above
₹50,000, their own word for "Employees", or an HRA capped at ₹20,000 changes a value —
not a release.

---

## Running it

```
cd C:\laragon\www\SmartPRS3
laragon-setup.bat --with-demo      # accounts, schema, grants, sign-in, demo data
```

Then open **http://localhost/SmartPRS3/public/app.php** and sign in with the
credentials the setup step prints.

`laragon-setup.bat --from-prs2` does the same and copies SmartPRS 2's real data in
instead of the demo rows. SmartPRS 2 is opened **read-only** and the importer refuses
to run at all if both `.env` files name the same database.

One command verifies the whole thing:

```
php tools/check.php
```

It runs the unit suite, the seven law gates, PHPStan and Deptrac, and five sweeps that
each exist because they found something: `smoke` (every route and all 84 module screens
against the real schema, failing on any warning), `exposure` (signs in as an ordinary
employee and hunts for a colleague's salary in the HTML), `scope` ×3 (viewer, manager and
tenant-wide — whose row is this), `modules` (a real insert and edit through every module,
rolled back), and `api-sweep` (mints credentials — valid, unscoped, expired, revoked,
another tenant's — and asserts the API refuses each). **A check that could not run is
reported as SKIPPED and never counted as a pass**, and an accidental skip of the sweeps
fails the run: "no database" looks exactly like "nothing to report" if you let it.

## Where things are

| Path | What it is |
|---|---|
| `public/app.php` | the application — every screen |
| `public/api.php` | `/api/v3`: bearer credentials, scopes, cursor paging, punch ingest |
| `public/index.php` | a localhost-only console over the engines; a bare visit redirects to the app |
| `surface/` | the application's own classes — one per subject, each with its reasoning in its header |
| `src/Platform/` | the framework-free engines: money, payroll, statutory, workflow, rules, formulas, access, persistence |
| `config/*.json` | the declarations: modules, reports, workflows, letters, compliance, navigation, dashboard, salary structure, punch layouts |
| `tools/` | the checks and the operational commands |
| `docs/` | the 25-part specification, plus `build-journal.json` — every defect found and what it taught |

`src/Platform/` is framework-free on purpose and holds no connection, container or
facade. `composer.json` declares the target stack (Laravel 12, PHPStan, Larastan,
Deptrac, Pest); PHPStan and Deptrac are the three checks that report SKIPPED until
`composer install` has run.

---

## Seeing it run — three commands, no Composer, no artisan

```
cd C:\laragon\www\SmartPRS3
php tools/db/migrate.php --fresh          # creates smartprs3, runs the 9 migrations, publishes 3 rule versions
php -S localhost:8000 -t public           # then open http://localhost:8000
```

`tools/db/migrate.php` is the runner `php artisan migrate` would be if the
framework were installed. It executes the same migration files, then publishes
three versions of one rule **through the real `Rules\RulePublisher`** — the nine
publish gates run, every `rule_payloads` column is derived from the `Rule`
object, each gate's outcome is stored, and each publication appends a
hash-chained ledger entry. So what lands in `config_versions`, `rule_payloads`,
`config_publication_checks` and `audit_entries` is what the platform writes, not
fixture rows typed to look like it. Connection defaults are Laragon's (`root`,
no password); override with `SMARTPRS_DSN`, `SMARTPRS_USER`,
`SMARTPRS_PASSWORD`, `SMARTPRS_DB`.

`public/index.php` is a single-file dev console, localhost-only, over the real
engines. Six pages:

| Page | What it drives | What it shows |
|---|---|---|
| Expressions | `Spx\Compiled` | type your own SPX; it parses and type-checks as at publish time. `late_minutes > 10` is **refused** — a bare number has no unit |
| Money | `Support\Money`, `Rational` | integer paise, the five rounding modes side by side with what each costs, and an allocation that sums back to the whole |
| Formulas | `Formula\Calculator` | two components where HRA prints first and computes second, with the exact rational, the declared boundary and the delta |
| Rules | `Rules\Engine`, `Rules\PublishGates` | a live decision and its trace, including the branch-scoped rule beating the default on specificity and the `except` clause suppressing both — then the nine publish gates run against each rule, showing which are checked and which report NOT CHECKED |
| Workflow | `Workflow\Validator`, `ParticipantResolver`, `Sla\*` | one approval graph, its eighteen publish checks run live, participant resolution showing who is excluded and why, and the SLA: change the raised-at time and watch the deadline skip a weekend and a holiday, then see the same timer auto-approve on time and be **suppressed** after an outage |
| Configuration | `Config\ConfigVersionRepository`, `Audit\AuditLedger` | change the **as-at date** and watch a different version resolve — a July recalculation run in August computes July — then the hash-chained ledger of who published what, with a live chain-integrity check you can break from a SQL prompt |
| Overview | — | what is connected, and the commands a browser cannot show |

The database is needed only for the Configuration page; the other six run with
no database at all. `php tools/demo/smoke-console.php` requests every route and
fails if any page did not render to completion — a PHP fatal still returns 200
with a truncated body, so "it loaded" is not evidence.

The scheduled jobs run too, and spine S2 makes them mandatory rather than
optional — SmartPRS 2's most damaging operational failures came from customers
running neither a queue nor a scheduler:

```
php tools/db/schedule.php timers      # the §IX.7.6 SLA sweep
php tools/db/schedule.php anchor      # the S14 daily audit anchor
php tools/db/schedule.php health      # what the health check would say
php tools/db/grants.php smartprs3 smartprs3_app localhost   # the SQL the db-privilege gate asks for
```

`grants.php` emits the GRANT statements the `db-privilege` gate is asking for,
derived from the migrations and from the gate's own append-only table list, so
the account it produces and the gate that judges it cannot drift apart. It exists
because satisfying the gate from memory failed for a reason worth recording once:
**MySQL grants are additive across scopes and cannot be subtracted.** Granting
`UPDATE ON db.*` and then revoking it on `audit_entries` is not narrowing — the
revoke is an error, and the database-level grant still applies. The only way to
withhold UPDATE from one table is never to grant it at the database level. The
first live run of that gate also found a real gap: `audit_anchors` was not on the
append-only list, and an anchor that can be rewritten attests to nothing.

`health` reports **DEGRADED** for a job that has never run, not healthy: the
absence of a heartbeat and a stale one mean the same thing to somebody waiting
for a reminder, and the safe reading of no evidence is not "fine" (R-IX-067).
`timers` exits non-zero when it had to withhold an automatic decision, because
that means the scheduler was down and those instances now need a human.

### The Phase 2 exit gate

Part XXXII makes a passing engine conformance suite the exit gate for the engine
phase, and Part XXX §XXX.3 lists the 61 case classes it must cover.

```
php tools/conformance/run.php            all four engines
php tools/conformance/run.php SPX        one of them
```

The corpus is DATA — `tests/conformance/*.json` — because R-XXX-021 requires it:
"each case is a declarative fixture in a data file, not a PHP method. This makes
the same corpus runnable against a future re-implementation of an engine, which
is the point." `tools/conformance/engines.php` is the only part that would be
replaced.

The runner reports **every case class in the blueprint's table**, including the
ones no case covers yet, as NOT COVERED. That is the design: a suite reporting
"64 passed" says nothing about the 61 properties required, while one reporting
"35 of 61 covered" says exactly where the engines are unproven. Same discipline
as `PublishCheck::couldNotCheck()` and the gates' vacuity suppression — a check
with nothing to read must say so rather than read green.

There is a third outcome, **NOT RUN**, for a case the runner could not execute —
WC-02 and WC-08 need two real database connections, because what they assert is
what two connections do to each other. Without a database they report NOT RUN
and their class stays UNCOVERED. They are not skipped-and-green: a case that did
not execute proved nothing, and a suite that says otherwise is the exact defect
this codebase keeps finding in itself.

Current state: **110 cases, 0 failures, 61 of 61 case classes covered.** Every
case class in Part XXX §XXX.3 is covered by at least one passing case, which is
Phase 2's exit gate met rather than approximated. Two of the last three closed
by fixing something real rather than by writing a case around it:

**RC-18** turned out to be a mechanism complete except for the declaration that
arms it. R-VII-015 says a decision point may declare symbols `not_null_required`
and that a null one FAILS evaluation rather than silently not matching.
`Symbol` carried the flag, `SymbolTable::requiringNotNull()` existed, the
evaluator raised `ERR_MISSING_INPUT` on it, and `spx_safety_test` asserted the
behaviour — and **not one decision point declared a single symbol**, so the
branch was unreachable in production. "The employee had no worked minutes, so no
penalty rule matched" and "we failed to load their attendance, so no penalty rule
matched" were the same outcome. `DecisionPoints` now carries R-VII-015's
`not_null` list per point: `day.date` on the attendance points, `leave.type` and
`leave.balance` on the leave ones (SmartPRS 2's `paidLeaveDays():1065` treats an
unknown type as PAID, and `LeaveController::apply()` never writes it at all),
`period.start`/`period.end` on the payroll ones. `attendance.worked_minutes` is
deliberately NOT on any list — null is legitimate on a holiday, and a not-null
list that over-reaches gets relaxed under pressure until it declares nothing.

**RC-19** needed the impact preview the blueprint asks for in six places and
that existed in none of them. `Rules\ImpactPreview` is it: it runs before
publication, over the whole population rather than a sample, AS AT the date the
version would take effect rather than today, and it counts a subject whose
evaluation fails as changed-and-named rather than folding it into "unchanged".
The conformance case computes the actual changed set without going through the
preview at all — otherwise it would be asserting that a function equals itself —
and separately proves the effective date is load-bearing by showing that a
preview taken before it gives a different, reassuring, wrong answer.

Putting the preview on a screen immediately found a fourth way for it to be
useless: the first version fingerprinted the rendered actions, so rewording a
rule's `reason` reported every employee in the tenant as affected. A preview
that cries wolf on a typo fix is one people learn to click past — and the click
they learn is the one that matters on the version that does move money. Effect
and explanation are now counted separately, and neither is hidden.

For the same engines on a terminal, `php tools/demo/walkthrough.php`.

### The Phase 3 exit gate

Part XXXII states four conditions for leaving the People phase, and
`tools/phase3/exit-gate.php` runs each of them rather than describing them:

```
php tools/phase3/exit-gate.php
```

1. **Adding one new employee field touches ZERO code files** and answers on all
   thirteen surfaces. The field is declared entirely in
   `tests/phase3/new_field.json`, and the gate GREPS `src/`, `public/` and
   `database/` for its key — a claim of zero code files is worth exactly as much
   as the check behind it. In SmartPRS 2 the same field costs six code edits at
   minimum and thirteen to sixteen for parity, because its field registry is a
   PHP constant identical for every tenant.
2. **Import/export round-trip symmetry per field**, over one field of every
   available type, plus the structural half: an undeclared import column is
   reported as `rejected` rather than dropped. Four SmartPRS 2 importers drop
   unknown columns by four different mechanisms and none of them says so.
3. **"What was this employee's branch on 15 May?" returns one row.** Checked
   here, proven in `tests/organisation_test.php` against a real database — a
   second as-at query is the one that eventually disagrees with the first.
4. **Every employee write produces a field-level audit record**, asserted
   against a real database because the property is that the audit row COMMITS
   WITH the value it describes. `storeEmployee()` writes no audit row at all.

A fifth criterion is the gate's own, and it is there because the four rest on
it. **R-X-025: every available authoring type actually has all nine artefacts,
exercised rather than declared.** The first cut of the type catalogue asserted
nine for twenty-five types while five of the nine — the client validator, the
template cell, the API schema, the mask renderer and the diff renderer — had no
code behind them at all. That is a claim standing in for a check, which is the
one failure this codebase keeps finding in itself, and it was in work written
the same afternoon as the argument against it. `Entity\Masking` and
`Entity\FieldArtefacts` are the repair; the gate now produces each artefact for
each type and fails when one returns nothing, when the mask returns its input,
or when a template header names a format the export does not emit.

`Entity\Encryption`, `Entity\Checksum` and `Entity\Composite` took `address`,
`bank_data` and `government_identifier` from declared to selectable, and
`Entity\FileValidation` closed the file-bearing four. **All thirty-two authoring
types are available**, and the sixth criterion holds R-X-028 down: MIME is
sniffed from the bytes, the extension must ADDITIONALLY agree, size bounds are
enforced, and `pending`, `infected` and `error` all withhold the file — a
scanner outage is not a pass.

A criterion whose check cannot run reports **NOT PROVEN**, never a silent pass,
and the gate exits non-zero. Every run also prints the seven authoring types
that are declared and **not available for selection**: R-X-025 says a type
without all nine of its artefacts — server validator, client validator, import
parser, export formatter, template cell, API schema, SPX type, mask renderer,
diff renderer — may not be chosen, so the file-bearing and encrypted types are
listed with exactly what each is missing. Shipping thirty-two type names of
which twenty-five work would be the same defect this codebase keeps declining to
repeat: the difference between "not built" and "built" must be legible without
running it.

### The Phase 4 exit gate

Part XXXII names cross-midnight semantics the highest risk of this phase —
"these are the cases SmartPRS 2 gets structurally wrong, and they are subtle
enough to get wrong twice" — and states five conditions:

```
php tools/phase4/exit-gate.php
```

1. **The attendance golden-test matrix passes, cross-midnight included.** The
   matrix is data (`tests/phase4/attendance_matrix.json`), and its expected
   values were computed independently — a matrix built from the engine's own
   output proves only that the engine agrees with itself. It caught two of my
   own hand-computed expectations on the first run.
2. **`is_weekly_off()` has exactly one implementation**, asserted by grepping
   `src/` for a second one and for any weekday comparison outside
   `Platform\Calendar`. SmartPRS 2 has FOUR definitions, one of which believes
   Sunday is the only weekly off there is — which is why its payslip and its
   muster roll disagree about how many days somebody worked.
3. **Recalculation is idempotent and produces a diff.** Every matrix case
   re-derives identically from *shuffled* punches: the answer comes from the
   effective punch set, not the order a device sent them.
4. **A correction on a locked period creates tracked arrears** with an owner and
   a target period. The lock does not refuse the fix — it makes the fix cost
   money in a different month and records that it did.
5. **Comp-off exists end to end**: the calendar resolves the Sunday, attendance
   settles the day worked on it, the accrual credits the ledger, the credit is
   spent oldest-first, and an unclaimed one lapses as an *entry* rather than
   being filtered out of a balance.

The two design decisions that carry this phase:

**The attendance day is not a property of the punch.** It is a WINDOW around the
shift, expressed as absolute instants, labelled by the date the shift *started*.
A 22:00 shift on the 30th ending 06:00 on the 1st is one night and one
attendance day. SmartPRS 2 groups by the calendar date of the punch, so when D is
30 September the two halves of one night land in two different payroll months.

**Every figure is arithmetic on instants anchored to the shift start.** Never
minutes-since-midnight. That single choice is why a night-shift worker can be
marked late here: an arrival at 00:15 on a 22:00 shift is 135 minutes late, where
`max(0, 15 − 1320)` is zero — and the same punctual 22:00 arrival is
simultaneously charged eight hours of early exit by the same arithmetic.

### The Phase 5 exit gate

Phase 5 is the only phase whose ENTRY gate is a test: Part XXXII requires "the
payroll golden-test matrix written and FAILING" before the pipeline exists. So
`tests/phase5/payroll_matrix.json` and this gate were written first and run
first, and the gate printed `0 of 5 … the pipeline is not built`. That order is
the only way to know that what passes afterwards is the pipeline rather than a
matrix shaped around it.

```
php tools/phase5/exit-gate.php
```

1. **The matrix passes with EXACT values** — five cases, twenty-one lines, all
   integer paise.
2. **The reconciliation assertion ABORTS the run** on any mismatch, naming the
   check, the employment and the difference to the paisa.
3. **No component name appears as a literal anywhere in the codebase.**
4. **A re-run reproduces the original exactly and reports any changed input.**
5. **Every recovery reverses cleanly.**

Four decisions carry this phase.

**Prorate the gross ONCE, then split it.** Not split, then prorate each part.
₹50,000 at 22/24 payable days becomes 22,916.67 + 9,166.67 + 13,750.00 =
45,833.34 under independent rounding — one paisa more than the employee is owed,
on a payslip whose own lines do not sum to its own gross. The matrix states that
wrong figure explicitly (`naive_gross`) and the gate asserts we did not produce
it, because without that the right answer and the wrong one differ by a paisa
nobody would notice.

**A balance component absorbs the residue.** It is computed by subtraction, so
the earning lines sum to the gross by construction rather than to within a
paisa — Part VIII's `ALLOCATE` invariant applied to a salary structure.

**Net is not clamped.** A month whose fixed deductions exceed a prorated salary
produces a negative net, stated. SmartPRS 2 clamps net at zero while leaving
`total_deductions` at full value, so the stored figures stop satisfying their own
equation and the PDF prints arithmetic the employee can see is wrong.

**A reconciliation you have never seen fail is one you do not know is wired up.**
R-XIV-069 makes that testable: the gate injects a deliberate one-paisa
perturbation and requires the corresponding check to fail.

### Phase 6 — and why it is deliberately incomplete

Part XXXII gives this phase a hard dependency in five words: **no compliance
owner, no phase** (R-XXXII-006). It is not an engineering blocker that can be
worked around. The platform can compute nothing statutory it cannot attribute,
and D-XVII-01 makes a named person the author of every statutory value in the
product — the name an inspector reads on a payslip trace in 2031.

```
php tools/phase6/entry-gate.php     # exits 2: BLOCKED, and says exactly what is missing
php tools/phase6/exit-gate.php      # 4 of 5 proven, 1 NOT PROVEN
```

So the phase is split where the dependency actually falls. The **content** half
— real PF, ESI, PT and LWF values — is not started, and the exit gate reports
that criterion NOT PROVEN rather than omitting it. Filling this repository with
real Indian rates attributed to nobody would turn the criterion green and mean
nothing; it would also be the exact defect being engineered out, since SmartPRS 2
carries **no source citation on any of its ~90 statutory values**.

The **mechanism** half carries no statutory values at all, so it is built and
proven against a synthetic pack: jurisdiction `IN-ZZ`, which does not exist,
invented figures, `verification.synthetic = true`, and every figure derived from
it stamped as such on the explanation. What that proves:

- **Ed25519 over `SHA-256(JCS(manifest) ‖ content_hash)`**, two signatures by two
  distinct keys, because one person must not be able to ship law. Twelve refusal
  cases each fire for their own reason: an edited pack, an unknown key, a
  countersignature by the same key, a pack needing a newer build, a rule with no
  `source_reference`, an empty band table, a missing `verification.*` field, a
  pack carrying PHP instead of an expression, an unknown schema version.
- **Canonicalisation before hashing.** The same pack with its keys in a different
  order hashes identically, so a signature survives a round-trip through an
  editor. A float is *refused* rather than canonicalised — a rate that arrived as
  an IEEE double has already lost what the notification said, and hashing it
  afterwards only certifies the loss.
- **No way to turn verification off.** The gate reflects over `PackVerifier` and
  fails on any parameter named like `force`, `skip` or `disable`. R-XVII-027 is
  absolute, and an option that exists is an option somebody sets on the 30th.
- **A pack change with no deployment.** An amended band table shipped as version
  2026.2, effective 1 January, moves the January figure from ₹700.00 to ₹1,000.00
  with no PHP changed and no migration — and leaves November alone, because
  resolution is by the statutory effective date, not by which pack was installed
  last.
- **Revocation that does not erase history.** A revoked key blocks future
  activation and keeps already-activated packs readable, because making
  revocation retroactive turns one compromised key into three years of
  unexplainable payslips.
- **A refusal where SmartPRS 2 returns zero.** A statute that applies and cannot
  be computed throws. A zero deduction that should have been ₹1,800 is
  indistinguishable on a payslip from a correct ₹0, and the employer finds out at
  assessment. "Nobody has decided" is a third state, separate from "does not
  apply" — the same zero on a payslip, completely different facts on an
  inspection.
- **One implementation, asserted.** `StatutoryEngine::compute()` is the only one
  under `src/`, and the pack's own signed golden cases replay *through it* rather
  than through a copy — R-XVII-125, against SmartPRS 2's `pt_extract.php`, a
  hand-copied duplicate of production code that stopped testing production the
  first time somebody forgot to re-copy it.

The gate also walks Part XXXII's Phase 6 **scope**, so "4 of 5 criteria" is not
read as "4/5 of the phase":

- **The floor (§XVII.8).** Company policy may improve on the statute and never
  reduce it, in the direction the pack declares — bigger is better for a
  contribution and wrong for a deduction. A `block` has no exception path at any
  permission level; the auditable route for a block believed wrong is an
  *applicability* declaration with a basis and evidence, not a severity somebody
  turned down. An `exception_required` departure needs a recorded exception
  naming *that* rule, with a legal basis long enough that "n/a" is impossible, a
  bounded range, a review date, and two different people as authoriser and
  approver. It expires, and expiry re-raises the finding.
- **The statutory calendar (§XVII.11).** Obligations are materialised from the
  pack crossed with applicability — none typed by a customer, none compiled into
  the application. A covered establishment generates rows; one outside coverage
  generates none; one nobody has assessed generates a **warning**, because an
  empty compliance calendar is either a compliant company or one nobody has
  looked at. An obligation whose owner role resolves to nobody is refused rather
  than sitting on a dashboard. And `filed` is reachable only from `prepared`, by
  a named person carrying evidence — which is what makes SmartPRS 2's six
  quarters showing "Filed" that nobody filed (DEFECT-F-07) structurally
  unreachable rather than merely unlikely.
- **Returns and registers (§XVII.11.4).** A return is bound from **stored**
  contribution rows — never recomputed, never from CTC, never over an unfiltered
  employee set — hashed and stored so "what exactly did we file?" survives the
  years. A control total out by one paisa produces **no file at all**, because a
  file generated with a warning is a file filed with a warning. The population is
  exactly the rows for that registration and period, which naturally includes a
  leaver who was paid and excludes one who was not. And a **synthetic** figure is
  refused at the filing boundary whatever else is true of it.

What remains is the per-statute **content** — PF, ESI, PT, LWF, TDS, gratuity,
bonus, minimum wages, protected leave. The engine that runs them is generic and
built: a statute is a pack, not a release. To open the phase, create
`config/compliance-owner.json` with the owner's name and role, the appointment
date, and the second reviewer. The entry gate says so in those words, and refuses
until it exists.

### The Phase 7 exit gate

```
php tools/phase7/exit-gate.php
```

Part XXXII gives this phase three exit conditions, and all three are proven.

**1. `compliance_status()` has exactly one implementation.** Part XXI calls this
"the most important requirement in this part", because SmartPRS 2 has **five**
implementations of "is the DRA valid?" — each individually reasonable, and
nothing prevented the second. So there is one `ComplianceService::status()`, the
gate greps for a second, the consumer list is closed, and the CI sweep is *seeded
with a deliberate violation* so a clean result means clean rather than broken.

The absent-record rule has exactly three cases and **none returns valid**:

```
applicable, no instance            → due,            evidence,      not valid
not applicable                     → not_applicable, derived,       excluded
applicability cannot be evaluated  → due,            not_assessed,  not valid
```

There is no fourth case. One of SmartPRS 2's five implementations treats a blank
free-text field as valid, and its score awards 20 points when the `complaints`
table does not exist — so a missing table scores better than an empty one. The
fix is not a better direction to guess in; it is making absence impossible to
read as a pass.

Validity resolves **as at the date the decision belongs to**, never "now", so a
commission approved for September asks about September and re-issuing March's
audit pack in July reproduces the March verdict. And what "valid" *means* is
`valid_state_codes` on the item type and nothing else — SmartPRS 2 requires
`verified` for DRA and accepts `submitted` for PCC, asymmetrically and
undocumented, because each consumer decided for itself.

**2. No assertion that was not derived from stored evidence.** Every finding
carries its `assessment_basis` and the record id that decided it. What could not
be checked appears in a first-class NOT ASSESSED section rather than being
omitted, and the pack refuses to call itself complete. The score reads *"score N
of a possible M assessed"*, never out of 100 — a score that hides what it could
not run **rises as the data gets worse**, and the direction of that error is what
makes it dangerous. SmartPRS 2's RBI audit pack asserts `'0'` disciplinary
actions because nothing counts them, six quarters "Filed" that nobody filed, and
`score => 92` hard-coded on a customer-branded sample; the gate greps
`Platform\Compliance` for both shapes.

**3. The collections pack installs into a clean tenant and contains no PHP.**
`packs/collections-compliance.json` is the reference vertical — four compliance
item types, three money-gate bindings, two vocabularies, a regulatory report
definition — and it is entirely data. A pack is refused if any value looks like
code or if it ships a file the loader would execute, because a pack that can
carry code can do anything the application can, and "change configuration, not
code" acquires an exception. Installation is all-or-nothing, a dirty tenant is
refused, a repeat install is refused rather than silently overwriting a tenant's
own edits, and a gate binding naming a type the pack does not ship is refused —
that gate would never fire and no screen would say so.

Each criterion was verified non-vacuous by breaking it: making the absent-record
case return valid, adding a second `status()`, and planting a literal score. All
three failed as they should.

The gate also walks the **talent** half of the phase, which the three criteria do
not reach: a candidate converts carrying every mapped field and *naming* the ones
it could not carry (a field that quietly does not cross is a field somebody
re-types differently, and a name spelled two ways is two people to a statutory
return); an offer letter is frozen at issue and refuses re-issue, because
re-rendering replaces the document a person signed with one built from today's
template; the employment timeline answers what somebody's grade *was* in August,
refuses a back-dated event and refuses anything after an exit; and a
full-and-final refuses to settle while an item is open.

### The Phase 8, 9 and 10 exit gates

```
php tools/phases/run-all.php     # every gate, in order, in one command
```

**Phase 8 — Reports, dashboards and automation (4/4).** A report is built and
extended *at runtime* from a declared dataset, and the gate proves the report code
appears nowhere under `src/`. An undeclared column is refused rather than rendered
blank — a blank column reads as a fact about the business rather than as a typo. A
PII column needs an explicit acknowledgement. Every dashboard widget carries the
action it implies and the freshness of its number, both enforced at construction:
"14 expired certificates" with no way to reach the fourteen is a figure somebody
reads, feels bad about and cannot act on, and within a month nobody reads it.

`sent` and `delivered` are separate states, and `deliver()` demands a provider
acknowledgement — collapsing them is how a team believes 400 payslip
notifications went out when the gateway dropped every one. The gate checks
*structurally* that no other method can reach the state, so a future convenience
setter cannot open a second route. And OTPs, passwords, PINs, tokens, identifiers
and account numbers are redacted **on the way into** the log rather than at
render: a log that masks on the way out still holds every password anyone was
emailed, one forgotten query away from being read.

**Phase 9 — Delivery, deployment and operations (4/4).** The installer writes no
credential of any kind and issues a single-use enrolment token instead — an
installer that creates `admin/admin` and asks the operator to change it has
created an account that is `admin/admin` on every site where the person who
installed it was not the person who was going to use it. It registers its
scheduler jobs and worker as part of installing, not as a runbook step, and an
install whose own health check *threw* is a failed install.

An update takes its database backup **first**, and a deliberately failing
migration rolls back the database *and* the code — rolling back only the code
leaves version N running against a schema for N+1, and every symptom then points
somewhere else. The restore drill restores **and verifies what landed**, because
a corrupt backup restores perfectly: the files arrive and the process exits zero.
A system that has never drilled reports `NEVER PROVEN RESTORABLE` rather than a
blank. All twelve §XXXI.7 console panels are present, and R-XXXI-090 is enforced
at construction: a read-only number with no next step cannot be added.

**Phase 10 — Migration, parallel run and cutover (2/3, 1 blocked).** The
tolerance policy has three classes and the demotion is one-way: anything failing
its own class's requirements becomes a **failure**, never a softer class, because
a classification that degrades under missing evidence is one everything eventually
qualifies for. A Class X difference with no reference is a Class E failure —
"we think it is because…" is not an explanation. A Class C correction must cite a
register entry, **move in the direction its defect predicts** (an LOP correction
that increases net pay is not a correction, it is an unexplained difference
wearing a label), be quantified, and have been disclosed before go-live; four of
the fourteen registered defects are additionally blocked on an unresolved
compliance ruling.

Class C differences are reported as **successes** under "Defects corrected",
using none of the four words R-XXIX-105 forbids, and their sum is stated exactly
once as *"the total financial effect of correcting known SmartPRS 2 defects"* —
printed twice it would one day disagree with itself.

The third criterion — **the customer signs off** — is NOT PROVEN, and cannot be
here: it is a fact about a named person at a specific customer. The harness that
refuses a cutover without it is built and proven, including a rollback plan that
must have been *rehearsed*, with a duration that fits the cutover window. A
95-minute rollback is not a rollback for a 60-minute window, and knowing that in
advance is the difference between a decision and a discovery.

### Two blocked phases, and why that is not two failures

`tools/phases/run-all.php` reports **failed** and **blocked** separately on
purpose. Failed means something is wrong with the code and an engineer can close
it. Blocked means a criterion cannot be evaluated at all — Phase 6 needs a named
compliance owner, Phase 10 needs a named person at a customer — and no amount of
engineering closes either. Reporting them as one red number would make them look
like the same kind of problem, and only one of them has an engineering answer.

```
8 passed · 0 failed · 3 blocked on something outside this repository
```

### The earliest phases had no gate at all

Phases 0, 1 and 2 were built before the phase-gate pattern existed, so nothing
re-checked them once the phases above started passing — and a foundation nobody
re-checks is one that quietly stops holding. Part XXXII's own warning about
Phase 1 is *"this is the phase where cutting corners recreates SmartPRS 2; there
is no later opportunity to make tenancy structural."*

**Phase 1 (5/5)** proves all 13 gates are blocking (the runner *exits non-zero*,
and the self-test seeds each check with a deliberate violation), that the
cross-tenant suite derives its table list from the registry rather than a
hand-kept one, and — the part a test file cannot say about itself — that it
**ran** rather than skipping because no database was reachable. Tenancy is the
one property that must never be proven by absence.

**Phase 2 (3/3)** proves the conformance corpus is data, reaches all 61 declared
case classes, and references no domain module: a suite that imports a payroll
fixture passes because payroll happens to work, and then cannot say which of the
two is broken.

**Phase 0 is BLOCKED, and it is the one that should worry you.** Its deliverable
is complete — fourteen analyses, a thirty-five part blueprint. Its *gate* is not:
`tools/phase0/exit-gate.php` parses the decisions register and finds **83 Tier 1
decisions unanswered**, plus no recorded Product Owner approval. By Part XXXIV's
own test a Tier 1 decision is one that changes a table, a key, a unique index or
an aggregate boundary; or changes what is sold, billed or owned; or carries a
legal clock. Those are the expensive ones to reverse once code exists — and code
now exists. The gate re-reads the register each run, so answering one in
`config/decisions-answered.json` closes it without touching the tool.

### The definition of done, and the whole programme on one screen

```
php tools/programme/definition-of-done.php    # 13 items, with evidence
php tools/programme/status.php                # everything, one screen
```

§XXXII.7's thirteen items are *run* rather than printed as empty boxes — a
checklist nobody has evidence for is a checklist that gets ticked. Twelve are
done; one needs a person (customer-facing documentation, which Part XXX makes a
module incomplete without and which is written by people, not generated).

Building it surfaced one real gap and closed it: **round-trip symmetry** had no
harness. `RoundTrip::check()` now compares field by field in both directions. A
column the exporter skipped is named as a *drop* — the customer opens the file,
sees their data, re-imports it, and that field is now empty, which is
indistinguishable from a deliberate clearing. A value that returns in a different
form (`01-04-2026` out, `2026-01-04` back) is named with its before and after. And
a masked cell is a **refusal**, not a difference, because counting it as one
pushes somebody to "fix" it by accepting the mask and writing dots over a real
account number.

`status.php` closes with the four things waiting on a person rather than on
engineering, stated as the decisions they are — describing them as "outstanding
tasks" on an engineering board is how they sit for a quarter while everyone
assumes somebody else is doing them.

### How much of the blueprint is actually built

```
php tools/programme/traceability.php              summary by part
php tools/programme/traceability.php R-XIV        one part, requirement by requirement
php tools/programme/traceability.php --uncited    everything nothing references
```

The honest number, and it is the most important one in this file:

```
TOTAL          3313        195       82       3036
  █ CHECKED    195  (6%)   a test or a gate names it — something RUNS and fails if it stops holding
  ▒ CITED       82  (2%)   a source file names it — attention, not proof
    UNCITED   3036 (92%)   nothing in this repository names it
```

**What is built is the spine and one worked path through each phase — not 3,313
requirements.** Every engine, every enforcement gate, every phase gate, and a
complete honest implementation of the parts each phase's exit criteria name. The
remaining 92% is scope, not defect: it includes the entire SPA, the public API
surface, the SaaS control plane, the on-premise installer beyond its exit
criteria, and the long tail of every part's detailed requirements.

Three things about that report are deliberate. It separates **checked** from
**cited**, because a requirement named in a docblock and implemented wrongly
looks exactly like one named and implemented correctly — only a check that runs
is evidence. It refuses to add the two columns together into a compliance
percentage, which would be the same species of claim as SmartPRS 2's
`score => 92`: a number that reads as a verdict and was never computed from
evidence. And the tool excludes **itself** from its own corpus, because a tool
that counts its own examples reports coverage it created by being written.

That last one produced this phase's real finding, and it is recorded in the
matrix rather than smoothed over. RC-01, RC-02, RC-03 and RC-08 **cannot** fail
on a perturbed line — a `Payslip` stores no gross, no deductions and no net, so
every total is summed from the lines on read and moving a line moves the total
with it. That is the defect being engineered out (SmartPRS 2 stores
`payroll_runs.net_total` at generate and never corrects it, with nothing
comparing the two), but it also means those four checks are structurally
satisfied rather than passing. The gate reports them as exactly that. What
catches a moved line is RC-04 to RC-06, against a header stated *independently*
of the lines — an imported total, or the original run's, which is what a re-run
reconciles against.

---

## Bringing it up on Laragon

1. **Prerequisites.** Laragon with PHP 8.4 and MariaDB 10.11 (or MySQL 8.0+),
   Redis, Composer, Node (for the SPA, later).
   Enable the PHP extensions `pdo_mysql`, `mbstring`, `json`, `openssl`, `zip`,
   `intl`, `bcmath`, `redis`.

2. **Get the dependencies** — the step this environment could not do:

   ```
   cd C:\laragon\www\smartprs3
   composer install
   ```

3. **Environment.**

   ```
   copy .env.example .env
   php artisan key:generate
   ```

   Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Create the database in
   HeidiSQL or `mysql -e "CREATE DATABASE smartprs3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`.

4. **Migrate.** Once `composer install` has run and the skeleton exists:

   ```
   php artisan migrate
   ```

   Before that — and it is worth doing first, because it works today — use
   `php tools/db/migrate.php --fresh`, which runs the same migration files
   without the framework.

   In any environment above local, migrations run as `DB_MIGRATION_USERNAME`;
   the *application* user must hold no `ALTER`, `CREATE` or `DROP` privilege at
   all (Law 2 — no schema change at runtime).

5. **Queue and scheduler are mandatory, not optional** (spine S2). Start both,
   and treat either stopping as an incident:

   ```
   php artisan queue:work
   php artisan schedule:work
   ```

6. **Tests.** One command runs everything and gives one verdict:

   ```
   php tools/check.php               # tests, gates, PHPStan, Deptrac, smoke
   php tools/check.php --fast        # the same without smoke, for the quick loop
   ```

   It reports each check as PASS, FAIL or **SKIP**, and a skip is never counted
   as a pass — PHPStan and Deptrac skip loudly when there is no `vendor/`, and
   smoke skipping on a full run is itself a failure, because smoke is the check
   that finds the most and is the easiest to lose silently. Use `--fast` if you
   mean to skip it.

   The individual commands still work on their own:

   ```
   php tests/run.php                 # the primitives — no Composer needed
   php tools/gates/gate.php          # the architectural gates
   php tools/smoke.php               # every route, twice, against real MySQL
   composer test                     # the primitives, then Pest
   composer check                    # lints, PHPStan, Deptrac, everything
   ```

   The schema test needs a database. It reads `SMARTPRS_TEST_*` from the
   environment and **skips** (loudly) if it cannot connect:

   ```
   set SMARTPRS_TEST_USER=smartprs3
   set SMARTPRS_TEST_PASSWORD=secret
   php tests/run.php
   ```

---

## What is here

```
src/Platform/
  Support/Money.php              Law 7. Integer paise, immutable, no float in any signature.
  Support/Rational.php           Exact rationals: an OT multiplier is 3/2, never 1.5.
  Support/RoundingMode.php       Six modes. Nothing rounds without one being named.
  Support/Clock.php              + SystemClock, FrozenClock. Domain code never calls now().
  Support/EffectiveRange.php     Law 3's primitive. Half-open inside, inclusive at the edge.
  Support/Scope.php              Spine S3.1/S3.2 targeting and the exact weight table.
  Support/CalculationTrace.php   Law 4. Renders like the S7 worked example; also JSON.
  Support/AmbiguousConfiguration.php   A tie is a blocked publication, never a coin toss.
  Support/UnresolvedConfiguration.php  Zero matches is a named error, never a default.
  Tenancy/TenantContext.php      Law 6. Explicit to establish; throws when unset.
  Config/ArtefactResolver.php    Spine S4 resolve(type, key, scope, as_of) — pure, injected candidates.
  Config/ConfigVersionCandidate.php
  Audit/HashChain.php            Law 5 / S14. Canonical serialisation, chain, break detection.
  Audit/AuditEntry.php           The single definition of WHAT IS HASHED — append and verify
                                 both build the payload here, so a chain cannot read as
                                 "broken" because two places disagreed about the bytes.
  Audit/AuditLedger.php          The only writer to audit_entries. Gapless seq and the chain
                                 head taken under one lock on the tenant, because a per-tenant
                                 hash chain has one head and is inherently serial.
  Access/Permission.php          S13 / XXII.2.1. `verb.resource` as a value, validated once.
  Access/PermissionCatalogue.php R-XXII-010's two paths: declared entries, and the three
                                 reserved generative namespaces form/report/dashboard.
  Access/catalogue.php           GENERATED — 543 declared codes. Rebuild with
                                 `php tools/seed/build-permission-catalogue.php`.
  Access/Role.php                A flat, versioned bundle. No inheritance, no deny rules.
  Access/DataScope.php           XXII.3's thirteen scope types; covers() and a set expression.
  Access/SubjectContext.php      The org and reporting facts, already resolved as at a date.
  Access/Assignment.php          R-XXII-028's triple, effective-dated, role and scope nullable.
  Access/ScopeComposition.php    R-XXII-050. The existential per assignment — not two unions.
  Access/AccessDecider.php       Steps 5 and 6 of the pipeline. One allow, one catch, it denies.
  Access/AccessDecision.php      The answer plus the §XXII.13 trace: which axis failed, and why.
  Persistence/EffectiveDatedRepository.php  Law 3's read layer. asOf() returns a dated view;
                                 a read without a date throws, it does not mean "today".
  Persistence/AsAtDateRequired.php
  Organisation/OrgUnit.php       S3's typed tree as a value: ancestors, isWithin, subtree prefix.
  Organisation/OrgUnitRepository.php  find/ancestorsOf/descendantsOf, off the materialised path.
  Organisation/AssignmentHistory.php  V.6.3. "What was this employee's branch on 15 May?" —
                                 one row, plus the audit view and the contiguity assertion.
  Organisation/ReportingLine.php V.6/R-XXII-043. Dated direct reports and reporting tree,
                                 depth-bounded and cycle-safe.
  Organisation/ReportingCycleDetected.php  R-XXII-044. A cycle denies; it never widens scope.

  Spx/                           The one expression language: lexer, parser, type checker,
                                 sandboxed evaluator, function catalogue, and the four
                                 registers that give a keyed accessor a declared type.
  Rules/Engine.php               Part VII. Resolve, filter, order, evaluate, seal — and a
                                 DecisionTrace the constructor will not let you omit.
  Rules/DecisionPoints.php       §3.2's thirteen points: each one's symbol table, its action
                                 allow-list, and its conflict policy. Closed by definition.
  Rules/PublishGates.php         R01..R09. Every gate runs; one that cannot check says so.
  Rules/ImpactPreview.php        §7.9. What publishing this rule would actually do, before it is
                                 published: the whole population not a sample, the version's
                                 EFFECTIVE date not today, and an evaluation that fails counted as
                                 changed rather than as "stayed the same" — the same fail-open this
                                 codebase keeps finding, wearing the costume of a summary statistic.
  Rules/ImpactReport.php         What the approver reads. The share is a Rational, so the
                                 percentage is rounded where it is PRINTED rather than three steps
                                 earlier on the way to a threshold comparison.
  Rules/Aggregation/Window.php   §5.9's nine windows resolved to date ranges. R-VII-022: a payroll
                                 period comes from the employee's effective-dated pay cycle,
                                 honouring its cut-off day, and is REFUSED rather than falling
                                 back to the calendar month. R-VII-023's rolling window spelled
                                 out, because "3 lates in 30 days" is ambiguous otherwise.
  Rules/Aggregation/Aggregator.php  The seven §5.9.1 primitives over one subject's settled
                                 events. Pure, bounded by the §5.9.4 row cap, and the definition
                                 of what the materialised projector must later agree with.
  Rules/RulePublisher.php        The only way a rule is published — every payload column is
                                 derived from the Rule, so no row can disagree with it.
  Formula/Calculator.php         Part VIII. Topological order, one declared rounding
                                 boundary, and the delta that boundary cost.
  Formula/PublishGates.php       G1..G12, and FormulaPublisher, the same shape as above.
  Formula/FormulaResolver.php    The READ side of formula_payloads. The publisher had written
                                 every column since the payload migration and nothing read one
                                 back, so effective dating was a property of the write path only
                                 and every caller handed Calculator formulas built in PHP. The
                                 stored AST is what runs (R-VII-009) — re-parsing the source is
                                 exactly how a parser upgrade changes a published payslip — and
                                 a formula's dependencies resolve AT THE SAME DATE, because
                                 September's basic against October's HRA percentage reconciles
                                 against nothing. Writing it turned up two more tables-with-no-
                                 reader: Spx\Types\Type had no `parse()` for the type it renders,
                                 and `formula_sample_cases` was written by nothing at all.
  Formula/SampleCase.php         R-VIII-047. G10 could only COUNT case names — a formula could be
                                 published with three names none of which had ever been run, which
                                 the gate's own docblock admitted. The cases run now, and §9.10's
                                 expected value is asserted EXACTLY, in minor units.
  Formula/Clamp.php              R-VIII-030. A declared bound applied at a NAMED step that appears
                                 in the trace whether or not it bound. `non_negative` RAISES
                                 ERR_RANGE rather than clamping — a negative earning is a symptom,
                                 and turning it into zero destroys the evidence. There is no
                                 implicit max(0, …) anywhere in the engine; SmartPRS 2 has three
                                 undeclared ones that disagree with each other.
  Workflow/                      Part IX. 13 node types, W01..W18, and a decision path
                                 where the write is one conditional UPDATE.
  Workflow/Sla/BusinessCalendar.php  R-IX-057/058. Absolute instants and a calendar, never
                                 minutes-since-midnight. Weekly offs and holidays are excluded
                                 using the attendance engine's holiday list, not a second one.
  Workflow/Sla/TimerSchedule.php What a node activation owes: due, reminders, escalation, and
                                 the stale check. Pure — activation time is the only clock.
  Workflow/Sla/Sweep.php         The workflow:timers decisions, INCLUDING R-IX-066: an
                                 automatic decision on a timer that fired late is withheld.
                                 The one place where "the job caught up" is the dangerous
                                 outcome.
  Scheduler/TenantSweep.php      The platform schedule. CrossTenantEscape has named this class
                                 in its allow-list since the tenancy layer was written and
                                 nothing implemented it. The grant is open around the TENANT
                                 LIST only, so a job body runs under an ordinary tenant context
                                 and cannot reach another tenant. Also R-IX-067's health check.
  Workflow/AmountRouter.php      §IX.9.1. W08 had validated amount bands since the SLA work and
                                 nothing SELECTED one. `from` inclusive, `to` exclusive, integer
                                 minor units only (R-IX-077), and R-IX-027's re-route: the band
                                 is chosen when the node ACTIVATES, so an amended request does
                                 not stay in the original approver's queue.
  Workflow/Persistence/TransitionLedger.php  Appends a transition AND its audit entry in one
                                 transaction. Until now this file contained no reference to
                                 audit at all, so R-IX-105/106 were sentences in a blueprint.
                                 The AuditLedger is a required dependency, not an optional one:
                                 an optional audit is the audit that is null in the deployment
                                 nobody checked.
  Workflow/Delegation.php        §IX.5.5, dated. The resolver took `array<from, to>` — a map with
                                 no dates — so an EXPIRED delegation could not be expressed at
                                 all, and a delegation raised for a fortnight's leave routed
                                 approvals for ever. Chains are followed to whoever actually
                                 holds the work; a CYCLE is refused rather than resolved, because
                                 taking the first hop hands the task to the person the delegation
                                 existed to route around. `delegation_allowed` is finally read:
                                 the publisher had written that column since the workflow payload
                                 landed and nothing consulted it.
  Workflow/Persistence/InstanceRepository.php  Where an instance's version is PINNED and read
                                 back. `config_version_id` existed since the workflow migration
                                 and nothing read it, so R-IX-016's "an instance mid-flight on v1
                                 completes on v1" was a property of a column. `start()` resolves
                                 once; `pinnedVersion()` takes no date, on purpose — a caller who
                                 could pass one could ask which version governs this instance
                                 today, which is a question with a wrong answer.
  Workflow/Persistence/TaskRepository.php    The conditional UPDATE, and R-IX-107 — every
                                 REFUSED attempt is audited too, with which predicate refused
                                 and whose decision stood instead. Who TRIED to approve a
                                 payroll run they were not entitled to approve is exactly the
                                 fact an auditor asks for, and a 403 that writes nothing cannot
                                 answer it. `refuse()` is where the upstream checks —
                                 permission, segregation, self-approval, a cancelled instance —
                                 record what they turned away.
  Workflow/WorkflowPublisher.php The third publisher. A workflow payload is FOUR tables, so
                                 the nodes, participants and transitions are written inside
                                 the publisher's own transaction — a published approval chain
                                 with no steps must not be reachable.
  Config/Publisher.php           Spine S4's write side: gate outcomes are stored whether or
                                 not the publication proceeds.

  Entity/Surface.php             R-X-029's thirteen surfaces, closed. SmartPRS 2 has nine employee
                                 write paths and no such list, which is why three of them are not
                                 documented as employee writers at all.
  Entity/FieldDefinition.php     One employee field, as DATA (R-X-001). Key contract, PII class,
                                 masking, history mode, uniqueness scope, locked attributes.
  Entity/AuthoringType.php       The 32 authoring types over 16 storage types, and R-X-025's nine
                                 artefacts per type. `available()` is what an administrator may
                                 actually pick; `incomplete()` is what is declared and is not, with
                                 the missing artefacts named.
  Entity/SurfaceRuleResolver.php X.4.2's four steps, failing closed at every one. Most permissive
                                 visibility across roles, strictest requirement, union of approval
                                 workflows — three directions on purpose. Step 4 is `hidden`:
                                 absence of a rule never grants access. SmartPRS 2's
                                 `denyUnlessRole()` catches Throwable and returns null, which means
                                 ALLOWED.
  Entity/EntityWriteService.php  The single write path (R-X-037). Stages 1-6 are pure, so the import
                                 preview is the same code as the commit rather than a second
                                 implementation of it. PATCH semantics: a field absent from the
                                 command is untouched and clearing is explicit, where the SmartPRS 2
                                 save NULLs every omitted key.
  Entity/ValueCoercion.php       The canonical parser, validator, sanitiser and export formatter for
                                 every type. R-X-026: files carry DD-MM-YYYY and the header says so;
                                 import also accepts ISO and Excel serials because a file arrives
                                 however it arrives. SmartPRS 2 parses dates through three
                                 implementations and exports Y-m-d under a (DD-MM-YYYY) header.
  Entity/FileValidation.php      R-X-028. Content decides what a file IS; the extension decides what
                                 everything downstream treats it as, so BOTH must be allowed.
                                 SmartPRS 2 checks the extension only, with no MIME sniff and no
                                 image decode anywhere, and accepts any base64 blob between 500 B
                                 and 4 MB as a selfie. Quarantine is the default state rather than a
                                 flag: only `clean` releases a file.
  Entity/TransitionTable.php     X.7's state machine. "A transition not present in the table is
                                 impossible" — which is what stops an archived employee being
                                 reactivated and then soft-deleted into a state nobody designed. A
                                 guard that cannot be EVALUATED refuses, because a guard that fails
                                 open is not a guard. Seat usage is computed from the status
                                 declaration, and an undeclared status consumes a seat.
  Entity/Encryption.php          X.2.6. AES-256-GCM with a fresh IV per value, and a SEPARATE key for
                                 the blind index — whoever can look a value up must not thereby be
                                 able to read it. Neither key has a default: a layer that encrypts
                                 under a constant when its key is missing is off in the one
                                 deployment nobody checked, and the ciphertext still looks like
                                 ciphertext. The envelope names its key id, so rotation is additive.
  Entity/Checksum.php            Verhoeff, PAN structure, Luhn, mod-11. WHICH algorithm applies is
                                 configuration on the field; the algorithms are arithmetic and live
                                 in code — Law 1 forbids business literals, not dihedral groups. A
                                 checksum catches a TYPO; it is not proof the identifier exists, and
                                 reading it as proof is how a system stops asking for the document.
  Entity/Composite.php           X.3.2 #18/#30. An address and a bank account are one field to the
                                 administrator and one column per sub-field to the file, because a
                                 composite exported as a JSON cell is a value nobody can edit. A bank
                                 account's number exports MASKED by default, and re-importing that
                                 masked cell is refused — a placeholder that imports cleanly is how
                                 `••••6789` becomes somebody's account number.
  Entity/Masking.php             X.2.6's six rules. `full` renders identically whatever it hides,
                                 because a mask whose width tracks the secret has told you about
                                 the secret; `last4` leaks length on purpose, because that is the
                                 affordance a bank statement uses. A NULL is never masked — an
                                 absent value and a withheld one are different facts.
  Entity/FieldArtefacts.php      The client validator, template cell, API schema and diff renderer:
                                 four PROJECTIONS of one definition, so a field cannot be required
                                 on the form and optional in the API. The diff of two values that
                                 mask identically SAYS they changed rather than rendering
                                 "•••• → ••••", which reads as "nothing changed".
  Entity/Persistence/EntityStore.php  Stages 7, 8 and 9 in ONE transaction, because an audit row
                                 that commits separately can be present for a write that rolled back.
                                 A sensitive field records a hash and no plaintext.

  Calendar/CalendarService.php   R-XII-001's ONE evaluator, called by twelve consumers and nothing
                                 else. Pure and TOTAL: an unresolvable pattern returns a typed
                                 verdict, never a default, because both defaults are wrong —
                                 "working" charges an absence nobody took and "off" pays for a day
                                 nobody granted. SmartPRS 2's absence sweep marks a whole workforce
                                 absent when its holiday query throws.
  Calendar/WeeklyOffPattern.php  Five shapes, because "Sunday off" describes almost nobody in an
                                 Indian six-day week. The rotation is total in BOTH directions:
                                 PHP's modulo keeps the sign of the dividend, so a naive one matches
                                 nothing before its own anchor.
  Attendance/ShiftWindow.php     Phase 4's highest risk. The attendance day is a window around the
                                 shift, labelled by the date the shift STARTED, so one night is one
                                 day. A shift whose end is numerically smaller than its start is
                                 REFUSED — that representation is what makes a night impossible to
                                 compute.
  Attendance/DaySettlement.php   Worked, late, early, shortfall and OT, all relative to the shift
                                 start. An unpaired punch is NAMED and the day is settled around it,
                                 where SmartPRS 2 infers direction from list position so one missed
                                 swipe inverts every pair after it.
  Attendance/ArrearsRecord.php   A correction to a closed month, with the month that was WRONG and
                                 the month that carries the MONEY held separately.
  Leave/LeaveLedger.php          A balance is a SUM of entries, never a column. SmartPRS 2 adjusts a
                                 stored balance in six places and reconciles it against nothing, so
                                 when the number and the transactions disagree only one of them has
                                 an audit trail.
  Leave/CompOffAccrual.php       The join: the calendar says the day was an off, attendance says how
                                 long they worked it, the ledger records what they earned — and it
                                 expires, because comp-off that never does is an unbounded liability
                                 that surfaces as a resignation settlement nobody budgeted for.

database/migrations/             tenants · installations · status vocabularies ·
                                 org units · classifications · config artefacts ·
                                 audit ledger · people · employments ·
                                 employment assignments · rule and formula payloads ·
                                 publication checks · workflow definitions, instances,
                                 tasks and transitions · SLA timers and the sweep's
                                 heartbeat. Thirty-one tables.

tests/run.php                    The runner. `php tests/run.php [filter]`.
tests/lib/laravel_schema_stub.php  TEST ONLY: compiles the migrations to MySQL DDL
                                 so they can be executed against a real database
                                 without the framework. Never loaded by the app.
```

### Conventions the schema test enforces, against `information_schema`

- no `ENUM` or `SET` column anywhere (spine S16 — status vocabularies are data);
- `tenant_id NOT NULL` on every table except the two explicitly commented
  platform-scoped ones (`tenants`, `status_domains`);
- `tenant_id` leads every unique index on every tenant-scoped table;
- every foreign key declares an `ON DELETE` behaviour **in the DDL**, not by
  falling back to MySQL's implicit `RESTRICT`;
- the audit ledger's `tenant_id` is `RESTRICT`, so deleting a tenant cannot
  delete its evidence;
- `*_on` columns are `DATE`, `*_at` columns are `DATETIME` (never `TIMESTAMP`,
  never `VARCHAR`), money is `DECIMAL(18,4)`;
- `audit_entries` has no `updated_at`, because there is no update path.

### What "at most one open assignment row" actually guarantees

`employment_assignments.open_marker` is a stored generated column — `1` while
`effective_to` is NULL, NULL once the row is closed — and
`UNIQUE (tenant_id, employment_id, open_marker)` therefore permits any number of
closed rows and **exactly one** open one, because both MySQL and MariaDB ignore
NULLs in a unique index. That is V.6.4's mechanism 1, implemented verbatim, and
`tests/organisation_test.php` asserts on the database's refusal of the second
open row, not on any application check.

It is **not** a range-exclusion constraint, and the README says so rather than
letting a reader assume more. Together with
`UNIQUE (tenant_id, employment_id, effective_from)` it forbids two open rows and
two rows starting on the same day; two *closed* rows can still overlap. MySQL 8
has no `EXCLUDE` and no multi-row `CHECK`, and MariaDB's
`UNIQUE (…, period WITHOUT OVERLAPS)` is MariaDB-only and needs a NOT NULL end
date, which would replace "in force until superseded" with a `9999-12-31`
sentinel. Full non-overlap is therefore V.6.4's mechanisms 3 and 4 — ordered
closure in one transaction with the employment locked `FOR UPDATE`, plus
`AssignmentHistory::contiguityBreach()` asserted after every write and run
nightly — and the write service that owns them is the next brick. The suite
asserts that limit too, so nobody discovers it in production.

### Why there is no `brick/money`

`Money` is ~250 lines instead of a dependency because the blueprint fixes three
things a general-purpose library does not: paise-exact semantics at the type
level, an `allocate()` contract whose residue goes deterministically to the last
element (spine S7, S19 #4), and a type-level float ban (R-V-020). We would have
had to wrap the library to enforce all three, and the wrapper would have been
most of this file. It is written down in `Money.php` too, so the next person
does not re-litigate it.

---

## What is deliberately not here

Add these on the target machine, in this order:

1. `composer install`, then the Laravel skeleton — `app/`, `config/`, `routes/`,
   `bootstrap/`.
2. Container bindings: `TenantContext` and `Clock` as singletons, `SystemClock`
   for the app and `FrozenClock` in tests.
3. The tenancy middleware that establishes `TenantContext` once per request and
   per queued job, and the Eloquent global scope that applies `tenant_id`. No
   query hand-writes it (Law 6).
4. `ConfigVersionRepository`, which loads published versions and hands
   `ArtefactResolver` its candidate list. The resolver stays pure. **Done.**
5. `AuditLedger` — the *only* writer of `audit_entries` — and the daily anchor.
   **Done**, and wired into `Config\Publisher`, so every publication and every
   refusal leaves a chained entry. What remains is the grant (`INSERT, SELECT`
   on that table and nothing else), the scheduled job that calls `anchor()` at
   the close of each business day, and the Ametecs signature over the anchor.
6. `phpstan.neon`, the two `deptrac.*.yaml` axes and the Pest suite. The primitive suite in
   `tests/` stays: it is fast, dependency-free, and it is what proves the money
   and hashing edge cases.

   Both static-analysis configs had gone stale while unrunnable, and both are
   now corrected. PHPStan was being pointed at `tests/lib/laravel_schema_stub.php`,
   which declares three Illuminate classes so the migrations can compile without
   the framework — once `vendor/` exists those classes are declared twice, and
   every migration is reported against whichever one resolved. It is excluded
   now, and `tools` is added to the analysed paths. Deptrac's layer config had
   no layer for `Formula`, `Workflow`, `Workflow\Sla`, `Workflow\Persistence`
   or `Scheduler`; with `ignore_uncovered_internal_classes: false` that is a
   violation per class. `tools/gates/checks/deptrac-coverage.php` now keeps it
   honest without needing deptrac installed — it asserts every class under
   `src/` is matched by a layer on both axes, and says plainly that coverage is
   all it checks.

Known gaps recorded in the code as `ponytail:` comments — org unit **name
history** (V.6.3), Rational allocation weights, per-currency minor-unit
exponents, and one genuine **blueprint gap**: spine S3.1 lists 21 scopeable
dimensions but the S3.2 weight table declares only 20 — `pay_group` has no
weight. It is accepted with weight 0, which errs toward blocking publication
rather than inventing a number. That needs a Product Owner decision.
"# smartprs3" 
