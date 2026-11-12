# PART IV — SYSTEM ARCHITECTURE

---

## IV.0 Purpose

This part defines the shape of the SmartPRS 3 system: its layers, its modules,
how a request travels through it, how the engines compose, what enforces the
rules, and which technology decisions are settled versus open. Spine `S1`–`S18`
is the binding contract; this part is the map that makes it navigable and adds
the enforcement mechanisms.

---

## IV.1 What SmartPRS 2's architecture actually is, and why it cannot be the model

Stated once, factually, because every decision below is a reaction to it.

| Property | SmartPRS 2 | Evidence |
|---|---|---|
| Layering | Effectively absent. Business logic lives in controllers, Blade views and browser JavaScript | `AppController.php` — 12,533 lines, 2 PHP methods, the entire SPA in heredocs |
| Largest unit | One controller of 1.2 MB | same |
| Service layer | 32 services exist; the best-tested payroll component (`PeriodResolver`, 9 tests) is wired to nothing | `M-quality` §6.4 |
| Schema authority | Split between 49 migrations and ~49 runtime-creating code paths; 15 tables get a different schema from each | `J-database` §1–§2 |
| Referential integrity | Zero foreign keys on business tables; an admin endpoint exists to sweep the resulting orphans | `RequestController.php:1246` |
| Tenancy | Declared via a global scope filtering a column that does not exist; 1,375 hand-written query sites | `G-security` §2 |
| Authorisation | 62 role-string comparisons, 0 policy checks, 6 controls failing open by design comment | `G-security` §1 |
| Error handling | 426 of 843 catch blocks destroy the exception entirely (50.5%) | `L-background-ops` §3 |
| Money | Float; a float comparison decides whether a loan instalment is deducted | `PayrollGenController.php:1357` |
| Concurrency | 4 transactions, 0 locks, 25 enumerated race conditions in 81,592 lines | `M-quality` §6.9 |
| Front end | Two, one of them dead; access control is 100% presentational | `K-frontend` §1 |

This is not a codebase with architectural problems. It is a codebase in which
architecture was never the unit of decision. That is why Section 122 of the
governing brief forbids porting it, and why this part starts from the domain
rather than from the existing folders.

---

## IV.2 The layered architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│  CLIENTS                                                             │
│  SPA (React/TS) · Mobile · Partner integrations · Smart Biometric     │
│  Bridge · SmartEPT · Devices                                         │
└───────────────────────────────┬──────────────────────────────────────┘
                                │  HTTPS · /api/v3 only
┌───────────────────────────────▼──────────────────────────────────────┐
│  INTERFACE LAYER                                                     │
│  Routing · request validation · authentication · rate limiting ·      │
│  idempotency · serialisation · OpenAPI. Contains NO business logic.   │
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  APPLICATION LAYER                                                   │
│  Use-case services. Orchestrate, own the transaction boundary,        │
│  authorise, emit events. Thin. No calculation.                        │
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  DOMAIN LAYER                                                        │
│  Aggregates · invariants · domain services · the ENGINES:             │
│  Rule · Formula · Workflow · Entity Schema · Attendance ·             │
│  Payroll · Statutory · Compliance · Template · Notification           │
│  Pure. Deterministic. No framework, no I/O, no clock.                 │
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  PERSISTENCE LAYER                                                   │
│  Repositories · tenant + data-scope enforcement · effective-dated     │
│  resolution · audit interception · outbox                             │
└───────────────────────────────┬──────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────┐
│  INFRASTRUCTURE                                                      │
│  MySQL · Redis · queue · object storage · mail/WhatsApp/SMS/push ·    │
│  device adapters · connectors · scheduler                            │
└──────────────────────────────────────────────────────────────────────┘
```

### IV.2.1 The rule for each layer

| Layer | May depend on | May never contain |
|---|---|---|
| Interface | Application | Any calculation, any query, any business condition |
| Application | Domain, Persistence contracts | Arithmetic on money, rule evaluation, direct SQL |
| Domain | Domain only | Framework classes, HTTP, the database, the system clock, randomness |
| Persistence | Domain contracts | Business decisions |
| Infrastructure | — | Anything a domain rule depends on being present |

A controller does exactly four things: authenticate, authorise, validate the
request shape, and call one application service. This is stated because
SmartPRS 2's controllers do everything else.

### IV.2.2 The canonical call chain

```
PayrollController                       ← interface
  → RunPayrollService                   ← application: transaction, authorisation, events
      → PayrollEngine                   ← domain: the pipeline
          → PeriodResolver
          → AttendanceSettlementService
          → LeaveSettlementService
          → StructureResolver → FormulaEngine
          → RuleEngine                  (eligibility, penalties, gates)
          → StatutoryEngine → RulePackResolver
          → RecoveryService → LedgerService
          → PayslipBuilder
      → PayrollRepository               ← persistence: tenant-scoped, audited
