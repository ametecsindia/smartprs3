# PART XXX — TEST STRATEGY AND THE GOLDEN TEST MATRIX

*Covers brief Sections 111, 112, 113, 114, 115 and 116.*
*Conforms to spine S1 (the seven laws — every one of which is stated in the spine as a
**test**, and this part is where those tests are specified), S4 (effective dating — the
mechanism the regression suite must prove), S5–S8 (the engine contracts whose conformance
suites are specified here), S11 (the payroll pipeline under test), S12 (rule packs, whose
golden cases are gated here), S13 (security), S15 (explainability), S16 (data conventions),
S18 (deployment parity — the suite runs on the shipped artefact).*
*Depends on: every preceding part for the behaviour it asserts. This part invents no
behaviour; it specifies how the behaviour the other parts define is proven, and it is the
single place where the release gate lives.*
*Depended on by: Part XXXI onward (delivery, migration execution, acceptance) and by the
Definition of Done that governs every module's sign-off.*
*Evidence base: `analysis/M-quality-performance-testing.md` in full. Section 11 ("Tests") of
Parts V, VI, IX, X, XI, XII, XIII, XIV, XV, XVI, XVII, XVIII, XIX, XX, XXI, XXII, XXIII,
XXIV and XXVIII is treated as **input**: this part federates those per-module suites, does
not duplicate them, and adds the cross-module matrices, the engine conformance suites, the
gates and the infrastructure. Every claim about SmartPRS 2 carries a `file.php:line`
citation carried through from the analysis.*

---

## XXX.0 Purpose

This part decides four things.

1. **That SmartPRS 3 has a release gate, and what is in it.** A build either passes every
   gate in §XXX.2.6 or it does not ship. There is no discretionary override, no "known
   failure" list, and no green-by-skipping. SmartPRS 2 has no CI at all
   (`M-…` §1.2 — no `.github/`, no `.gitlab-ci.yml`, no `Jenkinsfile`, no hook, no
   `Makefile`), so its release gate is a human remembering to run three commands that
   `README.md:74` asks for and nothing enforces.
2. **That the money is asserted.** The payroll golden-test matrix in §XXX.4 is the
   centrepiece of this part and, with the reconciliation invariants, the primary safety
   property of the product. SmartPRS 2's entire suite asserts **not one rupee of a generated
   payslip** (`M-…` §1.1); `PayrollTest` asserts `count === 2` and would pass if both
   payslips were ₹0 (`tests/Feature/PayrollTest.php`).
3. **That the engines are proven independently of the modules that use them.** The rule,
   formula and workflow engines each carry a conformance suite that no module can weaken
   and that a module cannot satisfy on the engine's behalf (§XXX.3). This is what makes
   "configuration is the product" testable rather than aspirational.
4. **What "done" means for a module** (§XXX.10) — a twelve-heading checklist a named
   reviewer signs, derived from brief Section 116, so that acceptance is an auditable event
   and not an opinion.

What this part does **not** own: the per-module scenarios themselves, which live in each
module's own part and are referenced here by ID; the rule-pack golden cases, which are owned
by Part XVII and gated here; the CI *infrastructure* procurement decision, which is a
delivery matter for Part XXXI.

---

## XXX.1 What SmartPRS 2 does today, and why it must change

This is not a defect dump. Each finding below is the direct justification for a specific
design decision later in this part, and the cross-reference is given.

### XXX.1.1 The measured position

| Measure | SmartPRS 2 | Source | Drives |
|---|---:|---|---|
| Declared test cases | **132** (137 executed) | `M-…` §0.4 | §XXX.2 |
| Assertion calls | **446** | §0.4 | §XXX.2.6 |
| Product LOC | **81,592** | §4.3 | — |
| LOC per test case | **618** | §4.3 | — |
| LOC per assertion | **183** | §4.3 | — |
| Route definitions | **422** | §0.4 | §XXX.7 |
| Routes exercised by a test | **18 → 4.3 %** | §1.1 | §XXX.7.2 |
| Controllers referenced by tests | **2 of 84** | §1.1 | — |
| Services referenced by tests | **9 of 32** | §1.1 | — |
| Modules with **zero** tests | **16 of 24 (67 %)** | §1.1 | §XXX.10 |
| Rupees of a generated payslip asserted | **0** | §1.1, §4.1 | §XXX.4 |
| CI configuration files | **0** | §1.2 | §XXX.11.7 |
| Static-analysis configuration files | **0** (`larastan` declared, `phpstan.neon` absent; `composer analyse` aborts) | §1.3 | §XXX.11.8 |
| Coverage measurement | **never produced** (no `<coverage>` element, no threshold, no driver) | §1.4 | §XXX.2.6 |
| Factories | **`database/factories/` does not exist**, though `composer.json` PSR-4-maps it and Faker is a dependency | §1.3, §8 D-1 | §XXX.11.1 |
| Mocking library | `mockery/mockery ^1.6` declared, **zero uses** | §1.3 | — |
| Test database engine | **SQLite `:memory:`**; production is MySQL | §1.5 | §XXX.11.3 |
| Locks in application code | **0** | §6.2 | §XXX.7.6 |
| Transactions in application code | **4** | §6.2 | §XXX.7.6 |
| `paginate()` calls | **0** | §6.3 | §XXX.9 |
| Runtime DDL sites inside `app/` | **156** | §0.4 | §XXX.8, Law 2 |
| Schema-introspection calls inside `app/` | **738** | §0.4 | §XXX.9.4 |

### XXX.1.2 The coverage is inverted with respect to risk

The suite is deep on three recent surfaces and absent everywhere else. Punch ingest carries
**43 cases across three files** with explicit idempotency, quarantine, timezone and
tenant-isolation assertions (`SbbIngestTest.php` 23/119, `SmarteptWebhookTest.php` 20/65,
`IclockTenantHotfixTest.php` 11/49). That work is genuinely good and `M-…` §9 records it as
**the single most valuable artefact in the repository** — because it is the one place the
team demonstrably got concurrency right (`PunchIngestService::ingest():72` plus two unique
indexes and `insertOrIgnore` at `:141, :246`).

Meanwhile the sixteen modules with zero tests include employee master, attendance status
derivation, shifts and rosters, leave, salary approval and the bank pack, every request type,
commissions, recruitment, onboarding, offboarding, documents, compliance, notifications,
licensing and — outside the ingest path — authentication and tenancy (`M-…` §1.1).

**Design consequence.** §XXX.2.6 makes the coverage floor **risk-weighted per module class**,
not a single repository-wide percentage. A uniform 70 % would be satisfied by testing more
CRUD; it would not have caught any of the twelve defects below.

### XXX.1.3 What the suite asserts that it should not

Three anti-patterns are live, and each has a countermeasure in this part.

**(a) A test that restates the implementation's own arithmetic.** `PayrollMathTest.php:19-31`
asserts `net === round(gross − total_ded, 2)` — the same expression `computeSlip()` evaluates.
The test passes if **every deduction changes** (`M-…` §4.1). Countermeasure: R-XXX-016 —
every money golden value is an **externally derived constant** committed to the golden file,
never an expression evaluated against the system under test.

**(b) A test that asserts a defect as correct behaviour.** `BillingTest` #8's renewal
assertion is sequential and proves nothing about the concurrent case the code actually fails
(`M-…` §6.2 C-9, §6.1 Rank 9), and `BillingTest` #5 replays the Razorpay webhook
**sequentially** — precisely the case the application already handles — creating false
confidence about the concurrent case it does not (`M-…` C-10). More directly:
`AuthAccessTest` #3 asserts `GET /app/data` returns 200, which **executes**
`ensurePayrollHistory()` and writes twelve months of fabricated `status='paid'` payroll runs
and payslips into the production-shaped `payslips` table
(`AppDataController.php:2340-2381`, called at `:455`). A test asserts the fabrication path is
healthy. Countermeasure: R-XXX-017 — a test may not assert a status code alone for any route
that writes; it must assert the write set, and §XXX.10 makes "no read fabricates a business
record" a Definition-of-Done line.

**(c) A test whose fixture papers over a production schema gap.** `tests/Pest.php:71-77` adds
`plans.seat_max` with `Schema::table()` because **no migration creates it**, while
`BillingController.php:118` reads it in production (`M-…` §1.5 item 3, §2). The fixture layer
is manufacturing a column the product depends on. Countermeasure: R-XXX-061 — a fixture may
not execute DDL, and a CI check fails the build on `Schema::` anywhere under `tests/`.

### XXX.1.4 The engine is wrong, so an entire defect class is unreachable by test

`phpunit.xml:33-34` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Four consequences,
in order of danger (`M-…` §1.5):

1. **SQLite has no `ENUM` and no length enforcement.** Seven migrations carry MySQL-only
   `ALTER … MODIFY` behind driver guards, so under test they run and do nothing. The most
   important, `2026_06_24_000100_widen_payroll_runs_status.php:25`, widens
   `payroll_runs.status` from `ENUM('draft','locked','approved','paid')` to `VARCHAR(30)` so
   that `hr_approved` can be stored at all. **The suite structurally cannot reproduce the
   production defect class that migration exists to fix.**
2. **MySQL-only SQL silently throws and is swallowed.** `PayrollLock::isPeriodFinalised()`
   uses `whereRaw("DATE_FORMAT(pay_date,'%Y-%m') = ?")` (`PayrollLock.php:64`); SQLite has no
   `DATE_FORMAT`, the query throws, the catch returns `false` (fail-open, `:71-73`), and the
   payroll period lock is **untestable and always open under test**.
3. **Runtime-created tables mean feature tests exercise the DDL path, never the migrated
   one.**
4. **`NULL` never collides in a SQLite unique index**, so every unique guarantee involving a
   nullable `tenant_id` is untested for the MySQL case.

**Design consequence.** R-XXX-055: the suite runs on the production engine, at the pinned
production major version, with the production SQL mode and collation. No SQLite substitution
exists, in any lane, for any reason.

### XXX.1.5 The suite's colour depends on a file that is not in the repository