```

---

## IV.3 The module map

Fifteen bounded modules, each a first-class namespace with a declared public
contract. A module owns its tables; no module reads another module's tables.

| # | Module | Owns | Public contract consumed by |
|---|---|---|---|
| 1 | **Platform** | tenants, installations, licences, entitlement, releases, rule packs | everything |
| 2 | **Identity & Access** | users, roles, permissions, data scopes, sessions, credentials | everything |
| 3 | **Organisation** | org units, classifications, calendars, locations | People, Time, Compensation |
| 4 | **People** | persons, employments, assignments, documents, entity schema | almost everything |
| 5 | **Time & Attendance** | devices, punches, attendance days, settlements, shifts, rosters | Payroll, Compliance |
| 6 | **Absence** | leave types, entitlements, ledgers, applications, comp-off | Payroll, Time |
| 7 | **Compensation & Payroll** | components, structures, runs, payslips, periods | Statutory, Money Movement |
| 8 | **Statutory** | rule packs, applicability, obligations, returns, filings | Payroll, Compliance |
| 9 | **Money Movement** | obligations, ledgers, loans, advances, recoveries, commissions, claims | Payroll |
| 10 | **Talent** | requisitions, candidates, offers, onboarding, lifecycle events, exits | People |
| 11 | **Compliance** | item types, registers, evidence, gates, audit packs | Money Movement, Talent |
| 12 | **Communication** | templates, events, subscriptions, channels, outbox, delivery log | everything |
| 13 | **Integration** | connectors, adapters, credentials, webhooks, delivery | Time, Communication |
| 14 | **Configuration & Rules** | artefacts, versions, scopes, rules, formulas, workflows | everything |
| 15 | **Audit & Observability** | ledger, traces, metrics, health, operations | everything |

### IV.3.1 Enforcing the module boundary

Discipline that is not mechanical is not discipline. Three gates run in CI:

1. **Dependency rules** (Deptrac or equivalent) — a declared layer and module
   graph; a violation fails the build. `Domain` may not reference `Http`,
   `Illuminate`, or another module's internals.
2. **Schema ownership** — each table is annotated with its owning module; a
   query from a foreign module fails a static check.
3. **Public contract surface** — each module exposes an explicit contracts
   namespace; anything else is internal by construction.

This is the substitute for the network boundary a microservice architecture
would have given, at a fraction of the operational cost — which matters because
on-premise customers cannot run a service mesh (spine S2).

---

## IV.4 Cross-cutting concerns

These are not modules; they are properties every request has.

| Concern | Mechanism | Fails how |
|---|---|---|
| Tenant context | Established at authentication, carried in a request-scoped context object, applied by the repository layer | Missing tenant → request rejected, never "all tenants" |
| Authorisation | Policy binding on every operation, evaluated before the application service runs | Any error or unknown → deny |
| Data scope | Applied by the query-scoping layer from the actor's role+scope assignments | Missing scope → empty result, never unfiltered |
| Effective dating | Resolution service; every configuration read takes an as-at date | No date supplied → programming error, throws |
| Audit | Interception at the write path; the write service produces before/after | Direct ledger insert is structurally impossible |
| Explainability | Every engine returns value + trace | Trace-less result throws |
| Idempotency | Idempotency key on every unsafe API method; fingerprints on rule actions | Duplicate → prior result returned |
| Money | `Money` value object, integer paise | Float in a money path → type error, banned by static analysis |
| Time | Injected clock; UTC instants plus business date and timezone | Domain code calling `now()` → static-analysis failure |
| Correlation | Request/correlation id propagated across HTTP, jobs, integrations and logs | — |
| Feature entitlement | Server-enforced at the operation, the UI, the scheduler and the API | Disabled module → 403 at the endpoint, not a hidden menu |

---

## IV.5 The request lifecycle

```
 1. TLS termination, WAF, rate limit by credential
 2. Authenticate → principal (user | API client | device | bridge)
 3. Establish TenantContext (never from a request parameter)
 4. Resolve entitlement — is this module licensed and enabled?
 5. Validate request shape (interface layer only)
 6. Idempotency check for unsafe methods
 7. Authorise: permission + data scope, fail closed
 8. Application service opens the transaction
 9. Domain executes; engines evaluate; traces accumulate
10. Repositories persist, tenant- and scope-filtered, audited
11. Domain events appended to the outbox in the same transaction
12. Commit
13. Outbox dispatch: notifications, webhooks, projections, integrations
14. Serialise response; attach correlation id
```

Two properties are worth naming. **Side effects are inside the transaction or
in the outbox — never after the commit in a swallow-all catch**, which is
exactly where SmartPRS 2 puts every payroll side effect. And **step 3 never
reads a tenant from the payload**, which is how SmartPRS 2's unauthenticated
device endpoint becomes spoofable.

---

## IV.6 How the engines compose

The engines are small, sharply separated, and used by every module. Their
separation is the reason the product stays comprehensible.

```
                        ┌─────────────────────┐
                        │  CONFIGURATION      │  scope · effective dating
                        │  RESOLUTION         │  versioning · precedence
                        └──────────┬──────────┘
                                   │ resolves artefacts for (subject, date)
       ┌───────────────┬───────────┼────────────┬────────────────┐
       ▼               ▼           ▼            ▼                ▼
  ┌─────────┐   ┌───────────┐ ┌─────────┐ ┌──────────┐   ┌────────────┐
  │  RULE   │   │  FORMULA  │ │WORKFLOW │ │  ENTITY  │   │  TEMPLATE  │
  │ decides │   │ computes  │ │ routes  │ │  SCHEMA  │   │  renders   │
  │outcomes │   │  amounts  │ │approvals│ │  defines │   │  documents │
  └────┬────┘   └─────┬─────┘ └────┬────┘ └────┬─────┘   └──────┬─────┘
       │              │            │           │                │
       └──────────────┴────────────┴───────────┴────────────────┘
                                   │  all share SPX and emit traces
                                   ▼
              DOMAIN SERVICES (attendance, leave, payroll, statutory,
              compliance, money movement) apply the results