`routes/web.php:22` calls `Edition::isOnPrem()` at route-file parse time, and
`Edition::current()` reads `base_path('.env')` line by line, deliberately bypassing `config()`
and `env()` so `config:cache` cannot freeze it. `phpunit.xml`'s `<env>` entries therefore
**cannot** override the edition. On a developer `.env` carrying `SMARTPRS_EDITION=l1|l2|l3`,
`/` redirects to `/app` (so `AuthAccessTest` #2 fails) and the whole `/app/billing/*` route
group is never registered (so all eight `BillingTest` cases 404) — `M-…` §1.7. **Whether the
suite is green is a function of an untracked local file.**

**Design consequence.** R-XXX-057: every deployment-shaped variable is an explicit test-lane
dimension (§XXX.11.7), the suite runs the full matrix of supported editions in CI, and no
code path may read configuration from the filesystem outside the framework's configuration
layer.

### XXX.1.6 The best-tested payroll component is wired to nothing

`app/Services/PeriodResolver.php` — 323 lines, the documented single answer to "what dates
does a payroll month cover", carrying **nine of the suite's best unit tests** — is consumed by
a label call and an audit command. Its own docblock admits *"Nothing in this class touches the
payroll engine yet"* (`M-…` §6.4). Meanwhile the payroll engine carries **its own** period
resolver and **two** working-day calculators inside `PayrollGenController` (2,845 lines, 88
`Schema::` calls).

**Design consequence.** R-XXX-018: a golden test must exercise the **shipped path**, entering
through the same public contract the product uses. A unit test of a component no caller
invokes counts for nothing in the coverage gate, and §XXX.11.9 specifies a reachability check
that fails the build on a tested-but-unreferenced domain service.

### XXX.1.7 The twelve defects a competent suite would have caught on the day they were written

Each is confirmed in source, each is presently live, and each maps to a named test below.

| # | Defect | Evidence | Caught by |
|---|---|---|---|
| 1 | **Loss-of-Pay leave is paid in full.** `LeaveController::apply()` writes `type_name` and never `type_id` (`:359-373`); `paidLeaveDays()` evaluates `$paid = $lv->type_id === null ? true : …` (`:1055, :1065`), so the unpaid `continue` at `:1067` is unreachable dead code | `M-…` §6.1 Rank 2 | `PR-041`, Part XIII P0 #1 |
| 2 | Balance charged in **calendar** days, credited in **working** days — a Fri–Mon leave costs 4 and credits 2 | `LeaveController.php:227-243` vs `PayrollGenController.php:1077-1082` | `PR-044` |
| 3 | **Authorisation fails open at 71 sites** — `catch (\Throwable) { return null; }` read by every caller as "allowed" | `ApprovalService.php:365-380` | `SEC-T-001` |
| 4 | **Four-eyes is not four-eyes** — `admin` satisfies both `isHR()` and `isFinance()`, and is the default workspace-owner role | `SalaryApprovalController.php:59-68` | `SEC-T-018` |
| 5 | **A page load fabricates payroll** — twelve months of `status='paid'` runs and one payslip per employee, from the **caller's role-scoped** employee list, first load wins forever | `AppDataController.php:2340-2381`, `:455`, `:268-273` | `SEC-T-032`, `MG-014` |
| 6 | **Regenerate hard-deletes the wage register** outside any transaction, then applies four best-effort reversals each in a swallow-all catch | `PayrollGenController.php:2389-2466` | `PR-118`…`PR-122` |
| 7 | **Every payroll side effect is applied after `DB::commit()`** in swallowed catches — a payslip can say "Loan EMI ₹5,000" while `installments_paid` never advances | `:2691`, `:2695-2832` | `PR-113`, `PR-114` |
| 8 | **The audit chain head is global across tenants**, read without a lock, all exceptions swallowed — ordinary multi-tenant concurrency forks the chain and produces false tamper alarms | `Audit.php:90-99` | `SEC-T-036`, `SEC-T-037` |
| 9 | **Cross-tenant write** via an upsert whose match key omits `tenant_id`, on a table whose unique index also omits it | `AttendanceReportController.php:501-511` | `SEC-T-027` |
| 10 | **Cross-tenant destructive IDOR** — `where('id',$id)->delete()` with only a role check | `KbController.php:112`, `TestController.php:192` | `SEC-T-026` |
| 11 | **Employee codes drawn from a 9,000-value random pool**, with no uniqueness check on the import path; collisions are swallowed and the employees vanish from a "successful" import | `EmployeeImportService.php:376`; cf. `AppDataController.php:2818-2820` | `SEC-T-040`, `MG-011` |
| 12 | **A migration deletes business data on every deploy** — an unbounded, cross-tenant, non-transactional attendance dedupe, duplicated verbatim in two migrations that both run | `2026_08_16_000200:137-156`, `2026_08_17_000100:212-234` | `MG-001`, `MG-002` |

### XXX.1.8 What is salvaged, and as what

Per `M-…` §9, and this governs how much of §XXX.4 and §XXX.5 is *harvested* rather than
invented:

| Asset | Salvaged as | Lands in |
|---|---|---|
| The 43 punch-ingest cases and the transaction-plus-two-unique-indexes pattern | **The reference specification** for ingest and for concurrency | §XXX.5, §XXX.7.6 |
| The PT state-slab table and its 7 assertions | **Data** — slabs into a fixture pack, assertions into golden cases | `PR-060`…`PR-071` |
| `PeriodResolver` and its 9 tests | **Specification** of the period engine | `PR-001`…`PR-012` |
| `StatutoryConfig` scope precedence and its 8 tests | **Design** — the ancestor of spine S3.2 precedence | `RE-001`…`RE-014` |
| The business rules inside `PayrollGenController::compute()` — three LOP bases, the sandwich rule, joiner proration, the recovery order, the zero-punch safeguard, the ESI period lock | **Requirements only.** The code is a liability; the rules are real customer requirements | §XXX.4 throughout |
| `tests/manual/pt_extract.php` — a hand-copied statutory table kept in sync by a docblock instruction | **Removed.** R-XXX-019 forbids a golden test reading a copy of the table the engine reads | §XXX.11.5 |

---

## XXX.2 The test strategy *(brief Section 111)*

### XXX.2.1 The shape

The classical pyramid is wrong for this product, because the product's behaviour is not in
the code — it is in **configuration evaluated by engines** (spine S0). Two consequences
follow. First, the widest layer is not "unit tests of PHP classes" but **engine conformance
and domain golden cases**, which are parameterised by configuration and therefore cheap to
multiply. Second, an integration test that exercises a hard-coded path proves nothing about a
tenant whose configuration differs; the matrix dimension matters more than the count.

The target distribution, expressed as a share of total execution time rather than case count,
because count is gameable:

```
                        ┌──────────────┐
              E2E       │   ~3 %       │  a handful of journeys, on the shipped artefact
                        ├──────────────┤
    Performance & scale │   ~7 %       │  nightly, seeded 10k tenant
                        ├──────────────┤
   Security & isolation │  ~10 %       │  generated, exhaustive, blocking
                        ├──────────────┤
   API contract & authz │  ~10 %       │  generated from the OpenAPI document + policy map
                        ├──────────────┤
            Integration │  ~20 %       │  a real database, a real queue, one module deep
                        ├──────────────┤
  Domain / engine gold  │  ~35 %       │  THE CENTRE OF GRAVITY — §XXX.3 and §XXX.4
                        ├──────────────┤
                   Unit │  ~15 %       │  pure functions, value objects, parsers
                        └──────────────┘
```

### XXX.2.2 What each layer owns

Every layer has an explicit **owns / does not own** statement, because the commonest cause of
a slow, brittle suite is a layer testing what another layer already proves.

| # | Layer | Owns | Explicitly does **not** own | Runtime budget | Gate |
|---|---|---|---|---|---|
| L1 | **Unit** | Value objects (`Money`, `Days`, `Hours`, `DayFraction`), the SPX lexer/parser/AST, date arithmetic primitives, allocation and rounding, pure resolvers | Anything touching a database, an HTTP request, a queue or a clock | ≤ 90 s total | blocking |
| L2 | **Domain / engine golden** | The payroll, attendance, leave, statutory, loan and commission scenarios of §XXX.4–§XXX.6: exact values from fixed configuration | Transport, authorisation, persistence performance | ≤ 12 min | blocking |
| L3 | **Integration** | One module through its real public contract against a real MySQL, a real queue and a real cache: persistence, transactions, constraints, events emitted | Cross-module journeys; UI | ≤ 15 min | blocking |
| L4 | **API contract** | Every `/api/v3` operation against the generated OpenAPI document: request and response schema, error envelope, pagination cursor, idempotency-key replay, additive-only diff vs the previous release | Business arithmetic (L2 owns it) | ≤ 8 min | blocking |
| L5 | **Authorisation** | The generated route × role × scope matrix: allow, deny, and **the write set on deny** | Whether the underlying operation is correct | ≤ 10 min | blocking |
| L6 | **Tenant isolation** | The generated per-entity and per-endpoint cross-tenant suite (Law 6): read, write, upsert-match-key, direct-id fetch, export, document stream, aggregate | Permission semantics (L5 owns them) | ≤ 10 min | blocking |
| L7 | **Workflow** | Instance semantics: immutability of a decided step, parallel/quorum, SLA and escalation, in-flight version pinning, delegation, self-approval prohibition | The business object under approval | ≤ 6 min | blocking |
| L8 | **Rule engine** | §XXX.3.2 conformance plus §XXX.6 precedence goldens | Module actions' side effects (L2/L3) | ≤ 5 min | blocking |
| L9 | **Formula engine** | §XXX.3.3 conformance: typing, money exactness, allocation, cycles, traces | Which formula a component uses | ≤ 4 min | blocking |
| L10 | **Migration** | Schema equivalence, transformation correctness, reconciliation, the parallel-run diff harness (§XXX.8) | Steady-state behaviour | ≤ 25 min (nightly full; sampled per commit) | blocking on the release branch |
| L11 | **Regression** | Every fixed defect, permanently. One case per defect, named for its ticket, never deleted | New behaviour | inside L2/L3 budgets | blocking |
| L12 | **Security** | §XXX.7: fail-closed fault injection, injection and traversal, crypto and secrets, session and MFA, signed URLs, egress, upload handling, dependency and SAST | Functional correctness | ≤ 12 min + nightly deep scan | blocking |
| L13 | **Performance** | §XXX.9: query-count budgets, latency budgets, ingestion throughput, payroll duration, memory ceilings, at 1k/5k/10k | Correctness of results (asserted anyway, cheaply) | nightly, ≤ 90 min | blocking on the release branch; query-count budgets blocking per commit |
| L14 | **End-to-end** | Six journeys (§XXX.2.5) on the shipped artefact through the SPA, in each supported deployment shape | Anything provable at a lower layer | ≤ 20 min | blocking on the release branch |

**R-XXX-001** Every automated test MUST declare its layer. A test not declaring a layer fails
the build. *(Prevents the drift by which SmartPRS 2's "unit" directory holds tests that boot
the framework and hit a database — `M-…` §1.5.)*

**R-XXX-002** A layer MUST NOT assert what a lower layer already proves. Duplication is
detected by the golden-case registry (§XXX.12) rejecting two cases with identical
`(subject, inputs_hash, expectation_hash)` at different layers.

### XXX.2.3 Determinism is a property of the harness, not of discipline

**R-XXX-003** There is exactly one clock. Domain code obtains time only from an injected
`Clock`; the test harness binds a `FrozenClock`. A CI check fails the build on `now()`,
`time()`, `date()`, `Carbon::now()`, `new DateTime()` without an argument, or `microtime()`
anywhere under `Domain\*`. *(SmartPRS 2's invoice month segment comes from `now()->format('m')`
inside a numbering routine — `BillingController.php:81-98` — which is both a defect and
untestable.)*

**R-XXX-004** There is exactly one randomness source, seeded per test from the test's own
name, so a failure reproduces from the failure message alone.

**R-XXX-005** No test may depend on database row order. Every assertion over a collection
sorts by a declared key first. *(SmartPRS 2 resolves scope precedence by row order —
`M-…` §9; the replacement is spine S3.2, and this rule stops the suite re-learning the habit.)*

**R-XXX-006** No test may sleep. Time passes only by advancing the frozen clock; queues drain
by an explicit `processUntilEmpty()`; SLA and escalation are tested by clock advance, never by
waiting.

**R-XXX-007** Every test is order-independent and may run in parallel. CI runs the suite in a
**shuffled** order with a recorded seed on every build, and in reverse-registration order once
per night. An order-dependent test is a failing test.

### XXX.2.4 Fixture-pack discipline — the legal-values rule, mechanised

The blueprint may not assert a statutory value as fact (spine S12). Neither may a test. The
mechanism:

**R-XXX-008** Golden values in this part are computed against **fixture rule packs authored
for the test suite** (`FIX-*`, §XXX.11.4). A fixture pack's rates, ceilings and slabs are test
data chosen to resemble the shape of Indian statute — *not* an assertion of what the statute
is. Where a scenario's expected value depends on a statutory parameter it is written as
`<pack:key>` and the arithmetic is given in full, so the same case is re-derivable against any
pack.

**R-XXX-009** The **real** packs Ametecs publishes carry their **own** golden cases inside the
pack (Part XVII `T-08`: a pack whose golden cases fail on this build is rejected and nothing
is activated). Those cases are authored by the compliance owner (spine D-03), verified against
the primary source recorded in `source_reference`, and are the only place a statutory value is
asserted as correct. The application suite never encodes one.

**R-XXX-010** A golden test MUST read the same artefact the engine reads. Hand-copied
snapshots of a statutory table are forbidden, and a CI check fails the build on any file under
`tests/` that declares a slab, rate, ceiling or threshold as a literal outside a fixture-pack
definition file. *(Directly replaces `tests/manual/pt_extract.php`, a duplicated statutory
table maintained by a docblock instruction — `M-…` §1.6, §9.)*

### XXX.2.5 The six end-to-end journeys

E2E is expensive and brittle; it earns its place only for journeys that cross every layer and
every deployment concern. Exactly six exist, and adding a seventh requires the same approval
as adding a module.

| # | Journey | Proves |
|---|---|---|
| E2E-1 | Provision a tenant from a configuration template → add one employee → run one payroll → approve → produce the payout file | A fresh tenant can pay somebody without opening a configuration screen (Part I R-33) |
| E2E-2 | Configure a rule in the visual builder → simulate → publish with approval → observe it change the next attendance derivation → view the trace | Configuration is the product (spine S0, Law 4) |
| E2E-3 | Ingest punches from a device adapter → derive days → settle a period → pay → explain a payslip line back to the originating punch | The four-layer attendance contract (spine S10) end to end |
| E2E-4 | Employee self-service: apply for leave → manager approves → payroll reflects it → payslip and trace visible to the employee only | Self-service isolation and the leave-to-money path (defect 1) |
| E2E-5 | Install the shipped artefact on-premise, run the installer, apply a rule-pack update, apply an application update, roll it back | Deployment parity (spine S18); rule-pack updates need no deployment (S12) |
| E2E-6 | Import a customer's SmartPRS 2 dataset into a fresh tenant, run the parallel-run diff for one period, produce the reconciliation report | The migration path (§XXX.8) |

### XXX.2.6 The coverage gates that block a release

Coverage is a **floor with a shape**, not a number. Four classes of module carry four
floors, and four structural gates carry no percentage at all because a percentage cannot
express them.

**Class A — money, statutory and identity.** `Domain\Payroll`, `Domain\Statutory`,
`Domain\Money`, `Domain\Loans`, `Domain\Commission`, `Domain\Leave` (ledger),
`Domain\Billing`, `Domain\Security`.
**Class B — engines.** `Domain\Rules`, `Domain\Formula`, `Domain\Workflow`, `Domain\Spx`,
`Domain\Config`.
**Class C — business modules.** Attendance derivation, employee schema, recruitment,
compliance, notifications, reports.
**Class D — adapters, transport and presentation.**

| Gate | Class A | Class B | Class C | Class D |
|---|---:|---:|---:|---:|
| Line coverage floor | 95 % | 95 % | 85 % | 60 % |
| Branch coverage floor | 90 % | 95 % | 75 % | 50 % |
| **Mutation score floor** (§XXX.11.10) | **85 %** | **85 %** | 60 % | — |
| Golden cases required per published rule/formula | every one | every one | every one | — |
| Cross-tenant isolation case per aggregate and endpoint | mandatory | mandatory | mandatory | mandatory |

**The structural gates.** Each is pass/fail, each maps to a spine law, and none can be
satisfied by writing more tests of something else.

| G | Gate | Law | Fails the build when |
|---|---|---|---|
| G-1 | **No business literal** in `Domain\*` outside the declared allow-list | L1 | A bare numeric or string business literal appears |
| G-2 | **No DDL outside `database/migrations`**, and the suite's database user holds no `ALTER`/`CREATE`/`DROP` | L2 | Any `Schema::create`/`Schema::table`/raw DDL is reachable from a request or a job — or a test — path |
| G-3 | **`migrate:fresh` produces a schema identical to production's structure** | L2 | Any structural diff (§XXX.8.1) |
| G-4 | **Every derived value carries a trace**; a trace-less result throws | L4 | Any engine returns a value without a trace, asserted by a harness that inspects every return of every engine call made during the whole suite |
| G-5 | **Every route has an explicit policy binding**, and fault injection inside authorisation denies | L5 | Any route lacks a binding, or any injected fault yields allow |
| G-6 | **A generated cross-tenant test exists for every aggregate and every endpoint**, and passes | L6 | Any aggregate or endpoint has no generated case, or any case leaks |
| G-7 | **No float arithmetic touches money**; every run asserts `SUM(lines) = totals` exactly | L7 | Static analysis finds a float in a money path, or any reconciliation invariant (§XXX.4.20) fails |
| G-8 | **No silent catch in a domain path** | S5.6 | A `catch` in `Domain\*` neither rethrows, nor converts to a typed domain error, nor records an operator-visible incident |
| G-9 | **Every fixed defect has a permanent regression case** | — | A defect is closed without a case referencing its identifier |
| G-10 | **No test skipped, incomplete or marked risky** in a blocking lane | — | Any skip without an approved, dated, ticketed exemption that expires |
| G-11 | **Static analysis at the pinned level, zero new findings** against a ratcheting baseline that may only shrink | — | The baseline grows |
| G-12 | **Query-count budget** per endpoint (§XXX.9.3) | — | Any endpoint exceeds its budget |

**R-XXX-011** The release gate is the conjunction of the four coverage floors and the twelve
structural gates. A build that fails any one does not ship. There is no override flag; a
genuine emergency is handled by reverting the change, not by lowering the gate.

**R-XXX-012** Coverage is measured, published per build, and **ratcheting**: a merge that
lowers a module's coverage below its recorded high-water mark fails, even when above the
floor. *(SmartPRS 2 has never produced a coverage figure — `M-…` §1.4.)*

**R-XXX-013** Every gate has a named owner and a dashboard tile. A gate nobody owns is
removed, not ignored.

**R-XXX-014** The suite MUST run in under **35 minutes** on the per-commit lane and under
**2 hours** on the nightly lane. A lane that exceeds its budget for three consecutive days
is a defect against the quality owner, not an acceptable cost.

**R-XXX-015** No test may be deleted to make a build pass. Deleting a test requires the same
review as deleting the behaviour it asserts, and the golden-case registry records the
deletion with a reason.

**R-XXX-016** Every money golden value is an externally derived constant stored in the golden
file. A test that computes its expected value by invoking the system under test, or by
restating the implementation's formula, is rejected at review and detected by
§XXX.11.10 mutation testing (an expectation that survives every mutant is an expectation that
asserts nothing).

**R-XXX-017** A test asserting an HTTP status code for a route that writes MUST also assert
the resulting write set, including the assertion that **nothing** was written on a denial.

**R-XXX-018** A golden test MUST enter through the shipped public contract of the module. A
CI reachability check fails the build on a domain service that is covered by tests but invoked
by no production caller.

**R-XXX-019** No test may read a copy of data the engine reads. Fixtures reference the
artefact; they do not restate it.

---

## XXX.3 Engine conformance suites

Each engine carries a suite that is **independent of every module**: it references no payroll
component, no leave type, no attendance status, and no tenant's configuration. It is the
contract test for spine S5–S8. A module may not satisfy it, and it may not be weakened to
accommodate a module.

**R-XXX-020** The rule engine, the formula engine, the workflow engine and the SPX
implementation each carry a conformance suite located outside every module namespace, with no
dependency on any module. A change to an engine ships only if the conformance suite passes
unchanged, or if the suite change is itself versioned with a documented behavioural reason and
an engine version bump.

**R-XXX-021** Conformance suites are **executable specifications**: each case is a declarative
fixture (input, configuration, expected output, expected trace) in a data file, not a PHP
method. This makes the same corpus runnable against a future re-implementation of an engine,
which is the point.

### XXX.3.1 SPX conformance *(spine S5)*

| # | Case class | Must assert |
|---|---|---|
| SPX-01 | **Grammar — accept.** A corpus of at least 400 valid expressions covering every operator, every function in the S5.4 library, every literal form, and nesting to the depth limit | Parses; the AST round-trips to canonical source and back to an identical AST |
| SPX-02 | **Grammar — reject.** Assignment, loops, recursion, function definition, host-language escapes, comments containing a terminator, unbalanced parentheses, an unknown function, an unknown namespace | Rejected at **publish time** with a message naming the offending token and its position. Never a runtime surprise |
| SPX-03 | **Type checking.** `Money * Money`, `Days + Hours`, `Money + Number`, `Date − Date` without a unit, `Code` compared to `Text` | Each is a publish-time type error naming both types. `Money / Number → Money`, `Money * Number → Money` and `Days → Days` succeed |
| SPX-04 | **No silent coercion.** `Days` and `Hours` in one expression | Type error. *(The unit-confusion class that lets SmartPRS 2 measure absence in working days and value it in calendar days — `M-…` §6.1 Rank 2)* |
| SPX-05 | **Percent-of, not modulo.** `X % 40` and `MOD(a, b)` | `X % 40` is 40 percent of X; `MOD` is the remainder; both traced with the operator named |
| SPX-06 | **`ROUND` requires a mode.** `ROUND(x)` with no mode | Publish-time error. There is no default rounding anywhere |
| SPX-07 | **Determinism.** The full corpus evaluated 1,000 times, in shuffled order, in parallel workers | Byte-identical results and byte-identical traces every time |
| SPX-08 | **No clock, no randomness.** An expression attempting to read a wall clock or a random source | Not expressible; `context.evaluation_date` is the only temporal input, and changing the host clock changes nothing |
| SPX-09 | **Budgets.** Node count at the limit and one above; depth at the limit and one above; an evaluation exceeding the time budget; a regex exceeding its step limit | Each yields a typed budget error, is surfaced as a rule-evaluation failure, and is **never** silently swallowed |
| SPX-10 | **Totality.** Division by zero, `SLAB` miss, `BANDS` miss, `COALESCE` over all-null, an out-of-range `NTH_WEEKDAY` | Each returns a typed error value that propagates to a rule-evaluation failure surfaced to the operator |
| SPX-11 | **Fail closed on error.** An evaluation error inside a rule that would otherwise permit an action | The action does not apply; the operator sees the failure. *(SmartPRS 2 destroys 50.5 % of its caught exceptions — spine S5.6)* |
| SPX-12 | **Stored AST wins.** Publish an expression, change the parser's precedence table, re-evaluate | The published artefact evaluates identically, because the AST is stored with the source (S5) |
| SPX-13 | **Symbol table.** A namespace not declared available for the current evaluation | Publish-time error naming the namespace and the evaluation context |
| SPX-14 | **Date functions.** `CLAMP_DAY_TO_MONTH(31, Feb)` in a leap and a non-leap year; `EOMONTH`/`SOMONTH` at boundaries; `NTH_WEEKDAY` for `n ∈ {1..5, −1}` for every weekday across 2020–2035 | Matches an independently generated calendar table exactly, and at least one month exists where `ceil(day/7)` disagrees and SPX returns the correct answer *(cross-referenced to Part XII P0 #1)* |
| SPX-15 | **`MATCHES` is anchored and bounded.** A catastrophic-backtracking pattern | Terminates within the step limit and returns a typed error, never hangs a worker |

### XXX.3.2 Rule-engine conformance *(spine S6)*

| # | Case class | Must assert |
|---|---|---|
| RC-01 | **Purity.** Evaluate any rule set against any subject | Zero writes to any table. The engine returns `AppliedAction[]` + `DecisionTrace`; the caller applies them |
| RC-02 | **Specificity precedence.** Two artefacts matching the same subject, constrained on different dimensions | The higher summed weight wins, for all 21 dimensions and a random sample of 10,000 weight combinations, against an independently computed weight table |
| RC-03 | **Priority tie-break.** Equal specificity, different declared `priority` | The higher priority wins, and the trace names the loser and why it lost |
| RC-04 | **No third tie-break.** Equal specificity and equal priority, conditions that can both be true | **Publication is blocked**, with a message naming both artefacts and the count of affected employees. Never a silent coin-toss |
| RC-05 | **Conflict detection is sound for satisfiable overlap.** Two rules whose conditions overlap only for a narrow input band | Detected at publish; a pair that provably cannot both be true is **not** reported (no false positives that would train operators to ignore the detector) |
| RC-06 | **`on_conflict` semantics.** `first_match`, `all_match`, `highest_priority_only` over an identical candidate set | Three different, declared, traced outcomes |
| RC-07 | **`except` short-circuits.** A rule whose `when` is true and whose `except` is true | Does not apply; the trace records the exception as the reason |
| RC-08 | **Effective-date resolution.** A version published later but effective earlier; a version effective later than the evaluation date; a closed range | The version in force **on the evaluation date** applies, never the newest-published |
| RC-09 | **Aggregation windows.** `agg.count` over `day`, `week(start_day)`, `payroll_period`, `calendar_month`, `quarter`, `financial_year`, `rolling(n, unit)`, `since(date)`, `service_year`, each at both boundaries | Exact counts against an independently computed window table; a `rolling(30, days)` rule matches a set that a `calendar_month` rule does not, and vice versa |
| RC-10 | **`agg.occurrence_index`** across a window with a cap | The Nth occurrence carries index N; the cap stops further penalties; the trace names the index |
| RC-11 | **`agg.collapse_repeats`** at the configured window boundary ±1 second | Deterministic collapse, and the collapsed events remain individually retrievable |
| RC-12 | **`agg.streak`** forwards and backwards over a gap | Exact streak length, with the terminating event named |
| RC-13 | **`agg.balance`** against a ledger with a superseded entry | Reads the ledger as at the date, not the current row set |
| RC-14 | **Trace completeness.** Every evaluation | The trace names inputs, rule code, version, matched condition, action list, and the losing candidates. A trace-less result throws (Law 4, gate G-4) |
| RC-15 | **Action catalogue is closed.** A rule referencing an action outside the module's declared catalogue | Publish-time rejection naming the action |
| RC-16 | **Simulator equals production.** The same subject and date through the simulator and through the live path | Identical actions and identical traces, byte for byte |
| RC-17 | **Stored regression cases run at publish.** A rule carrying cases whose expectations no longer hold | Publication refused, listing the failing cases |
| RC-18 | **Fail closed.** Fault injection at each of: symbol resolution, aggregation source, ledger read, scope resolution | The rule does not apply, an operator-visible failure is raised, and no partial action set leaks to the caller |
| RC-19 | **Impact preview is exact.** Preview a rule set, then apply it to the same population | The previewed changed-employee count and the actual differ by zero |
| RC-20 | **Determinism under parallelism.** The same corpus evaluated across 16 workers | Identical outcomes and identical traces |

### XXX.3.3 Formula-engine conformance *(spine S7, Law 7)*

| # | Case class | Must assert |
|---|---|---|
| FC-01 | **Integer paise throughout.** Every intermediate value in a 50-formula dependency graph | No float appears in any intermediate; static analysis plus a runtime type assertion in the harness |
| FC-02 | **Division is exact rational until an explicit `ROUND`.** `(₹1,00,000 ÷ 3) × 3` | Exactly ₹1,00,000.00, not ₹99,999.99 |
| FC-03 | **`ALLOCATE` sums to the whole.** 10,000 randomised (amount, weights) tuples including weights summing to zero, single-element, and 97-element cases | `SUM(parts) = amount` exactly in every case; residue lands deterministically on the declared element; re-running yields identical parts |
| FC-04 | **The residue case.** ₹50,000.00 prorated 22/24 across BASIC 50 %, HRA 40 % of BASIC, SPECIAL as balance | Components ₹22,916.67 + ₹9,166.67 + ₹13,749.99 = **₹45,833.33** exactly, which is the prorated gross. Naïve independent rounding gives ₹45,833.34 and must fail this case *(the SmartPRS 2 balance-residue allocation is the one correct money split in that codebase — spine S19.4 — and this generalises it)* |
| FC-05 | **Rounding modes.** Each declared mode (half-up, half-down, half-even, ceil, floor, truncate) at `.005`, `.015`, `.025`, and at negative values | Exact expected value per mode; a component without a declared mode fails at publish |
| FC-06 | **Rounding points are declared and honoured.** The same graph with rounding at the component versus at the total | Two different, correct, traced results; neither is the engine's choice |
| FC-07 | **Cycle rejection.** A → B → C → A, and a self-reference | Publish-time rejection naming the full cycle path |
| FC-08 | **Topological evaluation.** A graph whose declaration order is the reverse of its dependency order | Evaluates correctly; declaration order is irrelevant |
| FC-09 | **Trace substitution.** Any formula | The trace renders the formula source, the substituted values, each intermediate step, the rounding mode and unit, and the artefact version — matching the S7 worked example's shape exactly |
| FC-10 | **Effective-dated formulas.** A formula version published in October, effective 1-Oct; evaluate for August | The August version is used |
| FC-11 | **Currency is carried.** An attempt to add two `Money` values of different currencies | Type error, not a silent addition |
| FC-12 | **Zero and negative.** A formula yielding zero, and one yielding a negative `Money` where negatives are permitted and where they are not | Declared behaviour in both cases; a disallowed negative is a named failure, never a silent `max(0, …)` *(SmartPRS 2 hides the shortfall behind `round(max(0, gross − totalDed), 2)` — `AppDataController.php:2130`)* |
| FC-13 | **Determinism.** The corpus evaluated 1,000 times in shuffled order | Byte-identical |
| FC-14 | **Publish-time sample assertion.** A formula published with named sample employees and expected values that no longer hold | Publication refused |

### XXX.3.4 Workflow-engine conformance *(spine S8)*

| # | Case class | Must assert |
|---|---|---|
| WC-01 | **A decided step is immutable.** Approve, then approve again; approve then reject; reject then approve | The second call is refused with a named error; the decision, actor, timestamp and comment are unchanged; **no second notification is emitted**. *(SmartPRS 2 re-fires transfer orders and increment letters on re-approval — `LeaveController::decide():392-428`, same shape in `RequestController::decide`)* |
| WC-02 | **Concurrent decisions.** Two approvers decide the same step simultaneously | Exactly one succeeds; the other receives a conflict error; exactly one transition row exists |
| WC-03 | **Every node type.** `submit`, `approval` in all four addressing modes, `condition_branch`, `parallel_all`, `parallel_any`, `quorum(n of m)`, `auto_approve`, `notify`, `escalate`, `return_for_edit`, `reject`, `cancel`, `finalise` | Declared semantics for each, including quorum at n−1, n and n+1 approvals |
| WC-04 | **In-flight version pinning.** Publish v2 while an instance is mid-flight on v1 | The instance completes on v1; a new instance starts on v2; both are traced |
| WC-05 | **SLA and escalation by clock advance.** Advance to breach−1s and breach+1s | No escalation, then exactly one escalation; reminders fire on the declared schedule; escalation is a scheduled job, never a human's memory |
| WC-06 | **Self-approval prohibition.** The requester holds the approver role | Refused; routed per the declared fallback; the trace names the prohibition |
| WC-07 | **Maker-checker shapes.** Payroll approval, bank-detail change, salary change, statutory configuration, rule publication, workflow publication, role change, employee deletion, loan sanction, full-and-final | Each uses the same engine; **zero module-specific approval code exists**, asserted by a CI check that no module namespace declares an approval state machine |
| WC-08 | **Every transition writes audit.** Any transition | Actor, timestamp, IP, decision, comment and entity snapshot hash present; an audit write failure fails the business transaction (§XXX.7.7) |
| WC-09 | **Delegation and backup approvers.** Delegate active, expired, and circular | Declared resolution; a circular delegation is refused at configuration time |
| WC-10 | **Amount-based routing.** Values at, below and above each threshold | Exact route selection, traced |
| WC-11 | **Return-for-edit preserves history.** Return, edit, resubmit | Prior decisions retained and visible; the resubmission is a new step, not an overwrite |
| WC-12 | **Cancellation is terminal.** Any transition after cancel | Refused |

---

## XXX.4 The payroll golden-test matrix *(brief Section 112)*

This is the centrepiece of this part and the primary safety property of the product. Every
scenario below is an **exact-value regression test**. "Approximately" does not appear.

### XXX.4.1 How to read this matrix

Each row gives four things: the **setup**, the **configuration in force**, the **expected
outcome** in structural terms, and **what exactly must be asserted**. The last column is
binding: a test that asserts less than it says does not satisfy the row.

**On values.** No statutory value is asserted here as legally correct (spine S12, hard rule 1).
Golden values are computed against the **fixture packs of §XXX.11.4** — test data authored for
the suite, shaped like Indian statute but asserting nothing about it. Where a value depends on
a statutory parameter it is written `<pack:key>` with the arithmetic given in full, so the row
is re-derivable against any pack. The rupee figures shown are the values that fall out of the
**fixture** pack arithmetic and are asserted as constants in the golden file.

**On what a failure means.** A row marked ⚠ reproduces a defect that is live in SmartPRS 2.
Such a row must be written **before** the corresponding SmartPRS 3 code and must be observed to
fail against a deliberately reintroduced defect at least once (§XXX.11.10).

### XXX.4.2 The fixture tenant `GOLD-IN`

Deterministically seeded, versioned, and identical on every machine and in every lane.

| Object | Definition |
|---|---|
| Legal entity | `LE-1`, one tenant `GOLD-IN` |
| Companies | `CO-TG` (state TG), `CO-MH` (state MH), `CO-KA` (state KA) |
| Calendar `CAL-SUN` | Sundays only → **Jul-2026: 27 working days** (this is the calendar Part XIV `T-XIV-03` uses; both calendars are asserted, and their divergence is itself `PR-018`) |
| Calendar `CAL-TG` | Sundays + 2nd and 4th Saturday + holiday `H1` on Fri 17-Jul-2026 → **Jul-2026: 31 calendar, 6 offs (Sun 5/12/19/26, Sat 11/25), 1 holiday, 24 working days** |
| Calendar `CAL-MH` | Sundays + every Saturday → **Jul-2026: 23 working days** |
| Cycle `CYC-CAL` | 1st → last day of month; pay date = last day |
| Cycle `CYC-2625` | 26th of the prior month → 25th; Jul-2026 period = 26-Jun→25-Jul (30 days) |
| Cycle `CYC-2120` | 21st of the prior month → 20th |
| Structure `STR-A` | `BASIC = GROSS % 50`; `HRA = BASIC % 40`; `SPECIAL` = balance component (absorbs residue) |
| `E1` | `CO-TG`, `CAL-TG`, `CYC-CAL`, `STR-A`, monthly gross **₹50,000.00** → BASIC ₹25,000.00, HRA ₹10,000.00, SPECIAL ₹15,000.00; joined 01-Apr-2024; male |
| `E2` | `CO-MH`, `CAL-MH`, `STR-A`, gross **₹20,000.00** → BASIC ₹10,000.00, HRA ₹4,000.00, SPECIAL ₹6,000.00; female |
| `E3` | `CO-KA`, `STR-A`, gross **₹1,50,000.00** |
| `E4` | `CO-TG`, `STR-A`, gross **₹12,000.00** (minimum-wage boundary) |
| `E5` | `CO-TG`, `CAL-TG`, **`CYC-2625`**, `STR-A`, gross ₹50,000.00 |
| `E6` | Two concurrent employments in `CO-TG` and `CO-KA` |
| Population | 500 seeded employments for reconciliation and scale rows; the same seed everywhere |

---

### XXX.4.3 Group A — period and cycle resolution *(14 scenarios)*

Harvested from `PeriodResolver` and its nine tests, which SmartPRS 2 never wired to payroll
(`M-…` §6.4). The engine under test is the shipped one (R-XXX-018).

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-001 | Run Jul-2026 for `E1` | `CYC-CAL` | Period 01-Jul→31-Jul | `start=2026-07-01`, `end=2026-07-31`, `calendar_days=31`, `working_days=24`, `pay_date=2026-07-31`, cycle version id in the snapshot |
| PR-002 | Run Jul-2026 for `E5` | `CYC-2625` | Period 26-Jun→25-Jul | `start=2026-06-26`, `end=2026-07-25`, `calendar_days=30`; the period label is `Jul-2026` and the label rule is configuration, not code |
| PR-003 | Run Feb-2027 (non-leap) with cut-off day 31 | `CYC` cut-off 31, overflow mode `clamp` | Period 01-Feb→28-Feb | `end=2027-02-28`; and under `next_month_first` and `last_working_day` modes, the two other declared results |
| PR-004 | Same for Feb-2028 (leap) | as above | Period ends 29-Feb | `end=2028-02-29` |
| PR-005 | Cycle changed from `CYC-CAL` to `CYC-2120`, effective 01-Aug-2026 | Continuity declared on the cycle artefact | A **gap** 01-Aug→20-Aug is detected at publish | Publication refused with the exact gap dates named; the bridging period is offered; after acceptance the two runs together pay each day exactly once |
| PR-006 | Cycle changed producing an **overlap** | as above | Rejected at publish | Overlap dates named; nothing published |
| PR-007 | Run Aug-2026 after a cycle version published 15-Sep-2026 effective 01-Sep | S4 resolution by date | The August run uses the version in force in August | The resolved cycle version id equals the August version, not the newest |
| PR-008 | Two runs for the same `(company, period, lot)` created concurrently | unique constraint on the run key | One succeeds, one is rejected | Exactly one `payroll_runs` row; the loser receives a named constraint error, not a 500. ⚠ *(SmartPRS 2 has no unique index on `(tenant_id, company_id, cycle_label)` — `M-…` C-1)* |
| PR-009 | Period resolution for an employee whose company changes mid-period | Org assignment effective-dated | The period is resolved once, per run, per company scope | The employee appears in exactly one company's run; the other run excludes them; both traces name the assignment version |
| PR-010 | A 53-week financial-year edge: `financial_year` window over 2026-27 | `fin_year` starting April | Window 01-Apr-2026→31-Mar-2027 | Exact boundaries; a `service_year` window for `E1` (joined 01-Apr-2024) is 01-Apr-2026→31-Mar-2027 and differs from the calendar year |
| PR-011 | Run for a period whose `working_days` computes to zero (a month entirely of declared holidays in a fixture calendar) | `working` divisor basis | Blocking exception, not a division by zero | Named exception `zero_divisor`; the run cannot proceed past preview; **no** silent `max(1, …)` substitution. ⚠ *(SmartPRS 2 silently substitutes `max(1, …)` — `PayrollGenController.php:1117-1126`)* |
| PR-012 | Re-run PR-001 from the pinned snapshot | snapshot pinning (S11) | Byte-identical result | Every line, every trace and every version id identical; a diff of the two runs is empty |
| PR-013 | Period boundary at the tenant's timezone: a punch at 23:59:59 on 31-Jul and 00:00:00 on 01-Aug | Business timezone declared on the tenant | Each lands in its own period | Exact period assignment for both; the assertion is on the **business date**, never on a UTC instant |
| PR-014 | Payroll period versus attendance period differ (`CYC-2625` payroll, calendar-month attendance settlement) | both declared | The settlement consumed is the one the cycle names | The settlement id in the snapshot matches the cycle's declared attendance window, not the calendar month |

---

### XXX.4.4 Group B — divisor bases and proration *(12 scenarios)*

Every divisor basis SmartPRS 2 implements, plus the two it does not, each asserted as an
absolute rupee figure. SmartPRS 2 has **no test of any of them** (`M-…` §4.2).

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-015 | `E1`, Jul-2026, 2 absent working days | basis `working`, `CAL-TG` (24 working days) | Factor 22/24 | Gross **₹45,833.33**; BASIC ₹22,916.67; HRA ₹9,166.67; SPECIAL ₹13,749.99; components sum **exactly** to gross; trace names the denominator `24` and its calendar version |
| PR-016 | Same | basis `calendar` | Factor 29/31 | Gross **₹46,774.19** |
| PR-017 | Same | basis `fixed_30` | Factor 28/30 | Gross **₹46,666.67** |
| PR-018 | Same, but on `CAL-SUN` (27 working days) | basis `working` | Factor 25/27 | Gross **₹46,296.30** — identical input, different calendar, different money. Asserts that the calendar is an input to payroll, not an assumption (matches Part XIV `T-XIV-03`) |
| PR-019 | Same | basis `fixed_26` | Factor 24/26 | Gross **₹46,153.85** |
| PR-020 | Same | basis `payable_days` from the attendance settlement | Factor = settlement `payable_days / period_days` | The factor is read from `period_settlement`, never recomputed inside payroll; changing the settlement changes the gross and nothing else |
| PR-021 | `E1`, 2 absent days, basis `working`, **factor exceeds 1.0** because attendance credits 25 of 24 working days (an over-credit from a correction) | basis `working`, cap declared | Factor capped at 1.0 | Gross exactly ₹50,000.00, and a **warning exception** naming the over-credit is raised — the cap is not silent. ⚠ *(SmartPRS 2 caps at 1.0 silently — `:1287`)* |
| PR-022 | Per-day rate requested for an ad-hoc deduction | basis `fixed_26`, declared rounding | Per-day = gross ÷ 26 | ₹1,923.0769… held as exact rational; rounded only at the declared point; the deduction for 3 days is **₹5,769.23**, not 3 × ₹1,923.08 = ₹5,769.24 |
| PR-023 | The same employee's LOP and the same employee's arrear in one period use different bases | two components declaring different bases | Both honoured | Two denominators in one payslip, each named in its own trace; no cross-contamination |
| PR-024 | Divisor basis changed from `working` to `fixed_30` effective 01-Aug-2026; re-run Jul-2026 | S4 resolution by date | July still uses `working` | Gross ₹45,833.33 on the re-run; the trace names the July version |
| PR-025 | Half-day absence: 1 full absent day + 1 half day | `DayFraction` type, basis `working` | Factor 21.5/24 | Gross **₹44,791.67** (50,000 × 21.5 ÷ 24 = 44,791.6667); the payslip shows a 1.5-day LOP line. ⚠ *(SmartPRS 2 counts every leave day as exactly 1.0 — `paidLeaveDays():1077`)* |
| PR-026 | A month where `calendar` and `fixed_30` diverge maximally: February 2027, 2 absent | both bases | 26/28 vs 28/30 | ₹46,428.57 vs ₹46,666.67 — asserted as two distinct constants, proving the basis is configuration |

---

### XXX.4.5 Group C — joiners and leavers *(14 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-027 | `E1` joins 16-Jul-2026 | `CYC-CAL`, basis `working`, `CAL-TG` | Working days from DOJ = 12 of 24 | Gross **₹25,000.00**; the denominator is the **full-period** 24, the numerator the 12 working days from the DOJ |
| PR-028 | Same | basis `calendar` | 16/31 | Gross **₹25,806.45** |
| PR-029 | Same | basis `fixed_30` | 16/30 | Gross **₹26,666.67** |
| PR-030 | Same | basis `fixed_26` | 12/26 | Gross **₹23,076.92** |
| PR-031 | `E1` exits 10-Jul-2026 | basis `working`, `CAL-TG` | Working days to exit = 9 of 24 | Gross **₹18,750.00** |
| PR-032 | Same | basis `calendar` | 10/31 | Gross **₹16,129.03** |
| PR-033 | Same | basis `fixed_30` | 10/30 | Gross **₹16,666.67** |
| PR-034 | Joins 06-Jul, exits 20-Jul | basis `working`, `CAL-TG` | 11 of 24 | Gross **₹22,916.67**; calendar basis ₹24,193.55; fixed_30 ₹25,000.00 |
| PR-035 | `E5` joins 06-Jul on `CYC-2625` | cut-off cycle, basis `fixed_30` | Window 20 days of 30 | Gross **₹33,333.33** — identical to Part XIV `T-XIV-01`; asserts the joiner window is measured inside the **cut-off** period, not the calendar month |
| PR-036 | `E5` exits 30-Jun, run for period 26-Jun→25-Jul | `CYC-2625` | 5 payable days (26–30 Jun) | Gross **₹8,333.33** (fixed_30); the employee appears in the July-labelled run although the exit is in June |
| PR-037 | Joins on the last day of the period | basis `working`, day is a working day | 1 of 24 | Gross **₹2,083.33**; and 0 if the day is an off, with a named zero-payable exception rather than a zero payslip |
| PR-038 | Joins on the first day and exits on the last day of the same period | any basis | Full pay | Exactly ₹50,000.00 on every basis — the boundary that proves proration does not fire spuriously |
| PR-039 | Rejoin: exits 10-Jul, rejoins 22-Jul, same period | rejoin policy `two_windows` | Two payable windows | Two prorated windows, each with its own structure resolution; `SUM(windows) ≤ full-period entitlement`; the payslip shows both. Under policy `single_window` the declared alternative result |
| PR-040 | Exit with a notice-period recovery and unavailed leave encashment in the same run | F&F configuration | Both settle in one payslip | The encashment valuation, the recovery, and net; the F&F run is a declared run type and its own reconciliation holds |

---

### XXX.4.6 Group D — leave and loss of pay *(10 scenarios)*

`PR-041` is **the single most important test in this matrix**: it fails against SmartPRS 2 as
shipped and is the reason the leave-to-money path is re-specified.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-041 ⚠ | 1 day of an unpaid leave type, applied through the **product's own apply path**, approved, then payroll run | leave type `is_paid = false` | The day is LOP | `payable_days` reduced by exactly **1.000**; the payslip carries an LOP line of 1.000 day; gross ₹47,916.67 (23/24); the leave ledger shows one consumption. ⚠ *(In SmartPRS 2 `type_id` is never written and the unpaid branch is unreachable dead code — `LeaveController:359-373`, `PayrollGenController:1055,1065,1067`; every LOP leave is paid in full)* |
| PR-042 | 1 day of a paid leave type, same flow | `is_paid = true` | Full pay | Gross exactly ₹50,000.00; the leave ledger shows one consumption; `payable_days` unchanged |
| PR-043 | Leave applied but **not approved** at the moment of the run | pending-leave policy `treat_as_absent` / `treat_as_paid` / `block_run` | Three declared outcomes | Each asserted; `block_run` raises a blocking preview exception naming the employee count |
| PR-044 ⚠ | Fri–Mon leave with Sat and Sun off | one definition of a leave unit | Balance charged and days credited **agree** | Both numbers asserted and equal. ⚠ *(SmartPRS 2 charges 4 calendar days and credits 2 working days — `LeaveController:227-243` vs `PayrollGenController:1077-1082`)* |
| PR-045 | Sandwich policy on and off, same input | adjacency policy versions | 4 LOP days vs 2 | Two exact results from identical input with zero code differences; the policy version appears in the trace *(cross-ref Part XIII P0 sandwich table — all eight policies)* |
| PR-046 | Half-day leave plus a half-day late penalty on the same day | `DayFraction`, penalty rule | 1.0 day lost, not 1.5 or 0.5 | The declared combination rule is applied and named in the trace; the double-count and the swallow are both asserted absent |
| PR-047 | Leave spanning the period boundary (28-Jul→03-Aug) | period split | Split across two runs | 4 days in July, 3 in August; the same total; neither run double-counts |
| PR-048 | Leave of a type introduced **after** the period, back-dated | S4 resolution | The type in force in the period governs | The unknown-type case is a named error, never a silent unlimited grant. ⚠ *(SmartPRS 2 grants unlimited leave for an unmatched type name — Part XIII SEC-06)* |
| PR-049 | Encashment of 5 leave days at exit | encashment valuation formula | Value = 5 × declared per-day basis | Exact rupee amount; the valuation basis is a named artefact, not `gross ÷ 30` in code |
| PR-050 | Comp-off earned in June, availed in July, encashed in August | comp-off ledger, expiry rule | Ledger balances across three periods | Balance after each period; no double count; an expired credit is lapsed and traced |

---

### XXX.4.7 Group E — attendance settlement into payroll *(9 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-051 ⚠ | An employee with **zero punches for the whole period**, no leave, not on holiday | zero-evidence policy `pay_in_full` | Full pay | Exactly ₹50,000.00, and a **blocking preview exception** naming the employee so a human decides. The safeguard exists because a dead biometric reader must not zero a payroll; the test names it. Under the alternative policy `treat_as_absent` the declared zero-payable result, also with an exception. ⚠ *(SmartPRS 2 pays 100 % silently — `:1237`; inverting that guard pays nothing to a whole site with no test failing)* |
| PR-052 | Attendance settlement frozen, then a correction approved for a date inside it | period freeze | The frozen settlement is unchanged | The run consumes the frozen figures; the correction produces an **arrears item** for the next period with owner, target period and delta *(cross-ref Part XI `G-32`)* |
| PR-053 | Settlement not yet frozen at run time | preview gating | Blocking exception | `attendance_not_settled` in preview; the run cannot advance to draft |
| PR-054 | Attendance recalculated between preview and draft | snapshot pinning | The run uses the pinned snapshot | Result identical to the preview; the changed-input diff names the settlement version that changed |
| PR-055 | Employee present on a declared holiday | holiday-work policy: comp-off / OT / extra day's pay | Three declared outcomes | Each asserted as an exact figure; the policy version in the trace |
| PR-056 | Employee on a weekly off with punches | weekly-off work policy | Declared outcome | Exact figure; not counted as a working day for the divisor unless the policy says so |
| PR-057 | Late-arrival penalty: the 3rd qualifying late over 10 minutes in the payroll period | rule `agg.occurrence_index`, penalty half day | 0.5 payable day removed | `payable_days` reduced by exactly 0.5; the occurrence index is 3; the cap is respected; the same rule expressed over `rolling(30, days)` gives a **different** set of qualifying days *(cross-ref Part XI `G-09`, `G-10`)* |
| PR-058 | Shortfall offset: late 13m, late exit 41m, shortfall 21m, cap 60m | settlement variant B | Offset applied | Offset 34m, late 0m, shortfall 0m, OT 0m — and payroll consumes the settled figures, not raw minutes *(Part XI `G-12`)* |
| PR-059 | 500-employee population, a randomised month of mixed statuses | full settlement | Reconciliation holds | For every employee-day: `payable + LOP + leave + off + holiday = period days`, 100 % of rows; a deliberately corrupted settlement row makes the run **fail**, not pay |

---

### XXX.4.8 Group F — provident fund *(9 scenarios, fixture pack `FIX-PF-1`)*

`FIX-PF-1` (test data, not law): employee `<pack:pf.employee_rate>` = 12.00 %; wage ceiling
`<pack:pf.wage_ceiling>` = ₹15,000.00; employer total 12.00 %; EPS 8.33 % of
`min(wage, ceiling)` capped `<pack:pf.eps_cap>` = ₹1,250.00; EPF-employer = employer total −
EPS; EDLI 0.50 % of `min(wage, ceiling)` capped ₹75.00; admin 0.50 %; rounding half-up to
rupee.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-060 | `E1`, BASIC ₹25,000.00 above the ceiling | `FIX-PF-1`, ceiling applied | Wage base = ₹15,000.00 | Employee **₹1,800.00** = `ROUND(min(25,000, <pack:pf.wage_ceiling>) × <pack:pf.employee_rate>)` |
| PR-061 | Wage exactly at the ceiling ₹15,000.00 | as above | Same base | Employee **₹1,800.00** — the boundary asserted separately from PR-060 |
| PR-062 | Wage ₹12,000.00 below the ceiling | as above | Base = ₹12,000.00 | Employee **₹1,440.00** |
| PR-063 | Employer split for `E1` | as above | EPS + EPF-employer = employer total | EPS **₹1,249.50** (8.33 % × 15,000, cap not binding); EPF-employer **₹550.50** (1,800.00 − 1,249.50); EDLI **₹75.00**; admin **₹75.00**. **Each of the four is asserted separately because each is a separate ECR column** |
| PR-064 | Pack variant `FIX-PF-2` with ceiling ₹21,000.00 and the EPS cap unchanged at ₹1,250.00 | `FIX-PF-2` | The EPS cap now binds | EPS **₹1,250.00** (8.33 % × 21,000 = 1,749.30, capped); employer total ₹2,520.00; EPF-employer **₹1,270.00**. Asserts the cap and the ceiling are independent parameters |
| PR-065 | Employee with `apply_ceiling = false` (voluntary higher contribution) | per-employee statutory option | Base = full PF wage | Employee **₹3,000.00** on BASIC ₹25,000.00; the employer split follows the declared rule for the excess |
| PR-066 | The PF wage base is assembled from components | `is_pf_wage` flags on components | Base = sum of flagged components | The base contains no component matched by **name**; renaming BASIC to "Basic Pay" changes nothing; a CI check asserts no statutory code inspects a component name (spine S12) |
| PR-067 | An employee below the eligibility threshold on joining, crossing it mid-year | eligibility SPX in the pack | Membership begins per the pack rule and never lapses | The exact month membership begins; once a member, a later fall below the threshold does not end membership; the lock is a **stored, queryable fact**, not a re-derivation from payslip JSON |
| PR-068 | Arrear paid in July for a June increment | PF on arrears policy | Arrear enters the PF wage per its flag | The PF for the arrear computed against the **June** pack version and reported in the July ECR month; both version ids in the trace |

---

### XXX.4.9 Group G — employees' state insurance *(8 scenarios, `FIX-ESI-1`)*

`FIX-ESI-1` (test data): coverage when gross ≤ `<pack:esi.threshold>` = ₹21,000.00; employee
`<pack:esi.employee_rate>` = 0.75 %; employer 3.25 %; rounding **up to the next rupee**;
contribution periods Apr–Sep and Oct–Mar.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-069 | `E2`, gross ₹20,000.00 | `FIX-ESI-1` | Covered | Employee **₹150.00** = `CEIL(20,000 × 0.75 %)`; employer **₹650.00** = `CEIL(20,000 × 3.25 %)` |
| PR-070 | Gross ₹20,001.00 | as above | Rounding direction matters | Employee **₹151.00** (150.0075 → up); employer **₹651.00** (650.0325 → up). The row exists solely to pin the rounding direction |
| PR-071 | Gross ₹20,999.00 / ₹21,000.00 / ₹21,001.00 | threshold inclusive at the boundary | Covered, covered, not covered | ₹158.00 / ₹158.00 / **₹0.00**; and the pack's boundary inclusivity is asserted to be **declared**, not inferred |
| PR-072 ⚠ | An employee contributing in April crosses the threshold in July | contribution-period lock | Coverage continues to the end of September | ESI deducted in Jul, Aug, Sep at the **actual** gross; zero from October. The lock is read from a stored `employee_period_statutory_lock` row, **never** by scanning prior payslips' JSON. ⚠ *(SmartPRS 2 scans up to 24 payslips' `deductions` JSON for any key containing "esi" and reverts to the plain threshold on any error — `AppDataController.php:2011-2046`)* |
| PR-073 | The same crossing in the Oct–Mar period | as above | Coverage continues to the end of March | The exact stop month in both periods |
| PR-074 | Fault injection on the lock read | fail-closed contract | The run raises a blocking exception | The run does **not** silently fall back to the plain threshold; the exception names the employee and the lock |
| PR-075 | Renaming the ESI component | component flags | No behavioural change | Byte-identical figures before and after the rename |
| PR-076 | An employee in an establishment not covered by ESI | establishment applicability | No ESI lines | Zero ESI on both sides; the applicability decision and its source appear in the trace |

---

### XXX.4.10 Group H — professional tax *(9 scenarios)*

The nine rows below reproduce the seven SmartPRS 2 PT assertions — the only externally derived
statutory figures in that suite (`M-…` §4.1) — as fixture packs, and add the boundaries that
suite lacks. `FIX-PT-TG`: 0 ≤ ₹15,000; ₹150 for ₹15,001–₹20,000; ₹200 above.
`FIX-PT-MH`: 0 ≤ ₹7,500; ₹175 for ₹7,501–₹10,000; ₹200 above, with a February uplift to ₹300
**at the top slab only**, and a per-company female exemption up to ₹25,000.
`FIX-PT-KA`: 0 below ₹25,000; ₹200 at and above.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-077 | TG gross ₹14,000 / ₹15,000 / ₹15,001 / ₹20,000 / ₹20,001 | `FIX-PT-TG` | Slab walk | ₹0 / ₹0 / ₹150 / ₹150 / ₹200 — five assertions, both sides of both boundaries |
| PR-078 | KA gross ₹24,999 / ₹25,000 | `FIX-PT-KA` | Slab walk | ₹0 / ₹200 |
| PR-079 | MH gross ₹7,500 / ₹9,000 / ₹15,000 | `FIX-PT-MH`, month ≠ Feb | Slab walk | ₹0 / ₹175 / ₹200 |
| PR-080 | MH, **February**, gross ₹15,000 and ₹9,000 | `FIX-PT-MH` | Uplift at the top slab only | **₹300** and **₹175** — the second value is the whole point of the row |
| PR-081 | MH female, gross ₹20,000 / ₹25,000 / ₹30,000, exemption **on** | company switch on | Exempt to the declared ceiling | ₹0 / ₹0 / ₹200 |
| PR-082 | MH male, gross ₹20,000 | as above | Not exempt | ₹200 |
| PR-083 | MH female, gross ₹20,000, exemption **off** | company switch off | Not exempt | ₹200 — asserts the switch is a per-company configuration value |
| PR-084 | **TG** female, gross ₹20,000, MH exemption on for the MH company | two states in one tenant | The exemption does not leak | ₹150. The state is matched by **code**; a `str_contains` match on a state name fails this row |
| PR-085 | A state with no PT | pack declares the state PT-free | No PT line | Zero, with the reason traced; adding a new state's slab table requires **no code change**, asserted by adding one in the test |

---

### XXX.4.11 Group I — LWF, bonus, gratuity, minimum wage *(8 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-086 | LWF in a state with a half-yearly deduction month | `FIX-LWF-TG` | Deducted only in the declared month | Zero in eleven months, the exact amount in the declared month; both employee and employer sides asserted |
| PR-087 | Statutory bonus for an eligible employee | `FIX-BON-1` | Bonus computed **and paid onto a payslip** | The exact amount, the eligibility decision, and a payslip line. ⚠ *(SmartPRS 2 computes a provision that nothing ever pays)* |
| PR-088 | Bonus for an employee below the eligibility wage and above the calculation ceiling | pack eligibility and ceiling | Two declared outcomes | Each exact; the ceiling and the eligibility wage are separate pack parameters |
| PR-089 | Gratuity accrual for `E1` at 2 years 4 months of service | `FIX-GRA-1` | Accrual per the pack formula | The exact accrual and the service computation `YEARS_OF_SERVICE` at the boundary (2 years 4 months vs 2 years 6 months under the pack's rounding rule) |
| PR-090 | Gratuity payable at exit after 5 years | as above | Payable amount on the F&F run | Exact amount; the wage base built from `is_gratuity_wage` flags |
| PR-091 | `E4`, gross ₹12,000, minimum-wage floor ₹13,000 on the comparable component sum | `FIX-MW-TG` | Blocking exception | `minimum_wage_breach` naming the floor **₹13,000.00**, its source, and the shortfall **₹1,000.00**; the run cannot advance |
| PR-092 | `E4` with the floor at ₹12,000 exactly | as above | No breach | The run proceeds; the boundary is asserted on both sides |
| PR-093 | A company policy set **below** the statutory baseline in a pack whose severity is `block` | pack severity | Publication refused | The refusal names the dimension, the baseline, the tenant value and the pack version; with severity `warn`, publication succeeds and records an explicit compliance exception |

---

### XXX.4.12 Group J — income tax *(6 scenarios, `FIX-TAX-1`)*

`FIX-TAX-1` is a **toy regime authored for the suite**: standard deduction ₹50,000; slabs
0–₹3,00,000 at 0 %, ₹3,00,001–₹7,00,000 at 5 %, above ₹7,00,000 at 10 %; full rebate where
taxable income ≤ ₹7,00,000; cess 4 %. It asserts the **engine**, never the statute.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-094 | `E3`, annual gross ₹18,00,000 | `FIX-TAX-1` | Annual liability computed then spread | Taxable ₹17,50,000; slab tax ₹20,000 + ₹1,05,000 = **₹1,25,000**; cess **₹5,000**; total **₹1,30,000**; each of the four asserted separately |
| PR-095 | Same, monthly spread over 12 periods | declared spreading and residue rule | Instalments sum exactly to the annual figure | Eleven months **₹10,833.33**, final month **₹10,833.37**; `SUM = ₹1,30,000.00` exactly (an `ALLOCATE` case, not twelve independent roundings) |
| PR-096 | `E1`, annual gross ₹6,00,000 | `FIX-TAX-1` | Rebate applies | Taxable ₹5,50,000; slab tax ₹12,500; **rebate ₹12,500**; final tax **₹0.00**; each step visible in the trace |
| PR-097 | An employee joining in month 7 with declared prior-employer income | prior-income handling | Annualisation uses declared income | The exact annual and monthly figures; the prior-income declaration version in the trace |
| PR-098 | Declarations submitted in month 9 changing the projection | mid-year re-projection | Remaining months absorb the difference | The month-9 instalment and the remaining months asserted; no negative instalment unless the policy allows, and if it does, the exact refund figure |
| PR-099 | A regime choice change effective from the next financial year | effective dating | The current year is unaffected | Re-running any month of the current year reproduces the original figures |

---

### XXX.4.13 Group K — overtime, night, holiday work, comp-off *(8 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-100 | `E1`, 10 approved OT hours at a 2× multiplier | OT rate = declared base ÷ declared divisor artefact | OT amount from a named artefact | With divisor `26 days × 8 hours`: hourly ₹240.3846…, OT **₹4,807.69**; with divisor `24 days × 9 hours` a **different** exact figure. ⚠ *(SmartPRS 2 hard-codes `ctc/12/26/8` inside a money formula while the week-off pattern and the shift's full-day hours are configurable and disagree with it — `PayrollGenController.php:2165`)* |
| PR-101 | OT at two multipliers in one period (weekday 1.5×, holiday 2×) | two OT rules | Both lines present | Two OT lines, each traced to its rule and multiplier; neither is merged |
| PR-102 | OT minutes not approved at run time | approval gating | Not paid | Zero OT; the unapproved minutes are reported as a preview exception, never silently paid or silently dropped |
| PR-103 | OT already paid in a prior run | OT paid-stamp | Not paid twice | Zero; the stamp is set **inside** the run's transaction (§XXX.4.19) |
| PR-104 | Night-shift allowance, 3 qualifying nights | allowance per qualifying night, night defined by the resolved shift | 3 × allowance | Exactly 3 × the allowance; nights counted from the **resolved shift**, not from a punch-date heuristic; a rostered week-off night is not credited |
| PR-105 | Holiday work under each of three policies | comp-off / OT / extra day's pay | Three declared outcomes | Each exact, from configuration alone, with zero code differences |
| PR-106 | Comp-off encashed | encashment valuation formula | Value per the formula | Exact amount; the comp-off ledger balance after encashment; an expired credit cannot be encashed |
| PR-107 | OT enters the statutory bases its component flags declare | component flags | Bases follow flags | PF, ESI, PT-gross and taxable bases each include or exclude OT per its flags; flipping one flag changes exactly one statutory figure |

---

### XXX.4.14 Group L — commission, incentive, reimbursement *(6 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-108 | Commission approved and paid with salary | commission ledger, lock on the run version | One payslip line, one ledger debit | Exact amount; **exactly one** passbook debit; the lock is idempotent |
| PR-109 | The same commission in a second run | lock | Not paid again | Zero; the lock is matched on the **run version id**, never on a `LIKE 'run #<id> %'` free-text pattern. ⚠ *(SmartPRS 2 matches on free text, so a manual passbook entry whose reference happens to match is deleted — `M-…` C-13)* |
| PR-110 | Two concurrent runs both eligible to lock the same commission | constraint | One locks | Exactly one debit; the other run fails or excludes, never both. ⚠ *(SmartPRS 2 double-debits the passbook)* |
| PR-111 | A commission slab formula at each band boundary | `BANDS` in a formula | Exact band selection | The value at band edge, edge−0.01 and edge+0.01; no fall-through to the first band |
| PR-112 | Reimbursement with a taxable and a non-taxable portion | component flags | Split honoured | Exact amounts; only the taxable portion enters the tax base |
| PR-113 | Incentive scheme paying on an attendance-derived measure | rule + formula | Value from the settled measure | Exact amount; the measure is read from the settlement, and re-running reproduces it |

---

### XXX.4.15 Group M — loans, advances, recoveries, the net floor *(10 scenarios)*

The recovery order harvested from `PayrollGenController.php:1351-1382` is a real customer
requirement; the code implementing it is a liability (`M-…` §9).

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-114 | Net ₹20,000 available; loan EMI ₹15,000, advance ₹10,000, clawback ₹5,000 all due | recovery order `loan → advance → clawback`; loan `whole_instalments_only`; advance and clawback `partial_allowed` | Loan takes ₹15,000; advance takes ₹5,000; clawback nothing | Each recovery amount; net **₹0.00**; the advance shortfall **₹5,000** and the clawback **₹5,000** both remain open on their rows with a recorded reason |
| PR-115 | Same, but the recovery order is reconfigured to `clawback → advance → loan` | order is configuration | Different, declared split | Clawback ₹5,000; advance ₹10,000; loan ₹5,000 — and because the loan policy is `whole_instalments_only`, the loan takes **₹0** and ₹5,000 remains unrecovered. Both variants asserted |
| PR-116 | Loan EMI does not fit under each of four shortfall policies (`skip`, `partial`, `carry_forward`, `extend_tenure`) | policy per loan | Four declared outcomes | Four exact ledgers; `installments_paid` never exceeds `installments_total`; outstanding is maintained by the system, never by the caller |
| PR-117 | Recovery stamping | atomic side effects | Stamped exactly once | One `loan_recovery` ledger row; `installments_paid` advanced by exactly 1; the clawback stamped with the run version; **and the advance stamped too**. ⚠ *(SmartPRS 2 never stamps an advance — no `recovered_run_id`, no `recovered_at` — so an advance approved after a run is never recovered by any run and the two views can never be reconciled: `PayrollGenController.php:869-885`, `M-…` §8 D-15)* |
| PR-118 ⚠ | Regeneration after PR-117 | supersession | Every side effect reversed exactly once | Commission lock cleared and its debit removed; OT returned to `approved` with the paid stamp cleared; the loan counter decremented **exactly once** and a closed loan reopened; the clawback and advance stamps cleared. ⚠ *(Under two concurrent regenerates SmartPRS 2 decrements twice and reopens a correctly closed loan — `M-…` C-3)* |
| PR-119 | Regeneration a second time, immediately | idempotency | No further reversal | Ledgers identical to after PR-118; the operation is a no-op with a named result |
| PR-120 | An employee with more than 50 open clawbacks | no silent caps | All are considered | The recovery considers every open row; a truncating limit fails this row. ⚠ *(SmartPRS 2 carries a silent `->limit(50)` — `:903-930`)* |
| PR-121 | Deductions and recoveries exceed gross | minimum-net floor configured at ₹0.00 | Net is floored, and the shortfall is **visible** | Net **₹0.00**; a payslip note naming the unrecovered amount; the shortfall carried per policy. The floor is configuration: with a floor of ₹5,000 the recoveries stop at ₹5,000 net and the remainder carries. ⚠ *(SmartPRS 2 hides the shortfall inside `round(max(0, …), 2)` with no note and no carry — `AppDataController.php:2130`)* |
| PR-122 | The floor configured to permit a negative net | `allow_negative_net = true` | Negative net produced | The exact negative figure, and a blocking preview exception unless the exception is explicitly waived by a permitted role, audited |
| PR-123 | A loan disbursed and its first EMI due in the same period | schedule start rule | Declared outcome | Exact EMI or exact zero per the rule; the schedule is generated once and is idempotent |

---

### XXX.4.16 Group N — arrears, retro, mid-period change *(7 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-124 | Increment effective 01-Jun approved in July; June is locked | arrears policy | Arrear settled in July | Difference computed against the **pinned** June run, not a recomputation; the exact arrear; both version sets in the trace |
| PR-125 | A back-dated **decrease** | same | A recovery instruction, not silence | The exact recovery amount and a recovery line, never a suppressed negative |
| PR-126 | Retro spanning three locked periods with a statutory pack change in the middle | pack effective dating | Each month's difference uses **its own** pack version | Three exact differences; three pack version ids; the sum equals the arrear line |
| PR-127 | Salary structure change effective on day 12 of a 30-day period | mid-period structure windows | Two windows, each prorated | Window 1 (11 days) and window 2 (19 days) figures; `SUM(windows) = full-period entitlement` **exactly** (an `ALLOCATE` case) |
| PR-128 | Arrears-only run for a period with no regular payroll | run type `arrears_only` | A run containing only arrear lines | The run's reconciliation holds; no proration is applied to arrear lines; statutory treatment per each arrear's declared bases |
| PR-129 | Arrear crossing a financial year | tax handling of prior-year arrears | Declared treatment | The exact tax effect and the year attribution; a relief computation if the pack declares one, with each step traced |
| PR-130 | An increment approved twice (workflow replay attempt) | decided-step immutability | Applied once | Exactly one arrear; the second approval refused; no second letter emitted *(cross-ref `WC-01`)* |

---

### XXX.4.17 Group O — cross-state, transfers, multiple employments *(6 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-131 | `E1` (TG) and `E2` (MH) in one run | state-scoped statutory resolution | Each resolves its own state's values | TG PT ₹200 and MH PT ₹200-or-exempt per PR-081; neither leaks; state matched by **code** |
| PR-132 | An employee transferred from TG to MH on day 12 | effective-dated org assignment; transfer statutory policy `split` | Two statutory windows | PT computed per the declared policy (whole-period state of record, or split); the exact figure for each declared policy; the assignment version in the trace |
| PR-133 | The same transfer with PF establishment change | establishment-scoped PF | Two establishment attributions | Each establishment's ECR-facing figures; the sum equals the employee's total |
| PR-134 | `E6` with two concurrent employments | multi-employment policy | Two payslips, or one combined per policy | Under `separate`: two payslips, each with its own statutory computation and its own trace. Under `combined`: one, with the declared aggregation and the ceilings applied once. Both asserted |
| PR-135 | An employee whose state has no rule pack loaded | missing-pack handling | Blocking exception | `statutory_pack_missing` naming the jurisdiction; the run cannot advance. **Never a silent zero** |
| PR-136 | A run spanning two companies in different states with different cycles | per-company run scoping | Two runs, not one | Each run's period resolved from its own company's cycle; a single run spanning both is refused |

---

### XXX.4.18 Group P — money exactness, rounding, allocation *(6 scenarios, Law 7)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-137 | `E1` prorated 22/24 across `STR-A` | `SPECIAL` declared as the balance component | Parts sum to the whole | BASIC ₹22,916.67 + HRA ₹9,166.67 + SPECIAL **₹13,749.99** = ₹45,833.33. Independent rounding gives ₹45,833.34 and must fail. *(This generalises the one correct money split in SmartPRS 2 — spine S19.4)* |
| PR-138 | A three-way split of ₹100.00 into equal thirds | `ALLOCATE` | Residue lands deterministically | ₹33.33 + ₹33.33 + ₹33.34 = ₹100.00; the same input always yields the same placement |
| PR-139 | Every component's declared rounding mode at `.005` | six modes | Six declared results | Each mode's exact output; a component with no declared mode fails at publish |
| PR-140 | A payslip regenerated on a machine with a different locale and float precision | integer paise throughout | Identical output | Byte-identical lines; a static-analysis gate asserts no float arithmetic in `Domain\Money` or `Domain\Payroll` |
| PR-141 | 500-employee run, sum of all lines against run totals | reconciliation | Exact equality | `SUM(payslip lines) = run totals` to the paise; a deliberate ₹0.01 perturbation aborts the run |
| PR-142 | Currency mismatch: a component in a second currency | currency carried explicitly | Type error | The run fails with a named error; no implicit conversion exists |

---

### XXX.4.19 Group Q — run mechanics: lots, off-cycle, regeneration, restart *(10 scenarios)*

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| PR-143 | Two lots for one company-period (staff and contractors) | multi-lot runs | Two independent runs | Each lot's population disjoint; each reconciles independently; the period totals equal the sum of lots |
| PR-144 | An off-cycle run for a single employee | run type `off_cycle` | A run of one | Statutory treatment per the off-cycle policy; the main run for the period is unaffected; both appear in the period's register |
| PR-145 | A supplementary run after the main run is approved | run type `supplementary` | Additive, not replacing | The main run is untouched; the employee's period total is the sum; the payslip shows both |
| PR-146 | Regeneration of a **draft** run | supersession, never deletion | A new run version | The prior version is retained in full and remains queryable — payslips, line statuses, approval stamps and rejection remarks. ⚠ *(SmartPRS 2 hard-deletes payslips and the run header outside any transaction — `:2389-2390` — destroying the wage register before the replacement exists)* |
| PR-147 | Regeneration of an **approved** run | lock policy | Refused | Named error; the statuses that lock a run are **configuration**, not a literal array in code |
| PR-148 | A regeneration that fails midway (fault injected after supersession begins) | transactional supersession | The prior run is intact | The prior version and its payslips still exist and are still current; no period is left with no payslips |
| PR-149 | Worker killed mid-pipeline at stage 17 on shard 3, then restarted | restartable staged pipeline | Identical result | Byte-identical to an uninterrupted run; no duplicate side effect |
| PR-150 | The run's side effects (commission lock, OT stamp, loan ledger, clawback and advance stamps) | one transaction | Commit or roll back together | Fault injection at each side effect leaves **no** payslip committed. ⚠ *(SmartPRS 2 applies all of them after `DB::commit()` in swallow-all catches, so a payslip can claim an EMI deduction the ledger never records — `:2691, :2695-2832`)* |
| PR-151 | Two concurrent generations for the same key | unique constraint | One succeeds | Exactly one run; the other receives a named conflict, not a duplicate; and the same operation replayed with the same idempotency key returns the original result |
| PR-152 | Run duration and progress at 10,000 employments | queued, observable job | Within the §XXX.9.2 budget | Elapsed time, per-stage timings, per-employee progress observable while running, and `O(1)` query count per stage asserted by the query-count budget |

---

### XXX.4.20 Group R — the reconciliation invariants *(8 scenarios)*

These are not scenarios so much as **assertions that run on every payroll test in this
matrix**. A failure aborts the run in production and fails the build in CI. SmartPRS 2 has no
such check anywhere (spine S11).

| ID | Invariant | Must assert exactly |
|---|---|---|
| PR-153 | `SUM(payslip earning lines) − SUM(payslip deduction lines) = payslip net`, per payslip | Exact, to the paise, for 100 % of payslips |
| PR-154 | `SUM(payslips.gross) = run.gross_total`, and likewise for every declared total | Exact |
| PR-155 | `SUM(component lines) = SUM(structure entitlement × proration)` after `ALLOCATE` | Exact; PR-137 is the smallest failing case |
| PR-156 | `payable_days + LOP_days + leave_days + off_days + holiday_days = period_days`, per employee | Exact, for 100 % of employee-days |
| PR-157 | Every statutory line's base equals the sum of components flagged for that base | Exact; and no statutory line was produced by a name match |
| PR-158 | Every recovery line has a corresponding ledger posting inside the same transaction, and every posting has a line | Bijection; a orphan on either side fails the run |
| PR-159 | Every derived line carries a trace naming its formula/rule versions; a trace-less line throws | 100 % coverage of lines, asserted by the harness of gate G-4 |
| PR-160 | Re-running from the pinned snapshot reproduces every figure and every trace | Byte-identical; the changed-input diff is empty |

**R-XXX-022** The invariants of Group R execute as post-conditions of **every** payroll run in
production, not only under test. A violation aborts the run with an operator-visible
reconciliation report. *(SmartPRS 2 has no reconciliation assertion; a doubled run doubles
`net_total` in every register and the bank pack pays twice — `M-…` C-1.)*

**R-XXX-023** Every scenario in §XXX.4 exists as an executable golden case in the registry of
§XXX.12, is owned by a named module, and runs in the per-commit lane.

**R-XXX-024** No payroll golden case may be satisfied by `computeSlip`-style helper arithmetic;
each enters through the run pipeline's public contract and asserts the **persisted** payslip.
*(SmartPRS 2's only monetary assertions are against a static helper, not against `generate()`
— `M-…` §4.1.)*

---

## XXX.5 The attendance golden-test matrix *(brief Section 113)*

The brief's list is the floor. Part XI's `G-01`…`G-48` are the module-owned scenarios; the
matrix below is the **federated** set this part gates, extended with the additions the analysis
requires. Where a row restates a Part XI scenario it cites it, and the two must not diverge:
the registry rejects a duplicate expectation under two IDs (R-XXX-002).

The device fixture: `DEV-1` and `DEV-2` are readers in `CO-TG`; `DEV-3` belongs to `CO-MH`;
all three declare a time policy explicitly. `S-DAY` = 09:30–18:30 with 15-minute grace;
`S-NIGHT` = 22:00–06:00; `S-SPLIT` = 09:00–13:00 and 17:00–21:00.

### XXX.5.1 Device and direction derivation

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| AT-001 | One device, alternating punches: 09:28, 13:02, 13:31, 18:34 | single-device alternation | IN/OUT/IN/OUT | Directions in order; worked 530m; break 30m — and the identical figures in the report, ESS, the payroll input and the API *(Part XI `G-06`)* |
| AT-002 | Two devices, `DEV-1` = IN, `DEV-2` = OUT | device-role direction | Directions from device role | Directions independent of ordering; a punch on the wrong device is still attributed by role |
| AT-003 | Missing OUT (an unclosed IN at 09:28) | open-session policy | Deterministic, non-zero | One deterministic break/worked total across **every** consumer; a named exception raised; the day does not compute as zero hours and does not corrupt the month *(Part XI `G-07`)* |
| AT-004 | Missing IN (first event of the day is an OUT) | open-session policy | Deterministic | Declared outcome; the day is flagged, not silently absent |
| AT-005 | Duplicate punch: identical payload twice | natural key `(tenant, employee, instant, source)` | Exactly one stored | One row; the second returns verdict `duplicate`; counts reconcile |
| AT-006 ⚠ | The same punch delivered twice **simultaneously** | unique index + transaction | Exactly one row | One row, no exception surfaced to the device. ⚠ *(SmartPRS 2's ingest tests replay only sequentially — `M-…` §6.1 Rank 1 note, C-10 shape)* |
| AT-007 | Two genuinely distinct punches in the same second | dedupe = quarantine | Both retained | Both rows exist; one is **quarantined**, nothing is deleted; both are individually retrievable *(Part XI `G-30`)*. ⚠ *(SmartPRS 2's dedupe deletes, in application code and in two migrations that run on every deploy)* |
| AT-008 | Double-tap within the configured collapse window, and one second outside it | `agg.collapse_repeats` | Collapsed / not collapsed | Two exact outcomes at window and window+1s; the collapsed events remain retrievable |
| AT-009 ⚠ | Single-device alternation: two punches for the same employee-day delivered **simultaneously** | per-employee-per-day derivation as a pure function of the stored set | Correct alternation | The stored sequence is a correct alternation under concurrency. ⚠ *(The C-16 read-compute-write race: `ETimeOfficeService::sequenceDirections():620-680` writes a stale sequence over a concurrent one, turning an IN into an OUT and producing a zero-length or 14-hour shift — undocumented anywhere before this analysis)* |
| AT-010 | Device user id `5` on `DEV-1` and on `DEV-2` mapped to different employments | candidate-major identity strategy | Each resolves correctly | Two different employees; a PIN collision never merges attendance *(Part XI `G-21`)* |
| AT-011 | Device user remap: PIN `7` reassigned to a new employee on 01-Aug | effective-dated mapping | Punches attribute by date | Punches before 01-Aug to the old employment, after to the new; a re-run of July is unchanged |
| AT-012 | Two employees with the **same name**, different codes | identity by code, never by name | No merge | Correct attribution; a CI check fails the build on any name-based join in the domain layer *(Part XI `G-46`)* |
| AT-013 | Unknown device serial | unknown-device policy `quarantine` | Held, not dropped | No device activated; punches quarantined; the console shows a claimable device; **the count of held punches is reportable** |
| AT-014 | Punch body claiming a different tenant than the authenticated credential | tenant from credential | Credential wins | Body ignored; the assertion is explicit, because this is the one property SmartPRS 2 got right and must not lose *(Part XI `G-27`)* |
| AT-015 | Offline retry: a device buffers 4 hours and replays 900 punches | idempotency + batch verdicts | All accepted once | Exactly 900 rows; per-punch verdicts returned; `received = inserted + updated + duplicate + quarantined + rejected` |

### XXX.5.2 The attendance date, shifts and timezone

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| AT-016 | `S-NIGHT` 22:00–06:00; IN 21:58, OUT 06:04 | attendance-date rule `shift_start` | **One** day | One attendance day; worked 486m; present 1.0; late 0m; early exit 0m *(Part XI `G-01`)* |
| AT-017 | Same shift, IN 00:15 | late from the **shift-start instant** | Late 135m | Exactly 135m, not 0. ⚠ *(Minutes-since-midnight arithmetic gives 0 and is the SmartPRS 2 behaviour)* |
| AT-018 | The attendance-date rule under **every** shift type: `S-DAY`, `S-NIGHT`, `S-SPLIT`, a 24-hour shift, a shift starting at 00:00, a shift ending at 23:59 | rule `shift_start` / `punch_date` / `majority_hours` | Declared date per rule per shift | For each of the 18 (shift × rule) combinations, the exact attendance date of every punch; the rule is configuration and changing it changes nothing else |
| AT-019 | 30-Sep 22:00 → 01-Oct 06:00 on a monthly cycle | attendance date | All hours in September | September's payable days include the day; October's are unchanged *(Part XI `G-03`)* |
| AT-020 | Timezone: a punch string `2026-08-16 09:41:00` on a `naive_local` adapter | time policy declared | Stored as 09:41 local | Stored wall-clock 09:41 with the zone recorded; **never** shifted to UTC and back |
| AT-021 | The same string bearing `+00:00` on a `naive_local` adapter | policy conformance | **Rejected** | Verdict `TIME_FORMAT`; no row in layer 2. The timezone assumption is never guessed *(Part XI `G-17`)* |
| AT-022 | The same string on an `offset_authoritative` adapter, tenant IST | policy conformance | Converted | Stored 15:11 IST *(Part XI `G-18`)* |
| AT-023 | A feed emitting `03/04/2026` with a declared `m/d/Y` format | adapter data | 4 March | Not 3 April; the format is adapter configuration, and four different parsers cannot exist. ⚠ *(SmartPRS 2 has four punch-date parsers with three different rules — DUP-2)* |
| AT-024 | A DST-shifting zone, if any tenant declares one | zone handling | Deterministic | The declared behaviour at the transition instant, asserted on both sides |
| AT-025 | Roster shift resolution: roster → employee default → company default | precedence | The correct shift per employee per day | The resolved shift id per day, and the trace naming the level it came from |
| AT-026 | Rotation pattern 4-on/2-off, 3 crews, 18-day cycle, phases 0/6/12 | roster generator | Deterministic | Regenerating the same range produces byte-identical roster days *(Part XI `G-39`)* |
| AT-027 | Roster shift changes mid-period | effective-dated roster | Both shifts honoured | Each day resolves its own shift; the settlement reflects both |

### XXX.5.3 Statuses, penalties and calendars

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| AT-028 | Weekly off with no punches | weekly-off pattern | Status `WEEKLY_OFF` | Not an absence; no notification; the divisor is unaffected |
| AT-029 | 2nd and 4th Saturday pattern in a 5-Saturday month | `NTH_WEEKDAY`, not `ceil(day/7)` | Correct actual weekdays | Exact dates; at least one month where the two algorithms disagree is included and the correct answer asserted *(Part XI `G-40`, Part XII P0 #1)* |
| AT-030 | Public holiday with no punches | holiday calendar | Status `HOLIDAY` | Zero absences, zero notifications |
| AT-031 | A holiday scoped to one branch | `applies_to` scope | Scoped correctly | The scoped employee is on holiday; a colleague in another state is working; neither is absent *(Part XI `G-15`)* |
| AT-032 ⚠ | The holiday lookup **fails** (fault injected) | fail-closed | Nobody is marked absent | Zero absences recorded and **zero** e-mails sent; a named operator incident raised. ⚠ *(SmartPRS 2 flags the entire workforce absent on a public holiday and then e-mails every one of them)* |
| AT-033 | Late arrival 09:41 with 15m grace, and 10:05 | grace and bands | 0m late, then 20m late | Both exact; a late of 8m past grace with bands starting at 10m yields penalty **0** — no fall-through to the first band *(Part XI `G-04`, `G-05`, `G-11`)* |
| AT-034 | **Night-shift lateness**: `S-NIGHT`, IN at 22:20 and at 00:15 | shift-start-anchored arithmetic | 20m and 135m | Both exact; the same rule and the same grace produce correct results on a shift crossing midnight |
| AT-035 | Early exit 30m before shift end, on `S-DAY` and on `S-NIGHT` | early-exit rule | Exact minutes on both | 30m on both; the night case is the one that fails under midnight arithmetic |
| AT-036 | Break budget 60m, actual break 155m, block 30m, step 0.25d, ceil | break penalty rule | Penalty exactly 1.0 day | Exact *(Part XI `G-08`)* |
| AT-037 | OT minutes beyond the shift, with and without approval | OT rule + approval gate | Created / not created | Exact minutes; unapproved OT is visible, not silently dropped |
| AT-038 | Zero-punch working day, no leave, not holiday, not off | absence derivation | Status `ABSENT` | The row exists; the notification is deduplicated to exactly one *(Part XI `G-14`)* |
| AT-039 | The same employee-day queried through the attendance report, the payroll input, the absence sweep, the ESS "today" endpoint and the public API | **one** derivation | All five agree | Identical status, identical minutes, identical `pattern_version_id`. ⚠ *(SmartPRS 2 derives "present" four different ways in four places and they disagree — DUP-3)* |

### XXX.5.4 Quarantine, corrections and recalculation

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| AT-040 | Unmapped device PIN | quarantine | Held | Never dropped; visible and counted; the same PIN on a different serial is **not** promoted *(Part XI `G-29`)* |
| AT-041 | Mapping the identity releases held punches | release pipeline | Promoted exactly once | Verdicts identical to live ingest; a second promotion is a no-op; days recalculated |
| AT-042 | Dedupe as quarantine, at scale | never delete | Nothing deleted | A run over 1,000,000 fixture punches removes zero rows; the quarantine count is exact and reportable. ⚠ *(SmartPRS 2's dedupe loads the whole table into two PHP arrays, OOMs mid-loop having already deleted an arbitrary prefix, and runs cross-tenant on every deploy — `M-…` PERF-6, D-1)* |
| AT-043 | Recalculate the same range twice | idempotent command | Zero diffs | Second run produces no new generation and an empty diff *(Part XI `G-37`)* |
| AT-044 | Recalculation **diff preview** before applying | preview | Diff matches the applied result | The previewed changes and the applied changes are identical, row for row |
| AT-045 | Policy V2 published in October effective 01-Oct; recalculate August | S4 | August uses V1 | The applied rule versions are the August ones *(Part XI `G-36`)*; this is the structural form of the brief's Section 6 requirement |
| AT-046 | Correction overlay on an **open** period | overlay, not edit | Overlay written | Layer 2 unchanged; the day regenerated; the settlement refreshed; both the raw and the corrected views renderable *(Part XI `G-31`)* |
| AT-047 | Correction landing on a **locked** period | lock + arrears | Arrears item created | An arrears item with owner, target period and delta; visible in the queue; consumed by the next payroll preview *(Part XI `G-32`)*; and the employee is told the actual outcome |
| AT-048 | Lock check on a 21st–20th cut-off, correction dated the 25th | period lock | Tested against the **correct** run | The correct run is identified; the check is portable across drivers; an unknown status fails **closed**. ⚠ *(SmartPRS 2's lock uses `DATE_FORMAT`, throws on any other driver, and the catch returns "open" — `PayrollLock.php:64, :71-73`)* |
| AT-049 | Correction expressing an OUT on the following day | cross-day correction | Both days recalculated | Both days' figures exact |
| AT-050 | An approved correction plus a later erroneous device OUT on the same day | overlay supersedes | Correct sessions | The overlay wins; both views renderable; nothing silently deleted |
| AT-051 | An employee with two employments punching on the same device | multi-employment | Two independent day series | No cross-contamination *(Part XI `G-48`)* |
| AT-052 | Attendance ingestion at 10,000 employees × 4 punches × 30 days | throughput budget | Within §XXX.9.2 | Rate, memory ceiling, and `O(1)` queries per batch |
| AT-053 | A batch of 1 good, 1 blank-PIN, 1 unparsable-date, 1 unknown-device line | per-line verdicts | 4 layer-1 rows, 4 verdicts | `accepted`, `rejected`, `rejected`, `quarantined_unknown_device`; counts sum to 4; **nothing silently dropped** *(Part XI `G-25`)* |
| AT-054 | The same punch arriving via a device pull and via the bridge | cross-source dedupe | One accepted, one duplicate | Provenance of both retained *(Part XI `G-24`)* |
| AT-055 | `Explain` on any derived day | Law 4 | Full trace | Shift and how it was resolved, scheduled vs actual, computed minutes, the rule and version, the condition matched, the outcome, any override, and the payroll impact — the S15 shape exactly; a day without a trace throws |

**R-XXX-025** Every attendance scenario asserts the derived **day**, not the punch. A test that
asserts an ingest response without asserting the resulting attendance day does not satisfy a
row in this matrix.

**R-XXX-026** Every ingest path — every device adapter, every connector recipe kind, the
bridge, and the manual upload — runs the **identical** adapter conformance suite (Part XI
§XI.21.2). A new adapter is not shippable until it passes unmodified.

---

## XXX.6 The rule-engine golden tests *(brief Section 114)*

§XXX.3.2 proves the engine's mechanics. This section proves **precedence over the real
organisational ladder** with real artefacts, which is where a customer's configuration actually
lives. The fixture ladder is the spine S3 chain, and the weights are the S3.2 table.

The fixture: employee `E1` in tenant `GOLD-IN` → company `CO-TG` → state TG → branch `BR-HYD`
→ department `DEP-COLL` → designation `DSG-AGENT`. Six candidate versions of one artefact
(`LATE-POLICY`) exist, one scoped at each level, all in force on the evaluation date.

| ID | Setup | Configuration in force | Expected outcome | Must assert exactly |
|---|---|---|---|---|
| RE-001 | All six candidates present | S3.2 weights | Employee-scoped wins | The winning version id is the **employee**-scoped one; the trace lists all six candidates with their computed specificity sums, in order |
| RE-002 | Remove the employee-scoped version | as above | Department wins (400) | Winner id and the specificity sum **400**; the trace shows designation(350) losing to department(400). The row exists because the intuitive ordering is the opposite one, and the engine must follow the declared weight table, not intuition |
| RE-003 | Remove department | as above | Designation wins (350) | Winner id and the specificity sum 350 |
| RE-004 | Remove designation | as above | Branch wins (180) | Winner id and sum 180 |
| RE-005 | Remove branch | as above | State wins (140) | Winner id and sum 140 |
| RE-006 | Remove state | as above | Company wins (100) | Winner id and sum 100 |
| RE-007 | Remove company | as above | Tenant default (0) | Winner id; an empty scope is the tenant default and always matches |
| RE-008 | Remove everything | no candidate | Named failure | `no_policy_resolved` naming artefact and employee; **never** a silent built-in default. ⚠ *(SmartPRS 2 silently falls back to a hard-coded 09:30–18:30 shift when a master row is deleted — `MasterController.php:1198-1204`)* |
| RE-009 | Two candidates constrained on `department + designation` versus `team` alone | 400+350 = 750 vs 500 | The multi-dimension scope wins | Winner id and both sums; asserts specificity is **summed**, not lexicographic |
| RE-010 | Two candidates with identical specificity, different declared `priority` | priority tie-break | Higher priority wins | Winner id; the trace records that specificity tied and priority decided |
| RE-011 | Two candidates with identical specificity **and** identical priority whose conditions can both be true | ambiguity is a configuration error | **Publication blocked** | Publication refused with a message naming both artefacts **and the count of affected employees**; nothing is published; the employee list is downloadable |
| RE-012 | The same pair where the conditions are provably disjoint | conflict detector soundness | Publication succeeds | No false positive; the detector's reasoning appears in the publish record |
| RE-013 | Effective-date resolution: a version published 10-Sep effective 01-Aug, another effective 01-Sep; evaluate 15-Aug | S4 | The 01-Aug version | Winner id; and evaluating 15-Sep picks the other. This is the mechanism that makes "August payroll keeps using Late Policy V1" structural rather than disciplinary |
| RE-014 | A version whose effective range is closed before the evaluation date | S4 | Not a candidate | Excluded from the candidate list, and the trace says why |
| RE-015 | A gap in a `continuous` artefact | S4 continuity | Rejected at publish | The gap dates named |
| RE-016 | An overlap within one artefact and scope | S4 | Rejected at publish | The overlapping ranges named |
| RE-017 | The employee's **branch changes** on 15-Aug; evaluate 14-Aug and 16-Aug | effective-dated assignment | Two different winners | Both winner ids; the assignment version in each trace. *(This is the question SmartPRS 2 cannot answer at all)* |
| RE-018 | Aggregation window `payroll_period` versus `calendar_month` on a `CYC-2625` employee | window semantics | Different qualifying sets | The exact qualifying dates under each window; a late on 24-Jul counts in the July payroll period but a late on 26-Jul does not |
| RE-019 | `rolling(30, days)` with lates on 30-Sep, 01-Oct, 02-Oct | window semantics | Matched | Matched under rolling, **not** matched under `calendar_month` *(Part XI `G-10`)* |
| RE-020 | `week(start_day = Monday)` versus `week(start_day = Sunday)` | window parameter | Different sets | Exact qualifying sets for both |
| RE-021 | `service_year` for `E1` (joined 01-Apr-2024) | window semantics | 01-Apr→31-Mar | Exact boundaries, distinct from the calendar year and the financial year |
| RE-022 | `since(date)` and `financial_year` at their boundaries | window semantics | Exact | Both boundaries asserted |
| RE-023 | Occurrence-based penalty: the 3rd qualifying late, with a per-period cap of 2 penalties | `agg.occurrence_index` + cap | Cap respected | The 3rd occurrence is indexed 3 but not penalised; the cap and the index both appear in the trace |
| RE-024 | The brief's Section 127 standard case: **"3 late arrivals over 10 minutes = half day"** | rule builder, no code | Configured and working | The rule is created through the visual builder, published, and produces a 0.5-day penalty on the 3rd qualifying late — with **zero** code change and zero deployment. This row is the acceptance test for the entire configuration thesis |
| RE-025 | The same rule changed to "4 arrivals over 15 minutes = full day" | reconfiguration | New behaviour | New outcome, new version, old periods unchanged |
| RE-026 | Rule simulator versus the live path for 200 random employee-days | simulator fidelity | Identical | Byte-identical actions and traces |
| RE-027 | Impact preview versus the applied result over 500 employees | preview fidelity | Zero difference | The previewed changed count equals the actual |
| RE-028 | A rule referencing a namespace not available in its evaluation context | symbol table | Publish-time error | Named namespace and context |
| RE-029 | Fault injection inside scope resolution during evaluation | fail-closed | The rule does not apply | Operator-visible failure; no partial action set; no default-allow |
| RE-030 | Precedence weights are **not** tenant-editable | platform constant table | Refused | An attempt to change a weight through any tenant-facing surface is refused; changing it through the platform surface requires a release and invalidates no historical resolution because every resolution stored its winner |

**R-XXX-027** Every published rule carries at least one stored regression case, and publication
runs them (spine S6). A rule with no case cannot be published in a Class A or Class B module.

**R-XXX-028** `RE-024` is a **release-blocking acceptance test**. If it cannot be performed by
a configuration administrator without a developer, the release does not ship.

---

## XXX.7 The security test suite *(brief Section 115)*

Every item below is an **automated test that runs in CI and blocks the release**. None is a
manual checklist item, a penetration-test finding to be triaged later, or a code-review
convention. Part XXII owns the security model; this section owns its proof.

**R-XXX-029** The security suite is a blocking lane. A security test may not be skipped,
quarantined or marked flaky. A flaky security test is a failing security test.

### XXX.7.1 Fail-closed fault injection *(Law 5)*

The single most consequential defect class in SmartPRS 2: `ApprovalService::denyUnlessRole()`
ends `catch (\Throwable $e) { return null; // fail open }` and **71 call sites** read `null` as
"allowed" (`ApprovalService.php:365-380`). Six further fail-open catches exist across
`SubscriptionService::seatUsage():116-118`, `PayrollLock::isPeriodFinalised():71-73`,
`LicenseGate`, `EditionGuard`, `EnsureSubscriptionActive` and `AuthController`.

**R-XXX-030** For **every** guard in the product there exists a test that injects a fault into
each of its dependencies and asserts **denial**. The set of guards is enumerated from the code
by a generator, so a new guard without a fault-injection test fails the build.

| ID | Fault injected | Must assert |
|---|---|---|
| SEC-T-001 ⚠ | The role/permission lookup throws | **403**, no state change, an operator-visible incident. ⚠ *(SmartPRS 2 grants the request at 71 sites)* |
| SEC-T-002 | The data-scope resolution throws or returns null | Denied. A null scope is never "all" |
| SEC-T-003 | The policy registry cannot be loaded | Denied globally; the application refuses to serve rather than serving unguarded |
| SEC-T-004 ⚠ | The seat/licence count throws | **Denied** (fail closed). ⚠ *(SmartPRS 2 grants unlimited seats)* |
| SEC-T-005 ⚠ | The period-lock check throws | Period treated as **locked**. ⚠ *(SmartPRS 2 treats it as open, and under SQLite the query always throws — so the guard is untestable and always open under test)* |
| SEC-T-006 | The subscription/entitlement check throws deterministically (malformed entitlement, bad signature) | Denied |
| SEC-T-007 | The same check fails **transiently** (database timeout) | Degraded per the declared transient policy, the event counted and alerted — the distinction between deterministic and transient failure is itself asserted |
| SEC-T-008 | The audit writer throws | The business transaction **fails**; nothing is committed |
| SEC-T-009 | The tenant resolver returns null | Denied; there is no null-tenant state (Law 6) |
| SEC-T-010 | The rule engine raises an evaluation failure inside a permitting rule | The permitting action does not apply; the operator sees the failure |

### XXX.7.2 The generated authorisation matrix *(Law 5)*

**R-XXX-031** The matrix is **generated**, not written: the generator enumerates every route
from the router, every role from the role seeder, and every data scope, and emits a case per
cell. A route with no declared expectation fails the build. This is the countermeasure to
SmartPRS 2's position — **18 of 422 routes exercised, two authorisation assertions in the whole
suite**, neither of which touches the fail-open guard (`M-…` §1.1, §6.1 Rank 3).

| ID | Case | Must assert |
|---|---|---|
| SEC-T-011 | Every route has an explicit policy binding | A route without one fails the build (gate G-5) |
| SEC-T-012 | Every (route × role × scope) cell | The declared allow/deny, the exact status code, **and the write set** — on a deny, zero rows written anywhere |
| SEC-T-013 | An unknown role | Denied |
| SEC-T-014 | A role with an empty permission set | Denied everywhere |
| SEC-T-015 | A permission granted, a scope that excludes the record | Denied — permission and scope are orthogonal and both must pass |
| SEC-T-016 | Permission closure over `implies` | The materialised closure is exactly the declared set; count and members asserted |
| SEC-T-017 | An assignment at its effective boundary | Authorised at 23:59:59 on `effective_to`, denied at 00:00:00 the next day, in the tenant's timezone |
| SEC-T-018 ⚠ | **Segregation of duties on a payroll run.** One user holding one role attempts prepare, approve and disburse | The steps that a single configured role may satisfy are **configuration**; the default configuration permits **no** single role to satisfy both approve and disburse; the test asserts the default and asserts that a customer who deliberately relaxes it records an audited exception. ⚠ *(In SmartPRS 2 `admin` — the default workspace-owner role — satisfies both `isHR()` and `isFinance()`, so one person can prepare, approve and disburse alone: `SalaryApprovalController.php:59-68`)* |
| SEC-T-019 | The UI hides a control the server permits, and shows one the server denies | The server's answer governs in both directions; hiding is never a control |
| SEC-T-020 | A route added without a policy in a feature branch | The build fails before merge |

### XXX.7.3 The generated cross-tenant isolation suite *(Law 6)*

**R-XXX-032** For **every aggregate** and **every API endpoint**, a case is generated that
creates two tenants with deliberately colliding natural keys (identical `emp_code`, identical
device serial, identical invoice series position, identical document filename) and asserts
zero leakage in both directions. An aggregate or endpoint with no generated case fails the
build (gate G-6).

| ID | Case | Must assert |
|---|---|---|
| SEC-T-021 | Every list endpoint | Zero rows belonging to the other tenant |
| SEC-T-022 | Every detail endpoint by public id | **404**, not 403 — no existence oracle |
| SEC-T-023 | Every report, export and aggregate | Zero cross-tenant rows and zero cross-tenant contribution to a total |
| SEC-T-024 | Every document and file stream | Denied; the storage path itself is unguessable and unreachable |
| SEC-T-025 | Every write endpoint | Cannot create, update or delete another tenant's row under any parameter |
| SEC-T-026 ⚠ | Destructive endpoints by id | Deleting another tenant's row is impossible. ⚠ *(`KbController.php:112` and `TestController.php:192` delete by id with only a role check — a cross-tenant destructive IDOR)* |
| SEC-T-027 ⚠ | Every upsert's match key | The tenant participates in the match key **and** in the unique index. ⚠ *(`AttendanceReportController.php:501-511` upserts on `(emp_code, log_date)` and the index omits the tenant, so one tenant silently overwrites another's rating)* |
| SEC-T-028 ⚠ | An actor with a null tenant (platform operator, self-hosted install) performing a tenant-scoped operation | Scoped or refused — **never** global. ⚠ *(SmartPRS 2's `when($tid, …)` pattern disables tenant scoping entirely whenever `$tid` is null, at dozens of sites)* |
| SEC-T-029 | The persistence layer applies the tenant filter | Deleting the filter from any single query **breaks a test**; it does not silently widen a result set. The filter is applied by the layer, never hand-written |
| SEC-T-030 | Every unique index | `tenant_id` participates; a generated schema test enumerates them |
| SEC-T-031 | A background job, a scheduled command and a queue worker | Each carries an explicit tenant; a destructive command without one is refused, and `--dry` reports a correct count while deleting nothing. ⚠ *(Three SmartPRS 2 commands default to **every tenant on the server**)* |
| SEC-T-032 ⚠ | Every **read** endpoint, executed against a fixture with a recorded row-count snapshot | The row counts of every table are unchanged after the read. ⚠ *(`GET /app/data` fabricates twelve months of `status='paid'` payroll runs and one payslip per employee, from the caller's role-scoped employee list, and the first page load of a month wins permanently — `AppDataController.php:2340-2381`, `:455`, `:268-273`)* |

### XXX.7.4 Self-service isolation

| ID | Case | Must assert |
|---|---|---|
| SEC-T-033 | A plain employee through every path including global search, reports, exports and the public API | Sees only their own record; another employee's payslip by id returns 404 |
| SEC-T-034 | A self-service transaction carrying another employee's identifier in the request | The subject is resolved from the session and the request parameter is ignored; there is no dropdown of other employees on a self-service transaction |
| SEC-T-035 | A manager whose reporting tree is `depth = 2` over a 6 → 19 → 4 fixture | Exactly 25 reachable, the 4 at level 3 excluded and named; `depth = NULL` gives exactly 29; `include_self` toggles 25 vs 26 *(Part XXII §XXII.17)* |

### XXX.7.5 Audit integrity *(spine S14)*

| ID | Case | Must assert |
|---|---|---|
| SEC-T-036 ⚠ | Write N audit rows from M tenants in parallel, then verify the chain | The chain verifies. ⚠ *(SmartPRS 2 reads a **global** chain head with no lock and no tenant filter, so ordinary multi-tenant concurrency forks the chain and `verify()` reports the log as tampered from that point — `Audit.php:90-96`)* |
| SEC-T-037 ⚠ | An audit write fails | The business transaction fails; the business row is not committed. ⚠ *(SmartPRS 2 swallows every audit exception, so a fork is indistinguishable from a dropped row — `Audit.php:97-99`)* |
| SEC-T-038 | A direct insert into the ledger from anywhere but the audit service | Impossible: refused by database privilege, asserted by a test that attempts it with the application's own credentials |
| SEC-T-039 | Field-level before/after for employee, salary, bank, role, statutory and rule changes | Present and exact for each; produced by the write service, never by call sites |

### XXX.7.6 Concurrency and idempotency as security properties

SmartPRS 2 contains **zero locks and four transactions** (`M-…` §6.2). Twenty-five races are
enumerated there; the suite must prove each is impossible. Every case below runs **in
parallel**, not sequentially — the sequential form proves nothing and is the exact
anti-pattern `BillingTest` #5 and #8 embody.

**R-XXX-033** Every operation in `M-…` §10.1 carries a parallel-request test. The harness runs
N concurrent requests against a real database with real constraints and asserts the invariant,
not the absence of an exception.

| ID | Race | Must assert |
|---|---|---|
| SEC-T-040 ⚠ | Employee-code issuance, 500 concurrent creations | 500 distinct codes from a per-tenant sequence; zero collisions; **zero silently absent employees**. ⚠ *(SmartPRS 2 draws from a 9,000-value random pool at three sites, one with no check at all; ~13 of 500 collide, each throws, the import swallows it, and those employees vanish from a "successful" import and are then marked absent every day because their punches never match — `EmployeeImportService.php:376`)* |
| SEC-T-041 | Two concurrent payroll generations for one key | Exactly one run *(PR-151)* |
| SEC-T-042 | Two concurrent regenerations | Exactly one reversal of each side effect *(PR-118)* |
| SEC-T-043 | Two concurrent seat additions at N−1 | One succeeds; the cap is a **constraint**, not a check; and the cap holds through every door — the add form, bulk import, self-onboarding and recruitment hire |
| SEC-T-044 | Ten concurrent invoice creations | Ten distinct consecutive numbers, zero duplicates, zero exceptions; numbers issued by the database from a per-tenant per-series sequence, never `MAX+1` in PHP; and uniqueness holds **across every table sharing the series**. ⚠ *(SmartPRS 2 shares a GST series across `invoices.number` and an unindexed `onprem_clients.invoice_no`)* |
| SEC-T-045 | A gateway webhook delivered twice simultaneously | Exactly one payment row; the invoice credited once; enforced by a unique index on `(gateway, gateway_txn_id)`, not by a check-then-insert |
| SEC-T-046 | A manual payment and a webhook for the same invoice, concurrently | The sum of payments never exceeds the invoice total; enforced by a constraint |
| SEC-T-047 | Two concurrent leave applications for the last remaining day | Exactly one succeeds; the balance is ledger-derived and the check and the consumption are one atomic operation |
| SEC-T-048 | Two concurrent approvals of the same request | Exactly one transition *(WC-02)* |
| SEC-T-049 | Two concurrent payout-file downloads | Two-phase: reserve under lock, deliver, confirm. Neither receives the same lines; a failed delivery leaves nothing marked disbursed. ⚠ *(SmartPRS 2 flips `approved → disbursed` before the response leaves the server, outside any transaction — so two concurrent downloads instruct the bank to pay twice)* |
| SEC-T-050 | Every mutating endpoint replayed with the same idempotency key | The original result is returned; no second write; double-clicking Generate, Approve, Pay or Download is a no-op |

### XXX.7.7 Secrets, cryptography, files and egress

| ID | Case | Must assert |
|---|---|---|
| SEC-T-051 | No default credential in any seeder, installer, fixture or release artefact, in any environment | A generated scan over the build artefact finds none; the installer cannot complete without an interactively created administrator |
| SEC-T-052 | Sensitive fields (national ID, PAN, bank account, IFSC, salary, tax, biometric configuration, credentials) | Encrypted at the application layer; masked by default on every surface in the masking matrix, with exact rendered strings asserted; unmasking is a separately audited permission |
| SEC-T-053 | A decryption failure | Raises an operator alert; **never** falls back to treating ciphertext as plaintext |
| SEC-T-054 ⚠ | Any private document requested at its storage path directly, unauthenticated | 404/403. Every private file is streamed through an authorised, tenant-checked controller behind a signed, expiring URL. ⚠ *(SmartPRS 2 writes onboarding ID, address and bank proofs to `storage/app/public/employee-docs/<sequential employee id>/<basename>` — a fully predictable path on an unauthenticated public disk with no tenant check)* |
| SEC-T-055 | A signed URL after expiry, and replayed after single use | Refused, with the exact error code |
| SEC-T-056 | Credentials that must be verified vs compared | Verified ones encrypted, compared ones hashed; a generated check asserts no credential column holds a reversible value where a hash is required |
| SEC-T-057 | Webhook signature: valid, tampered body, stale timestamp, replayed nonce | Accept, reject, reject, reject — the freshness window and the nonce store both asserted |
| SEC-T-058 | Connector egress to `169.254.169.254`, `127.0.0.1`, a private range, and a non-allow-listed host | Blocked at save **and** at run, with a clear error; a LAN connector that explicitly permits a private range is the only exception and is audited |
| SEC-T-059 | Every outbound HTTP call | Has an explicit timeout and retry policy; a generated check fails the build on a call without one. ⚠ *(SmartPRS 2 has six different timeout literals and five Razorpay calls with no timeout at all)* |
| SEC-T-060 | An application update package that is unsigned, mis-signed, or signed by a retired key | Refused under every configuration; there is no configuration that permits an unsigned package |
| SEC-T-061 | A rule pack with a flipped byte, an unknown key, or failing golden cases | Rejected; nothing activated *(Part XVII `T-01`…`T-10`)* |
| SEC-T-062 | Backup encryption and the restore drill | A backup restores into a clean database and the full suite runs green against it; the last successful restore date is exposed in the UI |

### XXX.7.8 Authentication, session and MFA

| ID | Case | Must assert |
|---|---|---|
| SEC-T-063 | Login: valid, wrong password, inactive user, locked user, throttled, unknown user | Six exact outcomes; timing does not distinguish unknown user from wrong password |
| SEC-T-064 | Session regeneration on login; invalidation on logout; invalidation on password change and on role change | Four assertions |
| SEC-T-065 | Lockout threshold and window | Denied on attempt N+1 exactly; a failure outside the window does not count; authorised at `locked_until` + 1s, denied at `locked_until` − 1s |
| SEC-T-066 | Password policy and history depth | The (depth)th previous password refused, the (depth+1)th accepted |
| SEC-T-067 | Idle and absolute session lifetimes | A session active throughout still ends at the absolute boundary, with the exact end reason recorded |
| SEC-T-068 | MFA mandatory for privileged roles | A privileged assignment is active during the grace period and **suspended** on grace+1 day |
| SEC-T-069 | Cookie flags, HTTPS enforcement and security headers | Asserted on every response by a generated check |
| SEC-T-070 | Token expiry | Valid at TTL−1s, refused at TTL exactly |

### XXX.7.9 Input, injection and supply chain

| ID | Case | Must assert |
|---|---|---|
| SEC-T-071 | Injection corpus (SQL, NoSQL, template, header, log, CSV formula) against every input on every endpoint | Neutralised; a CSV export cell beginning `=`, `+`, `-` or `@` is escaped |
| SEC-T-072 | Path traversal and null bytes in every filename and path parameter | Rejected |
| SEC-T-073 | Upload: type confusion, oversized file, zip bomb, polyglot, SVG with script | Rejected by content inspection, not extension; size capped; stored outside the web root |
| SEC-T-074 | Mass assignment on every write endpoint | Only declared fields are writable; PATCH semantics — an omitted field is unchanged, and clearing is explicit. ⚠ *(SmartPRS 2's employee save is a full-record replace that nulls every omitted key)* |
| SEC-T-075 | Rate limiting on authentication, OTP, export and report endpoints | Enforced and asserted at the boundary |
| SEC-T-076 | Error responses | Carry a machine-readable code and never a stack trace, a raw exception message or a SQL fragment. ⚠ *(SmartPRS 2 returns the raw exception message to the browser — and returns payroll failures with **HTTP 200**, so monitoring cannot distinguish a failed payroll run from a successful one)* |
| SEC-T-077 | Dependency audit and licence scan | Zero known-vulnerable dependencies above the declared severity; the build fails otherwise |
| SEC-T-078 | SAST/taint analysis over the whole tree | Zero new findings against the ratcheting baseline |

---

## XXX.8 Migration tests

The migration is a one-way, high-stakes event over data whose defects are known and
enumerated. It is tested like a product, not performed like a script.

**R-XXX-034** No customer is migrated until the parallel-run diff for that customer, for at
least **three consecutive periods**, is either empty or fully explained by a signed
reconciliation note.

### XXX.8.1 Schema equivalence

| ID | Test | Must assert |
|---|---|---|
| MG-001 ⚠ | **No migration deletes business data.** Run the full migration set against a populated fixture, recording row counts per table before and after | Zero rows removed from any business table. ⚠ *(SmartPRS 2 runs an unbounded, cross-tenant, non-transactional attendance dedupe inside two migrations that both execute on **every** `php artisan migrate` — `2026_08_16_000200:137-156`, `2026_08_17_000100:212-234`)* |
| MG-002 | No migration executes a bulk `DELETE` or `UPDATE` over business rows at all | A static check over `database/migrations` fails the build on one |
| MG-003 | `migrate:fresh` versus the migrated-from-empty production structure | Byte-identical structure: tables, columns, types, nullability, defaults, collation, indexes, foreign keys, constraints (gate G-3) |
| MG-004 | Every migration is reversible, or declares itself irreversible with a reason | `down()` restores the prior structure exactly; an irreversible migration is refused unless declared and approved |
| MG-005 | Migrations run inside a transaction where the engine permits, and the runner reports the DDL statements that cannot be transactional | Explicit list; no implicit-commit surprise |
| MG-006 | The production database user holds no `ALTER`, `CREATE` or `DROP` | The whole suite runs green under that user (gate G-2); any runtime DDL fails loudly |
| MG-007 | `migrate:status` on every supported environment shape | No pending migration |

### XXX.8.2 Transformation correctness

The source shapes and their defects are known; each is a named transformation with a test.

| ID | Transformation | Must assert |
|---|---|---|
| MG-008 | Four tenancy column names → one `tenant_id`, `NOT NULL`, everywhere | Every migrated row carries a tenant; a row that cannot be attributed is quarantined with a reason, **never** defaulted |
| MG-009 | The 49 runtime-created tables and ~60 runtime-created columns | Each is either migrated into a real, declared table or explicitly discarded with a recorded reason; a source column present on one install and absent on another is handled by the transformation, not by a schema guess |
| MG-010 | `app_state` — a single JSON blob holding "all 113 screens' collections", unversioned, last-writer-wins, its column type not pinned in any migration | Every collection inside it is enumerated per tenant, mapped to a real table with real constraints, and reconciled by row count and by checksum. **This is the highest-risk transformation in the project** and it is gated on decision `Q-7` |
| MG-011 ⚠ | Employee codes | Every migrated employee has a code; duplicates within a tenant are surfaced for human decision, never auto-renamed; employees whose code was lost to a swallowed collision are identified and reported before migration |
| MG-012 | `leaves` rows carrying `type_name` and no `type_id` | Mapped to a real type by an explicit, reviewable mapping table; an unmappable row is quarantined. **Every historic leave must be re-classified paid/unpaid**, because the source system paid all of them |
| MG-013 | Payslips and payroll runs | Migrated as **historical evidence**, immutable, with a flag recording that they were produced by SmartPRS 2 and are not reproducible by SmartPRS 3's engines |
| MG-014 ⚠ | Fabricated payroll history | Runs and payslips created by the `/app/data` back-fill are **identified** (by their signature: twelve consecutive months, `status='paid'`, `employees_count` inconsistent with the tenant's headcount) and quarantined for customer decision, not migrated as genuine wage registers. Gated on decision `Q-6` |
| MG-015 | The audit ledger | Rows migrated as historical evidence; the **chain is not migrated**, because it will not verify; a new chain begins at migration with a genesis anchor recording why |
| MG-016 | Statutory override rows from `statutory_configs` | Migrated into the S4 configuration envelope with their effective dates preserved; the resolution result for a sample of 1,000 (employee, date, kind) triples is identical before and after |
| MG-017 | Documents and files | Every file referenced by a row exists; every file on disk is referenced; orphans on either side are reported; nothing private lands on a publicly readable path |
| MG-018 | Missing unique indexes back-filled | Before any data moves, duplicates in `payroll_runs`, `payslips`, `payments` and the shared invoice series are enumerated and resolved by an explicit, audited decision |

### XXX.8.3 Reconciliation and the parallel-run diff harness

**R-XXX-035** The parallel-run harness executes one payroll period on both systems against the
same input and produces a **line-level diff**: per employee, per component, old value, new
value, delta, and a classification — `identical`, `explained_by_defect_fix`,
`explained_by_configuration`, or **`unexplained`**. A single `unexplained` line blocks the
customer's cut-over.

| ID | Test | Must assert |
|---|---|---|
| MG-019 | Row-count reconciliation per table, per tenant | Source count = target count + quarantined count + explicitly discarded count. Exact, no residual |
| MG-020 | Money reconciliation | For every migrated period: `SUM(source net) = SUM(target net) ± the explained delta`, with the delta itemised by cause |
| MG-021 | Parallel run on a customer dataset, one period | Diff produced; every non-identical line classified; the classification distribution reported to the customer before cut-over |
| MG-022 | The expected diffs are **predicted in advance**. Unpaid leave now producing LOP; sandwich days now correct; the divisor now read from configuration; ESI period locks now stored | Each predicted class appears; a class appearing that was **not** predicted is an `unexplained` and blocks |
| MG-023 | Re-running the migration is idempotent | A second run changes nothing; a partially completed migration resumes without duplicating |
| MG-024 | Rollback | The migration can be abandoned and the source system remains authoritative and untouched — the source is read-only throughout, asserted by a read-only credential |
| MG-025 | E2E-6 on a real anonymised customer dataset per release | Green |

---

## XXX.9 Performance tests

**R-XXX-036** Performance budgets are **assertions**, not observations. A budget breach fails
the build exactly as a wrong number does. The nightly lane runs against seeded tenants at
1,000, 5,000 and 10,000 employments with five years of history; the per-commit lane runs the
query-count budgets, which are cheap and catch the regressions that matter earliest.

### XXX.9.1 The seeded scale tenants

| Tenant | Employments | History | `attendance` rows | Payslips | Purpose |
|---|---:|---|---:|---:|---|
| `SCALE-1K` | 1,000 | 5 years | ~5.0 M | 60,000 | per-commit query-count lane |
| `SCALE-5K` | 5,000 | 5 years | ~25.0 M | 300,000 | nightly |
| `SCALE-10K` | 10,000 | 5 years | ~50.0 M | 600,000 | nightly, release gate |

*(Row model: 4 punches/day, ~250 working days/year, per `M-…` §6.3, extended from three years
to the five the requirement states.)*

### XXX.9.2 The budgets

| Operation | 1,000 | 5,000 | 10,000 | Also asserted |
|---|---|---|---|---|
| **Payroll run, end to end** (queued, staged) | ≤ 2 min | ≤ 8 min | ≤ 15 min | Per-stage timings recorded; per-employee progress observable; restartable at any stage; peak memory ≤ 512 MB per worker |
| **Payroll run, queries** | — | — | — | `O(1)` per stage, **not** `O(N)`: one grouped attendance aggregate, one grouped leave aggregate, one grouped loan/advance/clawback fetch. ⚠ *(SmartPRS 2 issues ≥ 6 queries per employee — ≥ 60,000 at 10,000 employees — and cannot complete)* |
| **Attendance ingestion** | ≥ 2,000 punches/s | ≥ 2,000 punches/s | ≥ 2,000 punches/s | Sustained over a 1 M-punch batch; constant memory; `O(1)` queries per batch; the per-punch verdict still returned |
| **Attendance day derivation** (nightly, whole tenant) | ≤ 3 min | ≤ 12 min | ≤ 25 min | Idempotent; a second pass produces zero diffs within the same budget |
| **Attendance report, one month** | ≤ 3 s (p95) | ≤ 5 s | ≤ 8 s | Streamed, paginated, capped range; peak memory ≤ 256 MB. ⚠ *(SmartPRS 2 loads every punch into PHP twice and blanks the page at 5,000)* |
| **Report/export generation** (any) | ≤ 30 s queued | ≤ 2 min | ≤ 5 min | Streamed to storage, never assembled as one PHP string; PDFs queued |
| **Employee list, page 1** | ≤ 400 ms (p95) | ≤ 400 ms | ≤ 400 ms | Server-enforced page size; cursor pagination; identical at every scale |
| **Dashboard** | ≤ 800 ms (p95) | ≤ 1 s | ≤ 1.2 s | ≤ 15 queries |
| **Any `/api/v3` read** | ≤ 300 ms (p95), ≤ 800 ms (p99) | same | same | Bounded result set; no endpoint may return an unbounded collection |
| **Any `/api/v3` write** | ≤ 500 ms (p95) | same | same | Idempotency key honoured within the budget |
| **Login** | ≤ 500 ms (p95) | same | same | Constant-time credential comparison |

### XXX.9.3 Query-count budgets and the regressions they guard

**R-XXX-037** Every endpoint declares a maximum query count and a maximum returned row count.
Both are asserted per request in the per-commit lane. Exceeding either fails the build
(gate G-12). This is the cheapest and highest-yield performance control available, and it
catches every regression in the table below at the moment it is written.

| Guard | The SmartPRS 2 regression it prevents | Assertion |
|---|---|---|
| **No boot payload** | `/app/data` returns every employee (~62 fields each), every reference row, every payroll run and **every payslip the tenant has ever had** — 30–60 MB at 1,000 employees, memory exhaustion at 5,000, outright failure at 10,000 | No endpoint returns more than its declared maximum row count; a fixture at 10,000 employees asserts the largest response in the product is under 2 MB |
| **No `O(N)` payroll** | `compute()` issues `presentDays`, `paidLeaveDays`, `dayStats`, `presentDates`, `sandwichDays`, `loanEmiDue`, `advanceRecovery`, `clawbacksDue` and `esiPeriodLock` **per employee** | Query count per payroll stage is constant in the employee count, asserted at 100 and at 10,000 employments |
| **No unfiltered lookup inside a loop** | `paidLeaveDays()` plucks **every tenant's** leave types on every call | A generated check fails the build on a query issued inside a loop over a business collection; 103 such sites exist in the source across 25 files |
| **No full-table fallback** | `LeaveController.php:322` loads **every employee of the tenant, all columns**, as a fuzzy-name fallback on leave apply | Name-based lookup does not exist in the domain layer (`AT-012`); the endpoint's row budget makes it unrepresentable |
| **No unbounded audit read** | `Audit::verify()` loads the entire `activity_logs` table with no chunk, no tenant filter and no limit, then hashes every row | Verification is chunked, tenant-scoped and bounded; the endpoint asserts constant memory over a 10 M-row fixture |
| **No index-defeating predicate** | 46 `whereRaw('LOWER(…))` and 15 `whereDate(…)` sites; `DashboardController` runs two `whereDate` predicates on the hottest table on every load | A check fails the build on a query wrapping an indexed column in a function; normalisation happens on write |
| **Required composite indexes present** | `attendance_logs` has no `(tenant_id, log_date)`; `activity_logs` has no `created_at` index; `loan_recoveries` has no index at all | A schema test asserts the declared index set exists, including `attendance (tenant_id, date)`, `attendance (tenant_id, employee_id, date)`, `audit (tenant_id, created_at)`, `payslips (employee_id, period)`, `ledger (parent_id)` |
| **No schema introspection in a hot path** | 738 `Schema::hasTable`/`hasColumn` calls; `safeRow()` calls `getColumnListing()` on **every row it writes**, at 98 sites — a 10,000-payslip run issues 10,000 `SHOW COLUMNS` | Zero introspection calls during any request or job, asserted by a query-log filter |
| **Everything slow is queued** | Payroll, PDFs, exports, bulk messaging and gateway calls all run inside the HTTP request; a 500-recipient broadcast occupies a worker for up to two hours | A check asserts no outbound HTTP call and no long operation occurs in a request path; messaging is queued, individually retriable and rate-limited |
| **No silent truncation** | `limit(1000)` on archived employees (row 1,001 is invisible forever), `limit(50)` on clawbacks and salary schedules, `limit(24)` on ESI history — all with no user signal | Any truncated result carries an explicit indicator and a next-page cursor; a test asserts the indicator appears at the boundary |

### XXX.9.4 Soak and failure-mode performance

| ID | Test | Must assert |
|---|---|---|
| PF-01 | 24-hour soak at 5,000 employments with continuous ingestion, nightly derivation and a payroll run | No memory growth trend; no lock-wait pile-up; queue depth returns to zero |
| PF-02 | Queue worker stopped for one hour | Backlog drains within the declared recovery window; nothing is lost; the operations dashboard shows the outage. ⚠ *(SmartPRS 2's scheduled sync uses a hard-coded one-day window under a 24-hour overlap lock, so a longer outage loses punches permanently)* |
| PF-03 | Database failover mid-payroll | The run resumes from its last completed stage; no duplicate side effect |
| PF-04 | A single tenant issuing pathological load | Other tenants' budgets are unaffected (per-tenant limits asserted) |
| PF-05 | Index-only regression: drop a declared index and re-run the budgets | The relevant budget fails — proving the budget is actually sensitive to the index, not passing for unrelated reasons |

---

## XXX.10 The definition of done *(brief Section 116)*

The brief's acceptance principle is that a module is not done when it works, but when it is
**configurable, permissioned, isolated, audited, historical, notified, importable,
effective-dated, tested, documented and correct when it fails**. Below it is a checklist a
named reviewer signs, per module, per release. Every line has an **evidence** column, because
a checklist without evidence is a signature on an opinion.

**R-XXX-038** No module ships without a completed, signed Definition-of-Done record. The
record is stored with the release, names the reviewer, and is retrievable three years later.

**R-XXX-039** Every line is either satisfied, or explicitly waived by a named approver with a
dated, ticketed reason that expires. There is no "N/A" without a reason.

### XXX.10.1 The module checklist

| § | Heading | The line a reviewer signs | Evidence required |
|---|---|---|---|
| 1 | **Business behaviour** | Every rule in the module's business-rules register is implemented, and each has at least one exact-value golden case | Case IDs, and a link to the register row for each |
| 1b | | No business behaviour is expressed as a literal in code | Gate G-1 green for the module's namespace |
| 1c | | Every derived value the module produces carries a trace, and `Explain` renders it | Gate G-4 green; a screenshot or fixture of the rendered trace |
| 2 | **Configuration** | Every item a customer could reasonably want different is configuration, and the list is enumerated in the module's part | The configuration-surface table, and a test that changes each item and observes the change |
| 2b | | The Section 127 standard is met: the module's equivalent of *"3 late arrivals over 10 minutes = half day"* is achievable by an administrator with no code and no deployment | A recorded configuration session, and the passing golden case *(`RE-024` for attendance)* |
| 2c | | Publishing a configuration change runs its diff, its impact preview and its stored regression cases | Publish record showing all three |
| 3 | **Permissions** | Every operation is bound to an explicit policy; permission and scope are both enforced server-side and both fail closed | `SEC-T-011`, `SEC-T-012` green for the module's routes; the route × role matrix printed |
| 3b | | Every guard has a fault-injection test asserting denial | `SEC-T-001`…`SEC-T-010` coverage for the module's guards |
| 4 | **Tenant isolation** | Every aggregate and every endpoint has a generated cross-tenant case, including the upsert match keys | Gate G-6 green for the module; the generated case list |
| 4b | | `tenant_id` is `NOT NULL` and participates in every unique index the module owns | `SEC-T-030` output for the module's tables |
| 5 | **Audit** | Every write path emits an audit row through the single audit service, with field-level before/after where the spine requires it | `SEC-T-038`, `SEC-T-039` green; a sample ledger extract |
| 5b | | An audit failure fails the business transaction | `SEC-T-037` green |
| 6 | **History** | Every configuration artefact the module owns is versioned and effective-dated; nothing is updated in place | Law 3 test: "what applied on date D?" answered by a single service call, for the module's artefacts |
| 6b | | Derived records are superseded, never deleted, and the prior version remains queryable | The module's supersession test *(`PR-146` for payroll)* |
| 7 | **Notifications** | Every event the module emits that a human must know about has a configured, templated, deduplicated, queued notification with a delivery record | The event → notification map, and a delivery-log extract |
| 7b | | No notification storm is possible: dedupe and rate limits are asserted at the boundary | The storm test *(a whole-workforce absence event produces one digest, not N e-mails)* |
| 8 | **Import / export** | Every entity the module owns can be exported and re-imported, and the round trip is **provably symmetrical** | The round-trip test: export → import into a clean tenant → byte-identical export |
| 8b | | Import validates, previews, reports every rejected row with a reason, and is idempotent under replay | The rejection-report fixture; the replay test |
| 9 | **Effective dates** | Resolution is always by the **subject date**, never by "today"; a version published later but effective earlier behaves correctly | `RE-013`-shaped test for the module's artefacts |
| 9b | | Re-running a closed period reproduces the original result exactly | The module's reproducibility test *(`PR-012`, `AT-045`)* |
| 10 | **Tests** | The module's coverage floor for its class is met, its mutation score is met, and coverage has not regressed below its high-water mark | The coverage and mutation report for the module's namespace |
| 10b | | Every scenario in the module's part's Tests section exists, is registered, and runs in the per-commit lane | Registry diff: zero unregistered scenarios |
| 10c | | Every defect fixed in this release has a permanent regression case naming its identifier | Gate G-9 green |
| 11 | **Documentation** | The module's part is current: any behaviour that changed is reflected, and no statement contradicts the code | A reviewed diff of the part against the release |
| 11b | | Operator documentation exists for every failure state in §12 below: what the operator sees, what they can do, and what they must escalate | The runbook section |
| 11c | | The in-product help for the module's screens is content, not code | The content rows, not a PHP array. ⚠ *(SmartPRS 2 holds 2,206 lines of help copy in PHP arrays)* |
| 12 | **Failure behaviour** | Every failure mode is enumerated, and each is loud: a typed error, an operator-visible incident, and a defined recovery | The failure table from the module's part, each row with a test |
| 12b | | No catch in the module's domain path is silent | Gate G-8 green for the namespace |
| 12c | | Every guard fails **closed**; every degradation is deliberate, declared and alerted | The fault-injection matrix for the module |
| 12d | | No read fabricates a business record, and no write happens outside a transaction that covers all of its side effects | `SEC-T-032` for the module's read endpoints; the atomicity test for its writes *(`PR-150`)* |

### XXX.10.2 The sign-off record

```
MODULE            <name>            RELEASE  <version>
CHECKLIST         52 lines: <n> satisfied, <n> waived
WAIVERS           <ticket> · <approver> · <expiry> · <reason>      (one line each)
COVERAGE          line <x>% / branch <x>% / mutation <x>%   (floors: <…>)
GOLDEN CASES      <n> registered, <n> passing, 0 skipped
GATES             G-1…G-12 : <verdict each>
REVIEWER          <name>            DATE  <date>
QUALITY OWNER     <name>            DATE  <date>
```

**R-XXX-040** The Definition of Done applies to **configuration deliverables** as well as
code: a shipped configuration template, a report definition, a letter template or a rule pack
is a module for the purposes of this checklist.

---

## XXX.11 Test infrastructure

### XXX.11.1 Factories

**R-XXX-041** Every aggregate has a factory. A test may not construct domain state by raw
insert. `database/factories/` **exists** and is complete — the contrary position in SmartPRS 2
is that the directory does not exist at all while `composer.json` PSR-4-maps it and Faker is a
declared dependency with nothing to serve (`M-…` §8 D-1).

**R-XXX-042** Factories build through the **domain write service**, not through the query
builder, so a test's fixture is subject to the same validation, uniqueness, effective-dating
and audit as production data. A factory that bypasses the write service is a defect.
*(SmartPRS 2's `tests/Pest.php` uses raw `DB::table()->insertGetId()`, bypassing models, casts
and events, and `makeUser` creates roles with **no permissions attached** — so every
authorisation test is testing an empty role.)*

**R-XXX-043** Roles and permissions in tests come from the **real seeder**. A test may not
invent a role. *(`tests/Pest.php:38` calls `Role::findOrCreate` with no permissions.)*

**R-XXX-044** A fixture may not execute DDL, and a CI check fails the build on `Schema::`
anywhere under `tests/`. A missing column is a missing migration, and the fixture layer must
not conceal it.

### XXX.11.2 Fixtures and seeded tenants

| Tenant | Contents | Used by |
|---|---|---|
| `GOLD-IN` | §XXX.4.2 — three companies, three calendars, three cycles, six named employees, 500 seeded employments, fixture packs | The payroll, leave, statutory and rule matrices |
| `GOLD-ATT` | Three devices, three shifts, a roster with a rotation, a quarantine backlog, a correction backlog | The attendance matrix |
| `GOLD-SEC` | Two tenants with deliberately colliding natural keys; every role; a null-tenant platform actor | The isolation and authorisation suites |
| `SCALE-1K/5K/10K` | §XXX.9.1 | Performance |
| `MIG-<customer>` | An anonymised customer dataset | Migration and E2E-6 |

**R-XXX-045** Every fixture tenant is produced by a **versioned, deterministic seeder**
checked into the repository. Re-seeding produces byte-identical data. A fixture change is a
reviewed commit with a migration note, because it invalidates golden values.

**R-XXX-046** Anonymisation of a customer dataset is a tested transformation with its own
assertions: no real name, national ID, PAN, bank account, e-mail, phone or address survives,
and the *shape* of the data (distributions, cardinalities, edge rows) does.

**R-XXX-047** A destructive seeder may never select its target by "the first tenant by id".
*(`DummyDataSeeder.php:46-47` falls back to `orderBy('id')->value('id')` when no demo tenant
exists and then hard-deletes 22 tables for that tenant — i.e. the **oldest customer** — at
`:80-93`.)* A seeder without an explicit, confirmed target aborts.

### XXX.11.3 The database

**R-XXX-048** The suite runs on the **production engine at the pinned production major
version**, with production SQL mode, character set and collation, in every lane. No SQLite
substitution exists.

**R-XXX-049** Each parallel worker gets its own schema, created from `migrate:fresh` once per
build and restored per test by transaction rollback where possible and by truncation where not.
The database user for the suite holds no `ALTER`, `CREATE` or `DROP` after the initial
migration step (gate G-2).

**R-XXX-050** A separate lane runs the suite against a **restored backup** rather than a fresh
migration, weekly, to prove the restore path and to catch drift between `migrate:fresh` and
the accumulated migration path.

### XXX.11.4 Fixture rule packs

| Pack | Contents | Notes |
|---|---|---|
| `FIX-PF-1`, `FIX-PF-2` | §XXX.4.8 | Two ceilings so the EPS cap binds in one and not the other |
| `FIX-ESI-1` | §XXX.4.9 | Explicit boundary inclusivity and rounding direction |
| `FIX-PT-TG`, `FIX-PT-MH`, `FIX-PT-KA` | §XXX.4.10 | Includes a month uplift and a gender exemption switch |
| `FIX-LWF-TG`, `FIX-BON-1`, `FIX-GRA-1`, `FIX-MW-TG` | §XXX.4.11 | |
| `FIX-TAX-1` | §XXX.4.12 | A toy regime; asserts the engine, never the statute |

**R-XXX-051** Fixture packs are signed by a **test key** and are structurally identical to
production packs, so the pack import, verification, diff, preview and activation paths are
exercised by the same code the customer uses. A test key is never valid in production, and a
production key is never present in a test environment.

**R-XXX-052** A fixture pack carries a banner in its metadata: *"test data — asserts nothing
about Indian statute"*. Any tooling that renders a pack renders the banner.

### XXX.11.5 Golden files and snapshot discipline

**R-XXX-053** A golden value lives in a **data file** next to its case: the inputs, the
configuration version ids, the expected outputs and the expected trace. Golden files are
diffable text, reviewed like code, and never binary.

**R-XXX-054** **Snapshots are never updated automatically.** There is no `--update-snapshots`
flag in any lane, and the tool that regenerates a golden file requires a written reason that
is stored in the file's header alongside the previous value, the new value, the delta, the
authorising ticket and the reviewer. A golden-file change is reviewed by someone other than
its author.

**R-XXX-055** A golden-file diff in a pull request is rendered as a **money diff**: old value,
new value, delta, and the population affected. A reviewer approving a payroll golden change is
approving a change to somebody's pay, and the review interface must say so.

**R-XXX-056** A single commit may not change more than a declared number of golden values
without a change-control record. A pack upgrade legitimately changing hundreds of values is a
change-control event, not an ordinary commit.

**R-XXX-057** Every deployment-shaped variable — edition, licence tier, on-premise versus
SaaS, feature entitlement — is an explicit lane dimension. The suite runs the supported matrix,
and no code path reads configuration from the filesystem outside the framework's configuration
layer. *(SmartPRS 2's `Edition::current()` reads `.env` line by line specifically to defeat
`config:cache`, which makes the suite's colour a function of an untracked file.)*

### XXX.11.6 The deterministic clock and the world outside

**R-XXX-058** All outbound integrations are exercised against **recorded contract fixtures**
plus a **contract test against the real endpoint** run nightly, not per commit. A change in a
third party's behaviour must fail a build, not a payroll.

**R-XXX-059** The queue, the cache, the mailer and the object store are **real** in the
integration lane — not in-memory fakes — because SmartPRS 2's most damaging operational
failures come from customers running neither a scheduler nor a worker, and a fake proves
nothing about that.

### XXX.11.7 The CI pipeline

Two lanes. The per-commit lane is what a developer waits for; the nightly lane is what the
release gate reads.

| # | Stage (per-commit lane) | Content | Budget | Blocking |
|---|---|---|---|---|
| 1 | **Fast checks** | Formatter, dependency-rule test (module boundaries, `Domain` does not depend on `Http`), gates G-1, G-2, G-8, G-10, secrets scan | 2 min | yes |
| 2 | **Static analysis** | PHPStan/Larastan at the pinned level with the ratcheting baseline; taint analysis (gate G-11) | 4 min | yes |
| 3 | **Unit (L1)** | | 1.5 min | yes |
| 4 | **Engine conformance (L8, L9, plus SPX and workflow)** | §XXX.3 | 5 min | yes |
| 5 | **Domain golden (L2)** | §XXX.4, §XXX.5, §XXX.6 | 12 min | yes |
| 6 | **Integration (L3)** | Real MySQL, queue, cache | 15 min | yes |
| 7 | **Generated suites (L4, L5, L6)** | API contract, authorisation matrix, isolation | 10 min | yes |
| 8 | **Security (L12, fast subset)** | Fail-closed injection, injection corpus, headers | 6 min | yes |
| 9 | **Query-count budgets** | Gate G-12 against `SCALE-1K` | 4 min | yes |
| 10 | **Coverage and gate evaluation** | Floors, ratchet, G-3…G-7, G-9 | 2 min | yes |

Stages 3–9 run in parallel across workers; the wall-clock target is **≤ 35 minutes**
(R-XXX-014), and the whole lane runs on every push to every branch and again on merge.

| # | Stage (nightly / release lane) | Content | Budget |
|---|---|---|---|
| N1 | Full security suite including deep scans and dependency audit | §XXX.7 | 25 min |
| N2 | Performance at 1K/5K/10K | §XXX.9 | 90 min |
| N3 | Migration suite and the parallel-run diff on the reference dataset | §XXX.8 | 25 min |
| N4 | Mutation testing on Class A and Class B namespaces | §XXX.11.10 | 60 min |
| N5 | E2E-1…E2E-6 across the supported deployment matrix | §XXX.2.5 | 40 min |
| N6 | Shuffled and reverse-order runs of the whole suite | R-XXX-007 | 35 min |
| N7 | Restore drill: restore a backup, run the suite against it | `SEC-T-062` | 30 min |

**R-XXX-060** CI is mandatory from commit one. A branch cannot merge without a green
per-commit lane, and a release cannot be cut without a green nightly lane on the release
commit.

**R-XXX-061** The build publishes, per commit: coverage per module, mutation score per Class A
namespace, gate verdicts, the golden-case count, the flaky-test list, and the wall-clock time
per stage. These are the quality dashboard, and a regression in any of them is visible without
anyone asking.

### XXX.11.8 Static analysis

**R-XXX-062** PHPStan/Larastan at a **pinned level** with a **ratcheting baseline that may
only shrink**, plus custom rules implementing the spine's laws:

| Rule | Enforces |
|---|---|
| No bare business literal in `Domain\*` outside the constants allow-list | Law 1 |
| No `Schema::`/DDL outside `database/migrations` | Law 2 |
| No `float`, `double` or float-producing operation reachable from a money type | Law 7 |
| No silent catch in `Domain\*` | S5.6 |
| No clock, randomness or superglobal access in `Domain\*` | R-XXX-003/004 |
| No cross-module reach into another module's internals; no `Domain` → `Http` dependency | S2 |
| No name-based join or name comparison on a component, leave type, status or role in `Domain\*` | Law 1, S12 |
| No query inside a loop over a business collection | §XXX.9.3 |
| No outbound HTTP call without an explicit timeout | `SEC-T-059` |
| Every route file entry has a policy binding | Law 5 |

**R-XXX-063** A custom rule is added for **every defect class** found in SmartPRS 2 that a
static rule can detect. The 156 runtime-DDL sites, the 738 introspection calls, the fail-open
catches and the un-timed HTTP calls are each a rule, not a memory.

### XXX.11.9 Reachability and dead code

**R-XXX-064** A CI check fails the build on a domain service that is covered by tests but
invoked by no production caller. *(`PeriodResolver` — 323 lines, nine of the suite's best
tests, wired to nothing.)* It also fails on a controller or model that is imported but bound to
no route. *(Five of eleven SmartPRS 2 models are unreachable, along with five controllers.)*

### XXX.11.10 Mutation testing on the money paths

**R-XXX-065** Mutation testing runs nightly over Class A and Class B namespaces with an **85 %
minimum mutation score** (gate table, §XXX.2.6). Its purpose is precisely to catch the
SmartPRS 2 anti-pattern: an assertion that restates the implementation survives every mutant
and is therefore visibly worthless.

**R-XXX-066** Every ⚠ row in §XXX.4–§XXX.7 must be observed to **fail** at least once against
a deliberately reintroduced defect. The defect-injection corpus is maintained as part of the
suite: one injector per historical defect, run quarterly, asserting that the corresponding case
goes red.

### XXX.11.11 Flaky tests

**R-XXX-067** A test that fails and then passes without a code change is **quarantined within
one working day**, its quarantine is visible on the dashboard, and it must be fixed or deleted
within five working days. A quarantined test in a security or money lane blocks the release
immediately (R-XXX-029).

**R-XXX-068** Flakiness is treated as a defect in the *system*, not the test, until proven
otherwise. Ordering, clock, randomness and shared state are eliminated by R-XXX-003…007, so a
flake usually means the production code has a real non-determinism.

### XXX.11.12 Importing a customer's real configuration into a test tenant

This is how a customer's actual behaviour becomes regression-protected without their data
leaving their control.

**R-XXX-069** A tenant's configuration — org structure, calendars, cycles, structures,
formulas, rules, workflows, templates, statutory position, entity schema, roles — is
exportable as a **signed, versioned, self-describing configuration bundle** (Part VI's
export/import artefact), containing **configuration only and no personal data**. The bundle is
schema-validated and its export/import round trip is provably symmetrical (`DoD 8`).

**R-XXX-070** A bundle can be imported into a **test tenant** and run against **synthetic
employees generated to the shape of the customer's population** — headcount, state mix, shift
mix, structure mix, joiner/leaver rate — described by a small statistical profile the customer
supplies or that is derived on their premises. No employee record ever leaves the customer.

**R-XXX-071** The customer's configuration then carries its own **golden cases**: a period is
run, the customer confirms the figures are correct, and the run is frozen as that customer's
regression baseline. Every subsequent SmartPRS 3 release re-runs every customer baseline in
the nightly lane. A release that changes any customer's figures without a declared, approved
reason does not ship.

**R-XXX-072** For an on-premise customer who will not export, the same harness runs **on their
installation** as a self-check command, producing a signed result file that reports pass/fail
per baseline without disclosing any figure.

**R-XXX-073** A configuration bundle is treated as untrusted input: it is validated, its SPX
is re-typed against the current symbol table, its scopes are re-resolved, and any ambiguity is
reported. Importing a bundle can never execute anything.

---

## XXX.12 Data model

**Golden cases are files, not rows.** A golden case must be diffable, reviewable in a pull
request, and versioned with the code it asserts; a database row is none of those things. The
tables below are the **results warehouse** that the quality dashboard and the release gate
read, plus the two records that must survive a release: a customer's regression baseline and a
Definition-of-Done sign-off.

These tables live in the **Ametecs engineering datastore**, not in a customer installation,
with one exception noted per table. They are therefore not tenant business tables and Law 6's
`tenant_id` requirement does not apply to them; where a tenant is referenced it is recorded as
an opaque identifier, which is stated explicitly in each table.

### XXX.12.1 `qa_test_registry`

**Purpose.** The materialised index of every registered case, generated from the case files on
every build. Its uniqueness constraint is what enforces R-XXX-002 (no duplicate expectation
under two IDs) and R-XXX-023 (every matrix row exists).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `case_code` | varchar(32) | no | — | `PR-041`, `AT-009`, `SEC-T-027`, `RE-024` |
| `build_id` | varchar(64) | no | — | the build that materialised this row |
| `layer` | varchar(24) | no | — | FK → `qa_layers.code` (`L1`…`L14`); R-XXX-001 |
| `module` | varchar(64) | no | — | owning module namespace |
| `module_class` | char(1) | no | — | `A`/`B`/`C`/`D`, drives the coverage floor |
| `owner` | varchar(64) | no | — | named individual or team |
| `file_path` | varchar(512) | no | — | the case file |
| `inputs_hash` | char(64) | no | — | SHA-256 of the canonicalised inputs |
| `expectation_hash` | char(64) | no | — | SHA-256 of the canonicalised expected outputs |
| `defect_ref` | varchar(64) | yes | null | the SmartPRS 2 defect or the ticket this case locks (gate G-9) |
| `is_release_blocking` | boolean | no | true | |
| `created_at` | datetime(3) UTC | no | — | |

PK `id` · Unique `(build_id, case_code)` · Unique `(build_id, inputs_hash, expectation_hash)`
— *the duplicate-expectation guard* · Index `(build_id, module)`, `(case_code)`,
`(defect_ref)` · Tenant scope: none (engineering) · History: one row set per build, retained
per §XXX.12.7 · Soft delete: no · Audit: the build record is the audit · PII: none.

### XXX.12.2 `qa_case_results`

**Purpose.** One row per case per build. The source of the dashboard, the flake detector and
gate G-10.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `build_id` | varchar(64) | no | — | |
| `case_code` | varchar(32) | no | — | FK → `qa_test_registry(build_id, case_code)` |
| `status` | varchar(16) | no | — | FK → `qa_statuses.code`: `passed`/`failed`/`errored`/`quarantined`/`skipped`. **No database `ENUM`** (S16) |
| `duration_ms` | int unsigned | no | — | |
| `worker` | varchar(32) | no | — | for order-dependence diagnosis |
| `shuffle_seed` | varchar(32) | yes | null | R-XXX-007 |
| `expected_value` | text | yes | null | as asserted |
| `actual_value` | text | yes | null | on failure |
| `failure_class` | varchar(32) | yes | null | `value`/`exception`/`budget`/`timeout`/`gate` |
| `created_at` | datetime(3) UTC | no | — | |

PK `id` · Unique `(build_id, case_code)` · Index `(case_code, created_at)` — the flake query ·
Index `(build_id, status)` · Tenant scope: none · History: append-only · Soft delete: no ·
Audit: n/a · PII: none — **a case result may never contain customer data**, asserted by
R-XXX-076.

### XXX.12.3 `qa_budgets` and `qa_budget_results`

**Purpose.** The declared performance and query-count budgets (§XXX.9) and their measurements.
`qa_budgets` is checked into the repository as data and loaded per build; the results table is
the warehouse.

`qa_budgets`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `operation_code` | varchar(96) | no | — | endpoint or named operation |
| `scale_code` | varchar(16) | no | — | `1K`/`5K`/`10K` |
| `metric` | varchar(24) | no | — | `p95_ms`/`p99_ms`/`queries`/`rows`/`bytes`/`peak_mb`/`rate_per_s` |
| `limit_value` | bigint | no | — | |
| `direction` | char(3) | no | `max` | `max` or `min` (throughput) |
| `owner` | varchar(64) | no | — | R-XXX-013 |
| `effective_from` | date | no | — | budgets are effective-dated like everything else |

PK `id` · Unique `(operation_code, scale_code, metric, effective_from)` · Index
`(operation_code)`.

`qa_budget_results`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `build_id` | varchar(64) | no | — | |
| `budget_id` | bigint unsigned | no | — | FK → `qa_budgets(id)` `ON DELETE RESTRICT` |
| `measured_value` | bigint | no | — | |
| `verdict` | varchar(8) | no | — | `pass`/`fail` |
| `created_at` | datetime(3) UTC | no | — | |

PK `id` · Unique `(build_id, budget_id)` · Index `(budget_id, created_at)` — the trend query ·
Tenant scope: none · History: append-only · PII: none.

### XXX.12.4 `qa_gate_results`

**Purpose.** The release gate's verdict, per build, per gate. This is the record that answers
"was this release allowed to ship, and on what evidence?" three years later.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `build_id` | varchar(64) | no | — | |
| `gate_code` | varchar(16) | no | — | `G-1`…`G-12`, `COV-A`…`COV-D`, `MUT-A`, `MUT-B` |
| `verdict` | varchar(8) | no | — | `pass`/`fail` |
| `measured` | varchar(64) | yes | null | e.g. `96.2%` |
| `threshold` | varchar(64) | yes | null | |
| `high_water_mark` | varchar(64) | yes | null | the ratchet (R-XXX-012) |
| `detail_ref` | varchar(512) | yes | null | artefact link |
| `owner` | varchar(64) | no | — | |
| `created_at` | datetime(3) UTC | no | — | |

PK `id` · Unique `(build_id, gate_code)` · Index `(gate_code, created_at)` · Tenant scope:
none · History: append-only, **never deleted** · Soft delete: no · Audit: this **is** the
audit · PII: none.

### XXX.12.5 `qa_customer_baselines`

**Purpose.** A customer's frozen, confirmed regression baseline (R-XXX-071). The one table with
a genuine tenant reference, and the one whose contents must be handled as customer material.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `customer_ref` | varchar(64) | no | — | opaque customer identifier; **never** a customer name |
| `bundle_version` | varchar(32) | no | — | the configuration bundle version |
| `bundle_checksum` | char(64) | no | — | |
| `population_profile` | json | no | — | the statistical shape used to generate synthetic employees; **no personal data** |
| `period_code` | varchar(16) | no | — | the baselined period |
| `baseline_checksum` | char(64) | no | — | checksum of the frozen result set |
| `confirmed_by` | varchar(128) | no | — | the customer signatory |
| `confirmed_at` | datetime UTC | no | — | |
| `retired_at` | datetime UTC | yes | null | supersession, never deletion |
| `retirement_reason` | varchar(255) | yes | null | |

PK `id` · Unique `(customer_ref, period_code, bundle_version)` · Index
`(customer_ref, retired_at)` · Tenant scope: `customer_ref` is opaque; the table holds **no
tenant business data** · History: superseded by `retired_at`, never deleted · Soft delete: via
`retired_at` · Audit: every insert and retirement is audited in the engineering ledger · PII:
**none permitted** — R-XXX-076 asserts it, and a CI check scans the column set for
personal-data-shaped content.

### XXX.12.6 `qa_dod_signoffs`

**Purpose.** The Definition-of-Done record of §XXX.10.2, retained with the release.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `release_version` | varchar(32) | no | — | |
| `module` | varchar(64) | no | — | |
| `lines_total` | smallint unsigned | no | — | |
| `lines_satisfied` | smallint unsigned | no | — | |
| `waivers` | json | no | `[]` | each: ticket, approver, expiry, reason (R-XXX-039) |
| `coverage_line` | decimal(5,2) | no | — | |
| `coverage_branch` | decimal(5,2) | no | — | |
| `mutation_score` | decimal(5,2) | yes | null | required for Class A and B |
| `reviewer` | varchar(128) | no | — | |
| `quality_owner` | varchar(128) | no | — | |
| `signed_at` | datetime UTC | no | — | |

PK `id` · Unique `(release_version, module)` · Index `(module, signed_at)` · History:
append-only, retained for the life of the product · Soft delete: no · PII: reviewer names only,
classified internal.

### XXX.12.7 Retention

**R-XXX-074** `qa_gate_results` and `qa_dod_signoffs` are retained for the supported life of
every release they gate, plus three years. `qa_case_results` and `qa_budget_results` are
retained in full for 90 days and in daily aggregate thereafter for three years.
`qa_test_registry` is retained for the builds whose results are retained. Retention is a
declared retention class like any other (spine S16), and this part will not repeat SmartPRS 2's
position of having **no retention policy of any kind** (`M-…` §1.8).

**R-XXX-075** No table in §XXX.12 ships in a customer installation, with the single exception
of the **self-check harness** of R-XXX-072, which writes only a signed pass/fail result file
and no figures.

**R-XXX-076** No engineering table may contain customer personal data. A CI check inspects the
column set and a sampled row set for personal-data-shaped content and fails the build on a
match.

---

## XXX.13 API surface

This part exposes almost nothing to a customer, deliberately. Configuration export/import and
the rule simulator are owned by Parts VI and VII; the endpoints below are the additions this
part requires.

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `POST` | `/api/v3/config/regression-baselines` | Freeze the current tenant's confirmed run as a regression baseline (R-XXX-071) | `quality.baseline.create` |
| `GET` | `/api/v3/config/regression-baselines` | List this tenant's baselines with their status against the current release | `quality.baseline.view` |
| `DELETE` | `/api/v3/config/regression-baselines/{uuid}` | Retire a baseline (supersede; never destroy) | `quality.baseline.retire` |
| `POST` | `/api/v3/config/regression-baselines/{uuid}/verify` | Re-run this baseline against the current build and return pass/fail per assertion | `quality.baseline.run` |
| `GET` | `/api/v3/system/self-check` | The on-premise self-check result: gate verdicts, baseline verdicts, scheduler and worker health, last restore-test date | `system.health.view` |
| `POST` | `/api/v3/system/self-check/run` | Trigger the self-check (queued, idempotent) | `system.health.run` |

**R-XXX-077** Every endpoint above obeys S17 in full: OAuth2 or scoped key, idempotency key on
the unsafe methods, cursor pagination on the collection, the standard error envelope, and an
explicit policy binding asserted by `SEC-T-011`.

**R-XXX-078** The self-check endpoint reveals **verdicts, never figures**. An on-premise
customer's payroll numbers do not travel to Ametecs through it.

---

## XXX.14 Events

Domain events emitted by this area, consumed as stated.

| Event | Emitted when | Consumed by |
|---|---|---|
| `quality.build.completed` | A lane finishes | Dashboard; release tooling |
| `quality.gate.failed` | Any gate verdict is `fail` | Release tooling (blocks); the gate's owner (notified) |
| `quality.budget.breached` | A budget result is `fail` | The budget's owner; the performance trend board |
| `quality.case.quarantined` | A case is quarantined for flakiness | The owning module's team; the dashboard; the release gate if the case is in a security or money lane |
| `quality.golden.changed` | A golden file's expected value changes | Change control; the money-diff reviewer (R-XXX-055) |
| `quality.defect.regression_case_missing` | A defect is closed with no case naming it | Gate G-9; the defect's closer |
| `quality.baseline.frozen` | A customer confirms a regression baseline | The customer's account record; the nightly baseline runner |
| `quality.baseline.diverged` | A build changes a customer's baseline figures | **Blocks the release**; notifies the account owner and the quality owner |
| `quality.migration.diff.unexplained` | The parallel-run harness produces an unexplained line | Blocks that customer's cut-over; notifies the migration owner |
| `quality.selfcheck.failed` | An on-premise self-check fails | The customer's operations dashboard; Ametecs support, if the customer has opted in |

---

## XXX.15 Migration implication

| Artefact | Comes across as | Notes |
|---|---|---|
| The 132 SmartPRS 2 cases | **Specifications, not code.** Their assertions are harvested into §XXX.4–§XXX.7; not one file is ported | Every case is coupled to raw-table shapes that will not exist |
| The 43 punch-ingest cases | The **reference** for §XXX.5 and for the concurrency suite | They are behavioural, not implementation-coupled — the only such set in the source |
| The 7 PT assertions | `FIX-PT-*` fixture packs plus `PR-077`…`PR-085` | The slabs become data; the assertions become golden cases |
| The 9 `PeriodResolver` cases | `PR-001`…`PR-012` | The component's logic is carried forward and, this time, wired to payroll |
| The 8 `StatutoryConfig` cases | `RE-001`…`RE-014` | The merge semantics survive as spine S3.2 precedence; the implementation does not |
| `tests/Pest.php` | **Replaced.** Raw inserts, permissionless roles and fixture DDL are each forbidden by R-XXX-042/043/044 | |
| `tests/manual/*` | **Removed.** The reflection technique of `check_statutory_config.php` is folded into real tests; `pt_extract.php` — a hand-copied statutory table kept in sync by a docblock — is exactly what R-XXX-010 forbids | |
| CI, static analysis, coverage, factories | **Created.** Nothing to migrate | |
| Coverage history | None exists | The first measured figure is the first baseline |

---

## XXX.16 Edge cases and failure states

Unglamorous, specific, and each with a decided behaviour.

| # | Situation | Behaviour |
|---|---|---|
| 1 | **A golden value is wrong** — the test is right about the mechanism and wrong about the number | The case is corrected through R-XXX-054 with the reason recorded, and a second case is added covering whatever made the original wrong. Never silently regenerated |
| 2 | **A rule-pack upgrade legitimately changes hundreds of golden values** | Change-control event (R-XXX-056). The pack's own golden cases move with it; the application matrix's `<pack:…>` rows are re-derived by the documented arithmetic, and the diff is reviewed as money (R-XXX-055) |
| 3 | **A customer's baseline diverges because of a deliberate defect fix** | `quality.baseline.diverged` blocks the release until the account owner confirms; the confirmation is recorded on the baseline, which is then re-frozen. A fix is never shipped silently to a customer whose figures it changes |
| 4 | **A test is slow enough to threaten the lane budget** | It moves to the nightly lane only if a cheaper equivalent assertion exists in the per-commit lane; otherwise the *system* is made faster. A lane budget is never raised to accommodate a slow test |
| 5 | **The suite is green but the product is broken** | The defect gets a permanent regression case (gate G-9) **and** a retrospective asks which gate should have caught it; if the answer is "none", a gate is added |
| 6 | **Two modules disagree about the same derived value** | Both are wrong until one derivation exists. The federating test (`AT-039`, `PR-153`, Part XII "one evaluator") is the arbiter, and it lives here, not in either module |
| 7 | **A generated suite explodes in size** (route × role × scope is combinatorial) | The generator collapses equivalent cells by declared equivalence classes, and the collapse itself is asserted: removing a class must break a case |
| 8 | **A parallel-worker database collides** | Each worker owns a schema (R-XXX-049); a collision is a harness defect and blocks the lane, never retried into a pass |
| 9 | **A third party changes behaviour overnight** | The nightly contract test fails; the recorded fixtures still make the per-commit lane green, so a developer is not blocked, but the release gate reads the nightly lane |
| 10 | **A security test is flaky** | It blocks immediately (R-XXX-029). There is no quarantine path for a security or money case |
| 11 | **A gate's owner leaves** | The gate is reassigned within one release, or removed by an explicit decision. An unowned gate is worse than no gate because it decays into an override |
| 12 | **A developer needs to ship urgently and the gate is red** | The change is reverted. There is no override flag anywhere in the tooling; adding one is a change to this part, requiring the Product Owner's approval |
| 13 | **A migration diff has an unexplained line at 02:00 on cut-over night** | Cut-over stops. The source system remains authoritative and untouched (`MG-024`), and the customer runs one more period on SmartPRS 2 |
| 14 | **The fixture tenant's seed changes** | Every golden value derived from it is re-derived, reviewed and re-frozen in the same commit; a seed change and a behaviour change may not share a commit |
| 15 | **A test needs data that only production has** | It does not get it. Either the shape is expressible as a fixture, or the gap is a modelling failure worth fixing in the product |
| 16 | **CI infrastructure itself is unavailable** | Nothing merges and nothing ships. The gate is not "green by default when unmeasured" — the same fail-closed principle the product obeys (Law 5) |
| 17 | **A test asserts behaviour the Product Owner later changes** | The case is amended with the decision reference in its header. A golden file records *why* a number is what it is, which is the property that makes it defensible three years later |
| 18 | **Coverage is met but the behaviour is untested** | Mutation testing (R-XXX-065) is the countermeasure; a namespace with high coverage and a low mutation score fails its gate |

---

## XXX.17 Requirements index

| # | Requirement | Origin |
|---|---|---|
| R-XXX-001 | Every test declares its layer | `M-…` §1.5 (unit tests booting a database) |
| R-XXX-002 | No layer asserts what a lower layer proves; duplicate expectations rejected | §111 |
| R-XXX-003 | One injected clock; no wall-clock access in `Domain\*` | `BillingController.php:81-98` |
| R-XXX-004 | One seeded randomness source | §111 |
| R-XXX-005 | No dependence on database row order | scope-by-row-order |
| R-XXX-006 | No test sleeps; time advances explicitly | §111 |
| R-XXX-007 | Order independence; shuffled and reverse runs in CI | §111 |
| R-XXX-008 | Golden values computed against **fixture** packs; `<pack:…>` notation | spine S12, hard rule 1 |
| R-XXX-009 | Real packs carry their own golden cases, authored by the compliance owner | S12, D-03 |
| R-XXX-010 | A golden test reads the artefact the engine reads; no hand-copied tables | `tests/manual/pt_extract.php` |
| R-XXX-011 | The release gate is the conjunction of four floors and twelve structural gates; no override | §111, §116 |
| R-XXX-012 | Coverage is measured, published and ratcheting | `M-…` §1.4 |
| R-XXX-013 | Every gate has a named owner and a dashboard tile | §111 |
| R-XXX-014 | Lane budgets: 35 min per-commit, 2 h nightly | §111 |
| R-XXX-015 | No test deleted to make a build pass | §111 |
| R-XXX-016 | Money goldens are externally derived constants | `PayrollMathTest.php:19-31` |
| R-XXX-017 | A status-code assertion on a writing route must assert the write set | `AuthAccessTest` #3 |
| R-XXX-018 | Golden tests enter through the shipped contract; reachability check | `PeriodResolver` |
| R-XXX-019 | No test reads a copy of engine data | DUP-8 |
| R-XXX-020 | Four module-independent engine conformance suites | spine S5–S8 |
| R-XXX-021 | Conformance suites are declarative data, portable across implementations | S5–S8 |
| R-XXX-022 | Reconciliation invariants run in production on every run | spine S11; `M-…` C-1 |
| R-XXX-023 | Every §XXX.4 scenario is a registered, per-commit golden case | §112 |
| R-XXX-024 | Payroll goldens assert the persisted payslip from the pipeline | `M-…` §4.1 |
| R-XXX-025 | Attendance scenarios assert the derived day, not the punch | §113 |
| R-XXX-026 | Every ingest path passes the identical adapter conformance suite | spine S10.1 |
| R-XXX-027 | Every published rule carries stored regression cases | spine S6 |
| R-XXX-028 | `RE-024` (the Section 127 case) is release-blocking | §127 |
| R-XXX-029 | The security suite is blocking; no skip, no quarantine | §115 |
| R-XXX-030 | Every guard has fault-injection tests asserting denial | `ApprovalService.php:377-379` |
| R-XXX-031 | The authorisation matrix is generated from routes × roles × scopes | `M-…` §6.1 Rank 3 |
| R-XXX-032 | Cross-tenant cases generated per aggregate and per endpoint | Law 6; `M-…` §6.1 Rank 7 |
| R-XXX-033 | Every §10.1 operation carries a parallel-request test | `M-…` §6.2 (25 races) |
| R-XXX-034 | No cut-over without three clean parallel-run periods | §XXX.8 |
| R-XXX-035 | The parallel-run harness classifies every line; one `unexplained` blocks | §XXX.8.3 |
| R-XXX-036 | Performance budgets are assertions, not observations | `M-…` §6.3 |
| R-XXX-037 | Every endpoint declares query-count and row-count maxima | `M-…` PERF-1…PERF-9 |
| R-XXX-038 | No module ships without a signed Definition-of-Done record | §116 |
| R-XXX-039 | Every DoD line satisfied or explicitly waived with an expiry | §116 |
| R-XXX-040 | The DoD applies to configuration deliverables too | §116 |
| R-XXX-041 | Every aggregate has a factory; no raw-insert fixtures | `M-…` §8 D-1 |
| R-XXX-042 | Factories build through the domain write service | `tests/Pest.php` |
| R-XXX-043 | Roles and permissions come from the real seeder | `tests/Pest.php:38` |
| R-XXX-044 | No DDL under `tests/` | `tests/Pest.php:71-77` |
| R-XXX-045 | Deterministic, versioned fixture seeders | §111 |
| R-XXX-046 | Anonymisation is a tested transformation | §XXX.8 |
| R-XXX-047 | A destructive seeder never targets "the first tenant by id" | `DummyDataSeeder.php:46-47` |
| R-XXX-048 | The suite runs on the production engine, everywhere | `phpunit.xml:33-34` |
| R-XXX-049 | Per-worker schemas; no `ALTER`/`CREATE`/`DROP` for the suite user | Law 2 |
| R-XXX-050 | A weekly lane runs against a restored backup | `M-…` §6.7 restore paths |
| R-XXX-051 | Fixture packs are structurally identical to production packs, test-signed | S12 |
| R-XXX-052 | Fixture packs carry a "test data" banner | hard rule 1 |
| R-XXX-053 | Golden values live in diffable data files | §112 |
| R-XXX-054 | No automatic snapshot update; regeneration requires a recorded reason and a second reviewer | §111 |
| R-XXX-055 | Golden diffs are rendered as money diffs | §112 |
| R-XXX-056 | Bulk golden changes are change-control events | §112 |
| R-XXX-057 | Deployment shape is an explicit lane dimension; no filesystem config reads | `M-…` §1.7 |
| R-XXX-058 | Recorded contract fixtures plus a nightly live contract test | §111 |
| R-XXX-059 | Real queue, cache, mailer and object store in the integration lane | spine S2 |
| R-XXX-060 | CI mandatory from commit one; green lanes gate merge and release | `M-…` §1.2 |
| R-XXX-061 | Per-build publication of coverage, mutation, gates, flakes and timings | §111 |
| R-XXX-062 | Static analysis at a pinned level with a shrinking baseline and the spine's custom rules | `M-…` §1.3 |
| R-XXX-063 | A custom rule per statically detectable SmartPRS 2 defect class | `M-…` §0.4 |
| R-XXX-064 | Reachability check: no tested-but-uncalled service, no unrouted controller | `M-…` §6.4 |
| R-XXX-065 | Mutation testing at 85 % on Class A and B | `M-…` §4.1 |
| R-XXX-066 | Every ⚠ case observed to fail against a reintroduced defect | §112 |
| R-XXX-067 | Flake quarantine in one day, resolution in five; none in security or money lanes | §111 |
| R-XXX-068 | Flakiness is a system defect until proven otherwise | §111 |
| R-XXX-069 | Configuration exports as a signed bundle containing no personal data | Part VI R-34 |
| R-XXX-070 | Bundles run against synthetic populations shaped by a statistical profile | §111 |
| R-XXX-071 | Customers hold frozen regression baselines re-run every night | §111 |
| R-XXX-072 | On-premise customers run the same harness locally, returning verdicts only | spine S18 |
| R-XXX-073 | A configuration bundle is untrusted input; import executes nothing | spine S5 |
| R-XXX-074 | Declared retention classes for every quality table | `M-…` §6.5 |
| R-XXX-075 | Quality tables do not ship to customers, bar the self-check result file | S18 |
| R-XXX-076 | No customer personal data in any engineering table, asserted by CI | DPDP position, `M-…` §6.5 |
| R-XXX-077 | The part's endpoints obey S17 in full | S17 |
| R-XXX-078 | The self-check reveals verdicts, never figures | S13 |

---

## XXX.18 Open items — PRODUCT OWNER DECISION REQUIRED

**D-XXX-01 · Is the release gate genuinely absolute?**
R-XXX-011 states there is no override flag. The alternative is a documented, time-boxed
override requiring two named signatures.
*Recommendation: absolute, with no override mechanism in the tooling.* The moment an override
exists it becomes the release process, and SmartPRS 2's entire quality position is the
consequence of a pre-commit instruction (`README.md:74`) that nothing enforced. The escape
hatch for a genuine emergency is **revert**, which is faster than an override and leaves the
gate intact.
*Trade-off:* a red gate on a Friday evening blocks a hotfix. Mitigated by making revert the
first-class operation and by keeping the per-commit lane under 35 minutes.

**D-XXX-02 · What is the coverage floor for Class A, and is 95 % affordable?**
*Recommendation: 95 % line / 90 % branch / 85 % mutation on money, statutory and identity
code.* Below that, the mutation score is the number that matters and the line figure is
decoration.
*Trade-off:* Class A will be perhaps 15–20 % of the codebase and will carry perhaps 45 % of the
test-writing effort. The alternative — a uniform 70 % — is cheaper and would not have caught a
single one of the twelve defects in §XXX.1.7.

**D-XXX-03 · Who is the named quality owner, and does that person hold a veto?**
The gates need an owner who can stop a release (§XXX.10.2 requires their signature).
*Recommendation: a named individual, not a committee, holding a veto over the release, with
escalation to the Product Owner.*
*Trade-off:* a single point of contention. The alternative — the release manager owns quality —
puts the gate under the person with the strongest incentive to open it.

**D-XXX-04 · How many customer regression baselines does Ametecs commit to running nightly?**
R-XXX-071 implies every customer's baseline runs on every build. At 200 on-premise customers
that is a real cost.
*Recommendation: every customer whose configuration deviates materially from a shipped template
holds a baseline; template-identical customers are represented by the template's own baseline.*
Materiality is measured by the configuration diff, not by revenue.
*Trade-off:* a customer without a baseline discovers a divergence in production. The recommended
middle path is to require a baseline for any customer whose configuration diff exceeds a
declared threshold, and to offer one to any customer who asks.

**D-XXX-05 · Is the fabricated SmartPRS 2 payroll history migrated, quarantined or discarded?**
`MG-014` quarantines it pending this decision, which is analysis `Q-6`. `/app/data` has been
writing `status='paid'` runs and payslips into production for an unknown period, and the LOP
defect has been paying unpaid leave in full.
*Recommendation: quarantine, then a customer-by-customer decision with a reconciliation report,
because the wage register is a statutory document and Ametecs cannot decide unilaterally what a
customer's historical register says.*
*Trade-off:* it makes migration a conversation rather than a script. It is also the only
defensible position.

**D-XXX-06 · Do customers see their own quality evidence?**
The self-check (R-XXX-072) could be internal-only, visible to the customer, or shareable by the
customer with their auditor.
*Recommendation: visible to the customer and exportable as a signed statement*, because the
target market is Indian collection agencies and BPOs whose own auditors ask exactly this. It is
also a differentiator no competitor offers.
*Trade-off:* it makes Ametecs' quality position externally observable, which is a strong
commitment. That is the point.

**D-XXX-07 · Does the parallel-run diff requirement (three clean periods) apply to every
customer, including small ones?**
*Recommendation: yes, without exception, but the period count is the variable — three for any
customer with more than a declared headcount or any statutory complexity, one for the smallest.*
*Trade-off:* three periods is up to three months of parallel running. The alternative is
discovering a divergence after the source system has been decommissioned, which has no remedy.

**D-XXX-08 · Is a performance budget breach release-blocking, or a warning?**
*Recommendation: blocking at the release gate, warning on a feature branch.* A latency
regression that ships is never subsequently prioritised.
*Trade-off:* budgets need occasional revision as the product legitimately grows; R-XXX-036
makes them effective-dated so a revision is a reviewed, owned decision rather than a silent
adjustment.

---

## XXX.19 Compliance with the seven laws

Every law in spine S1 is stated **as a test**. This part is where those tests live, which makes
it the enforcement mechanism for the whole blueprint.

| Law | Its test, per the spine | Where it is implemented here |
|---|---|---|
| **1 — No business literal in code** | Static analysis fails the build on a bare literal in `Domain\*` | Gate G-1; R-XXX-062; the custom-rule table in §XXX.11.8; `RE-024` proves the positive case |
| **2 — No schema change at runtime** | Production user holds no DDL privilege; CI greps for DDL outside migrations; `migrate:fresh` matches production | Gates G-2 and G-3; `MG-003`, `MG-006`; R-XXX-044 extends it to fixtures; R-XXX-049 |
| **3 — Nothing overwritten; everything versioned and dated** | "What applied on date D?" and "what was the branch on date D?" answerable in one service call | `RE-013`, `RE-017`; `PR-007`, `PR-024`, `PR-146`; `AT-045`; DoD §6 |
| **4 — Every number explains itself** | No engine returns a value without a trace; a trace-less result throws | Gate G-4; `RC-14`, `FC-09`, `PR-159`, `AT-055`; DoD §1c |
| **5 — Authorisation is server-side, declarative, fails closed** | Every route has a policy binding; a fault inside authorisation denies | Gate G-5; `SEC-T-001`…`SEC-T-020`; R-XXX-030/031 |
| **6 — Tenancy is structural** | A generated cross-tenant test for every aggregate and endpoint, in CI | Gate G-6; `SEC-T-021`…`SEC-T-032`; R-XXX-032 |
| **7 — Money is exact** | A type-level ban on float in money; every run asserts `SUM(lines) = totals` exactly | Gate G-7; `FC-01`…`FC-05`; `PR-137`…`PR-142`; the Group R invariants; R-XXX-022 |

**The closing argument for why this part is as elaborate as it is.** SmartPRS 2 is 81,592 lines
carrying 446 assertions, of which **zero** assert a rupee of a generated payslip. Its
authorisation grants on error at 71 sites, its audit chain forks under ordinary concurrency,
its regenerate destroys the wage register before the replacement exists, its migrations delete
attendance on every deploy, and a page load fabricates payroll. Not one of those is exotic;
each is the kind of defect a single, cheap, exact-value test catches on the day it is written.
The elaboration in this part is not thoroughness for its own sake — it is the minimum set of
assertions that would have prevented every confirmed defect in the evidence base, expressed so
that a reviewer can check them off and a Product Owner can hold someone to them.