```

**The division of labour, stated once:**

- A **rule** decides *whether* and *what outcome* — "this day is a half day".
- A **formula** computes *how much* — "HRA is 40% of Basic".
- A **workflow** decides *who approves and in what order*.
- The **entity schema** decides *what data exists at all*.
- A **template** decides *how it is presented*.

Engines never mutate state. They return decisions, amounts, routes and traces;
the domain service applies them inside its own transaction. This is what makes
every engine independently testable and the simulator trivial to build.

---

## IV.7 Data architecture in outline

Detail is in Part XXVIII; the shape is here.

- **One MySQL database per deployment**, with a `tenant_id NOT NULL` on every
  business table participating in every unique index. Physical per-tenant
  separation is a PO decision (Part XXVIII D-XXVIII-01) with a recommendation of
  shared-schema plus enforced scoping.
- **Three data classes**, treated differently:
  - **Configuration** — versioned, dated, scoped, small, cached hard.
  - **Transactional** — employees, punches, runs, payslips, ledgers.
  - **Derived** — attendance days, settlements, projections, read models;
    rebuildable from transactional data plus configuration versions.
- **Immutable evidence layers** — raw punches and the audit ledger are
  append-only. Nothing else in the product may write to them.
- **No database ENUM types.** Status vocabularies are reference tables, so
  adding a value is data. SmartPRS 2 has twelve live cases where code writes a
  value its own `ENUM` forbids.
- **No runtime DDL, enforced by database privilege** — the application user has
  no `ALTER`, `CREATE` or `DROP`. This makes Law 2 physically true rather than
  culturally hoped for.

---

## IV.8 Technology decisions

| Decision | Position | Status |
|---|---|---|
| Platform | Laravel 12 / PHP 8.4 modular monolith | Recommended — spine S2, **D-01** |
| Database | MySQL 8.0+ | Recommended; on-prem reality decides |
| Cache / queue | Redis, with a database-queue fallback for constrained on-prem installs | Recommended |
| Front end | Single SPA, React + TypeScript, against `/api/v3` only | Recommended — **D-02** |
| Search | MySQL full-text initially; a search service only when measured to be needed | Recommended |
| Object storage | S3-compatible in cloud; filesystem with the same interface on-prem | Recommended |
| Packaging | Filesystem release package **and** a container image from one build | Part XXXI recommendation |
| Signing | Asymmetric (Ed25519), private key never in a customer build | Binding — spine S12/S18 |
| Expression language | SPX, purpose-built, sandboxed, non-Turing-complete | Binding — spine S5 |
| Reporting | Metadata-driven report definitions over declared datasets | Binding |

### IV.8.1 Deliberately rejected

| Rejected | Why |
|---|---|
| Microservices for v3.0 | On-premise parity is a Section 87 requirement; customers who do not reliably run a cron worker cannot run a mesh. The module boundaries are enforced in code so the option stays open |
| Event sourcing as the primary store | The reproducibility requirement is met by input snapshots plus versioned configuration, at a fraction of the operational and cognitive cost |
| A generic EAV model for everything | Custom fields use a typed side table; core entities keep real columns. Full EAV trades away every query the product depends on |
| A rules DSL that is a real programming language | Sandboxing a general language is a permanent security liability. SPX is deliberately unable to express a loop |
| Retaining any SmartPRS 2 code as a base | The defects are architectural. Specific *logic* is carried forward (Part I §preservation); the code is not |

---

## IV.9 Non-functional architecture

| Property | Target | Mechanism |
|---|---|---|
| Scale | 10,000 employees per tenant; 30M punch events | Partitioning, cursor pagination, batch engine paths, materialised aggregation counters |
| Payroll throughput | A 10,000-employee run inside the operational window | Staged, restartable, parallelisable pipeline with compiled-formula caching |
| Availability | Degrades to read-only rather than failing closed on licence or subscription lapse | Part XXVII |
| Recoverability | Proven restore, tested on a schedule, last successful test visible in the UI | Part XXVI |
| Security | Fails closed everywhere; generated cross-tenant isolation suite per entity | Part XXII |
| Observability | Correlation ids, metric catalogue, real health checks, no PII in logs | Part XXXI |
| Upgradability | Signed releases, DB backup, rollback; statutory content on a separate channel | Part XXVII |

---

## IV.10 The enforcement toolchain

Every law in spine S1 has a mechanical gate. This table is the architecture's
immune system, and building it is Phase 1 work, not a later nicety.

| Law | Gate | Build stage |
|---|---|---|
| 1 — no business literal | Static-analysis rule over `Domain\*` with an allow-list | CI, blocking |
| 2 — no runtime DDL | Grep for DDL outside migrations **and** a production DB user without `ALTER`; `migrate:fresh` diffed against production structure | CI + deploy |
| 3 — versioned and dated | Architecture test: every configuration read passes an as-at date | CI |
| 4 — explains itself | Engine contract test: a trace-less return throws | CI |
| 5 — fails closed | Route sweep asserting a policy binding on every operation; fault injection asserting denial | CI, blocking |
| 6 — structural tenancy | Generated cross-tenant suite per aggregate and per endpoint | CI, blocking |
| 7 — exact money | Float ban in money namespaces; run-level reconciliation assertion | CI + runtime |
| — | Module dependency rules | CI, blocking |
| — | No empty catch in a domain path | CI, blocking |
| — | No PII in logs | CI test over the redaction layer |
| — | OpenAPI contract test | CI |

---

## IV.11 Requirements arising from this part

| ID | Requirement |
|---|---|
| R-IV-001 | The layer dependency rules in §IV.2.1 shall be enforced by an automated architecture test that fails the build on violation. |
| R-IV-002 | No controller shall contain a calculation, a database query or a business condition; violation shall fail the build. |
| R-IV-003 | Each of the fifteen modules shall declare a public contract namespace; cross-module access outside it shall fail the build. |
| R-IV-004 | Every table shall declare an owning module; a query from a non-owning module shall fail a static check. |
| R-IV-005 | Domain code shall not reference the framework, HTTP, the database or the system clock; violation shall fail the build. |
| R-IV-006 | Tenant context shall be derived only from the authenticated principal, never from a request parameter, header or body field. |
| R-IV-007 | All side effects of a business operation shall occur inside its transaction or via the transactional outbox; no side effect shall run after commit in an unsupervised path. |
| R-IV-008 | Every engine shall be pure: no state mutation, no I/O, no clock access, no randomness; enforced by test. |
| R-IV-009 | The production database user shall hold no `ALTER`, `CREATE` or `DROP` privilege on any environment carrying customer data. |
| R-IV-010 | Every gate listed in §IV.10 shall exist and be blocking before the first module is declared complete. |
| R-IV-011 | `migrate:fresh` shall produce a schema structurally identical to production; a deployment-time diff shall fail the release on divergence. |
| R-IV-012 | The SPA shall consume only `/api/v3`; no private endpoint shall exist for the product's own UI. |
| R-IV-013 | No deployment-conditional branch shall exist in a domain path; violation shall fail the build. |

---

## IV.12 Product Owner decisions

| ID | Decision | Recommendation |
|---|---|---|
| **D-IV-01** | Confirm the platform (spine D-01): Laravel 12 / PHP 8.4 modular monolith | Accept. On-premise parity and team continuity dominate; module boundaries keep extraction available later |
| **D-IV-02** | Confirm the front-end framework (spine D-02) | React + TypeScript, for hiring depth in India |
| **D-IV-03** | Is Redis a hard prerequisite for on-premise, or must a database-queue fallback be supported? | Support the fallback. SmartPRS 2's most damaging operational failures come from customers running no worker at all; lowering the prerequisite bar raises the proportion of installs that work |
| **D-IV-04** | Build the enforcement toolchain (§IV.10) in Phase 1, before feature work? | Yes. Every one of these gates exists because its absence produced a confirmed defect. Retrofitting them after 100,000 lines is how SmartPRS 2 happened |

---

*End of Part IV.*
