# PART XXXI — DEPLOYMENT, OBSERVABILITY AND OPERATIONS

*Covers brief Sections 92 (background-job reliability, from the platform side) and 93
(monitoring and observability), together with the release pipeline, the operational runtime,
the runbooks, the capacity model and the incident practice that the rest of the blueprint
assumes exists.*
*Conforms to spine S1 (the seven laws), S2 (one deployable artefact; the queue and scheduler
are mandatory, not optional), S5.6 (an SPX error value propagates to a rule-evaluation failure
surfaced to the operator and is never silently swallowed), S14 (the audit ledger carries
`request_id` and `correlation_id` — this part is where those two identifiers are defined and
generated), S16 (data conventions, retention classes), S17 (API conventions) and S18
(deployment parity, the operations dashboard, no silent queue failure).*
*Depends on: Part XXII (security and the policy layer that gates every operations route),
Part XXIII (the audit ledger this part feeds identifiers into), Part XXIV (the API envelope
and error-code catalogue), Part XXV (which owns SaaS topology, per-tenant quotas, queue-class
fairness R-XXV-127…R-XXV-131, per-tenant observability dimensioning and the single-tenant
incident runbook), Part XXVI (which owns the installer, the health-check catalogue, the
on-premise operations console, backup and restore), Part XXVII (licence and update delivery),
Part XXX (which owns the test suite, the CI lane definitions and the performance
**assertions** whose production counterparts are specified here).*
*Depended on by: Part XXV §XXV.12.3–12.5, Part XXVI §XXVI.2, §XXVI.6, §XXVI.7, §XXVI.9 and
§XXVI.12.2, each of which defers to this part by name.*
*Evidence base: `analysis/L-background-ops-observability.md` and
`analysis/M-quality-performance-testing.md`, with supporting citations from
`analysis/I-saas-licensing-deployment.md` and `analysis/J-database.md`. Every claim about
SmartPRS 2 carries a `file.php:line` citation carried through from those files.*

---

## XXXI.0 Purpose

This part decides five things.

1. **That the operational runtime is part of the product.** In SmartPRS 2 it is not in the
   repository at all: there is no `deploy/` directory, no cron file, no worker unit, no
   service definition and no runbook — verified absent by `find` (`L-…` DEF-L-27,
   **CRITICAL**), and the README instead tells the operator to run `php artisan queue:work`
   and `php artisan schedule:work` by hand in *"separate terminals"* (`README.md:50-51`).
   Nothing in a release enforces or verifies that either is installed. In SmartPRS 3 the
   scheduler and the worker are declared dependencies, installed by the installer (Part XXVI
   §XXVI.5.6), asserted by the health check, and their absence is a **loud, product-visible
   degradation**.

2. **That failure is never silent.** SmartPRS 2 destroys **426 of its 843 typed catch blocks
   (50.5 %)** entirely and preserves a durable signal in only **10.2 %**; inside
   `app/Console/Commands/` — the scheduled work itself — **21 of 23 catch blocks are fully
   silent and not one logs anything** (`L-…` §Q3). This part specifies the error-handling
   contract, the exception taxonomy, the surfacing behaviour for each class, and the static
   analysis that enforces it.

3. **That observability exists at all.** SmartPRS 2 has no correlation id, no metric, no
   trace, no APM and no heartbeat; `bootstrap/app.php:44-46` is an empty `withExceptions`
   closure and `AppServiceProvider` is empty, so no log line carries a tenant, an actor or a
   request identity (`L-…` §Q7). Its single health endpoint, `/up` (`bootstrap/app.php:14`),
   reports that the framework booted — *"a monitoring system polling `/up` would have reported
   the system green throughout every failure described in this document"*. This part
   specifies the log format, the metric catalogue, the trace model, the real readiness
   contract, the SLOs and the alert routing.

4. **That one artefact serves all three deployment models.** This part carries the **complete
   enumeration** of legitimate deployment-time differences that Parts XXV and XXVI both defer
   to, and the CI check that fails the build on a deployment-conditional branch in a domain
   path.

5. **That operations is a written practice, not folklore.** Eleven runbooks, a capacity model
   with per-scale budgets and named scaling levers, a severity ladder, an on-call rota
   proportionate to a company of Ametecs' size, and a blameless review that produces a
   requirement and a test.

**What this part does not decide.** The installer and the health-check catalogue (Part XXVI
§XXVI.5, §XXVI.6); backup, restore and the drill (Part XXVI §XXVI.8); the scheduled-job
registry tables (Part XXVI §XXVI.12.4); queue-class fairness and per-tenant quotas (Part XXV
§XXV.12); the licence and update artefacts (Part XXVII); the test suite and its gates
(Part XXX).

---

## XXXI.1 What SmartPRS 2 does today, and why it must change

Seven findings. Each one is the justification for a numbered design decision below; none is
recorded here for its own sake.

### XXXI.1.1 The operational runtime is not in the repository

The reference documentation cites `deploy/smartprs-scheduler.cron`,
`deploy/supervisor-smartprs-worker.conf`, `deploy/systemd-smartprs-worker.service` and
`deploy/nginx-smartprs.conf` roughly forty times. **None of them exists in the source tree**
(`L-…` §0.5, DOC-L-03; verified by `find`). The consequences are structural rather than
cosmetic:

- Nothing in a release installs the scheduler or the worker, and nothing verifies that they
  are installed. `queue:restart` appears nowhere in the codebase, so even where a worker does
  run, a deployment leaves it executing pre-update code (`L-…` requirement 65; `I-…` DEF-27).
- The twelve scheduled entries at `routes/console.php:143-266` all chain
  `->withoutOverlapping()->onOneServer()` and **none** uses `->onFailure()`, `->onSuccess()`,
  `->pingOnFailure()`, `->emailOutputTo()`, `->sendOutputTo()`, `->runInBackground()`,
  `->evenInMaintenanceMode()` or `->timezone()` (`L-…` §Q1, verified by grep). There is no
  last-run table and no heartbeat. **Whether the scheduler has ever run is not answerable
  from the product.**
- `withoutOverlapping()` is called with no argument on all twelve, so Laravel's 24-hour
  default lock applies to a five-minute task. A task killed without releasing its lock is
  blocked for a full day, and the recovery command (`schedule:clear-cache`) appears nowhere in
  the repository (`L-…` DEF-L-02).

> **The design response.** §XXXI.4 makes background processing a *verified* dependency; the
> service definitions ship in the artefact and are installed and asserted by the installer
> (Part XXVI §XXVI.5.6, health checks 7 and 8).

### XXXI.1.2 Half of all caught exceptions are destroyed

A machine census of typed PHP catch blocks across `app/ routes/ database/ config/ tests/
updater/ public/` (`L-…` §0.4, §Q3):

| Class | Count | Share |
|---|---:|---:|
| **EMPTY** (body empty or comment-only) | 332 | 39.4 % |
| **RETURN_ONLY** (`return;` / `return null\|false\|[]` and nothing else) | 94 | 11.2 % |
| **OTHER** (neither logs nor rethrows) | 331 | 39.3 % |
| **LOGGED** | 82 | 9.7 % |
| **RETHROW** | 4 | 0.5 % |
| **Total** | **843** | |

**426 (50.5 %) destroy the exception entirely and leave no trace anywhere. Only 86 of 843 —
10.2 % — preserve any durable signal.** The concentration is worse than the average in exactly
the places that matter: `app/Console/Commands/` has 23 typed catches, **17 empty and 4
return-only, and not one logs anything**; `app/Http/Middleware/` has 7, **6 empty and 0
logging** (`L-…` §Q3, §6.3).

The consequences are not theoretical. Four "fail-soft" DDL catches on the payroll path
(`PayrollGenController.php:2571-2616`) mean that on a correctly hardened database — where the
application user lacks `ALTER` — overtime is never stamped paid and **next month's run pays it
again**, and a loan's instalment counter never advances so **the EMI is deducted from the
employee's salary forever** (`L-…` §Q3 item 1). `AbsenceService::isHoliday()` returns `false`
on any exception (`AbsenceService.php:233-235`), so a failing holidays query **flags the entire
workforce absent on a public holiday**; its two siblings swallow to an empty set, so everyone
who was present, and everyone on approved leave, is also marked absent (`:250-251`, `:273-274`).

> **The design response.** §XXXI.5 — the no-silent-failure rule, its exception taxonomy, and
> the static-analysis gate that fails the build on an unjustified swallow in a domain path.
> This is spine S5.6 made enforceable.

### XXXI.1.3 The one alerting mechanism is structurally blind, and self-referential

`errors:digest` (`app/Console/Commands/ErrorDigest.php`), a daily 07:45 email, is the entire
alerting surface of the product. Seven defects, each independently disabling (`L-…` §Q7,
DEF-L-06, DEF-L-07):

| # | Defect | Evidence |
|---|---|---|
| 1 | Scans only `ERROR\|CRITICAL\|ALERT\|EMERGENCY`, while **every scheduled service logs at `warning`** and production sets `LOG_LEVEL=warning` | `ErrorDigest.php:48`; `.env.production.example:24`; `AbsenceService.php:134`, `GreetingService.php:157`, `ProbationService.php:165`, `MailService.php:112, 121`, `NotifyLateArrivals.php:52` |
| 2 | **Delivered through the queue whose failure it is supposed to detect** | `ErrorDigest.php:82` → `MailService::queue()` → `SendNotification::dispatch` |
| 3 | Returns SUCCESS and sends nothing when `SMARTPRS_OPS_EMAIL` is unset — a silent opt-out | `ErrorDigest.php:25-30` |
| 4 | Matches `str_contains($line, $lvl.':')`, so any business string containing "ERROR:" inflates the digest | `ErrorDigest.php:62` |
| 5 | Scans the last 2 MB / 4,000 lines of an **unrotated, unbounded** `single`-channel log file, so the window covers a shrinking slice of wall-clock time | `ErrorDigest.php:46, 108`; `config/logging.php:57` |
| 6 | No deduplication — two runs send two identical emails | `L-…` §Q7 |
| 7 | No `try/catch` at all; `filesize`/`fopen`/`fseek`/`fread` are unguarded and the failure goes to a discarded stream | `ErrorDigest.php:105-112` |

> **The design response.** §XXXI.6.9 — alerting is generated from **signals**, not from log
> scraping; it is deduplicated on an alert key; it is routed through a transport that does not
> depend on the subsystem it monitors; and its thresholds and recipients are configuration.

### XXXI.1.4 The system asserts a delivery that never happened

`MailService::queue()` returns the `mail_log` row id **the moment the row is inserted**, before
any delivery attempt (`MailService.php:119`). `SubscriptionAlerts.php:107-108` writes
`subscription_alerts.status = 'sent'` on the basis of that id. Meanwhile the auto-suspend write
is direct and unqueued (`SubscriptionAlerts.php:65`).

The compound failure: with no worker running, a paying tenant is **suspended out of a live HR
system having received none of the five warning emails** at 15/7/3/1/0 days
(`SubscriptionAlerts.php:35`), while the ledger positively records all five as sent — and the
one diagnostic an operator would naturally run comes back green, because credential and
password-reset mail bypasses the queue entirely (`MailService.php:107-116`). There is direct
in-code evidence this has already happened in production: the rev-170 comment at
`MailService.php:102-106` records the fix as a response to *"the silent failure that locked a
paying client out of their new workspace"* (`L-…` §Q9).

> **The design response.** §XXXI.4.3 and §XXXI.5.2 — `queued`, `sent`, `delivered`, `failed`
> and `suppressed` are five distinct ledger states and no caller may treat one as another
> (Part XXVI R-XXVI-174); and a subsystem that cannot complete its work degrades visibly.

### XXXI.1.5 There are no observability primitives at all

| Capability | SmartPRS 2 | Evidence |
|---|---|---|
| Correlation / request id | **None.** grep for `request_id`, `X-Request-Id`, `correlation`, `trace_id` across `app/`, `bootstrap/`, `routes/` returns nothing | `L-…` §Q7 |
| Exception enrichment | **None.** `withExceptions` is an empty closure; `AppServiceProvider::register()` and `boot()` contain only `//`, so there is no Monolog processor either | `bootstrap/app.php:44-46` |
| Metrics | **None.** No counter, timer, StatsD, Prometheus or OpenTelemetry. Nothing measures duration, throughput, queue depth or error rate | `L-…` §Q7 |
| Tracing | **None** | |
| Health endpoint | `/up` — reports that the framework boots and nothing else | `bootstrap/app.php:14` |
| APM / exception monitoring | **None.** No Sentry, Bugsnag, Flare or Telescope | `composer.json` |
| Authentication logging | **None.** `grep -n "Log::" app/Http/Controllers/AuthController.php` returns **no matches** — no line for a successful login, a failed login, a lockout, a password change, a role change or a 403 | `L-…` §Q6 |
| Log rotation | **None.** The default stack is the `single` channel, one unbounded file; the `daily` channel with its 14-day window exists and is not selected. `storage/logs` is excluded from backups | `config/logging.php:57`; `config/backup.php:37` |

Queue visibility is equally absent: `failed_jobs` has **no reader, no pruning and no
`Queue::failing()` listener** — grep for `Queue::` returns zero matches across `app/`,
`routes/` and `config/` — and `job_batches` is dead schema (`L-…` DEF-L-23).

### XXXI.1.6 PII reaches the log file; credentials reach the ledger; both reach the backup

The single highest-exposure logging pattern in SmartPRS 2 is the ~60 sites shaped
`Log::warning('X failed: '.$e->getMessage())` on a `QueryException`
(`MasterController.php:697, 829`; `EmployeeImportService.php:152, 458`; `LeadController.php:91`
and others). **A failed insert on `employees` writes that row's PAN, bank account, salary and
date of birth into the log file** (`L-…` §Q6 item 9). At `LOG_LEVEL=debug` — the value the
on-prem install ships with, because `composer.json:51-53` copies `.env.example:12` — every
unhandled exception writes a stack trace with bound query parameters.

Those lines then leave the premises: `errors:digest` mails the ten most recent ERROR-class
lines, 240 characters each, to `SMARTPRS_OPS_EMAIL` — *"an address that is not necessarily the
customer's"* (`ErrorDigest.php:76-93`; SEC-L-09).

Separately, `wa_log.body_values` is a permanent, unencrypted, unpruned store of **one-time
passcodes and the tenant administrator's plaintext temporary password**: `WaService.php:171`
persists `json_encode(array_values($opts['bodyValues']))` on every send, and
`SignupController.php:413-425` places the plaintext password in `bodyValues[4]` whenever
`SMARTPRS_WA_SEND_PASSWORD` is truthy — **which it defaults to** (SEC-L-03). That table sits
inside the nightly backup, which is unencrypted by default, includes `.env` with `APP_KEY` and
every gateway secret, and is written to `storage/app` **on the same server it is backing up**
(`config/backup.php:28-38, 72-81`; SEC-L-06).

> **The design response.** §XXXI.6.3 — PII never enters a log, enforced by a redactor on the
> egress path of every channel and by a CI test that proves it with a poisoned corpus.

### XXXI.1.7 A fixed window where a watermark belongs

`attendance:sync-etimeoffice` is scheduled with `--days=1` hard-coded in the schedule line
(`routes/console.php:225`; `SyncETimeOffice.php:41-42`). The pull therefore always asks for the
last 24 hours regardless of when it last succeeded.

**A scheduler outage longer than 24 hours loses punches permanently.** The next successful run
asks only for the last day; recovery requires a manual `--from/--to` run *and* the upstream
device still retaining the data. Missing punches become missing pay (`L-…` DEF-L-01,
**CRITICAL** — the highest-impact hard-coded value in the area). Compounded by DEF-L-02, a
five-minute job that dies holding a 24-hour overlap lock produces exactly that outage.

> **The design response.** §XXXI.4.2 — every recurring job cursors on a durable high-water
> mark, never a fixed relative window, with a configurable maximum look-back.

### XXXI.1.8 The findings, mapped to this part's design

| Finding | Severity | Answered by |
|---|---|---|
| Operational runtime absent from the repository (DEF-L-27) | CRITICAL | §XXXI.3.2, §XXXI.4, R-XXXI-035, R-XXXI-036 |
| 50.5 % of exceptions destroyed (§Q3) | CRITICAL | §XXXI.5, R-XXXI-053 … R-XXXI-059 |
| Runtime DDL inside swallowing catches (DEF-L-08) | CRITICAL | §XXXI.3.5 (migration policy), Law 2; Part XXVI health check 4 |
| Biometric window `--days=1` (DEF-L-01) | CRITICAL | §XXXI.4.2, R-XXXI-044 |
| Alerting blind and self-referential (DEF-L-06, -07) | HIGH | §XXXI.6.9, R-XXXI-082 … R-XXXI-088 |
| `sent` recorded before dispatch (DEF-L-13) | HIGH | §XXXI.5.2; Part XXVI R-XXVI-174 |
| No correlation id, no metrics, no trace (§Q7) | HIGH | §XXXI.6.1, §XXXI.6.5, §XXXI.6.6 |
| PII in logs; credentials in `wa_log`; unencrypted backup (SEC-L-03, -06, -09) | HIGH | §XXXI.6.2, R-XXXI-062 … R-XXXI-064; Part XXVI §XXVI.8.2 |
| `failed_jobs` unread, unpruned; no `Queue::failing()` (DEF-L-23) | HIGH | §XXXI.4.3, §XXXI.7 |
| Six log-shaped tables never pruned (DEF-L-29) | MEDIUM | §XXXI.12, R-XXXI-130 … R-XXXI-133 |
| Unbounded reads, N+1, in-memory exports, 120,012 inserts in one request (`M-…` PERF-1…8) | HIGH | §XXXI.9, R-XXXI-110 … R-XXXI-118 |

---

## XXXI.2 The single-artefact principle

> This section is the **complete enumeration** that Part XXV §XXV.2.1 and Part XXVI §XXVI.2
> both defer to by name. It is specified once, here.

### XXXI.2.1 The rule

Spine S18 is absolute: **one build serves SaaS, private cloud and on-premise.** The same
commit, the same package, the same checksum. Deployment models differ only in *infrastructure
configuration* — the values a deployment supplies to a platform port — never in business
behaviour.

Three corollaries, each testable:

- **A domain path may not know which deployment model it is running under.** Not by a constant,
  not by a configuration read, not by an injected flag, not by an interface chosen at compile
  time. `Domain\*` has no vocabulary for "SaaS" or "on-premise" at all.
- **Where behaviour genuinely differs it is a *port*, not a branch.** The access gate is one
  interface with two implementations — subscription (Part XXV) and licence (Part XXVII) —
  selected by the deployment profile at the composition root, outside `Domain\*`. The domain
  asks `AccessGate::assertPermitted(...)` and does not know which answered.
- **The golden test matrix produces identical numbers under every profile.** Payroll for the
  fixture tenant `GOLD-IN` (Part XXX §XXX.4.2) is byte-identical in SaaS and on-premise
  profiles. A divergence is a build failure, not a variance.

SmartPRS 2 fails all three. `I-…` §10 Q8 enumerates **twenty-one live branch sites** on
deployment or edition, including one that produces a configuration which is *neither*
subscription-gated *nor* licence-gated — a cloud-hosted L-edition tenant that is unbillable and
unenforceable (`I-…` DEF-36), and a deployment switch trivially flippable by editing one `.env`
line (`SubscriptionService.php:109`, `Edition.php:88-91`).

### XXXI.2.2 The complete enumeration of legitimate deployment-time differences

Every row is infrastructure configuration. Not one is business logic. The right-hand column is
the test that it is not.

| # | Difference | SaaS | Private cloud | On-premise | Expressed as | Why it is not business logic |
|---:|---|---|---|---|---|---|
| 1 | Database connection, credentials, pool size, read replicas | managed cluster | customer VPC cluster | single instance | profile → connection config | No domain code names a connection; the connection name is never a literal (the direct fix for `config/backup.php:45-46`, which hard-codes `'mysql'` so an install on any other connection name silently backs up nothing — DEF-L-26) |
| 2 | Cache and atomic-lock store | Redis cluster | Redis | Redis or database | profile → store driver | Lock semantics are identical; only the driver differs. An install whose store cannot hold a lock **fails to boot**, it does not silently degrade (`config/cache.php:18` allows `array`/`file` today, which silently stops `onOneServer()` deduplicating) |
| 3 | Queue backend and worker topology | Redis, N workers per class | Redis, N workers | Redis or database, 1–N workers | profile → queue config + service definitions | Queue *classes* and their semantics are identical everywhere (Part XXV §XXV.12.3); only counts and the backend differ |
| 4 | Session driver and cookie domain | Redis, `*.smartprs.…` | Redis | Redis or database, host domain | profile | — |
| 5 | Document/object storage | object store | object store or NFS | local disk, NAS or object store | storage port | The document service's contract — signed, expiring, authorised URLs (spine S13) — is identical on every driver |
| 6 | Mail transport | platform relay | customer relay | customer SMTP | transport config, per tenant and per company as today | The delivery ledger and its five states are identical |
| 7 | Public base URL, TLS termination, trusted proxies | platform edge | customer edge | customer reverse proxy | profile | — |
| 8 | Tenancy mode | pooled, or silo for a named tenant | pooled or silo | single tenant | profile + `tenants.residency` | The schema, the scoping and the isolation tests are **unchanged** (Law 6). Single-tenant is a population of one, not a different model |
| 9 | Access gate implementation | subscription | subscription or licence | licence | port binding at the composition root | Both implement one interface with one contract; `Domain\*` never names either |
| 10 | Route groups registered | tenant + vendor console + signup + update server | tenant (+ vendor by agreement) | tenant + operations only | route registration by profile role | Registration, not branching inside a handler. An unregistered route is 404 at the router, never an `if` in a controller |
| 11 | Backup destinations, retention, encryption key custody | platform-owned | customer VPC | customer-owned (NAS, S3-compatible, removable media) | backup policy (Part XXVI §XXVI.12.6) | Policy is data; the backup engine is identical |
| 12 | Observability sink | central collector | central or customer collector | local files by default, exportable to a customer SIEM | log/metric exporter config | The log *format*, the metric *names* and the redaction *rules* are identical everywhere (§XXXI.6) |
| 13 | Alert channels and routing | platform on-call rotation | shared | customer-nominated recipients | alert routing policy (§XXXI.6.9) | Which conditions raise an alert is product behaviour and is identical; **who receives it** is configuration |
| 14 | Rule-pack delivery | vendor push on effective date | pull | pull, or side-loaded on media in an air-gapped install (Part XXVI §XXVI.10) | channel config | The pack's signature verification, validation, diff, impact preview and activation are identical |
| 15 | Application update delivery | vendor-applied, canaried | vendor- or customer-applied | customer-pulled and customer-applied | update channel (Part XXVII) | The update *procedure* — verify, compatibility check, backup, migrate, rebuild, restart workers, health check, rollback — is identical |
| 16 | Service manager for scheduler and worker | container orchestrator | systemd | systemd, Supervisor or a Windows service | installation service definitions (Part XXVI §XXVI.12.3) | The jobs, their cadences and their semantics are identical; only the process supervisor differs |
| 17 | Scale parameters: worker counts, connection limits, memory ceilings, per-tenant quotas | tuned per shard | tuned | tuned to the reference architecture | profile + `tenant_quotas` (Part XXV §XXV.12.2) | Numbers, not behaviour |
| 18 | Release-rollout flags | canary cohorts in use | rarely | effectively unused (one install) | flag store (§XXXI.3.6) | A rollout flag governs *when code becomes active*, never *what a customer is entitled to* |
| 19 | Identity provider / SSO connection | per tenant | per tenant | per install | authentication connector config | — |
| 20 | Log retention window and redaction strictness | platform policy | customer policy | customer policy | retention class configuration (spine S16) | The redactor is identical; only the retention duration and the optional stricter profile differ |
| 21 | Vendor support access | tenant-approved, time-boxed grant | same | same, or disabled entirely | support grant (Part XXVI) | No permanent vendor account exists in any model |
| 22 | Telemetry egress to Ametecs | on | on | **opt-in, off by default** | profile flag — see **D-31-03** | Telemetry is diagnostic data flow, not product behaviour; the product functions identically with it off |

**Nothing else may differ.** In particular these do **not** appear in the table and are
identical everywhere: the schema; every migration; the engines (rule, formula, workflow, entity
schema); every calculation; every validation; every authorisation policy; every audit rule;
every statutory rule pack; every default; every API contract; every event; every error code.

- **R-XXXI-001** The artefact deployed on-premise MUST be the same build, with the same
  checksum, as the artefact Ametecs runs in its own cloud. *Test: the published release
  checksum matches the artefact under test in both environments.* *(Mirrors R-XXVI-001.)*
- **R-XXXI-002** Every deployment-time difference MUST appear in the table in §XXXI.2.2. A
  difference not in that table is a defect, and adding a row is an architectural change
  requiring the same review as a schema change. *Test: the profile schema's key set equals the
  table's row set; a profile key with no row fails the build.*
- **R-XXXI-003** Every difference MUST be expressed as a **deployment profile** value validated
  at boot; an invalid or incomplete combination MUST refuse to boot with a named reason and a
  remediation. *Test: the SmartPRS 2 combination that is neither subscription-gated nor
  licence-gated (`I-…` DEF-36) is rejected at boot rather than served.*
- **R-XXXI-004** **No code under `Domain\*` may branch on, read, inject or otherwise observe
  the deployment model, the edition, the licence state or the tenancy mode.** *Test: the
  deployment-parity check in §XXXI.2.4 fails the build; the twenty-one branch sites enumerated
  at `I-…` §10 Q8 have no successors in `Domain\*`.* *(This is the requirement Part XXVI
  §XXVI.2 cites by number.)*
- **R-XXXI-005** Behaviour that genuinely differs MUST be a port with one contract and N
  implementations bound at the composition root. *Test: no `Domain\*` class names a concrete
  implementation of a deployment-varying port.*
- **R-XXXI-006** The golden test matrix MUST run under every supported deployment profile and
  MUST produce identical results. *Test: Part XXX §XXX.4 executed under the `saas` and `onprem`
  profiles yields identical payslip values, byte for byte.*
- **R-XXXI-007** A deployment profile MUST be a **signed, versioned artefact** recorded in the
  installation record, not an editable `.env` line. *Test: editing the deployment model by hand
  invalidates the profile signature and the application refuses to boot — the direct fix for
  `I-…` B9, where flipping `SMARTPRS_DEPLOYMENT=saas` in `.env` removes the on-prem gate
  entirely (rated **Trivial** to exploit).*
- **R-XXXI-008** No configuration value that governs runtime behaviour may be frozen by a
  configuration cache in a way that makes editing it a silent no-op. Every cached configuration
  MUST carry a fingerprint checked at boot, and a drifted fingerprint MUST be surfaced.
  *Test: changing the timezone and restarting produces either the new behaviour or a named
  error — never the old behaviour silently, as `config:cache` does today for `APP_TIMEZONE` and
  `ETIMEOFFICE_ENABLED`, a defect the codebase already knows about and works around in exactly
  one place (`DEF-L-24`; `Edition::current()` reads `.env` directly with a comment explaining
  why).*
- **R-XXXI-009** No destructive or demonstration-only capability may exist in a customer
  artefact. *Test: no command in the release can create an account, wipe a tenant or seed demo
  content — the direct fix for `demo:reset`, scheduled unconditionally at 03:30 on every
  install, which creates an `admin`-role login with the published password `smartprs-demo` and,
  because its user lookup is not tenant-scoped, **takes over** a matching account under another
  tenant (`routes/console.php:208-211`; `DemoReset.php:35, 101-123`; SEC-L-01, CRITICAL).*
- **R-XXXI-010** The deployment profile MUST be visible in the operations console and in the
  diagnostic bundle. *Test: an operator can state which profile is active without shell
  access.*

### XXXI.2.3 The deployment profile

```
deployment_profile            (one signed artefact per installation, versioned)
  profile_key                 saas | private_cloud | onprem
  profile_version, signature, public_key_id
  ports        { access_gate, storage, mail, queue, cache, search, telemetry }
  routes       { tenant: bool, vendor_console: bool, signup: bool, update_server: bool,
                 operations: bool }
  scale        { workers_per_class{}, db_pool, memory_ceiling_mb, request_timeout_s }
  channels     { rule_pack: push|pull|sideload, update: push|pull }
  telemetry    { egress: on|off, sink_url, redaction_profile }
  observability{ log_sink, metric_sink, trace_sink, retention_days }
```

The profile is read **once, at the composition root**, to bind ports and register routes. It is
not available to `Domain\*` at all — not by injection, not by a facade, not by a helper.

### XXXI.2.4 The CI check that enforces it

**The deployment-parity check.** Runs in stage 1 (fast checks) of the per-commit lane defined
in Part XXX §XXX.11.7, so it blocks in under two minutes.

It fails the build when **any** of the following appears anywhere under `Domain\*` (all module
domain namespaces, including engines):

1. A reference to the `DeploymentProfile` type, service, facade or any of its keys — by
   injection, by static call, by container resolution, or by string.
2. A configuration read of any key in the profile's key set, or of `app.env`.
3. Any of the reserved deployment vocabulary as a string or class-constant literal:
   `saas`, `onprem`, `on_prem`, `on-premise`, `private_cloud`, `cloud`, `hosted`, `edition`,
   `l1`, `l2`, `l3`, `basic`, `premium`, `enterprise`, `licence_enforce`, `license_enforce`.
4. A conditional whose subject is a licence state, a subscription state or an edition — these
   are entitlement questions and belong behind the access-gate port (Part VI, Part XXV
   §XXV.6.2).
5. A class-name reference to a concrete implementation of any deployment-varying port.

Three companion assertions run in the same stage:

- **Symmetry.** The profile schema's key set is compared to the §XXXI.2.2 table; a key with no
  row, or a row with no key, fails.
- **Port binding.** Every deployment-varying port has ≥ 1 implementation, every implementation
  is bound in exactly one profile, and no implementation is referenced from `Domain\*`.
- **Parity golden.** The nightly lane runs Part XXX §XXX.4 under each profile and diffs the
  results (Part XXX §XXX.11.7 stage N5, extended to the profile axis).

The check is **allow-list free**: there is no suppression mechanism and no baseline. A genuine
new difference is added to the §XXXI.2.2 table and to the profile schema, which is an
architectural review, not a lint exemption.

---

## XXXI.3 Environments and the release pipeline

### XXXI.3.1 The environment ladder

| Environment | Purpose | Data | Deployment profile | Who deploys | Gate to enter |
|---|---|---|---|---|---|
| **development** | Local work | Seeded fixtures only; **never** customer data | `saas` and `onprem` both runnable locally | developer | — |
| **ci** | Every push and merge | Generated fixtures; deterministic clock (Part XXX §XXX.11.6) | both, per job | pipeline | — |
| **staging** | Integration of a release candidate; the parallel-run diff harness | **Anonymised** production-shaped data (Part XXX §XXX.8.3) | `saas` | pipeline, automatic on green | green per-commit lane |
| **uat** | Customer-facing acceptance of a release candidate; migration rehearsal for a named customer | That customer's data, **anonymised**, restored from a real backup | matches the customer's target profile | pipeline, on request | green nightly lane |
| **production-canary** | First N tenants / first on-prem pilot | Real | as production | pipeline, gated | green nightly lane + signed artefact |
| **production** | All | Real | as production | pipeline, gated | canary soak clean for the declared window |

- **R-XXXI-011** No environment other than development may run on fixture data, and **no
  environment other than production may hold un-anonymised customer data**. *Test: an
  anonymisation assertion runs on every staging and UAT restore and fails the restore if any
  declared PII class survives.*
- **R-XXXI-012** Every environment MUST be created by the same automation from the same
  definitions; a hand-built environment is not a valid test target. *Test: destroying and
  recreating staging from source produces an environment that passes the full nightly lane.*
- **R-XXXI-013** UAT MUST be able to run a **migration rehearsal** against a restored copy of a
  named customer's database before that customer is upgraded, and the rehearsal's timings and
  row counts MUST be recorded. *Test: an on-premise customer's upgrade window is quoted from a
  rehearsal, not estimated.*

### XXXI.3.2 Infrastructure and runtime as code

Everything an environment needs is in the repository, versioned with the application:

```
deploy/
  profiles/            deployment profile definitions (saas, private_cloud, onprem)
  services/            scheduler + worker service definitions per supervisor:
                         systemd/, supervisor/, windows/, container/
  web/                 reverse-proxy configuration templates
  provisioning/        infrastructure definitions for the Ametecs cloud
  observability/       dashboard definitions, alert rules, SLO definitions
  runbooks/            the runbooks in §XXXI.8, as versioned documents
  migrations-policy/   the expand/contract checklist and the migration gate config
```

- **R-XXXI-014** The scheduler and worker service definitions for **every** supported
  supervisor MUST ship inside the release artefact and MUST be installed by the installer.
  *Test: a release artefact contains a runnable service definition for each supported platform
  — the direct fix for DEF-L-27, where every operational file is cited by the documentation and
  absent from the repository.*
- **R-XXXI-015** Dashboards, alert rules and SLO definitions MUST be versioned in the
  repository and applied by the pipeline, never configured by hand in a console. *Test: an
  alert rule deleted in the monitoring system is restored by the next deploy.*
- **R-XXXI-016** Runbooks MUST be versioned with the code they describe, and a runbook step
  that names a command MUST be executed by the runbook drill in the nightly lane where it is
  safely executable. *Test: a command renamed in code fails the runbook drill.*

**Containerisation — SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** Publish, from one build, two
delivery forms of the same artefact:

| Form | For | Contents |
|---|---|---|
| **Container image** (primary) | SaaS, private cloud, and on-premise customers who run containers | app image + a worker entrypoint + a scheduler entrypoint from the **same** image; a compose bundle wiring app, worker, scheduler, MySQL, Redis |
| **Filesystem package** (secondary) | The LAMP-class on-premise estate spine S2 explicitly targets | the same source tree plus the service definitions in `deploy/services/` and the installer |

Both are produced by one pipeline from one commit and carry the same checksum manifest. The
container form removes an entire class of on-premise prerequisite failure (PHP version,
missing `zip` extension — which `config/backup.php:15-17` records as required and which nothing
at runtime checks); the filesystem form remains mandatory because spine S2's rationale for the
monolith is that on-premise customers *"cannot operate a service mesh"* and today *"do not
reliably run a cron worker"*.

- **R-XXXI-017** Worker and scheduler MUST run from the **same image or package** as the web
  process, never from a separately built one. *Test: the running code version reported by every
  process is identical, and is visible in the operations console (Part XXVI R-XXVI-167).*

### XXXI.3.3 The release pipeline

```
commit
  → 1 build          reproducible; one artefact; SBOM; version stamped from the tag
  → 2 test           per-commit lane (Part XXX §XXX.11.7) — blocking
  → 3 security scan  dependency audit, secrets scan, SAST, container CVE scan, licence scan
  → 4 sign           detached signature with the release key; the private key never
                     leaves Ametecs' KMS/HSM (spine S12, S18)
  → 5 stage          deploy to staging; smoke suite; parallel-run diff on the reference dataset
  → 6 uat            on request, per customer, with a migration rehearsal
  → 7 canary         a declared cohort; soak for the declared window against the SLOs
  → 8 promote        full production; then publish to the on-premise update channel
                     (Part XXVII), which customers pull
```

| Stage | Fails the release when | Evidence it exists because of |
|---|---|---|
| 1 build | The build is not reproducible from the tag; the SBOM cannot be generated; the version in the artefact does not match the tag | `R-56`: *"the pipeline MUST fail on a version mismatch or an unsigned artefact"* |
| 2 test | Any blocking gate in Part XXX §XXX.2.6 is red | zero tests exist today for the scheduler, either job, or any of the eighteen console commands (DEF-L-34) |
| 3 scan | A dependency with a known critical advisory; any secret matched; a new SAST finding above the declared severity | no scanning of any kind exists today |
| 4 sign | The signing key is unavailable, or the artefact is modified after signing | SmartPRS 2's second licensing path is **symmetric with the key shipped to every customer** (`I-…` Q1(B)); no application artefact is signed |
| 5 stage | The smoke suite fails, or the parallel-run diff shows an unexplained money difference | — |
| 7 canary | Any SLO burn-rate alert fires, or any error-class rate exceeds the declared multiple of baseline | — |

- **R-XXXI-018** A release MUST NOT be publishable without a green nightly lane on the release
  commit and a valid signature. *Test: an unsigned or red artefact is rejected by the update
  channel.*
- **R-XXXI-019** Every deployment MUST record: artefact version and checksum, profile version,
  migration set applied, actor, start and end time, health-check result, and rollback point.
  *Test: "what changed on the day this broke?" is answerable from the deployment record alone.*
- **R-XXXI-020** Every deployment MUST **restart the workers** and MUST verify that no worker
  is running pre-deployment code before it is declared complete. *Test: after a deploy, every
  worker reports the new version — the direct fix for the absence of `queue:restart` anywhere
  in SmartPRS 2 (`L-…` requirement 65; `I-…` DEF-27).*
- **R-XXXI-021** A canary cohort MUST be declared configuration, MUST exclude any tenant inside
  a payroll lock or a statutory filing window, and MUST be revertible without a code change.
  *Test: a tenant with an open payroll run is never in the canary. See **D-31-04**.*
- **R-XXXI-022** Rollback MUST be a first-class, rehearsed operation with its own runbook
  (§XXXI.8.9), covering code **and** database, and it MUST be exercised in the nightly lane.
  *Test: a rollback drill restores service within the declared RTO.*
- **R-XXXI-023** Maintenance mode MUST be granular: a whole installation, one tenant, or one
  capability. It MUST present a customer-comprehensible message with an expected end time, and
  it MUST NOT strand in-flight background work. *Test: entering maintenance during a payroll
  run pauses the run at a stage boundary and resumes it on exit.*

### XXXI.3.4 Database migration policy

The single most damaging schema behaviour in SmartPRS 2 is that **the schema depends on which
screens were opened**: 72 `Schema::create` and six raw `ALTER TABLE` calls sit on request paths
(`J-…` §6.5), nine tables and eleven columns in the background area alone are created at
runtime, and every one of those call sites swallows its exception (`L-…` §2, DEF-L-08). On a
correctly hardened database the product *"degrades feature by feature with no error"* — which
is how overtime comes to be paid twice.

The pipeline policy that makes Law 2 operational:

| Rule | Statement |
|---|---|
| **Expand / contract** | Every schema change is split into an **expand** migration (additive, backward-compatible, deployed with or before the code that uses it) and a **contract** migration (destructive, deployed only after the release that stopped using the old shape is fully promoted). The two never ship in the same release. |
| **Backward compatibility window** | The schema must serve the current release **and** the immediately previous one, for the full canary and promotion window. |
| **No destructive DDL in an expand migration** | No `DROP`, no narrowing type change, no `NOT NULL` on a column without a backfilled default, no rename — a rename is add + backfill + dual-write + contract. |
| **Backfills are jobs, not migrations** | A backfill that touches more than a declared row count runs as a resumable, observable, throttled queued job with progress, never inside a migration. *(The two SBB dedupe migrations run an unbounded in-PHP algorithm on **every** `php artisan migrate` — i.e. during every deploy — `M-…` PERF-6.)* |
| **Transactional where the engine allows** | Migrations run inside a transaction on engines that support transactional DDL; where MySQL cannot, the migration set is idempotent and restartable, and the runner records progress per migration. |
| **Locks are budgeted** | Every migration declares an expected lock duration and row count from the UAT rehearsal. A migration exceeding its budget in production aborts and alerts rather than holding a metadata lock on a hot table. *(`ALTER TABLE payslips ADD calc_note` on a 360,000-row table rebuilds it and blocks every query on `payslips` for the duration — `M-…` PERF-3.)* |
| **Privilege separation** | Migrations run as a migration user; the application user holds no `CREATE`, `ALTER` or `DROP` (spine Law 2; asserted by Part XXVI health check 4). |

- **R-XXXI-024** Every migration MUST be classified `expand` or `contract`, and a release MUST
  NOT contain a contract migration for a shape its immediately preceding release still uses.
  *Test: the pipeline rejects the pairing.*
- **R-XXXI-025** No migration may execute unbounded data movement. *Test: a migration touching
  more than the declared threshold fails the build and must be re-expressed as a job.*
- **R-XXXI-026** A deployment MUST verify the schema fingerprint after migrating and MUST fail
  the deployment on a mismatch. *Test: a half-applied migration set is detected and the
  deployment does not complete — the direct fix for `I-…` DEF-25.*
- **R-XXXI-027** `migrate:fresh` on an empty database MUST reproduce the production schema
  exactly. *Test: schema equivalence, Part XXX §XXX.8.1.*

### XXXI.3.5 Feature flags versus configuration versus entitlement

Three mechanisms, routinely conflated, with different owners and different lifetimes. Getting
this wrong is how a deployment concern becomes a pricing decision.

| | **Release flag** | **Configuration** | **Entitlement** |
|---|---|---|---|
| Question it answers | *Is this code active in this deployment yet?* | *How does this customer want the product to behave?* | *Has this customer bought this?* |
| Owner | Engineering | Tenant administrator | Commercial (Ametecs) |
| Surface | Never customer-visible | Tenant configuration screens | Plan / licence |
| Lifetime | **Bounded** — removed with the release that completes the rollout | Permanent | Contract term |
| Where it lives | Deployment profile + flag store, per environment and cohort | Effective-dated configuration artefacts (spine S4) | `entitlement_sets` (Part XXV §XXV.6.2) / licence (Part XXVII) |
| Effective-dated? | No | **Yes** — spine S4 | Yes |
| Audited? | Deployment record | Configuration audit, with diff and approval | Commercial audit |
| Reaches `Domain\*`? | **Never** | Yes, as resolved configuration | Only through the access-gate port |

**The rule, absolute: a feature flag is a deployment concern and is never a customer-facing
capability switch.** If a customer could ask for it to be on or off, it is not a flag — it is
configuration (spine Law 1) or entitlement (Part VI, Part XXV). A flag whose value differs
between two production tenants for a commercial reason is a defect.

- **R-XXXI-028** Every release flag MUST declare an owner and a **removal date**, and the build
  MUST fail on a flag past its removal date. *Test: an expired flag blocks the release.*
- **R-XXXI-029** No release flag may be readable from `Domain\*`. *Test: the
  deployment-parity check of §XXXI.2.4 covers the flag store.*
- **R-XXXI-030** No release flag may gate a capability a customer purchases, and no entitlement
  may be implemented as a release flag. *Test: a review assertion — every flag maps to a
  release, every purchasable capability maps to an entitlement key; the two sets are disjoint.*
- **R-XXXI-031** Flag state MUST be recorded in the deployment record and visible in the
  operations console, so "was this code active when the incident happened?" is answerable.
  *Test: the incident record can state the flag state at the incident time.*

---

## XXXI.4 Background processing as a first-class, verified dependency

Spine S2 states it: *"Queue and scheduler are mandatory, not optional. The installer refuses to
complete without a working scheduler and queue worker, and the health check fails loudly and
visibly if either stops. SmartPRS 2's most damaging operational failures come from customers
running neither."*

The registry tables (`scheduled_jobs`, `scheduled_job_runs`) and the on-premise service
installation are specified in Part XXVI §XXVI.9.1 and §XXVI.12.4 and are not restated. This
section specifies **the catalogue, the execution contract, the queue topology and the
work-placement rule** — for both deployment models.

### XXXI.4.1 The schedule catalogue

Every entry is a row in `scheduled_jobs`, seeded as a shipped default and editable as
configuration. `Scope` is `platform` (once per installation) or `tenant` (iterated with
per-tenant fault isolation). `Cursor` states what the job resumes from — see §XXXI.4.2.

| # | `job_key` | Purpose | Default cadence | Scope | Isolation unit | Cursor | Lock TTL | Missed-run policy |
|---:|---|---|---|---|---|---|---|---|
| 1 | `attendance.ingest.pull` | Pull punches from every enabled pull-adapter device/connector | every 5 min | tenant | device | **watermark** `last_successful_event_at` | 4 min | catch up to `max_lookback` |
| 2 | `attendance.ingest.retry` | Re-attempt quarantined and failed ingest batches | every 15 min | tenant | batch | batch id | 10 min | catch up |
| 3 | `attendance.day.derive` | Derive `attendance_days` for dates whose inputs changed | every 15 min + nightly sweep | tenant | employee-day | generation number | 12 min | catch up |
| 4 | `attendance.staleness.check` | Raise integration-staleness conditions per device policy | every 15 min | tenant | device | — | 5 min | skip |
| 5 | `leave.accrual.run` | Accrue entitlement per the accrual policy effective on the date | daily, tenant-local | tenant | employee | last accrued date | 2 h | catch up per date |
| 6 | `leave.expiry.lapse` | Lapse and carry-forward per policy | daily, tenant-local | tenant | employee | last processed date | 2 h | catch up per date |
| 7 | `employment.effective.apply` | Apply transfers, increments, confirmations, stage changes due today | daily, tenant-local, before first shift | tenant | assignment | last applied date | 1 h | catch up per date |
| 8 | `employment.probation.milestones` | Probation reminder ladder and overdue nudges | daily, tenant-local | tenant | employee | ledger key | 1 h | **at-or-past**, deduped |
| 9 | `workflow.sla.escalate` | SLA breach, reminder and escalation for open workflow steps | every 15 min | tenant | instance | instance id | 10 min | catch up |
| 10 | `compliance.expiry.scan` | DRA / PCC / BGV / document expiry digests | daily, tenant-local | tenant | tenant | ledger key | 1 h | at-or-past, deduped |
| 11 | `compliance.filing.due.scan` | Statutory filing windows and due-date items | daily, tenant-local | tenant | filing obligation | ledger key | 1 h | at-or-past, deduped |
| 12 | `payroll.period.reminders` | Cut-off, input-freeze and approval reminders | daily, tenant-local | tenant | run | ledger key | 1 h | at-or-past |
| 13 | `payroll.reconcile.audit` | Assert period integrity: no day paid twice, no day never paid | weekly + after every run close | tenant | period | period id | 2 h | catch up |
| 14 | `statutory.rulepack.check` | Check for, verify and stage new signed rule packs | daily | platform | jurisdiction | pack version | 30 min | catch up |
| 15 | `notification.dispatch` | Build and enqueue scheduled digests | hourly, tenant-local gate | tenant | audience | ledger key | 30 min | at-or-past |
| 16 | `notification.retry.sweep` | Re-attempt transient delivery failures within their expiry | every 10 min | platform | message | message id | 8 min | catch up |
| 17 | `webhook.redeliver` | Re-attempt failed webhook deliveries with backoff | every 5 min | platform | delivery | delivery id | 4 min | catch up |
| 18 | `device.health.evaluate` | Derive device health status and consecutive-failure counts | every 15 min | tenant | device | — | 10 min | skip |
| 19 | `subscription.lifecycle` | Renewal reminders, grace, dunning, terminal action *(SaaS profile)* | daily | platform | tenant | ledger key | 1 h | at-or-past, deduped |
| 20 | `licence.expiry.check` | Licence countdown and expiry conditions *(on-prem profile)* | daily | platform | installation | — | 15 min | skip |
| 21 | `backup.run` | Execute the backup policy | per backup policy | platform | set | — | policy window | alert if missed |
| 22 | `backup.verify` | Verify the newest archive (Part XXVI §XXVI.8.5) | daily | platform | archive | — | 1 h | catch up |
| 23 | `backup.restore.drill` | Scheduled restore-to-scratch drill (Part XXVI §XXVI.8.7) | per policy | platform | drill | — | drill window | alert if missed |
| 24 | `audit.chain.anchor` | Write the daily signed per-tenant chain anchor (spine S14) | daily | tenant | tenant | last anchored date | 1 h | **catch up per date** |
| 25 | `retention.enforce` | Archive, anonymise and purge per retention class (spine S16) | daily, off-peak | tenant | class | last processed date | 4 h | catch up |
| 26 | `ops.log.prune` | Prune every log-shaped table to its retention class | daily, off-peak | platform | table | — | 2 h | catch up |
| 27 | `ops.health.probe` | Run the health-check catalogue and persist results | every 1 min | platform | check | — | 50 s | skip |
| 28 | `ops.metrics.rollup` | Roll raw metric samples into the retained series | every 5 min | platform | series | last rolled-up bucket | 4 min | catch up |
| 29 | `ops.alert.evaluate` | Evaluate alert rules against signals; open, dedupe and close conditions | every 1 min | platform | rule | — | 50 s | skip |

**Deliberate differences from SmartPRS 2's twelve entries.** `demo:reset` does not exist in a
customer artefact at all (R-XXXI-009). `billing:renew`, `app:clear-state`,
`attendance:purge-demo`, `attendance:dedupe` and `invoices:renumber-prs` are removed (`L-…` §9).
`payroll:period-audit` — *"the only command that returns FAILURE on a data condition"* and
which today is **not on the schedule at all** (`PayrollPeriodAudit.php:127-134`; DEF-L-33) — is
promoted to entry 13 with failure alerting, making it a real financial control.

### XXXI.4.2 The execution contract

Every scheduled job, without exception, obeys all nine clauses.

| # | Clause | Requirement | The SmartPRS 2 failure it removes |
|---:|---|---|---|
| 1 | **Overlap protection** | A per-job lease with a declared TTL **proportional to the cadence**, renewed by heartbeat while the job runs, released on exit, and expiring on a crash | `withoutOverlapping()` with no argument on all twelve jobs → Laravel's 24-hour default lock on a five-minute task; a crash blocks it for a day and the recovery command is undocumented (DEF-L-02) |
| 2 | **Tenant awareness** | A `tenant`-scoped job resolves its tenant set from the registry, not from an unscoped table query | `transfers:apply` queries `transfers` with no tenant scope at all (`L-…` §Q1 row 6); `DemoReset.php:106` looks up users with no tenant scope and takes over accounts (SEC-L-01) |
| 3 | **Per-tenant local time** | The gate is the **tenant's** local time from its own timezone, resolved at evaluation, never a single global value | all twelve `dailyAt()` calls evaluate against one `config('app.timezone')` frozen by `config:cache`; an install omitting the line runs the whole schedule 5 h 30 m off and the day-boundary jobs target the wrong day (`L-…` §Q1; DEF-L-24) |
| 4 | **Fault isolation** | Failure is contained at the **declared isolation unit**. One tenant's failure is recorded, alerted and skipped; the sweep continues | `ComplianceController::notify()` has no try/catch, so tenant 3 of 20 aborts tenants 4–20 (DEF-L-04); `AbsenceService` and `GreetingService` wrap the **entire** tenant loop in one catch and exit SUCCESS with an under-count (DEF-L-03) |
| 5 | **Honest outcome** | A run reports `succeeded` only when `items_failed = 0`. Partial success is `partial` and is a first-class, alertable state | both services above exit SUCCESS having abandoned most tenants |
| 6 | **High-water-mark cursoring** | A job resumes from a **durable cursor** — a watermark instant, a date, a generation, or a ledger key — never a fixed relative window. A cursor advances only on a **verified** success | `--days=1` hard-coded in the schedule line: an outage > 24 h loses punches permanently (`routes/console.php:225`; DEF-L-01, CRITICAL) |
| 7 | **Bounded catch-up** | Catch-up is bounded by `max_lookback` and by a per-run item budget, so a long outage recovers in successive runs rather than one unbounded run | a drained backlog today delivers months of stale notifications with no age check (`L-…` §Q9 D) |
| 8 | **At-or-past milestones** | A reminder ladder fires on the **first run at or past** the threshold, deduplicated on a ledger key — never on exact day equality | `in_array($days, [15,7,3,1,0], true)` and `[30,15,7,0]`: a missed run loses the reminder **permanently**, including the last warning before suspension and the "completes today" probation reminder (DEF-L-05, four call sites) |
| 9 | **No blocking external call** | A scheduled job never makes a synchronous external call while holding a lease; it enqueues to the `integration` or `notification` class | `WaService.php:146` is called inline, per tenant, with a 15 s timeout, inside `billing:alerts` while holding a 24-hour lock — 200 due tenants is up to 50 minutes of a single held lock (DEF-L-21) |

- **R-XXXI-035** The scheduler MUST be a declared, installed and **verified** dependency. Its
  absence MUST be detected by the application within the declared detection window and surfaced
  as a loud degradation. *Test: stopping the scheduler produces the heartbeat failure, the
  banner and the alert — the direct fix for the failure `L-…` §Q4 names as one of *"the two
  that hurt most … both leave the product looking entirely healthy"*.*
- **R-XXXI-036** Every scheduled job MUST write a run row with `started_at`, `finished_at`,
  `status`, `items_attempted`, `items_succeeded`, `items_failed` and `error_summary`, and the
  heartbeat MUST be derived from those rows, not from a process being alive. *Test: the
  heartbeat goes stale when jobs stop completing, even if the process is up.*
- **R-XXXI-037** A job's overlap-lease TTL MUST be proportional to its cadence, MUST be renewed
  by heartbeat, and MUST expire without operator intervention after a crash. *Test: a job
  killed with `SIGKILL` runs again at its next cadence, not a day later.*
- **R-XXXI-038** Per-item fault isolation MUST be a **framework guarantee**, not a per-job
  choice. *Test: a fault injected into tenant 3 of 20 leaves tenants 4–20 processed, one failed
  item recorded and one alert raised.*
- **R-XXXI-039** A job MUST NOT report success when `items_failed > 0`. *Test: a partial run is
  `partial`, is alertable, and is visible in the console.*
- **R-XXXI-040** Every `tenant`-scoped job MUST evaluate its schedule gate in the **tenant's**
  timezone. *Test: two tenants in different zones receive their 09:00 notifications at their own
  09:00.*
- **R-XXXI-041** Every job MUST declare a `max_runtime`; exceeding it MUST abort the run,
  release the lease, record `timed_out` and alert. *Test: a hung job does not block its
  successor.*
- **R-XXXI-042** No scheduled job may perform DDL. *Test: the CI check for DDL outside
  migrations covers job code — the direct fix for `ProbationService::ensureSchema()`,
  `SubscriptionService::ensureAlertTable()`, `MailService::ensureTable()` and
  `WaService::ensureLog()`, each executed on **every** scheduled run and each wrapped in a
  swallowing catch (DEF-L-08).*
- **R-XXXI-043** No destructive job may be schedulable without an environment allow-list **and**
  a per-install opt-in. *Test: no shipped default schedules a destructive job.*
- **R-XXXI-044** **Every recurring ingestion or sweep MUST cursor on a durable high-water mark
  with a configurable `max_lookback`, never a fixed relative window.** The cursor MUST advance
  only after the items it covers are durably persisted. *Test: with ingestion stopped for 72
  hours and the device still retaining data, restarting recovers every punch — the direct fix
  for DEF-L-01. The value of `max_lookback` is **D-31-07**.*
- **R-XXXI-045** Reminder ladders MUST evaluate **at or past** the threshold with ledger-key
  deduplication. *Test: skipping the run on the 7-day milestone still sends the 7-day reminder
  on the 6th day, once.*
- **R-XXXI-046** A scheduled job MUST NOT make a synchronous external call while holding a
  lease. *Test: a static check; and a fault-injected 30 s external stall does not extend any
  lease.*

### XXXI.4.3 Queue topology

The five queue classes and their fairness properties are specified in Part XXV §XXV.12.3
(R-XXV-127 … R-XXV-131) and apply in **every** deployment model, single-tenant included — the
classes exist to separate work *shapes*, not only tenants. This section adds the retry, backoff
and dead-letter contract that Part XXV does not specify.

| Class | Latency target | Priority | Default attempts | Backoff | Expiry (`retryUntil`) | Dead-letter behaviour |
|---|---|---:|---:|---|---|---|
| `interactive` | seconds | 1 | 3 | 5 s, 15 s | 5 min | DLQ + user-visible failure on the originating surface |
| `notification` | seconds–minutes | 2 | 5 | 30 s, 2 m, 10 m, 30 m | message-type specific (a renewal warning expires at its own deadline) | DLQ + delivery ledger `failed` + operator condition |
| `integration` | minutes | 3 | 5 | 1 m, 5 m, 15 m, 60 m (jittered) | per connector policy | DLQ + integration health degraded + condition |
| `bulk` | minutes | 4 | 2 | 2 m | run-scoped | DLQ + the run's own failure state; **restartable at its last completed stage** |
| `maintenance` | hours | 5 | 3 | 15 m, 60 m | next window | DLQ + condition |

Rules:

- **A dead-letter is a durable, operator-visible record**, not a row nobody reads. It carries
  tenant, class, job type, payload reference, every attempt with its error, the correlation id,
  and a **replay** action that is idempotent.
- **No payload carries file bytes or secrets.** Attachments are stored and referenced by id
  (the direct fix for `attach_b64` serialised into `jobs.payload`, SEC-L-07). Payloads carry
  identifiers, never rendered personnel content.
- **Every job is idempotent** under at-least-once delivery, keyed on a declared idempotency key,
  because a worker can die after doing the work and before acknowledging.
- **Every job implements a failure handler**, and a terminal failure emits an event, writes the
  dead-letter record and raises a condition. There is no path by which a job disappears.
- **Poison-message protection.** A job failing at the same stage on N consecutive attempts is
  dead-lettered immediately rather than retried to exhaustion.

- **R-XXXI-047** Every job MUST declare its queue class, its attempts, its backoff, its expiry
  and its idempotency key. A job declaring none MUST fail the build. *Test: a static check —
  the direct fix for the single queue literally named `mail` shared by the product's only two
  job classes (`SendNotification.php:41`; `NotifyLateArrivals.php:43`).*
- **R-XXXI-048** Every job MUST implement a failure handler, and a terminal failure MUST be
  visible in an operator surface with its tenant, class and error, and MUST be replayable.
  *Test: `failed_jobs` has a reader, an alert and a replay — SmartPRS 2 has none of the three,
  and no `Queue::failing()` listener exists anywhere (DEF-L-23).*
- **R-XXXI-049** A job MUST NOT swallow an exception it declares a retry policy for. *Test:
  a fault-injected job reaches the dead-letter queue — the direct fix for `NotifyLateArrivals`,
  whose `handle()` swallows every `\Throwable`, making its `$tries = 2` and `$backoff = 30`
  dead configuration and losing the notification on the first transient failure (DEF-L-10).*
- **R-XXXI-050** Every time-sensitive job MUST declare an expiry so a drained backlog cannot
  deliver stale work. *Test: a worker restarted after a week sends no last-week reminders.*
- **R-XXXI-051** The queue and dead-letter tables MUST have a scheduled retention policy.
  *Test: `ops.log.prune` bounds them — SmartPRS 2 prunes none of its six log-shaped tables and
  does not schedule `activitylog:clean` at all (DEF-L-29).*
- **R-XXXI-052** Queue depth, oldest-pending-job age, per-class throughput, failure rate and
  worker liveness MUST be emitted as metrics (§XXXI.6.5) and asserted by health checks 8–10
  (Part XXVI §XXVI.6.1). *Test: a stopped worker is visible within the declared detection
  window.*

### XXXI.4.4 No long-running work inside an HTTP request

SmartPRS 2 runs *"every report, export, PDF, payroll run and payment-gateway call"* inside the
HTTP request (`M-…` §6.3), and `L-…` §Q2 enumerates **twenty** synchronous long operations on
request paths — including a **900-second** update download holding a PHP-FPM worker
(`UpdateClient.php:225`), five payment-gateway calls with **no timeout at all**
(`SignupController.php:224, 799, 819, 867`; `BillingController.php:593`;
`TenantBillingController.php:339`; `OnpremClientController.php:600`), an employee import that
parses, validates and commits every row in-request, and `Audit::verify()` reading every row of
every tenant into memory whenever an administrator opens Activity Logs (`Audit.php:111`).

**The rule.** A request handler does three things: authorise, validate, and either return
bounded data or enqueue work and return a job handle. Anything else is a defect.

| Operation | Where it runs in SmartPRS 3 | What the user sees |
|---|---|---|
| Payroll run, recalculation, arrears | `bulk`, staged and restartable | a run with per-stage progress and per-employee counts |
| Any export, report or PDF over the declared inline threshold | `bulk`, streamed to storage | a job handle, then a signed expiring download link |
| Any import | `bulk`, staged → validated → committed | a staging report before commit, then progress |
| Any outbound integration call | `integration` | the integration's own health surface |
| Any message | `notification` | the delivery ledger |
| Retention, archival, anonymisation, purge | `maintenance` | the retention log |

- **R-XXXI-053** No request handler may exceed the declared request time budget, make an
  outbound integration call, or return an unbounded result set. *Test: gate G-12 and the
  query-count budgets of Part XXX §XXX.9.3; a check asserts no outbound HTTP call occurs in a
  request path.*
- **R-XXXI-054** Every long operation MUST be a queued, resumable, observable job with
  progress, cancellable by an authorised actor, and MUST NOT be triggerable twice for the same
  logical work by a double click. *Test: a second submission returns the in-flight job handle —
  the direct fix for the payroll timeout that leads the user to re-click and produce a
  duplicate run (`M-…` PERF-3).*

---

## XXXI.5 The no-silent-failure rule

### XXXI.5.1 The contract

> **A catch block must do exactly one of three things: rethrow (possibly wrapped), handle the
> condition completely and record it, or be a declared, typed, justified and logged
> suppression. There is no fourth option, and the build enforces it.**

Six clauses:

1. **No empty catch in a domain path, ever.** Not with a comment, not "fail-soft", not
   "best-effort". `Domain\*`, `Application\*` and every job, command and engine are domain
   paths.
2. **No catch of a supertype without re-narrowing.** `catch (\Throwable)` is permitted only at
   a declared boundary (the top-level handler, a job's failure handler, a per-item isolation
   frame) — never as a general-purpose "ignore".
3. **Every suppression is declared.** A suppression carries an attribute naming the exception
   type, the business reason, the recovery, and the owner. It logs at the declared level with
   structured context. It is counted as a metric. Anything not so declared fails the build.
4. **A failed job is visible.** Terminal failure → dead-letter record → event → condition →
   alert. Nothing disappears.
5. **A subsystem that cannot complete its work degrades visibly and loudly** — a persistent,
   non-dismissible banner for administrators, a degraded health status, a console condition and
   an alert. The behavioural table is Part XXVI §XXVI.9.3.
6. **No control whose purpose is a legal, contractual or safety obligation may be best-effort.**
   Audit writes, privacy wipes, statutory calculations, entitlement checks and authorisation
   decisions either succeed or fail the operation. *(`Audit.php:41-43` and `:97-99` wrap the
   audit ledger's own DDL and its inserts in swallowing catches with the comment "auditing is
   best-effort; never block the caller" — so the tamper-evident ledger silently loses rows and
   silently loses its chain **while the UI still reports "chain VERIFIED"** — SEC-L-15.)*

### XXXI.5.2 The exception taxonomy and its surfacing behaviour

Seven classes. Every exception in SmartPRS 3 is one of them; the class determines the HTTP
status, the log level, the retry behaviour, the alert behaviour and the user message.

| Class | Meaning | Retryable | HTTP | Log level | Metric | Alert | User sees | Job behaviour |
|---|---|---|---|---|---|---|---|---|
| **ValidationFailure** | The input is not acceptable | no | 422 | `info` | counter by field | no | The field-level message, from the validation definition | fail fast, no retry |
| **AuthorisationDenied** | Actor may not perform the verb, or not on this record | no | 403 | `notice` + audit | counter by policy | on anomalous rate | "You do not have permission", with a reference id | fail fast |
| **BusinessRuleViolation** | The domain forbids it: period locked, negative net, minimum-wage breach, seat cap, statutory floor | no | 409 | `notice` | counter by rule code | no (except gate breaches) | The rule's own business message **and its trace** (Law 4) | fail fast, surfaced on the run's exception list |
| **ConfigurationError** | Resolvable configuration is missing, ambiguous or invalid: no structure, no policy, ambiguous precedence, no mail transport | no | 409 / 503 | `error` | counter by artefact type | **yes** — operator | "This cannot proceed because …", naming the artefact and linking to it | fail; item recorded as `blocked` |
| **IntegrationFailure** | An external dependency failed: device, gateway, SMTP, storage, rule-pack server | **yes** | 502 / 503 | `warning`, `error` on exhaustion | counter + latency by endpoint | on threshold or consecutive failures | "Temporarily unavailable", with the integration's health surface | retry with backoff, then dead-letter |
| **TransientInfrastructureFailure** | Database deadlock, lock timeout, cache unavailable, queue backend blip | **yes** | 503 | `warning`, `error` on exhaustion | counter by kind | on rate | Retry-After | retry with backoff, then dead-letter |
| **InvariantViolation** | The system is in a state it declares impossible: reconciliation mismatch, broken audit chain, trace-less engine result, checksum failure | no | 500 | `critical` | counter | **yes, immediately** | A reference id and nothing else | **abort the containing operation and roll it back**; never proceed |

Cross-cutting rules:

- **Never leak internals.** No raw exception message, SQL, stack trace or internal identifier
  reaches an HTTP client, an email or a customer-visible screen. Every client-facing error is a
  machine-readable code plus a human message from the message catalogue plus a reference id
  that ties to the log by correlation id. *(SmartPRS 2 sends a raw `$e->getMessage()` to the
  client at **194 sites** and has **four incompatible error-envelope shapes** — `L-…` items
  45–46.)*
- **One envelope**, defined in Part XXIV, for the whole API.
- **Wrap, don't re-throw naked.** A lower-layer exception crossing a module boundary is wrapped
  in the module's own typed exception, preserving the cause chain.
- **A fail-open is never a class.** Authorisation, entitlement and licence checks have no
  transient-failure fall-through to "allowed": a `TransientInfrastructureFailure` inside a gate
  produces 503, is counted and is alerted — it never produces access. *(SmartPRS 2 has **five**
  deliberate fail-opens on authorisation and entitlement, each with a comment saying so:
  `LicenseGate.php:73-75`, `EditionGuard.php:55-57`, `DemoWriteGuard.php:73-75`,
  `EnsureSubscriptionActive.php:58-59`, and `SubscriptionService.php:116-118` whose comment
  reads `// fail-soft → unlimited` — SEC-L-14.)*
- **Every SPX error value is a `BusinessRuleViolation` or a `ConfigurationError`**, surfaced as
  a rule-evaluation failure with its trace, never swallowed (spine S5.6).

### XXXI.5.3 The declared suppression

The only legal way to not handle an exception:

```php
#[SuppressedFailure(
    type:   \League\Csv\SyntaxError::class,   // exactly the type, never a supertype
    reason: 'A malformed optional trailing column is skipped; the row is still ingested '
          . 'and the skip is counted on the import report.',
    surface:'import_report.skipped_columns',  // where a human can see the count
    level:  'info',
    owner:  'attendance',
)]
```

The attribute is **not decoration**: the static check verifies that the annotated block logs at
the declared level with structured context, that it increments the declared metric, and that
the named surface exists. A suppression whose surface does not exist fails the build.

### XXXI.5.4 Enforcement

| Check | Runs in | Fails on |
|---|---|---|
| **Empty catch** | fast checks | any catch with an empty or comment-only body, anywhere in the application |
| **Silent catch in a domain path** | fast checks | a catch under `Domain\*`, `Application\*`, `Jobs\*`, `Console\*` or `Engine\*` that neither rethrows, nor logs with structured context, nor carries a valid `#[SuppressedFailure]` |
| **Supertype catch** | fast checks | `catch (\Throwable\|\Exception)` outside the declared boundary allow-list |
| **Fail-open in a gate** | fast checks | any catch inside an authorisation, entitlement, licence or tenancy path that returns a permissive value |
| **Raw message egress** | fast checks + taint analysis | `$e->getMessage()` reaching a response, a view, a mail body or a webhook payload |
| **DDL outside migrations** | fast checks | `Schema::create`, `Schema::table`, `ALTER TABLE`, `CREATE TABLE` outside `database/migrations` (Law 2) |
| **Fault-injection suite** | per-commit lane | an injected fault inside authorisation, a gate, the audit writer, or a per-item isolation frame that does **not** produce a denial, a failure and a signal |

**There is no baseline and no suppression file for these checks.** Part XXX §XXX.11.8 permits a
ratcheting baseline for general static analysis; the seven checks above are exempt from it,
because a baseline is exactly how 426 silent catches accumulate.

- **R-XXXI-055** No catch block in the application may be empty. *Test: the empty-catch check;
  the 332 empty blocks in SmartPRS 2 have no successors.*
- **R-XXXI-056** Every catch in a domain path MUST rethrow, handle-and-record, or carry a valid
  `#[SuppressedFailure]`. *Test: the silent-catch check — the direct fix for the 21 of 23 fully
  silent catches inside `app/Console/Commands/`, none of which logs anything.*
- **R-XXXI-057** No authorisation, entitlement, licence or tenancy control may fail open, and
  every failure of such a control MUST be counted and alerted. *Test: fault injection into each
  of the five SmartPRS 2 fail-open sites' successors produces denial + alert (SEC-L-14).*
- **R-XXXI-058** No control implementing a legal or contractual obligation may be best-effort.
  *Test: an injected failure in the audit writer aborts the business operation rather than
  completing it unaudited (SEC-L-15); an injected failure in a privacy wipe raises a condition
  rather than returning "nothing due" (SEC-L-05).*
- **R-XXXI-059** No raw exception text may reach any external surface, and there MUST be exactly
  one error envelope. *Test: the taint check plus a contract test over every endpoint.*

---

## XXXI.6 Observability *(brief Section 93)*

> This section is the specification Parts XXV and XXVI defer to: metric names and emission
> (`§XXXI.6` in R-XXV-129, R-XXV-132, R-XXVI-… and the on-premise console), the health-endpoint
> contract (`§XXXI.6.4`), and the redactor (`R-XXXI-062`, cited by R-XXVI-102).

### XXXI.6.1 Structured logging, correlation and propagation

Every log line is a structured event. There is no free-text logging.

```
timestamp_utc, level, event                     -- event is a stable dotted key, never prose
correlation_id                                  -- one business operation, end to end
request_id                                      -- one HTTP request or one job execution
parent_id                                       -- the id that caused this unit of work
trace_id, span_id                               -- when tracing is enabled
deployment_profile, installation_id, app_version
tenant_id, actor_id, actor_type, on_behalf_of   -- actor omitted on system work
module, component
subject_type, subject_id                        -- UUID public ids only, never a sequential id
outcome        success | failure | partial | suppressed
duration_ms, attempt, queue_class, job_key
error_class, error_code                         -- the taxonomy class of §XXXI.5.2
context{}                                       -- typed, redacted (§XXXI.6.3)
```

**Propagation.** A `correlation_id` is minted at the first entry point of a business operation
— an HTTP request without an inbound id, a scheduled job run, a webhook receipt — and then
travels: into the log context; into every job the request enqueues; into every outbound HTTP
call as a header; into the webhook signature envelope; into the audit ledger's
`correlation_id`/`request_id` columns (spine S14); and into the explainability trace (spine
S15). One identifier retrieves every log line, every span, every audit entry and every trace
for one operation.

`request_id` is per execution unit — a retry of a job has a new `request_id` and the same
`correlation_id` — which is how "did this run three times?" is answerable.

- **R-XXXI-060** Every log line, every audit entry, every job execution and every outbound call
  MUST carry `correlation_id`, `request_id`, `tenant_id` and, where an actor exists, `actor_id`.
  *Test: a generated check asserts no log line is emitted outside the context binder; one
  identifier retrieves the complete history of one operation — SmartPRS 2 has no correlation id
  at all and its `withExceptions` closure is empty, so every log line is an orphan (`L-…` §Q7).*
- **R-XXXI-061** Authentication and authorisation events — login success, login failure,
  lockout, password change, MFA change, role change, permission denial, session invalidation,
  impersonation and support-grant use — MUST be logged **and** audited. *Test: each event
  produces both records — `AuthController` contains **zero** `Log::` calls today (`L-…` §Q6).*

### XXXI.6.2 The redaction rule — PII never enters a log

> **Absolute rule: no personally identifiable information, no credential and no secret is ever
> written to a log, a trace, a metric label, an error report, a diagnostic bundle or an alert
> payload — in any environment, at any level, including `debug`.**

**The SmartPRS 2 counter-example, stated plainly.** The dominant logging idiom is
`Log::warning('X failed: '.$e->getMessage())` on a `QueryException`, at roughly sixty sites
(`MasterController.php:697, 829`; `EmployeeImportService.php:152, 458`; `LeadController.php:91`
and others). MySQL's exception message contains the offending statement and its bound values.
**A failed insert on `employees` therefore writes that employee's PAN, bank account number,
salary and date of birth into `laravel.log`** — a file that is unrotated, unbounded, excluded
from backups, and whose ten most recent ERROR-class lines are **emailed off the premises every
morning** to an address that is not necessarily the customer's (`ErrorDigest.php:76-93`;
SEC-L-09). And separately, `wa_log.body_values` stores OTPs and the tenant administrator's
plaintext temporary password permanently, unencrypted, unpruned, inside an unencrypted on-box
backup (SEC-L-03, SEC-L-06).

**The mechanism.** Redaction is a property of the **egress path**, not of the caller. A
developer cannot forget it, because there is no unredacted path.

| Layer | Mechanism |
|---|---|
| 1. **Typed context only** | The log API accepts a typed context object, not an array of arbitrary values. Fields are declared. A value whose declared type is a PII class cannot be passed. |
| 2. **Field-classification driven** | The redactor reads `field_definitions.pii_class` and `masking` from the entity schema engine (spine S9). A field classified PII is redacted **automatically and everywhere** — new custom fields included, with no code change. |
| 3. **Exception message sanitisation** | Every exception message crossing into a log passes the sanitiser: driver exceptions are reduced to `{sqlstate, driver_code, table, constraint}`; **the statement and every bound value are dropped**, not masked. |
| 4. **Pattern sweep (defence in depth)** | The final serialiser scrubs shaped values — PAN, Aadhaar, UAN, IFSC, account numbers, mobile numbers, e-mail addresses, JWTs, bearer tokens, private-key headers, `Authorization` headers — anywhere they still appear. |
| 5. **Deny-list on stack frames** | Stack traces are recorded **without argument values**. |
| 6. **Identifiers, not identities** | A log line references a subject by UUID public id. Names, codes, addresses and numbers are resolved at read time by an authorised viewer, never stored in the line. |
| 7. **Same redactor everywhere** | Logs, traces, metric labels, error reports, alert payloads, the diagnostic bundle and any telemetry egress use one implementation. |

- **R-XXXI-062** **PII, credentials and secrets MUST NOT appear in any log, trace, metric label,
  error report, alert payload, diagnostic bundle or telemetry export.** Redaction MUST be
  implemented on the egress path of every sink, driven by the field classification, and MUST NOT
  depend on the caller. *Test: `OBS-REDACT-01`, below.* *(This is the requirement Part XXVI R-XXVI-102 cites
  by number.)*
- **R-XXXI-063** No delivery ledger, notification log, message payload or queue payload may
  store credential material or file bytes. *Test: a schema-level assertion plus a content scan
  — the direct fix for `wa_log.body_values` (SEC-L-03) and `attach_b64` in `jobs.payload`
  (SEC-L-07).*
- **R-XXXI-064** Log content MUST NOT leave the installation except through a channel the
  customer has explicitly enabled, and it MUST pass through the redactor on the way out.
  *Test: with telemetry off, no log content egresses; with it on, the poisoned corpus survives
  the round trip clean (**D-31-03**).*

**`OBS-REDACT-01` — the CI test that proves it.** It is not a review; it is an assertion.

1. A fixture tenant is seeded with **canary values** in every PII-classified field: a canary
   PAN, Aadhaar, UAN, bank account, IFSC, mobile, e-mail, date of birth, salary amount,
   biometric template reference, API key, password and session token. Each canary is a unique,
   improbable, greppable string.
2. The suite then **provokes failure at every logging surface**: a constraint violation on an
   employee insert; a duplicate-key on a bank detail; a validation failure carrying the whole
   payload; a payroll `InvariantViolation`; an integration timeout mid-payload; a job dead-letter
   with a full context; an unhandled exception at the top-level handler; an authorisation
   denial; a webhook signature failure; a rule-evaluation failure carrying its context.
3. Every sink is captured: the log stream, the trace exporter, metric labels, the error report,
   the alert payload, the diagnostic bundle, and the API error envelope returned to the client.
4. **The assertion: no canary appears in any sink.** One occurrence fails the build.
5. **Inverse assertion:** the correlation id, the subject's UUID, the error class and the error
   code **do** appear — proving the redactor removed the PII and not the diagnostics.
6. **Coverage assertion:** every field with a PII classification in the entity schema has a
   canary. Adding a PII-classified field without a canary fails the build, so the test cannot
   rot as the schema grows.

`OBS-REDACT-01` runs in the per-commit lane. It is exempt from every baseline.

### XXXI.6.3 Log levels and retention

**Log levels — what belongs at each.** SmartPRS 2 has 64 `warning`, 13 `info` and 6 `error`
calls, and **no** `debug`, `critical`, `alert` or `emergency` (`L-…` §Q6). Everything that
matters is logged at `warning`, which is precisely the level its alerting does not read.

| Level | Meaning | Examples | Alerting |
|---|---|---|---|
| `debug` | Developer detail. **Never enabled in production**; carries no PII even so | resolution steps, cache decisions | never |
| `info` | A significant thing happened as designed | run completed, batch ingested, pack activated, message delivered | never |
| `notice` | A business decision worth a durable record | permission denied, business rule blocked, quota soft limit | anomalous-rate only |
| `warning` | Something failed but the system recovered or will retry | retryable integration failure, transient DB error, throttle | rate and burst rules |
| `error` | Something failed and did **not** recover; a user or a tenant is affected | retries exhausted, job dead-lettered, run aborted, configuration unresolvable | yes |
| `critical` | An invariant is violated or a subsystem is down | reconciliation mismatch, audit chain break, scheduler stale beyond hard threshold, no worker for a class | yes, immediately, paging |
| `alert` / `emergency` | Estate-wide | data-integrity event, security breach indicator, total unavailability | yes, paging, escalation |

- **R-XXXI-065** Log level MUST NOT be the alerting mechanism. Alerts are generated from
  signals (§XXXI.6.9); the level is metadata. *Test: an alert fires with the log level set to
  `error` and again with it set to `warning` — the direct fix for DEF-L-06, where the digest
  scans for ERROR+ while every scheduled service logs at `warning` and production sets
  `LOG_LEVEL=warning`.*
- **R-XXXI-066** Logs MUST rotate with a retention window that is configuration, MUST be
  size-bounded, and their volume MUST be a monitored signal. *Test: a log volume spike raises a
  condition — SmartPRS 2's default stack is one unrotated, unbounded `single` file, excluded
  from backups (DEF-L-22).*

### XXXI.6.4 Health, readiness and liveness — the endpoint contract

> The **check catalogue** — what is checked, its threshold, its severity and its remediation —
> is Part XXVI §XXVI.6.1 and is not repeated. This section is the **contract**: the endpoints,
> their audiences, their semantics and what each may disclose.

| Endpoint | Audience | Auth | Semantics | Body | Failure meaning |
|---|---|---|---|---|---|
| `GET /health/live` | process supervisor, orchestrator | none | **Liveness.** Is this process able to serve? No dependency is checked | `{status}` only | restart this process |
| `GET /health/ready` | load balancer, orchestrator | none | **Readiness.** Can this process serve real traffic? Asserts the dependencies a request needs: database round-trip, cache, lock acquisition, queue enqueue, storage write, schema fingerprint | `{status}` only | remove from rotation; do **not** restart |
| `GET /health/startup` | orchestrator | none | Startup complete: migrations applied, caches built, profile valid | `{status}` only | keep waiting, then fail the deployment |
| `GET /api/v3/ops/health` | operator, monitoring | authenticated, `ops.health.view` | **Full catalogue** with per-check status, observed value, threshold, onset time, remediation and owner | full detail | act per the check's remediation |
| `GET /api/v3/ops/health/{check_key}` | operator | `ops.health.view` | One check with its history | detail + history | — |
| `GET /metrics` | metric collector | network-restricted **and** bearer-authenticated | Metric exposition | metrics | — |

Rules:

- **`ready` asserts real dependencies, not framework boot.** *(SmartPRS 2's `/up` reports only
  that the framework boots — `bootstrap/app.php:14` — and would have shown green throughout
  every failure in `L-…`.)*
- **Liveness and readiness are different questions.** A dependency outage must not cause an
  orchestrator to restart-loop healthy processes.
- **Neither unauthenticated endpoint discloses anything but a status.** No schema name, no
  connection string, no migration output, no version, no stack trace. *(SmartPRS 2's on-prem
  poll endpoint serves the first 400 characters of failed `migrate --force` output verbatim to
  anyone holding the poll token — `I-…` SEC-12.)*
- **The scheduler and the workers have their own liveness**, expressed as heartbeat freshness
  and a completed probe job per queue class — not as "a process exists".
- **A degraded check never silently becomes healthy.** Status changes are events with onset and
  clearance times.

- **R-XXXI-067** `ready` MUST assert database, cache, lock acquisition, queue enqueue-dequeue,
  storage write and schema fingerprint, and MUST fail if any is unavailable. *Test: severing
  each dependency in turn turns `ready` red while `live` stays green.*
- **R-XXXI-068** No unauthenticated health endpoint may disclose more than a status token.
  *Test: the response body of `live`, `ready` and `startup` is asserted byte-shape.*
- **R-XXXI-069** Scheduler and per-queue-class worker liveness MUST be asserted by probe, not
  by process existence. *Test: a worker process that is alive but wedged fails the probe.*

### XXXI.6.5 The metrics catalogue

Naming: `smartprs_<domain>_<measure>_<unit>`. Every metric carries `installation_id`,
`deployment_profile` and `app_version`; every metric that can be attributed to a tenant carries
`tenant_id` (Part XXV R-XXV-132). **No metric label may carry PII or free text** — labels are
bounded value sets, because an unbounded label is both a cardinality incident and a PII leak.

| # | Metric | Type | Key labels | Why it exists |
|---:|---|---|---|---|
| 1 | `smartprs_http_request_duration_ms` | histogram (p50/p90/p95/p99) | route, method, status class, tenant | API and page latency SLI |
| 2 | `smartprs_http_requests_total` | counter | route, status class, error_class | error-rate SLI |
| 3 | `smartprs_http_response_rows` | histogram | route | proves no endpoint returns an unbounded set (`M-…` PERF-1) |
| 4 | `smartprs_queue_depth` | gauge | queue_class, tenant | a silently growing backlog |
| 5 | `smartprs_queue_oldest_pending_age_s` | gauge | queue_class | **the single most diagnostic queue signal** |
| 6 | `smartprs_queue_delay_ms` | histogram | queue_class | enqueue-to-start latency |
| 7 | `smartprs_job_duration_ms` | histogram | job_key, queue_class | — |
| 8 | `smartprs_jobs_total` | counter | job_key, outcome (succeeded/failed/dead_lettered/expired) | job failure rate |
| 9 | `smartprs_worker_alive` | gauge | queue_class, host, app_version | a stopped worker (`L-…` §Q4) |
| 10 | `smartprs_scheduler_heartbeat_age_s` | gauge | — | a stopped scheduler (`L-…` §Q4) |
| 11 | `smartprs_scheduled_job_last_success_age_s` | gauge | job_key | a job that stopped succeeding while the scheduler runs |
| 12 | `smartprs_scheduled_job_items` | counter | job_key, outcome | partial sweeps (DEF-L-03) |
| 13 | `smartprs_db_query_duration_ms` | histogram | operation class | database performance |
| 14 | `smartprs_db_queries_per_request` | histogram | route | N+1 detection in production; 103 such sites exist in SmartPRS 2 across 25 files (`M-…`) |
| 15 | `smartprs_db_connections_in_use` / `_wait_ms` | gauge / histogram | — | pool exhaustion |
| 16 | `smartprs_db_deadlocks_total`, `_lock_wait_timeouts_total` | counter | table class | contention |
| 17 | `smartprs_db_replication_lag_s` | gauge | — | stale reads |
| 18 | `smartprs_integration_calls_total` | counter | connector, endpoint class, outcome | integration failure rate |
| 19 | `smartprs_integration_duration_ms` | histogram | connector | — |
| 20 | `smartprs_integration_freshness_age_s` | gauge | connector, device | staleness (DEF-L-01) |
| 21 | `smartprs_attendance_punches_ingested_total` | counter | source, adapter, verdict (accepted/duplicate/quarantined/rejected) | ingestion rate; every rejected line counted (`C-…` requirement 46) |
| 22 | `smartprs_attendance_ingest_lag_s` | gauge | device | punch instant → ingested instant |
| 23 | `smartprs_attendance_days_derived_total`, `_derivation_duration_ms` | counter, histogram | tenant | derivation throughput |
| 24 | `smartprs_payroll_run_duration_ms` | histogram | stage, tenant scale band | payroll run duration budget (§XXXI.9) |
| 25 | `smartprs_payroll_run_employees` / `_exceptions_total` | histogram / counter | stage, exception type | run health |
| 26 | `smartprs_rule_evaluation_duration_us` | histogram | module | rule evaluation time |
| 27 | `smartprs_formula_evaluation_duration_us` | histogram | component class | formula evaluation time |
| 28 | `smartprs_expression_budget_exceeded_total` | counter | kind (nodes/depth/time/regex) | SPX budget breaches (spine S5.6) |
| 29 | `smartprs_notifications_total` | counter | channel, state (queued/sent/delivered/failed/suppressed) | **delivery** rate, not enqueue rate (DEF-L-13) |
| 30 | `smartprs_notification_delivery_latency_ms` | histogram | channel | — |
| 31 | `smartprs_backup_age_s`, `smartprs_backup_last_size_bytes` | gauge | policy, destination | a backup that silently stopped (`L-…` §Q5) |
| 32 | `smartprs_backup_verify_age_s`, `smartprs_restore_drill_age_s` | gauge | policy | an unproven restore (Part XXVI §XXVI.8.7) |
| 33 | `smartprs_audit_chain_verified_age_s`, `_chain_breaks_total` | gauge, counter | tenant | a silently broken ledger (SEC-L-15) |
| 34 | `smartprs_authz_denials_total`, `smartprs_authn_failures_total` | counter | policy / reason | security signal (none exists today) |
| 35 | `smartprs_gate_transient_failures_total` | counter | gate | **every fail-closed gate error is counted** (SEC-L-14) |
| 36 | `smartprs_suppressed_failures_total` | counter | suppression id | every declared suppression (§XXXI.5.3) is visible |
| 37 | `smartprs_config_resolution_failures_total` | counter | artefact type | unresolvable configuration |
| 38 | `smartprs_disk_free_bytes`, `smartprs_db_size_bytes`, `_growth_bytes_per_day` | gauge | volume / table class | headroom (§XXXI.9) |
| 39 | `smartprs_rulepack_age_days`, `smartprs_licence_days_remaining` | gauge | jurisdiction / installation | currency |
| 40 | `smartprs_tenant_seats_used` / `_quota_usage_ratio` | gauge | tenant, quota_key | commercial and fairness signals (Part XXV) |

- **R-XXXI-070** Every metric in the catalogue MUST be emitted in **every** deployment model.
  Whether it is exported off-box is configuration; whether it is measured is not. *Test: an
  on-premise installation with telemetry disabled still renders every panel of its own console
  from local metrics.*
- **R-XXXI-071** No metric label may be unbounded or carry PII. *Test: a cardinality assertion
  per metric, and `OBS-REDACT-01` covers labels.*
- **R-XXXI-072** Metrics MUST be recorded at raw resolution for the declared short window and
  rolled up for the retained window; the rollup MUST NOT lose the percentile shape used by the
  SLOs. *Test: an SLO computed from rollups matches the raw computation within the declared
  tolerance.*
- **R-XXXI-073** Metric emission MUST be non-blocking and MUST NOT be able to fail a business
  operation. *Test: a metric sink outage does not raise the error rate — but it **does** raise
  its own condition, so the failure is visible.*

### XXXI.6.6 Distributed tracing

Sampled, always-on for the operations that matter, and structurally the same shape as the
explainability trace so an engineer and an auditor look at the same causal chain from different
ends.

- **Spans**: the HTTP request; every job execution; every database statement class; every
  outbound integration call; every engine evaluation (rule set, formula graph, workflow step);
  every payroll stage.
- **Sampling**: head-based at the declared rate for routine traffic; **always sampled** for a
  payroll run, a statutory filing generation, an ingestion batch, any request that produces an
  `error` or `critical` outcome, and any request an operator marks for capture.
- **Propagation**: W3C trace context, plus `correlation_id`, across HTTP, queue and webhook
  boundaries.
- **A trace never carries PII** — span attributes go through the same redactor (R-XXXI-062).

- **R-XXXI-074** Trace context MUST propagate across every process boundary the product owns —
  request → job → job → outbound call → webhook. *Test: one trace shows a punch ingested by a
  webhook, derived by a job, and consumed by a payroll run.*
- **R-XXXI-075** A trace MUST be retrievable by `correlation_id` from an operator surface.
  *Test: runbook step 4 of §XXV.12.5 completes inside its five-minute budget.*

### XXXI.6.7 Dashboards

Definitions live in `deploy/observability/` and are applied by the pipeline (R-XXXI-015). Six,
each with a stated question:

| Dashboard | Answers | Primary signals |
|---|---|---|
| **Service health** | *Is the product serving?* | request rate, error rate, latency percentiles, readiness, saturation (the four golden signals) |
| **Background runtime** | *Is work happening?* | scheduler heartbeat, per-job last-success age and outcome, queue depth and oldest-job age per class, worker liveness, dead-letter counts |
| **Data pipeline** | *Is attendance arriving and being turned into days?* | ingestion rate by source and verdict, ingest lag, quarantine backlog, derivation throughput, per-device freshness |
| **Payroll and money** | *Are runs healthy?* | run duration by stage and scale band, exceptions by type, reconciliation assertions, rule/formula evaluation time |
| **Data protection** | *Are we recoverable?* | backup age and size, verification age, restore-drill age and result, audit-chain verification age |
| **Tenant view** | *Is this customer healthy?* | every one of the above, filtered to one tenant (Part XXV R-XXV-132, R-XXV-134) |

- **R-XXXI-076** Every dashboard panel MUST show a value **and its freshness**; a stale value
  MUST render as stale. *Test: stopping the metric pipeline renders panels stale within the
  declared window — the shape of `biometric_configs.last_sync_at`, whose stamp is written
  inside an empty catch so the one screen an operator can check shows a timestamp that is a lie
  about the write, not about the sync (`L-…` §Q3 item 9).*

### XXXI.6.8 SLOs and error budgets

SLOs are declared per service journey, not per component, because a customer experiences
journeys.

| # | SLI | Definition | Proposed objective (28-day window) | Rationale |
|---:|---|---|---|---|
| 1 | **Availability** | Fraction of `/api/v3` requests not returning a 5xx of our making | **99.5 %** | An HR/payroll system is business-hours critical, not life-critical |
| 2 | **Latency — read** | p95 of `/api/v3` reads under the §XXXI.9 budget | **99 %** | — |
| 3 | **Latency — write** | p95 of `/api/v3` writes under budget | **99 %** | — |
| 4 | **Attendance freshness** | Fraction of enabled devices whose ingestion lag is within policy | **99 %** | Missing punches become missing pay |
| 5 | **Notification delivery** | Fraction of non-expired messages reaching `delivered` within their class target | **99 %** | The failure that suspended a paying customer silently |
| 6 | **Payroll run success** | Fraction of runs completing without an infrastructure-caused failure | **99.9 %** | A failed run has no acceptable frequency |
| 7 | **Background timeliness** | Fraction of scheduled job instances completing within their expected interval | **99 %** | The scheduler is a product dependency |
| 8 | **Recoverability** | Fraction of days with a verified backup **and** a restore drill within policy | **100 %** | Binary; no budget |

**Error budget policy.** Budget = 1 − objective. Three responses, automatic, not discretionary:

| Consumption in the window | Response |
|---|---|
| > 50 % | The SLO owner reviews at the weekly operations review; risky changes need explicit sign-off |
| > 75 % | Canary windows are doubled; only reliability and security changes promote to production |
| **100 % (exhausted)** | **A change freeze on the affected service journey** until the budget recovers, except reliability fixes and security patches. Lifted by the SLO owner, recorded |
| Burn rate ≥ 14× over 1 h, or ≥ 6× over 6 h | Page immediately — this is the fast-burn alert, not a monthly report |

- **R-XXXI-077** Every SLO MUST have a named owner, a definition expressed in the metric
  catalogue, and a published objective. *Test: an SLO with no owner fails the pipeline's
  observability-definition validation. Objectives are **D-31-01**.*
- **R-XXXI-078** Error-budget policy MUST be automatic: exhaustion produces the freeze without
  anybody deciding. *Test: a simulated exhaustion blocks a non-reliability promotion.*
- **R-XXXI-079** SLIs MUST be measured from the **serving** side and exclude client-caused
  errors (4xx of the client's making), so the number is honest in both directions. *Test: an
  injected 5xx moves the SLI; an injected 422 does not.*
- **R-XXXI-080** On an on-premise installation the same SLIs MUST be computed locally and shown
  in the operations console, with no obligation to export them. *Test: an air-gapped install
  renders its own SLO panel.*

### XXXI.6.9 Alerting, routing and escalation

**Alerting is generated from signals, deduplicated on a stable alert key, and delivered by a
transport independent of the subsystem it monitors.** This is the direct repudiation of
`errors:digest`, which scrapes a log file for a severity nothing writes and mails the result
through the queue whose failure it exists to detect (DEF-L-06, DEF-L-07).

**The alert catalogue** — every rule declares: key, signal expression, `for` duration,
severity, deduplication key, routing target, escalation policy, runbook link, and auto-close
condition.

| Alert key | Condition | Sev | Routes to | Runbook |
|---|---|---|---|---|
| `scheduler.heartbeat.stale` | heartbeat age > soft, then > hard | **S2 → S1** | on-call | §XXXI.8.6 |
| `worker.absent` | no worker alive for a class > detection window | **S1** | on-call | §XXXI.8.5 |
| `queue.backlog` | depth or oldest-pending age > threshold `for` 10 m | S2 | on-call | §XXXI.8.5 |
| `queue.deadletter.rate` | dead-letters > threshold in window | S2 | on-call | §XXXI.8.5 |
| `job.repeated_failure` | a `job_key` failing on N consecutive runs | S2 | on-call | §XXXI.8.4 |
| `integration.stale` | freshness age > per-connector policy | S2 (S1 near payroll cut-off) | on-call + tenant admin | §XXXI.8.4 |
| `payroll.run.failed` | a run aborted, or a reconciliation assertion failed | **S1** | on-call + payroll owner | §XXXI.8.1 |
| `invariant.violation` | any `InvariantViolation` | **S1** | on-call, paging | §XXXI.8.10 |
| `audit.chain.break` | verification failed, or anchor age > policy | **S1** | on-call + compliance owner | §XXXI.8.10 |
| `backup.overdue` / `backup.verify.failed` / `restore.drill.overdue` | age > policy | S2 | on-call + customer contact | §XXXI.8.8 |
| `slo.fast_burn` | burn rate ≥ 14× / 1 h | **S1** | on-call, paging | §XXXI.8 |
| `slo.slow_burn` | budget > 75 % consumed | S3 | operations review | — |
| `security.authn_anomaly` / `gate.transient_failures` | rate above baseline | S2 | on-call + security owner | §XXXI.8.10 |
| `config.unresolvable` | configuration resolution failures above threshold | S2 | tenant admin + on-call | §XXXI.8.4 |
| `capacity.headroom` | projected exhaustion of a volume within the declared horizon | S3 | operations review | §XXXI.9 |
| `licence.expiring` / `rulepack.stale` | within lead time | S3 | customer contact | Part XXVII |

Delivery rules:

- **Independent transport.** Alerts leave by a path that does not use the application queue, the
  application mailer or the application scheduler. On-premise installations that cannot reach an
  external provider fall back to a **local, non-dismissible administrator banner plus a local
  alert log** — degraded, but never absent.
- **Deduplication and auto-close.** One open condition per alert key; a persisting condition
  does not re-alert. Conditions close automatically when the signal recovers, and the closure is
  recorded. *(SmartPRS 2's digest has no dedupe, so two runs send two identical emails.)*
- **Every alert is actionable.** An alert with no runbook link and no owner is deleted, not
  tuned. Alert volume is itself a monitored signal, reviewed weekly.
- **Severity, threshold, recipient and channel are configuration.** *(All four are literals in
  `ErrorDigest.php:48, 76, 79, 108` today.)*
- **Audience resolution fails closed *and* alerts.** A rule whose recipient set resolves to
  nobody raises a meta-alert. *(`ComplianceController.php:269-272` fails closed correctly and
  **silently**: the tenant simply never receives its compliance digest and nobody knows.)*

- **R-XXXI-081** Alerting MUST NOT depend on the subsystem it monitors. *Test: with the queue,
  the scheduler and the mailer each stopped in turn, the corresponding alert is still delivered
  (mirrors Part XXVI R-XXVI-173).*
- **R-XXXI-082** Alerts MUST be generated from signals, never by scraping log text. *Test: no
  alert rule reads a log file.*
- **R-XXXI-083** Every alert MUST carry a severity, an owner, a runbook link and an auto-close
  condition, and MUST be deduplicated on a stable key. *Test: a rule missing any of the five
  fails the observability-definition validation.*
- **R-XXXI-084** Alert thresholds, severities, channels and recipients MUST be configuration,
  resolvable per installation and per tenant. *Test: changing a threshold requires no release.*
- **R-XXXI-085** An alert rule whose audience resolves to nobody MUST raise a meta-alert.
  *Test: clearing the recipient list produces a condition rather than silence.*
- **R-XXXI-086** Alert payloads MUST pass through the redactor. *Test: `OBS-REDACT-01` covers
  the alert sink.*
- **R-XXXI-087** Every alert MUST be recorded with its onset, its acknowledgement, its closure
  and the actions taken, so alert quality is reviewable. *Test: the weekly review is generated
  from data, not memory.*
- **R-XXXI-088** An on-premise installation MUST have a working alert path on day one, chosen
  during installation, and the installer MUST NOT complete without one. *Test: an install with
  no alert recipient is refused; see **D-31-09**.*

---

## XXXI.7 The operations console

One console, one implementation, both deployment models. Part XXVI §XXVI.7 specifies **what an
on-premise operator must be able to answer without a support call**; Part XXV §XXV.12.4
specifies that every signal is dimensioned by tenant. This section specifies the **panels, the
actions and the rule that every item is actionable** — the same screen, with route registration
and data scope deciding what a given operator sees.

| Panel | Shown | Actionable — the operator can, from this panel |
|---|---|---|
| **Scheduler** | Heartbeat age; next due; per `job_key`: last run, outcome, duration, items attempted/succeeded/failed, cursor position, lease state | Run now; pause/resume a job; adjust cadence; clear a stale lease; **run catch-up over a named range** |
| **Queues** | Per class: depth, oldest-pending age, throughput, in-flight, worker count and liveness, code version per worker | Pause/resume a class; scale workers (where the profile allows); drain; restart workers |
| **Failed and dead-lettered jobs** | Grouped by job type and error class, with tenant, correlation id, attempt history and payload reference | **Replay** (idempotent), replay all in a group, discard with a recorded reason, open the trace |
| **Notifications** | Counts by state — queued / sent / **delivered** / failed / suppressed — with oldest queued age, per channel and per tenant | Retry a message; retry a batch; inspect the failure reason; test a transport |
| **Webhooks** | Pending, failed, dead-lettered deliveries; per-endpoint success rate and latency; signature failures | Redeliver; disable an endpoint; rotate a secret; inspect the delivery log |
| **Integrations and devices** | Per connector/device: status (healthy / stale / failing / never-seen), last successful sync, **watermark position**, consecutive failures, punches today, quarantine backlog | Test connection; force a sync; **rewind the watermark** within `max_lookback`; resolve quarantined punches; disable a device |
| **Data protection** | Last successful backup (time, size, destination, verification result); **last successful restore drill and its date**; next drill; retention in force; destination reachability | Run a backup now; **run a restore drill**; test a destination |
| **Licence and rule packs** | Licence identity, expiry, seats; per jurisdiction: pack version, effective range, **age**, signature status, available update | Check for updates; verify a signature; preview a pack diff and its impact; activate; side-load a pack |
| **Migrations and version** | App version, schema version and fingerprint, migration set applied, drift, last deployment record, flag state | Re-run the fingerprint check; open the deployment record |
| **Capacity** | Free space per volume with **projected exhaustion date**; database size and growth per table class; document store size; connection-pool and queue saturation | Open the retention policy; trigger an archival job; open the capacity runbook |
| **Health and conditions** | Every health check with status, observed value, threshold, **onset time**, remediation and owner; every open alert condition with acknowledgement state | Run a health check; acknowledge; open the runbook; download the diagnostic bundle |
| **Audit and compliance** | Chain verification status and age per tenant; last anchor; open blocking configuration prerequisites | Run verification; open the prerequisite |

- **R-XXXI-090** Every operations-console item MUST be actionable: it MUST offer at least one
  action, a runbook link, or both. A read-only number with no next step MUST NOT be shown.
  *Test: a console assertion enumerates every panel item and fails on one with neither.*
- **R-XXXI-091** Every value MUST be rendered with its freshness, and a stale value MUST render
  as stale (mirrors Part XXVI R-XXVI-101).
- **R-XXXI-092** Every console action MUST be authorised by an explicit policy, audited with
  actor, target and reason, and either reversible or clearly marked irreversible **before**
  execution. *Test: a destructive action requires typed confirmation naming the target.*
- **R-XXXI-093** The console MUST be available in both deployment models, MUST be reachable by
  a dedicated operator role, and MUST NOT be a vendor-only surface. *Test: an on-premise
  operator answers "when was my last good backup, and when was it last proven restorable?" from
  one screen.*
- **R-XXXI-094** Replay of a failed job MUST be idempotent and MUST NOT be able to duplicate a
  money effect. *Test: replaying a completed-then-failed payroll posting produces no second
  posting.*
- **R-XXXI-095** The console MUST NOT expose PII beyond what the operator's data scope already
  permits, and the diagnostic bundle MUST pass through the redactor. *Test: `OBS-REDACT-01`
  covers the bundle.*

---

## XXXI.8 Operational runbooks

Runbooks are versioned in `deploy/runbooks/` alongside the code they describe (R-XXXI-016).
Each states its trigger, its steps, its decision points, its abort condition and its
communication. Where a step names a console action, that action exists in §XXXI.7 — a runbook
that requires shell access to a production server has failed as a runbook.

### XXXI.8.1 Payroll day

> **Trigger:** the payroll calendar's run day for a company-period.
> **Owner:** the tenant's payroll owner, with Ametecs on-call available.

| # | Step | Abort / escalate if |
|---:|---|---|
| 1 | **T−3 days.** Open the run's readiness view: attendance ingestion freshness for every device, quarantine backlog at zero, open corrections, unapproved variable inputs, employees missing bank/PAN/UAN, unresolved configuration prerequisites | Any integration is stale beyond policy → §XXXI.8.4 **before** proceeding |
| 2 | **T−2.** Confirm the statutory rule pack for every jurisdiction in scope is current and activated for the period's effective date | A pack is stale or unactivated → Part XXVII; do not run on an out-of-date pack |
| 3 | **T−1.** Freeze inputs: lock attendance for the period, close the correction window, confirm approvals complete | Approvals outstanding → the run cannot leave preview |
| 4 | **Run day.** Confirm scheduler heartbeat fresh, every worker class alive, queue oldest-pending age normal | Either stale → **do not start**; §XXXI.8.5 or §XXXI.8.6. *(The product refuses — R-XXXI-100.)* |
| 5 | Start the run. It is a `bulk` job with per-stage progress; **do not re-click** | The console shows the in-flight handle |
| 6 | Work the **preview exception list** to zero: missing structure, missing attendance, negative net, minimum-wage breach, unmapped statutory policy, duplicate run, loan inconsistency, invalid tax data | Any exception unresolved → the run does not advance past preview (spine S11) |
| 7 | Verify the **reconciliation assertions**: sum of payslip lines = run totals; per employee, earnings − deductions = net, exactly | Any mismatch → the run aborts itself; raise S1 `payroll.run.failed`; §XXXI.8.10 if the cause is an invariant |
| 8 | Compare against last period: headcount delta, gross delta, statutory delta, exception count. Investigate any delta beyond the declared tolerance | Unexplained delta → hold at draft |
| 9 | Submit for approval (maker-checker, spine S8). Approve. Lock the period | — |
| 10 | Generate payslips, bank pack and statutory outputs; confirm counts match the run | Count mismatch → do not release the bank pack |
| 11 | Record the run in the operations log: duration per stage, exception counts, any deviation | — |

- **R-XXXI-100** A payroll run MUST refuse to start when the scheduler heartbeat is stale
  beyond the hard threshold, when a queue class has no worker, or when attendance ingestion for
  any in-scope device is stale beyond policy — naming the condition rather than computing on
  incomplete data. *Test: with ingestion stopped, the run refuses; SmartPRS 2 computes an
  under-count of payable days instead (mirrors Part XXVI R-XXVI-172). Whether this hard gate
  may be overridden with a recorded reason is **D-31-08**.*

### XXXI.8.2 Month-end close

> **Trigger:** the last working day of the payroll month.

1. Confirm every company-period in the month has a completed, approved and locked run — the
   console lists periods with no run and runs not locked.
2. Run `payroll.reconcile.audit` explicitly and read the result: **no day paid twice, no day
   never paid**, for every employee in the month. *(This control exists in SmartPRS 2, returns
   FAILURE correctly, and is not on the schedule at all — DEF-L-33.)*
3. Reconcile ledgers: loans and advances (opening + disbursed − recovered = closing),
   commissions, clawbacks, reimbursements, comp-off balances, leave balances.
4. Confirm employer contribution and CTC reconciliation totals.
5. Close the period: no further postings without an audited reopen workflow.
6. Confirm the audit chain anchor exists for every day of the month for every tenant, and
   verification is green.
7. Confirm a verified backup exists **after** the close, and record its identity against the
   period — this is the archive a dispute three years later will be answered from.
8. Publish the close record: periods closed, exceptions carried, reconciliation results.

### XXXI.8.3 A statutory filing window

> **Trigger:** a filing obligation enters its window (`compliance.filing.due.scan`).

1. Confirm the rule pack that governs the filing is current, signed and activated for the
   filing period; a stale pack blocks the filing rather than producing a wrong one.
2. Generate the return from **observed evidence only**; no literal is printed as a finding
   (`F-…` DEFECT-F-09).
3. Reconcile the return against the payroll runs it derives from — totals, headcount, and the
   contribution/deduction sums — and record the reconciliation with the return.
4. Work the exception list: missing UAN/PAN/ESIC identifiers, employees with a treatment the
   pack cannot classify, cross-state cases.
5. Produce the filing artefact; store it with its input snapshot, the pack version, and the
   run versions it drew on, so it can be reproduced exactly.
6. Record submission (reference number, date, actor) against the obligation; the obligation
   closes only on a recorded submission, never on a date passing.
7. If the window will be missed: raise the condition **before** the due date, record the reason,
   and notify the compliance owner. A missed filing is never discovered by its absence.

- **R-XXXI-101** A statutory output MUST NOT be generable from a rule pack that is not current
  and activated for the filing period. *Test: an out-of-date pack blocks generation with a
  named reason.*

### XXXI.8.4 A failed integration

> **Trigger:** `integration.stale`, `integration.failure_rate`, or a device reported by a
> customer.

| # | Step |
|---:|---|
| 1 | Open the integration panel: status, **watermark position**, last successful sync, consecutive failures, last error class |
| 2 | Classify: credential/authorisation, network/DNS/TLS, upstream outage, payload/schema change, our defect, or device hardware |
| 3 | Establish blast radius: one device, one tenant, one connector type, or all — the panel groups by each |
| 4 | Contain: disable the failing device or endpoint so it stops consuming retries and stops raising noise; the disable is audited and visible to the tenant |
| 5 | **Establish the data gap**: from the watermark, state precisely which interval is missing and which employees are affected. This is a number, not an estimate |
| 6 | Fix. For a credential or endpoint change, update the connector configuration — no deployment (spine S10.1) |
| 7 | **Recover**: re-run ingestion from the watermark. The watermark advances only on verified persistence, so recovery is exact and idempotent |
| 8 | If the gap exceeds `max_lookback` or upstream retention, escalate: the punches may be unrecoverable. Record the affected employees and dates as an attendance exception so payroll cannot silently treat them as absent |
| 9 | Verify: ingestion rate returns to baseline; quarantine backlog drains; freshness green; the affected employees' attendance days re-derive |
| 10 | If a payroll run touched the gap, re-run it for the affected employees as a supplementary run — never edit a payslip |

- **R-XXXI-102** A recoverable ingestion gap MUST be recoverable **exactly** by re-running from
  the watermark, and an unrecoverable gap MUST be recorded as a named attendance exception
  visible to payroll. *Test: a 72-hour outage recovers completely; a gap beyond upstream
  retention blocks the affected employees' payroll rather than paying them as absent — the
  direct fix for DEF-L-01, where an outage over 24 hours loses punches permanently and silently.*

### XXXI.8.5 A stuck or backed-up queue

> **Trigger:** `queue.backlog`, `worker.absent`, or `queue.deadletter.rate`.

1. Distinguish three shapes from the queue panel — **no worker** (depth rising, throughput
   zero, liveness red), **slow worker** (throughput low, job duration high), **poison message**
   (throughput normal, one job type failing repeatedly).
2. **No worker:** confirm the service state; restart it; confirm it registers and reports the
   expected code version. If it restarts and dies, read its last error and treat as a deploy
   failure (§XXXI.8.9).
3. **Slow worker:** identify the dominant job type by duration; check the database panel for
   lock waits and pool exhaustion; check whether one tenant's `bulk` work is saturating the
   class — if so, apply the per-tenant share cap (Part XXV R-XXV-128) rather than adding
   workers blindly.
4. **Poison message:** the class dead-letters it automatically. Inspect the dead-letter record,
   fix or discard with a reason, and replay the rest.
5. **Before draining a large backlog, check expiries.** Time-sensitive jobs past their expiry
   are discarded, not delivered — a drained backlog must not send last week's reminders
   (R-XXXI-050; `L-…` §Q9 D).
6. Scale workers temporarily if the profile permits; record it, and remove it when depth
   normalises.
7. Verify: depth returns to baseline; oldest-pending age normal; the delivery ledger shows
   `delivered`, not merely `sent`.
8. Establish the **customer impact of the outage window**: which notifications were delayed,
   which tenants, whether any deadline-bearing message expired. Communicate it.

### XXXI.8.6 A scheduler outage, and the catch-up procedure

> **Trigger:** `scheduler.heartbeat.stale`.
> **This is the failure SmartPRS 2 cannot see at all** — there is no last-run table, no
> heartbeat, no `->onFailure()`, and cron's output is discarded (`L-…` §Q4).

| # | Step |
|---:|---|
| 1 | Confirm from the scheduler panel: heartbeat age, and per `job_key` last-success age. A running scheduler with one job failing is a different incident (§XXXI.8.4 or the job's own runbook) |
| 2 | Restart the scheduler service; confirm the heartbeat advances within one tick |
| 3 | If it will not start: check the lock store (an unreachable lock store stops the whole schedule and, on Redis, also logs every user out), disk, and the deployment record for a recent change |
| 4 | **Establish the outage window** precisely from the last successful run of each job |
| 5 | **Catch-up, in dependency order, not alphabetical order**: ingestion first (watermark), then derivation, then ledgers (accrual, expiry, effective-dated assignments — replayed **per missed date**, because each date must be evaluated against the rules effective on it), then notifications, then reporting/maintenance |
| 6 | Catch-up runs **bounded**: `max_lookback` and a per-run item budget, over successive runs, so recovery does not become a second incident |
| 7 | **Reminder ladders catch up rather than skip** — a milestone missed during the outage fires on the first run at or past it, deduplicated (R-XXXI-045). *(SmartPRS 2 loses it permanently — DEF-L-05.)* |
| 8 | **Check for terminal actions that should not have fired, and for terminal actions that were suppressed.** A subscription or licence terminal action must not execute on the catch-up run without the warnings that were supposed to precede it having been delivered. *(This is exactly the SmartPRS 2 failure: the suspend write is direct and unqueued while the five warnings were only enqueued and recorded as `sent` — `SubscriptionAlerts.php:65, 107-108`.)* |
| 9 | Verify: every job's last-success age within its interval; no `partial` runs; watermarks advanced; queue drained |
| 10 | Communicate the window and the effects to affected tenants (§XXXI.10.4) |

- **R-XXXI-103** Catch-up MUST be an explicit, bounded, observable operation over a named range,
  and MUST evaluate each missed date against the rules **effective on that date** (Law 3).
  *Test: catching up three missed days produces the same result as if each had run on its own
  day.*
- **R-XXXI-104** A terminal action (suspension, licence enforcement, auto-lapse, auto-suspend)
  MUST NOT execute on a catch-up run unless its prerequisite warnings were **delivered**, not
  merely enqueued. *Test: a scheduler outage spanning a subscription's full warning ladder
  produces the warnings and defers the terminal action, never the reverse — the direct fix for
  the SmartPRS 2 behaviour where a paying tenant is suspended having received none of five
  warning emails (`L-…` §Q9).*

### XXXI.8.7 A single-tenant incident

The full runbook is **Part XXV §XXV.12.5** and is not restated. Two platform-side additions:

- Step 4 of that runbook (capture a correlation id, retrieve logs, spans and audit entries) is
  executable only because of §XXXI.6.1 and §XXXI.6.6; if a correlation id cannot be obtained
  from the failing operation, that is itself a defect to be raised.
- On an **on-premise** installation the same runbook applies with the tenant being the
  installation; containment levers are the queue-class pause, the integration disable, and
  read-only mode, all from §XXXI.7.

### XXXI.8.8 A restore drill

The restore procedure, its assertions and the drill cadence are **Part XXVI §XXVI.8.6–8.7**.
The operational wrapper:

1. Schedule the drill in a declared window; it is a scheduled job (`backup.restore.drill`), not
   a human's initiative.
2. Restore the most recent verified archive into an isolated scratch environment — **never**
   into any environment that can reach a production integration, a real mail transport or a
   payment gateway.
3. Run the restore assertion suite: schema fingerprint; row counts against the archive
   manifest; **an exact payslip net for named fixture employees** (Law 7); audit-chain
   verification; document-store integrity for a sample.
4. Record the outcome, the wall-clock duration (this is the measured RTO) and the archive's
   age at restore (the measured RPO).
5. Publish the date to the operations console. **This is the date the customer sees.**
6. A failed drill is an S2 condition and blocks nothing — but an **overdue** drill is a
   persistent, non-dismissible banner, because an untested backup is not a backup.

### XXXI.8.9 A failed update, and its rollback

> **Trigger:** an update fails at any step, or the post-update health check fails.
> The update flow itself is spine S18 and Part XXVII §XXVII.4.

| # | Step |
|---:|---|
| 1 | **Stop.** Do not retry the update. The installation is in a known state only because the flow recorded it |
| 2 | Read the deployment record: which step failed, and whether migrations were applied |
| 3 | **Migrations not applied** → restore the code artefact, rebuild caches, restart workers, exit maintenance, run the health check. Service returns on the previous version |
| 4 | **Migrations applied** → the database rollback path: restore the pre-update database backup taken by the update flow, then the code artefact. **This is the step SmartPRS 2 does not have**: its updater restores the code tree only (`updater/updater.php:140-159`) *and runs migrations at step 9* (`:310`), which the rollback cannot undo — leaving a rolled-back application on a migrated schema |
| 5 | Verify: schema fingerprint matches the restored version; health check green; workers on the restored version; a probe job completes in every queue class |
| 6 | Exit maintenance. Confirm from the outside — a served HTTP route, not a CLI boot |
| 7 | Record: what failed, what was restored, the downtime, and the data window (if any) lost to the restore |
| 8 | Do not re-attempt until the failure is reproduced in UAT against a restored copy of this customer's database (§XXXI.3.1) |

- **R-XXXI-105** An update that applies migrations MUST take a verified database backup first,
  and the rollback path MUST restore **both** code and database. *Test: a rollback drill from a
  mid-migration failure returns the installation to its exact pre-update state.*

### XXXI.8.10 A suspected security incident

> **Trigger:** an authentication anomaly, a gate-failure spike, an audit-chain break, a
> credential exposure report, an unexpected privileged action, or a customer report.

1. **Preserve first.** Snapshot logs, audit ledger, sessions and access records **before**
   changing anything. Containment destroys evidence if done first.
2. Classify: credential compromise, authorisation bypass, data exposure, integrity (audit chain
   or data tampering), availability, or supply chain.
3. Contain proportionately: revoke the credential or API key; invalidate sessions for the
   affected principals; disable the affected integration or endpoint; place the tenant
   read-only. Every containment action is audited and reversible (Part XXV R-XXV-135).
4. **Assess exposure using the audit ledger and the correlation id**, not by inference: which
   records, which fields, which tenants, over what window, by which principal.
5. Verify ledger integrity: chain verification and the daily anchors either establish that the
   record is intact, or establish the date the chain broke — which is itself a finding.
6. Rotate: credentials, API keys, webhook secrets, signing keys where implicated. A key that
   *may* have been exposed is rotated.
7. Notify. Legal and contractual obligations — including DPDP Act 2023 duties — are decided by
   the named owner, not by engineering; engineering supplies the exposure assessment. The
   customer is told what is known, what is not yet known, and when the next update comes.
8. Remediate, then close with a post-incident review (§XXXI.10.5) that produces at least one
   requirement and one test.

- **R-XXXI-106** Every containment action MUST be executable from the console, audited, and
  reversible, and MUST NOT require a code deployment. *Test: each of revoke, invalidate,
  disable and read-only is exercised in a drill.*

### XXXI.8.11 Onboarding a new on-premise customer

The installer is Part XXVI §XXVI.5. This is the operational sequence around it.

| Phase | Steps |
|---|---|
| **Pre-sales / pre-install** | Size against the reference architectures (Part XXVI §XXVI.3); run the prerequisite matrix (§XXVI.4) on the customer's intended host and **record the result** — a failed prerequisite is a commercial fact, not an installation-day surprise; agree backup destination, retention, RTO/RPO, alert recipients and the support-access policy |
| **Install** | Run the installer. It refuses to complete without: a working scheduler, a working worker, a reachable backup destination, an alert recipient, HTTPS, and a first administrator created interactively with a generated secret. **No default credential exists** (spine S13) |
| **Licence and content** | Activate the licence; pull and activate the rule packs for every jurisdiction in scope; record the pack versions |
| **Configuration** | Apply the configuration template; work the blocking prerequisite list to zero; the tenant is not "installed" until it is **usable** (Part XXV §XXV.3.4) |
| **Data** | Migrate or import master data; reconcile counts; run a parallel payroll against the customer's last known-good period and diff to the paise (Part XXX §XXX.8.3) |
| **Proving the runtime** | Force a scheduler tick and confirm the heartbeat; enqueue and drain a probe job in every queue class; send a test message end-to-end and confirm `delivered`; ingest a real punch from every device and confirm it reaches an attendance day |
| **Proving recovery** | **Run a backup, then run a restore drill, before go-live.** The customer sees the drill date on their console from day one |
| **Handover** | Walk the operations console; hand over the runbooks; confirm the alert recipients receive a test alert; agree the support-access grant procedure; record the installation certificate |
| **Day 30** | Review: health history, backup and drill history, capacity trend, alert volume and quality, open conditions. Adjust thresholds to the customer's real shape |

---

## XXXI.9 Capacity planning and performance budgets

> Part XXX §XXX.9 owns these as **CI assertions** against seeded tenants. This section owns
> them as **production budgets and capacity plan** — the same numbers, measured in production,
> with the scaling levers named. A budget that is asserted in CI and unmeasured in production
> is half a control.

### XXXI.9.1 The regressions the budgets exist to prevent

Every budget below guards a specific, measured SmartPRS 2 failure mode (`M-…` §6.3):

| Failure mode | Evidence | Consequence at scale |
|---|---|---|
| **Unpaginated datasets** | `->paginate(` appears **zero** times in `app/`; **308** unbounded `->get()` calls. `/app/data` returns every employee (~62 fields), every reference row, every payroll run and **every payslip the tenant has ever had** (`AppDataController::bootstrap():247-590`) | 30–60 MB JSON at 1,000 employees; **memory exhaustion at 5,000**; outright failure at 10,000 |
| **N+1 queries** | **103 sites across 25 files**. `PayrollGenController::compute():1091-1490` issues `presentDays`, `paidLeaveDays`, `dayStats`, `presentDates`, `sandwichDays`, `loanEmiDue`, `advanceRecovery`, `clawbacksDue` and `esiPeriodLock` **per employee**; `paidLeaveDays()` additionally plucks **every tenant's** leave types on every call | ≥ 6,000 queries at 1,000 employees; ≥ 60,000 at 10,000 — the run **cannot complete** |
| **Whole-file in-memory exports** | `EmployeeExportController:246-320` builds the XLSX in memory then `file_get_contents` reads the finished file **back into a PHP string**; `ReportController::export` assembles the entire CSV as one string; the attendance report loads every punch into PHP **twice** (`AttendanceReportController::index():105-123`) and hands the same unbounded set to dompdf | blank page at ~5,000 |
| **Bulk writes in a web request** | `ensurePayrollHistory():2340-2381` inserts one row per employee per month, no batching, no transaction, no queue — **120,012 single-row inserts in one web request** at 10,000 employees | cannot complete; dies part-way having written an arbitrary prefix of fabricated `status='paid'` runs into `payslips`, and the existence check then skips those months forever |
| **Unbounded audit reads** | `Audit::verify()` reads the **entire** `activity_logs` table — no chunk, no tenant filter, no limit — then hashes every row, whenever an administrator opens Activity Logs; `Audit::record()` scans for the chain head on **every** audited write | guaranteed OOM; the write path serialised against the largest table in the schema |
| **Schema introspection in hot paths** | **738** `Schema::hasTable`/`hasColumn` calls; `safeRow()` calls `getColumnListing()` on **every row it writes** at 98 sites | a 10,000-payslip run issues 10,000 `SHOW COLUMNS` |
| **Index-defeating predicates and missing indexes** | 46 `whereRaw('LOWER(…))` and 15 `whereDate(…)` sites; `attendance_logs` has no `(tenant_id, log_date)` composite; `activity_logs` has no `created_at` index | full scans on the hottest tables, on every dashboard load |

### XXXI.9.2 The scale model

Planning baseline, per tenant, 4 punches per employee per day, ~250 working days per year
(`M-…` §6.3), five years retained:

| | 1,000 employees | 5,000 | 10,000 |
|---|---:|---:|---:|
| Punches per year | 1.0 M | 5.0 M | 10.0 M |
| Punches, 5 years | 5.0 M | 25.0 M | 50.0 M |
| Attendance days per year | 0.37 M | 1.83 M | 3.65 M |
| Payslips per year | 12,000 | 60,000 | 120,000 |
| Payslip lines per year (≈25/slip) | 0.30 M | 1.50 M | 3.00 M |
| Audit ledger rows per year (planning: ≈20/employee/month) | 0.24 M | 1.20 M | 2.40 M |

### XXXI.9.3 Performance budgets

Identical to the assertions in Part XXX §XXX.9.2, restated as production SLIs so a regression
is caught in the field as well as in CI.

| Operation | 1,000 | 5,000 | 10,000 | Also required |
|---|---|---|---|---|
| **Payroll run, end to end** (queued, staged) | ≤ 2 min | ≤ 8 min | ≤ 15 min | per-stage timings; per-employee progress; restartable at any stage; ≤ 512 MB peak per worker |
| **Payroll run, query count** | — | — | — | **`O(1)` per stage**, not `O(N)`: one grouped attendance aggregate, one grouped leave aggregate, one grouped recovery fetch |
| **Attendance ingestion** | ≥ 2,000 punches/s | ≥ 2,000 | ≥ 2,000 | sustained over a 1 M-punch batch; constant memory; `O(1)` queries per batch; per-punch verdict still returned |
| **Attendance day derivation** (whole tenant) | ≤ 3 min | ≤ 12 min | ≤ 25 min | idempotent; a second pass yields zero diffs within budget |
| **Attendance report, one month** | ≤ 3 s p95 | ≤ 5 s | ≤ 8 s | streamed, paginated, range-capped; ≤ 256 MB peak |
| **Report / export, any** | ≤ 30 s queued | ≤ 2 min | ≤ 5 min | streamed to storage; never assembled as one string; PDFs queued |
| **Employee list, page 1** | ≤ 400 ms p95 | ≤ 400 ms | ≤ 400 ms | identical at every scale — the proof that pagination is real |
| **Dashboard** | ≤ 800 ms p95 | ≤ 1 s | ≤ 1.2 s | ≤ 15 queries |
| **`/api/v3` read** | ≤ 300 ms p95 / ≤ 800 ms p99 | same | same | bounded result set |
| **`/api/v3` write** | ≤ 500 ms p95 | same | same | idempotency key honoured within budget |
| **Login** | ≤ 500 ms p95 | same | same | constant-time comparison |
| **Rule set evaluation, one employee-day** | ≤ 5 ms p95 | same | same | within the SPX node/time budget |
| **Formula graph, one payslip** | ≤ 20 ms p95 | same | same | — |

### XXXI.9.4 Storage growth and headroom

Per-row figures are **planning budgets to be validated by the load harness**, not measurements.
Each is a declared budget: a row shape exceeding its budget is a schema review, which is how
storage growth stays predictable.

| Store | Row budget (incl. index) | 10,000 employees, per year | 5 years |
|---|---:|---:|---:|
| `punch_events` (raw, append-only, payload + hash) | ≈ 500 B | ≈ 5.0 GB | ≈ 25 GB |
| `punch_normalised` | ≈ 200 B | ≈ 2.0 GB | ≈ 10 GB |
| `attendance_days` (derived, supersedable) | ≈ 400 B | ≈ 1.5 GB | ≈ 7.3 GB |
| `payslips` + lines | ≈ 250 B/line | ≈ 0.8 GB | ≈ 4.0 GB |
| Audit ledger | ≈ 600 B | ≈ 1.4 GB | ≈ 7.2 GB |
| Documents (object store, ≈ 6 docs/employee/year @ 250 KB) | — | ≈ 15 GB | ≈ 75 GB |
| Logs, metrics, traces (retained window only) | — | per retention policy | — |
| **Database total (planning)** | | **≈ 11 GB/yr** | **≈ 54 GB** |

Growth is **measured**, not assumed: `smartprs_db_size_bytes` and `_growth_bytes_per_day` per
table class feed a projected-exhaustion date on the capacity panel, and
`capacity.headroom` alerts on the declared horizon.

### XXXI.9.5 Scaling levers

| Budget under pressure | Levers, in order of preference |
|---|---|
| **Payroll run duration** | 1. Confirm `O(1)` per stage — an `O(N)` regression is a defect, not a capacity problem. 2. Parallelise the per-employee stages across workers within one run. 3. Raise the `bulk` worker count. 4. Read replicas for the snapshot stages. 5. Shard the tenant (Part XXV §XXV.12.6) |
| **Ingestion throughput** | 1. Batch size and bulk upsert. 2. `integration` worker count. 3. Partition `punch_events` by month. 4. Move raw retention beyond the hot window to cold storage |
| **Report/export generation** | 1. Stream; never materialise. 2. Cursor pagination. 3. Pre-aggregate into read models for the heaviest reports. 4. Dedicated reporting worker pool and read replica |
| **API/page latency** | 1. Query-count budget per endpoint. 2. Index review. 3. Cache resolved configuration (versioned, so cache invalidation is a version change, not a guess). 4. Horizontal web scale |
| **Queue delay** | 1. Class separation (already). 2. Per-tenant share caps. 3. Worker count per class. 4. Move the class to its own worker pool |
| **Database size** | 1. Enforce retention classes. 2. Partition the two large append-only tables by period. 3. Archive superseded derived rows to cold storage. 4. Move documents out of the database entirely (they already are) |
| **Audit ledger growth** | 1. Retention class per entity. 2. Partition by period. 3. Chunked, tenant-scoped verification with checkpoints — never a full-table read |

- **R-XXXI-110** Every budget in §XXXI.9.3 MUST be measured in production as an SLI, not only
  asserted in CI. *Test: each budget has a named metric in §XXXI.6.5 and a dashboard panel.*
- **R-XXXI-111** No endpoint may return an unbounded result set; pagination with a
  server-enforced maximum page size is mandatory, and any truncation MUST carry an explicit
  indicator and a cursor. *Test: `smartprs_http_response_rows` has a hard ceiling per route —
  the direct fix for the boot payload and for the silent `limit(1000)` that makes archived
  employee 1,001 invisible forever.*
- **R-XXXI-112** Query count per request and per job stage MUST be bounded and asserted, and
  MUST NOT grow with the size of a business collection. *Test: Part XXX gate G-12; in
  production, `smartprs_db_queries_per_request`.*
- **R-XXXI-113** No export, report or document may be assembled entirely in memory. *Test: a
  10,000-employee export completes within a declared constant memory ceiling.*
- **R-XXXI-114** No schema introspection may occur in any request or job path. *Test: a
  query-log filter asserts zero introspection calls (738 exist today).*
- **R-XXXI-115** Bulk writes MUST be batched, transactional and queued. *Test: no request path
  issues more than the declared write count — the direct fix for the 120,012 single-row inserts
  in one web request.*
- **R-XXXI-116** Every table's declared index set MUST exist and be asserted, and no query may
  wrap an indexed column in a function. *Test: the schema index assertion plus the
  index-defeating-predicate check.*
- **R-XXXI-117** Every store MUST declare a per-row size budget and a retention class, and
  actual growth MUST be measured against the projection. *Test: a row shape exceeding its budget
  fails the schema review gate; the capacity panel shows projected exhaustion.*
- **R-XXXI-118** The load harness MUST be run at all three scales before any release that
  changes a hot path, and the reference architectures (Part XXVI §XXVI.3) MUST NOT be published
  to customers without a harness run behind them. *Test: sizing guidance cites a harness run,
  not an estimate.*

---

## XXXI.10 On-call, incident management and post-incident review

Sized for Ametecs: a small engineering team supporting a SaaS estate and an on-premise
installed base. The practice below is deliberately modest — a rota nobody sustains is worse
than an honest business-hours commitment.

### XXXI.10.1 Severity

| Sev | Definition | Examples | Response target | Update cadence |
|---|---|---|---|---|
| **S1** | Product unusable for many tenants, or money/data integrity at risk, or a security incident | total unavailability; a reconciliation assertion failed; an audit-chain break; no worker for a class estate-wide; a payroll run aborted on the run day; suspected breach | **15 min** ack, business hours and after hours | hourly until contained |
| **S2** | Significant degradation, or one tenant's critical function down, or a control not working | scheduler stale; queue backlog beyond threshold; an integration stale approaching payroll; backup overdue; a tenant unable to run payroll | **1 h** in business hours; **next morning** after hours unless it is payroll day or a filing deadline, when it is treated as S1 | at each shift |
| **S3** | Degraded but with a workaround; no immediate customer harm | elevated error rate within budget; a restore drill overdue; capacity headroom warning | next business day | daily |
| **S4** | Cosmetic, or an internal-only condition | alert noise; a non-blocking prerequisite | scheduled | — |

**Escalation to S1 is automatic**, not discretionary, when: money is wrong or at risk; the audit
ledger's integrity is in question; PII may have been exposed; or a statutory deadline is
threatened.

### XXXI.10.2 The rota

- **One primary on-call, one secondary**, weekly rotation, minimum two people in the pool. If
  the pool falls below two, after-hours cover is formally suspended and customers are told —
  never quietly dropped.
- **Coverage:** business hours full; after hours for **S1 only**, plus S2 during a declared
  **critical window** — payroll days, month-end close, and open statutory filing windows,
  published in advance.
- **Every alert that pages carries a runbook.** A pageable alert with no runbook is deleted or
  downgraded at the weekly review. This is the discipline that keeps a two-person rota viable.
- **Handover is written**: open conditions, anything deferred, anything watched.
- Fair compensation and time-in-lieu are a management commitment, recorded in the rota policy —
  stated here because an unfunded rota decays into no rota, which is precisely the state
  SmartPRS 2's operations are in.

### XXXI.10.3 Running an incident

1. **Declare.** Anyone may declare. Over-declaring is free; under-declaring is not.
2. **Roles.** At this size, one **incident lead** (decides, communicates, does not debug) and
   one or more **responders**. For S1 the lead does not also debug.
3. **One channel, one timeline.** Every observation, action and decision is timestamped in the
   incident record as it happens — reconstruction afterwards is unreliable.
4. **Mitigate before diagnose.** Restore service first; understand second. Roll back, fail over,
   contain the tenant, pause the class.
5. **Change control during an incident:** every change is announced in the timeline, is
   reversible, and is made by one person at a time.
6. **Declare resolved** only when the triggering signal has recovered **and** stayed recovered
   for the declared observation window.
7. **Close** with a review (§XXXI.10.5).

### XXXI.10.4 Communicating with affected tenants

- **Who is affected is a query, not a guess** — every metric carries a tenant dimension
  (Part XXV R-XXV-132), so the affected set is enumerable.
- **The first message goes out inside the S1 acknowledgement window**, before the cause is
  known. It says: what is not working, who is affected, what we are doing, when the next update
  comes. It does not speculate.
- **The tenant's own status feed** carries the same fact the support engineer sees
  (Part XXV R-XXV-135) — one truth, not two.
- **On-premise installations are notified through the update/advisory channel** (Part XXVII),
  and an advisory affecting data integrity or security is delivered whether or not the customer
  has telemetry enabled.
- **After resolution**, affected tenants receive a written summary: what happened, the window,
  the impact on their data, what was done, and what changes so it does not recur. For a money
  or statutory impact this is a formal notice, not an e-mail.

### XXXI.10.5 Post-incident review

**Blameless, mandatory for every S1 and every repeat S2, within five working days.** The
framing is *"what about the system allowed this?"* — because every finding in §XXXI.1 was
written by a competent engineer under delivery pressure, and blame would have prevented none of
them.

The review produces, at minimum:

1. A **timeline**: from the first signal to resolution, with detection time and mitigation time
   called out separately — the gap between them is usually the biggest lever.
2. **Contributing conditions**, plural. "Human error" is not a finding; the finding is what
   made the error possible and undetected.
3. **Detection assessment.** Was there an alert? Did it fire? Was it actionable? If a customer
   told us first, that is the primary finding.
4. **At least one requirement and at least one test.** A review that produces neither has not
   finished. The test is written before the review is closed.
5. **Actions with owners and dates**, tracked to completion in the same place as product work,
   not in a document nobody reopens.
6. A **runbook update** if any step was missing, wrong or unexecutable.

- **R-XXXI-120** Every alert that can page MUST have a runbook and a named owner. *Test: an
  alert definition failing either check fails the observability validation.*
- **R-XXXI-121** Every incident MUST have a machine-readable record: severity, timeline,
  affected tenants, containment actions (each cross-referenced to its audit entry), resolution
  and review outcome. *Test: "how many S1s touched payroll this year, and what did we change?"
  is answerable from data.*
- **R-XXXI-122** The affected-tenant set MUST be derivable from telemetry, not estimated.
  *Test: an injected fault in one tenant's queue class produces an affected set of exactly that
  tenant.*
- **R-XXXI-123** Every S1 and every repeat S2 MUST have a blameless review within five working
  days producing at least one requirement and one test. *Test: an S1 cannot be closed without a
  linked review, requirement and test case.*
- **R-XXXI-124** Detection time and mitigation time MUST be recorded separately for every
  incident and MUST be a reviewed trend. *Test: both are fields on the incident record and are
  reported quarterly.*
- **R-XXXI-125** No incident may be closed with "monitoring added" as its only action unless the
  review states explicitly why no structural fix is possible. *Test: a review assertion.*
- **R-XXXI-126** Alert volume, alert actionability and page count MUST be reviewed weekly, and
  an alert that fired without action three times MUST be deleted, downgraded or fixed.
  *Test: the weekly review is generated from `operations_alerts`, not from memory.*
- **R-XXXI-127** After-hours cover MUST be a published, staffed commitment; if the pool falls
  below two people, cover MUST be formally suspended and affected customers informed.
  *Test: the rota policy names the pool and the suspension procedure. See **D-31-02**.*

---

## XXXI.11 Configuration surface

Tested against the brief's Section 127 standard: nothing in this list requires code, a
deployment, or a support call. Everything in it is effective-dated and versioned where it
changes behaviour over time (spine S4).

| # | What can be changed | By whom | Where | Section 127 check |
|---:|---|---|---|---|
| 1 | Any scheduled job's cadence, timezone source, enabled state, lock TTL, max runtime, `max_lookback`, per-run item budget, failure action and alert channel | operator (Ametecs on SaaS; the customer on-prem) | `scheduled_jobs` | *"move the compliance scan to 05:30 and widen the biometric look-back to 7 days"* — configuration |
| 2 | Which tenants a `tenant`-scoped job covers, and per-tenant enable | operator | `scheduled_jobs` + tenant configuration | — |
| 3 | Per-tenant timezone and send-hour for every notifying job | tenant administrator | tenant configuration | — |
| 4 | Reminder ladders (day offsets), grace durations, bucketing thresholds, digest item caps, and audiences expressed as roles, named users or distribution lists | tenant administrator | rule artefacts (Part VII) | *"remind at 30/14/7/1 instead of 15/7/3/1/0, and copy the Compliance Officer role"* — configuration |
| 5 | Queue class worker counts, per-tenant concurrency shares, attempts, backoff schedules, expiry per job type | operator | deployment profile + queue policy | — |
| 6 | Log level per channel, retention window, rotation size, sink, and the redaction profile (strict/standard) | operator | observability configuration | — |
| 7 | Which metrics are exported, to which sink, at what resolution and retention | operator | observability configuration | — |
| 8 | Trace sampling rate, and the always-sampled operation list | operator | observability configuration | — |
| 9 | Every alert rule: threshold, `for` duration, severity, channel, recipients, escalation policy, auto-close, quiet hours | operator; recipients also by tenant administrator for tenant-facing alerts | `alert_rules` | *"page us for queue backlog only during payroll week"* — configuration |
| 10 | SLO objectives, windows and burn-rate thresholds | Ametecs (platform) | `slo_definitions` | — |
| 11 | Health-check thresholds, severities and remediation text | operator | `health_checks` (Part XXVI) | — |
| 12 | Backup policy: schedule, sets, destinations, encryption key, retention, verification depth, drill cadence, RTO/RPO targets | operator | `backup_policies` (Part XXVI) | — |
| 13 | Performance budgets per operation and per scale band, and the capacity-headroom horizon | Ametecs | `qa_budgets` (Part XXX) + capacity configuration | — |
| 14 | Retention class per store, including every log-shaped table | Ametecs, within statutory floors; tenant may extend | retention classes (spine S16) | — |
| 15 | Release-flag state per environment and cohort | engineering only | flag store | Never customer-visible (R-XXXI-030) |
| 16 | Canary cohort membership and soak window | Ametecs | release configuration | — |
| 17 | Maintenance-mode scope, message and expected end time | operator | maintenance configuration | — |
| 18 | On-call rota, escalation targets, critical windows | Ametecs | rota policy | — |
| 19 | Telemetry egress on/off, sink and redaction profile | the customer, on-prem | deployment profile | Off by default (**D-31-03**) |
| 20 | Incident severity thresholds that auto-escalate | Ametecs | incident policy | — |

**Not configurable, deliberately:** the metric names and log schema (renaming them breaks every
dashboard and every historical query); the exception taxonomy; the redaction rule itself — the
*profile* may be made stricter, never weaker; the requirement that a scheduler and a worker
exist; and the fact that a failure is surfaced. A customer may choose **where** an alert goes,
never **whether** a failure is visible.

---

## XXXI.12 Data model

Specified to the Section 123 standard. Tables owned elsewhere are referenced, not restated:
`scheduled_jobs`, `scheduled_job_runs`, `health_checks`, `health_check_results`,
`backup_policies`, `backup_runs`, `restore_runs`, `operations_alerts` and `installation` are
Part XXVI §XXVI.12; `tenant_quotas` and `quota_events` are Part XXV §XXV.14.18; `qa_budgets` is
Part XXX §XXX.12.3.

All tables below are **installation-scoped**, not tenant-scoped: they describe the running
system rather than a customer's business data. They are the documented exception to Law 6 that
Part XXVI §XXVI.12 already establishes, and they hold **no tenant business data** — tenant
attribution is by `tenant_id` reference only, and no PII is stored in any of them
(R-XXXI-062).

### XXXI.12.1 `alert_rules`

*Purpose:* the alert catalogue as data — the direct replacement for `ErrorDigest.php`'s
hard-coded severity set, hit count, truncation and scan window.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | no | auto | PK |
| `alert_key` | varchar(120) | no | — | stable dedup key; **unique** |
| `name`, `description` | varchar(200) / text | no / yes | — | operator-facing |
| `signal_expression` | text | no | — | over the metric catalogue; validated at save |
| `for_seconds` | int unsigned | no | 0 | dwell before firing |
| `severity` | varchar(8) | no | — | FK → `ref_alert_severities` (S1…S4); reference table, **not** a DB enum (spine S16) |
| `scope` | varchar(16) | no | `platform` | `platform` \| `tenant` |
| `routing_json` | json | no | — | channels and recipient references; **no e-mail literal**, references a recipient set |
| `escalation_policy_key` | varchar(80) | yes | null | FK → `escalation_policies` |
| `runbook_ref` | varchar(200) | no | — | **required**; R-XXXI-083 |
| `auto_close_expression` | text | no | — | **required** |
| `quiet_hours_json` | json | yes | null | with an explicit override for S1 |
| `enabled` | boolean | no | true | |
| `owner_role` | varchar(60) | no | — | |
| `effective_from` / `effective_to` | date | no / yes | — | spine S4 |
| `version_no` | int | no | 1 | never updated in place (Law 3) |
| `created_by`, `created_at`, `superseded_by_id` | — | — | — | audited |

PK `id` · Unique `(alert_key, version_no)` · Unique `(alert_key)` where `effective_to IS NULL`
· Index `(enabled, severity)`. History: versioned. Soft delete: no — superseded. Audit: full
field-level. PII: none.

### XXXI.12.2 `alert_notifications`

*Purpose:* proof of delivery for an alert, on a transport independent of the application queue.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned | no | PK |
| `operations_alert_id` | bigint unsigned | no | FK → `operations_alerts` (Part XXVI), `ON DELETE RESTRICT` |
| `channel` | varchar(24) | no | FK → `ref_alert_channels` |
| `recipient_ref` | varchar(120) | no | **a reference to a recipient set, never an address** |
| `attempt_no` | int | no | |
| `state` | varchar(16) | no | `queued` \| `sent` \| `delivered` \| `failed` \| `suppressed` — five distinct states (R-XXXI-048; Part XXVI R-XXVI-174) |
| `sent_at`, `delivered_at`, `failed_at` | datetime(3) | yes | UTC |
| `error_class`, `error_code` | varchar(80) | yes | taxonomy class; **never a raw message** |

PK `id` · Index `(operations_alert_id, attempt_no)` · Index `(state, sent_at)`. Retention: log
class. PII: none — recipients are references.

### XXXI.12.3 `slo_definitions`, `slo_windows`

*Purpose:* SLOs and error budgets as data, so a burn-rate freeze is automatic.

`slo_definitions`: `slo_key` (unique), `name`, `journey`, `sli_expression`,
`objective_ratio` decimal(6,5), `window_days`, `owner_role`, `fast_burn_multiple`,
`slow_burn_threshold_ratio`, `enabled`, `effective_from`/`effective_to`, `version_no`.
PK `id` · Unique `(slo_key, version_no)` · versioned, audited.

`slo_windows`: `slo_key`, `window_start`, `window_end`, `good_events`, `valid_events`,
`achieved_ratio`, `budget_consumed_ratio`, `freeze_active`, `freeze_lifted_by`,
`freeze_lifted_at`, `freeze_reason`.
PK `id` · Unique `(slo_key, window_start)` · Index `(freeze_active)`. Append-only; a freeze
lift is a recorded, audited act.

### XXXI.12.4 `deployments`

*Purpose:* the answer to "what changed on the day this broke?" — a question SmartPRS 2 cannot
answer, because its update path records only a client-update action log.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned | no | PK |
| `deployment_uuid` | char(36) | no | UUIDv7, unique; the external identity |
| `environment` | varchar(24) | no | FK → `ref_environments` |
| `app_version`, `artefact_checksum` | varchar(40) / char(64) | no | |
| `profile_key`, `profile_version` | varchar(24) / varchar(20) | no | |
| `migration_set_hash`, `schema_fingerprint_before`, `schema_fingerprint_after` | char(64) | no / yes | R-XXXI-026 |
| `flags_json` | json | no | flag state at deploy time (R-XXXI-031) |
| `strategy` | varchar(16) | no | `canary` \| `full` \| `rollback` |
| `canary_cohort_ref` | varchar(80) | yes | |
| `started_at`, `finished_at` | datetime(3) | no / yes | UTC |
| `outcome` | varchar(16) | no | `succeeded` \| `failed` \| `rolled_back` |
| `health_result_ref` | bigint unsigned | yes | FK → `health_check_results` |
| `rollback_point_ref` | varchar(200) | yes | the code artefact and backup identity |
| `actor_id`, `approved_by` | bigint unsigned | no / yes | |
| `notes` | text | yes | |

PK `id` · Unique `(deployment_uuid)` · Index `(environment, started_at DESC)` · Index
`(app_version)`. Append-only. Audit: the record **is** the audit. PII: none.

### XXXI.12.5 `release_flags`, `release_flag_states`

`release_flags`: `flag_key` (unique), `description`, `owner_role`, `created_at`,
`removal_due_on` **NOT NULL** (R-XXXI-028), `removed_at`, `linked_release`.
`release_flag_states`: `flag_key`, `environment`, `cohort_ref`, `state` (`off`/`on`/`cohort`),
`changed_by`, `changed_at`, `reason`.
PK `id` · Unique `(flag_key, environment, cohort_ref)` · Index `(removal_due_on)` — the index
the build reads to fail on an expired flag. Append-only state history. PII: none.

### XXXI.12.6 `job_dead_letters`

*Purpose:* the operator-visible failure record. SmartPRS 2's `failed_jobs` *"has no reader,
nothing prunes it, and no `Queue::failing()` listener exists"* (DEF-L-23).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned | no | PK |
| `dead_letter_uuid` | char(36) | no | UUIDv7, unique |
| `queue_class`, `job_key`, `job_class` | varchar(40)/(80)/(200) | no | |
| `tenant_id` | bigint unsigned | yes | null only for platform work |
| `correlation_id`, `request_id` | char(36) | no | ties to logs, traces and the audit ledger |
| `idempotency_key` | varchar(120) | no | makes replay safe (R-XXXI-094) |
| `payload_ref` | varchar(200) | no | **a reference, never the payload**; no file bytes, no PII (R-XXXI-063) |
| `attempts_json` | json | no | per attempt: timestamp, `error_class`, `error_code`, sanitised summary |
| `first_failed_at`, `dead_lettered_at` | datetime(3) | no | UTC |
| `state` | varchar(16) | no | `open` \| `replayed` \| `discarded` |
| `replayed_at`, `replayed_by`, `discard_reason`, `discarded_by` | — | yes | discarding requires a reason |

PK `id` · Unique `(dead_letter_uuid)` · Index `(state, dead_lettered_at DESC)` · Index
`(tenant_id, state)` · Index `(job_key, state)`. Retention: log class, pruned by
`ops.log.prune`. Audit: replay and discard are audited actions. PII: none.

### XXXI.12.7 `incidents`, `incident_events`, `incident_tenants`

`incidents`: `incident_uuid` (unique), `severity`, `title`, `declared_at`, `declared_by`,
`detected_at`, `mitigated_at`, `resolved_at`, `closed_at`, `lead_user_id`, `trigger_source`
(`alert`/`customer`/`internal`), `source_alert_key`, `summary`, `review_ref`,
`requirement_refs_json`, `test_refs_json`.
PK `id` · Unique `(incident_uuid)` · Index `(severity, declared_at DESC)` · Index
`(resolved_at)`. R-XXXI-121, R-XXXI-123, R-XXXI-124 read these columns directly; an S1 cannot
be closed with `review_ref`, `requirement_refs_json` or `test_refs_json` empty.

`incident_events`: `incident_id`, `at`, `actor_id`, `kind`
(`observation`/`action`/`decision`/`communication`), `detail`, `audit_entry_ref`.
PK `id` · Index `(incident_id, at)`. Append-only; every containment action carries its audit
reference (Part XXV R-XXV-135).

`incident_tenants`: `incident_id`, `tenant_id`, `impact`, `notified_at`, `notice_ref`.
PK `id` · Unique `(incident_id, tenant_id)`. Derived from telemetry, not typed by hand
(R-XXXI-122).

- **R-XXXI-130** No operations table may store PII, a credential, a message body or a file
  byte. *Test: a schema assertion over the classification of every column in this section, plus
  `OBS-REDACT-01`.*
- **R-XXXI-131** Every log-shaped table — `scheduled_job_runs`, `health_check_results`,
  `operations_alerts`, `alert_notifications`, `job_dead_letters`, `incident_events`, and every
  delivery ledger — MUST have a declared retention class enforced by a scheduled job.
  *Test: `ops.log.prune` bounds each; SmartPRS 2 prunes none of its six log-shaped tables and
  does not schedule `activitylog:clean` at all (DEF-L-29).*
- **R-XXXI-132** Every table in this section MUST be created by a migration, and no application
  path may create or alter it. *Test: Law 2's CI check — the direct fix for `mail_log`,
  `wa_log`, `notification_log`, `subscription_alerts` and `probation_events`, each created by
  application code on **every** scheduled run inside a swallowing catch (DEF-L-08).*
- **R-XXXI-133** Operations records MUST be append-only; a status change is a new row or a
  recorded transition, never an in-place overwrite (Law 3). *Test: append-only grants asserted
  in CI.*
- **R-XXXI-134** Every operations record that describes a failure MUST carry the
  `correlation_id` that ties it to logs, traces and the audit ledger. *Test: from any
  dead-letter record, the complete history of the originating operation is retrievable.*

---

## XXXI.13 API surface

All under `/api/v3`, all with explicit policy bindings (Law 5), all returning the Part XXIV
envelope. The three unauthenticated probe endpoints return a status token only (R-XXXI-068).

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/health/live` | *(none)* | liveness |
| GET | `/health/ready` | *(none)* | readiness — asserts real dependencies |
| GET | `/health/startup` | *(none)* | startup complete |
| GET | `/metrics` | network-restricted + bearer | metric exposition |
| GET | `/api/v3/ops/health` | `ops.health.view` | full check catalogue with status, threshold, onset, remediation |
| GET | `/api/v3/ops/health/{check_key}` | `ops.health.view` | one check with history |
| POST | `/api/v3/ops/health/run` | `ops.health.run` | run the catalogue now |
| GET | `/api/v3/ops/scheduler` | `ops.runtime.view` | heartbeat, per-job last run, cursor, lease state |
| POST | `/api/v3/ops/scheduler/jobs/{job_key}/run` | `ops.runtime.manage` | run now |
| POST | `/api/v3/ops/scheduler/jobs/{job_key}/catch-up` | `ops.runtime.manage` | bounded catch-up over a named range (R-XXXI-103) |
| PATCH | `/api/v3/ops/scheduler/jobs/{job_key}` | `ops.runtime.manage` | cadence, enable, TTL, `max_lookback` |
| DELETE | `/api/v3/ops/scheduler/jobs/{job_key}/lease` | `ops.runtime.manage` | clear a stale lease |
| GET | `/api/v3/ops/queues` | `ops.runtime.view` | per class: depth, oldest age, throughput, workers, versions |
| POST | `/api/v3/ops/queues/{class}/pause` · `/resume` · `/restart-workers` | `ops.runtime.manage` | containment levers |
| GET | `/api/v3/ops/dead-letters` | `ops.runtime.view` | cursor-paginated, filterable by class, job, tenant, state |
| POST | `/api/v3/ops/dead-letters/{uuid}/replay` | `ops.runtime.manage` | idempotent replay (R-XXXI-094) |
| POST | `/api/v3/ops/dead-letters/{uuid}/discard` | `ops.runtime.manage` | requires a reason |
| GET | `/api/v3/ops/notifications` | `ops.delivery.view` | counts by state, oldest queued age |
| POST | `/api/v3/ops/notifications/{uuid}/retry` | `ops.delivery.manage` | |
| GET | `/api/v3/ops/integrations` | `ops.integration.view` | per connector/device health, watermark, quarantine |
| POST | `/api/v3/ops/integrations/{id}/test` · `/sync` · `/rewind` | `ops.integration.manage` | `rewind` bounded by `max_lookback` |
| GET | `/api/v3/ops/alerts` | `ops.alert.view` | open and recent conditions |
| POST | `/api/v3/ops/alerts/{alert_key}/acknowledge` | `ops.alert.manage` | |
| GET · PUT | `/api/v3/ops/alert-rules` | `ops.alert.configure` | the catalogue as configuration |
| GET | `/api/v3/ops/slos` | `ops.slo.view` | objectives, achieved ratio, budget consumed, freeze state |
| GET | `/api/v3/ops/deployments` | `ops.deployment.view` | deployment records |
| GET | `/api/v3/ops/capacity` | `ops.capacity.view` | volumes, growth, projected exhaustion |
| GET | `/api/v3/ops/diagnostics` | `ops.diagnostics.export` | the redacted diagnostic bundle |
| GET | `/api/v3/ops/incidents` · POST `/incidents` · POST `/incidents/{uuid}/events` | `ops.incident.*` | incident record |
| GET | `/api/v3/ops/trace/{correlation_id}` | `ops.trace.view` | logs, spans and audit entries for one operation |

Conventions (spine S17): cursor pagination on every collection; idempotency keys on every
unsafe method; the Part XXIV error envelope; and **no operations endpoint returns PII** beyond
the caller's data scope.

---

## XXXI.14 Events

Emitted by this part, consumed by the operations console, the alert evaluator, the audit ledger
and (where the profile permits) the telemetry exporter.

| Event | Payload (identifiers only) | Consumed by |
|---|---|---|
| `ops.scheduler.heartbeat_stale` / `.recovered` | last heartbeat, threshold | alerting, console, banner |
| `ops.scheduled_job.completed` | job_key, outcome, items attempted/succeeded/failed, duration, cursor | console, metrics, alerting |
| `ops.scheduled_job.partial` | job_key, failed item refs | alerting |
| `ops.scheduled_job.timed_out` / `.lease_expired` | job_key, lease id | alerting |
| `ops.worker.absent` / `.recovered` | queue class, detection window | alerting, banner |
| `ops.queue.backlog_breached` / `.recovered` | class, depth, oldest age | alerting |
| `ops.job.dead_lettered` | dead_letter_uuid, job_key, tenant, error class | console, alerting |
| `ops.job.replayed` / `.discarded` | dead_letter_uuid, actor, reason | audit |
| `ops.integration.stale` / `.recovered` / `.gap_detected` | connector, device, watermark, interval | alerting, attendance exception list |
| `ops.notification.delivered` / `.failed` / `.expired` | message ref, channel, state | delivery ledger, metrics |
| `ops.invariant.violated` | invariant key, operation ref, correlation id | **S1 alert**, incident |
| `ops.suppression.triggered` | suppression id, count | metrics — a declared suppression is never invisible |
| `ops.gate.transient_failure` | gate, error class | security metrics, alerting |
| `ops.health.status_changed` | check_key, from, to, onset | console, alerting |
| `ops.deployment.started` / `.completed` / `.failed` / `.rolled_back` | deployment_uuid, version, outcome | console, audit, incident |
| `ops.flag.changed` | flag_key, environment, cohort, state, actor | deployment record, audit |
| `ops.slo.budget_threshold_crossed` / `.fast_burn` / `.freeze_activated` / `.freeze_lifted` | slo_key, ratio, actor | alerting, release gate |
| `ops.capacity.headroom_breached` | volume, projected exhaustion date | alerting |
| `ops.incident.declared` / `.mitigated` / `.resolved` / `.closed` | incident_uuid, severity | console, communications, review tracking |
| `ops.alert.opened` / `.acknowledged` / `.closed` | alert_key, severity, actor | console, weekly review |

**Consumed** from elsewhere: `backup.completed`/`.failed`, `restore.drill.completed`
(Part XXVI); `payroll.run.*` (Part XIV); `attendance.ingest.*` (Part XI);
`audit.chain.verification.*` (Part XXIII); `licence.*` and `rulepack.*` (Part XXVII);
`subscription.*` (Part XXV).

---

## XXXI.15 Migration implication

| From SmartPRS 2 | Verdict | Notes |
|---|---|---|
| The twelve schedule entries at `routes/console.php:143-266` | **Seed as defaults, then replace** | Their *cadences* seed `scheduled_jobs`; their implementations do not carry across — every one lacks isolation, cursoring, run rows and honest outcomes |
| `demo:reset`, `billing:renew`, `app:clear-state`, `attendance:purge-demo`, `attendance:dedupe`, `invoices:renumber-prs` | **Removed** | Destructive, dead, or obsolete (`L-…` §9). `demo:reset` must not exist in a customer artefact at all (R-XXXI-009, SEC-L-01) |
| `payroll:period-audit` | **Retained and promoted** | Becomes scheduled job 13 with failure alerting — *"the only command that returns FAILURE on a data condition"* |
| The idempotent upsert on `(tenant_id, emp_code, punch_at, source)` and the quarantine-and-replay design | **Preserved** | *"the two best pieces of engineering in this area and must survive the rebuild"* (`L-…` requirement 25) |
| The per-tenant try/catch shape at `SubscriptionAlerts.php:135-137` | **Preserved as the framework default** | The one correct fault-isolation implementation in the codebase; it becomes a guarantee rather than a choice (R-XXXI-038) |
| `jobs`, `failed_jobs` | **Not migrated as data** | The queue is drained before cutover; the schema is replaced. `job_batches` is dead schema and is dropped |
| `mail_log`, `notification_log`, `subscription_alerts`, `client_updates` | **Migrated to the delivery ledger**, with `queued`/`sent` **re-derived** | Historical rows whose `sent` status was written before dispatch (`MailService.php:119`) are migrated as `unknown`, not as `delivered` — the ledger must not inherit a false assertion (DEF-L-13) |
| `wa_log` | **Migrated with `body_values` dropped entirely** | It stores OTPs and plaintext administrator passwords; the column is not migrated, and the source is purged after cutover (SEC-L-03) |
| `laravel.log` and existing archives | **Not migrated** | Unstructured, unredacted, containing PII from ~60 `getMessage()` sites. Retained per the customer's legal hold, then destroyed; never imported into the new log store |
| Existing backup archives | **Not a migration input** | Unencrypted, on-box, `.env`-bearing, of unknown retention (SEC-L-06). Preserved separately under access control for the customer's declared retention, then destroyed |
| `activity_logs` | **Part XXIII** | Its chain is global rather than per tenant and is already broken; re-anchoring is that part's concern |
| `deploy/` | **Nothing to migrate — it does not exist** | The runtime is authored fresh and ships inside the artefact (R-XXXI-014) |

**Cutover-specific operations requirements:**

- The old and new systems run in parallel for at least one full payroll cycle, with the
  parallel-run diff harness (Part XXX §XXX.8.3) reconciling to the paise.
- The new installation's scheduler, workers, backup and **restore drill** are all proven green
  before any tenant is cut over — the drill date is a go-live gate, not a follow-up action.
- The first thirty days after cutover run with tightened alert thresholds and a daily
  operations review, because baselines are not yet known.

---

## XXXI.16 Edge cases and failure states

| # | Case | Required behaviour |
|---:|---|---|
| 1 | The scheduler runs but one job never succeeds | `scheduled_job_last_success_age` per job is a distinct signal from the heartbeat; the job alerts on its own |
| 2 | The scheduler process is alive but wedged (no ticks) | The heartbeat is derived from **completed run rows**, not process liveness (R-XXXI-036) |
| 3 | A job holds a lease and its host dies | The lease is heartbeat-renewed and expires; the job runs at its next cadence without operator action (R-XXXI-037) |
| 4 | Two schedulers run simultaneously after a failover | Single-execution leasing; the second observes the lease and records `skipped_locked`, not a failure |
| 5 | The clock jumps backwards (NTP correction) | Cursors are monotonic and never rewind on a clock jump; a jump beyond tolerance is a health failure (Part XXVI check 20) |
| 6 | A tenant's timezone changes mid-year | Schedule gates resolve the timezone effective on the evaluation date; a change is effective-dated like any other configuration |
| 7 | DST transition in a tenant's zone | Local-time jobs run once; a job scheduled inside a skipped hour runs at the next valid instant, and one inside a repeated hour runs once, deduplicated on the ledger key |
| 8 | A catch-up run and a normal run overlap | The lease serialises them; the catch-up is bounded and resumes on the next tick |
| 9 | Catch-up would exceed `max_lookback` | The job recovers what it can, records the gap explicitly as an exception, and alerts — it never silently narrows the window (the DEF-L-01 fix) |
| 10 | Upstream retention is shorter than the outage | The gap is unrecoverable and is recorded per employee and date; payroll refuses those employees rather than treating them as absent (R-XXXI-102) |
| 11 | A worker dies mid-job after the side effect but before the acknowledgement | The job is idempotent on its declared key; the replay is a no-op |
| 12 | The queue backend loses everything (Redis restarted without persistence) | Enqueue is transactional with the business write where correctness requires it; on restart, the reconciliation sweep re-enqueues from durable state. **No work vanishes silently** — SmartPRS 2 loses every queued notification with no trace |
| 13 | A backlog is drained after a week | Expired jobs are discarded and counted; nothing stale is delivered (R-XXXI-050) |
| 14 | A poison message fails identically on every attempt | Dead-lettered after N identical failures rather than retried to exhaustion |
| 15 | The metric or trace sink is unavailable | Business operations are unaffected; the sink outage raises its own condition; buffered samples are dropped with a counted loss, never silently |
| 16 | The alert transport is unavailable | Fall back to the local banner and the local alert log; the fallback itself is recorded and alerted on recovery |
| 17 | An alert rule's audience resolves to nobody | Meta-alert (R-XXXI-085) — never silence |
| 18 | An alert storm (one cause, many rules) | Deduplication on alert key plus grouping by incident; the on-call receives one page, not fifty |
| 19 | Log volume spikes and fills a volume | Rotation and size cap bound it; the volume alert fires before exhaustion; **logging never fails a business operation**, and a dropped log line is counted |
| 20 | A PII value reaches a log despite the redactor | `OBS-REDACT-01` should have caught it; if found in production it is an S1 security incident (§XXXI.8.10) with a rotation and purge path, and it produces a new canary |
| 21 | A deployment succeeds but the health check fails | The deployment is not complete; it does not promote and it rolls back (R-XXXI-022) |
| 22 | Migrations applied, code deploy failed | The rollback restores **both** (R-XXXI-105) — the failure SmartPRS 2's code-only rollback cannot handle |
| 23 | A canary tenant is mid-payroll when the canary starts | Excluded by R-XXXI-021 |
| 24 | An expired release flag reaches the release branch | Build failure (R-XXXI-028) |
| 25 | The error budget is exhausted during an active incident | The freeze applies to changes, never to the fix; reliability and security changes are always permitted |
| 26 | An on-premise install has no outbound network at all | Alerts land in the local banner and log; telemetry is off; rule packs and updates are side-loaded (Part XXVI §XXVI.10). Everything else is identical |
| 27 | The customer disables an alert rule that protects money | Rules protecting a legal or money control are **non-disableable**; the recipient may change, the rule may not |
| 28 | A support engineer needs production data to diagnose | Time-boxed, tenant-approved, audited support grant; never a standing account; never a copy of production data off-platform |
| 29 | An incident spans SaaS and the on-premise estate (a shared defect) | One incident record, two communication tracks; on-premise customers are notified through the advisory channel regardless of telemetry state |
| 30 | Two operators act simultaneously during an incident | Console actions are serialised per target with optimistic concurrency; the timeline records both attempts |

---

## XXXI.17 Tests

Exact-value regression tests. Test ids are for the Part XXX registry; every one of these is a
scenario SmartPRS 2 would fail today.

**Parity (§XXXI.2)**

| ID | Scenario | Must assert |
|---|---|---|
| OPS-T-001 | Deployment-parity check against a fixture containing each of the five forbidden patterns | build fails, with the offending file and pattern named |
| OPS-T-002 | Payroll golden matrix under `saas` and `onprem` profiles | byte-identical payslip values |
| OPS-T-003 | Profile key set vs the §XXXI.2.2 table | symmetric; an extra key on either side fails |
| OPS-T-004 | A hand-edited deployment model in the profile | signature invalid → refuses to boot |

**Background runtime (§XXXI.4)**

| ID | Scenario | Must assert |
|---|---|---|
| OPS-T-010 | Ingestion stopped 72 h, device retains data, then restarted | **every punch recovered**, exactly once, watermark advanced (the DEF-L-01 fix) |
| OPS-T-011 | Ingestion stopped beyond `max_lookback` | gap recorded per employee and date; payroll refuses those employees; alert raised |
| OPS-T-012 | Fault injected into tenant 3 of a 20-tenant sweep | tenants 4–20 processed; one failed item; run status `partial`; alert raised; **not** SUCCESS |
| OPS-T-013 | Job killed with `SIGKILL` while holding its lease | runs again at its next cadence, not 24 h later |
| OPS-T-014 | Two tenants in `Asia/Kolkata` and `Asia/Dubai`, both configured for 09:00 | each receives at its own 09:00 |
| OPS-T-015 | Milestone run skipped on the 7-day threshold | reminder sent on the first subsequent run, **once** (the DEF-L-05 fix) |
| OPS-T-016 | 5-minute job configured with a 24-hour lock TTL | registry rejects it |
| OPS-T-017 | A job attempts DDL | build fails at the CI check; at runtime the database privilege denies it |
| OPS-T-018 | Worker restarted after a week with a backlog of expired reminders | zero stale messages delivered; expiries counted |
| OPS-T-019 | Job dies after side effect, before acknowledgement | replay is a no-op; no duplicate money effect |
| OPS-T-020 | Scheduler stopped for 26 h, then catch-up | ledger jobs replay **per missed date** against the rules effective on each date; terminal actions deferred until warnings are **delivered** (OPS-T-021) |
| OPS-T-021 | Subscription warning ladder entirely inside a scheduler outage | all warnings delivered; terminal action deferred — never the SmartPRS 2 outcome of suspension with no warning |

**No silent failure (§XXXI.5)**

| ID | Scenario | Must assert |
|---|---|---|
| OPS-T-030 | Fixture with an empty catch, a silent domain catch, a supertype catch, a gate fail-open and a raw-message egress | each fails its check by name |
| OPS-T-031 | `#[SuppressedFailure]` whose named surface does not exist | build fails |
| OPS-T-032 | Fault injected into the audit writer during an employee salary change | the business operation **fails and rolls back**; it does not complete unaudited (the SEC-L-15 fix) |
| OPS-T-033 | Fault injected into each of authorisation, entitlement and licence evaluation | denial + counted metric + alert; **never** access (the SEC-L-14 fix) |
| OPS-T-034 | Holiday lookup throws during the absence sweep | the run fails visibly; **no employee is marked absent** (the `AbsenceService.php:233-235` fix) |
| OPS-T-035 | Reconciliation assertion fails mid-payroll | run aborts, rolls back, raises S1; no payslip is written |

**Observability (§XXXI.6)**

| ID | Scenario | Must assert |
|---|---|---|
| OPS-T-040 | `OBS-REDACT-01` poisoned-corpus test across all sinks | zero canaries; correlation id, UUID, error class present |
| OPS-T-041 | A PII-classified field added with no canary | build fails (coverage assertion) |
| OPS-T-042 | Constraint violation inserting an employee with canary PAN, bank account, salary and DOB | the log line contains `{sqlstate, driver_code, table, constraint}` and **no statement and no bound value** (the direct fix for the ~60 `getMessage()` sites) |
| OPS-T-043 | One business operation spanning request → job → outbound call → webhook | one `correlation_id` retrieves every log line, span and audit entry |
| OPS-T-044 | Each dependency severed in turn | `ready` red, `live` green; body contains a status token only |
| OPS-T-045 | Worker process alive but wedged | probe fails; `worker.absent` fires |
| OPS-T-046 | Alert fires with `LOG_LEVEL` at `warning` and again at `error` | fires identically both times (the DEF-L-06 fix) |
| OPS-T-047 | Queue, scheduler and mailer each stopped in turn | the corresponding alert is still delivered (the DEF-L-07 fix) |
| OPS-T-048 | The same condition persisting across ten evaluation cycles | one open condition, one notification; auto-closes on recovery |
| OPS-T-049 | Alert rule with an empty recipient set | meta-alert raised |
| OPS-T-050 | Login success, failure, lockout, password change, role change, 403 | each produces a log line **and** an audit entry |
| OPS-T-051 | Metric label cardinality per metric | within the declared ceiling; no free-text label |

**Operations, capacity and incident (§XXXI.7, §XXXI.9, §XXXI.10)**

| ID | Scenario | Must assert |
|---|---|---|
| OPS-T-060 | Enumerate every console panel item | each has an action, a runbook link, or both |
| OPS-T-061 | Replay a payroll-posting job that completed then failed | no second posting |
| OPS-T-062 | Payroll run started with ingestion stale beyond policy | refused with a named reason (R-XXXI-100) |
| OPS-T-063 | `SCALE-10K` boot and list endpoints | no response exceeds its declared row and byte ceilings; the largest response in the product is under the declared cap |
| OPS-T-064 | Payroll run at 1K / 5K / 10K | within 2 / 8 / 15 minutes; query count per stage constant in employee count |
| OPS-T-065 | Employee export at 10,000 | completes within the constant memory ceiling; never assembled as one string |
| OPS-T-066 | Any request or job path | zero schema-introspection calls |
| OPS-T-067 | Drop a declared index and re-run the budgets | the relevant budget fails — proving sensitivity |
| OPS-T-068 | Simulated SLO exhaustion | non-reliability promotion blocked automatically |
| OPS-T-069 | Injected fault confined to one tenant | affected-tenant set is exactly that tenant |
| OPS-T-070 | Attempt to close an S1 with no review, requirement or test | refused |
| OPS-T-071 | Rollback drill from a mid-migration failure | code **and** database restored; fingerprint matches; workers on the restored version; within RTO |
| OPS-T-072 | Runbook drill: every runbook step naming a command | the command exists and is executable at the named surface |

---

## XXXI.18 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Question | Recommendation | Trade-offs |
|---|---|---|---|
| **D-31-01** | **What availability and latency objectives does Ametecs commit to, and is any of it contractual?** | Adopt §XXXI.6.8 internally now; publish an SLA only for availability (99.5 %) and only after two clean quarters of measurement | Publishing early creates a contractual exposure against a number nobody has measured. Not publishing at all loses an enterprise-sales differentiator. Internal-first is reversible; contractual is not |
| **D-31-02** | **Who carries the pager, and how is it compensated?** | Two-person weekly rotation; business hours full; after hours S1 only, plus S2 in declared critical windows (payroll days, month-end, filing windows); explicit time-in-lieu | A rota of one is not a rota — it is a single person's goodwill and it fails on holiday. Below two people, formally suspend after-hours cover and tell customers, rather than pretending |
| **D-31-03** | **May diagnostic telemetry leave an on-premise installation, and is it opt-in?** | **Opt-in, off by default**, redacted, metrics and health only — never log content, never PII — with a visible indicator of exactly what is sent | Telemetry on by default is a trust and DPDP exposure and would not survive a customer security review. Off by default means Ametecs cannot see a customer's degradation before they report it; that is the correct trade for this market |
| **D-31-04** | **Which tenants form the canary cohort, and are they told?** | Internal tenants first, then a small volunteer cohort with a commercial acknowledgement; **never** a tenant inside a payroll lock or a filing window; disclosed, not covert | An undisclosed canary is a trust incident when discovered. A volunteer cohort is smaller and slower but defensible |
| **D-31-05** | **Is there a published SaaS maintenance window, and may deployments happen outside it?** | A published low-traffic weekly window for anything requiring maintenance mode; zero-downtime deployments permitted at any time under the expand/contract policy | A window customers can plan around is worth more than the flexibility it costs — but only if the expand/contract discipline makes most deployments not need it |
| **D-31-06** | **How long are logs and traces retained, and where?** | Logs 90 days hot / 1 year cold; traces 14 days; metrics 15 months (to allow year-on-year); on-premise defaults to local retention with export optional | Longer retention aids forensics and costs money and enlarges the redaction blast radius. Metrics at 15 months is the cheapest of the three and the most useful for capacity |
| **D-31-07** | **What is the acceptable data-loss window for biometric attendance — i.e. what is `max_lookback`?** *(carries `L-…` OQ-L-09)* | Default 7 days, per-connector configurable up to the upstream provider's retention, with the effective value shown on the device panel | Today's answer is *"24 hours, then permanent loss"*. A longer look-back costs a larger catch-up read; a shorter one costs punches. The number must be stated per provider from their real retention, not guessed |
| **D-31-08** | **May the payroll-run gate (R-XXXI-100) be overridden when attendance is stale?** | **Yes, but only with a named authorising role, a typed reason, an audit entry, and the staleness printed on the run's own exception list and on every payslip trace** | A hard block will eventually stop a legitimate month-end for a device that is genuinely decommissioned. An unrecorded override recreates the SmartPRS 2 failure of paying on incomplete attendance. The recorded override is the honest middle |
| **D-31-09** | **Who is the alert recipient of record on an on-premise install, and what happens when they leave?** | The installer requires **two** recipients (a person and a role mailbox); a bounce or a delivery failure to either raises a meta-alert; recipients are re-confirmed at each annual renewal | One recipient becomes a leaver's dead mailbox, and the product goes quiet exactly as `SMARTPRS_OPS_EMAIL` does today when it is simply unset — for which SmartPRS 2 returns SUCCESS and sends nothing |
| **D-31-10** | **Does Ametecs operate a customer-facing status page for the SaaS estate?** | Yes, from go-live, with per-incident updates on the S1 cadence; the on-premise estate is served by the advisory channel instead | A status page is a support-cost reduction and a trust signal, and it is an obligation to keep current during the worst hour of the year. Half-maintaining one is worse than not having one |

---

## XXXI.19 Requirements index

| Range | Area | Count |
|---|---|---:|
| R-XXXI-001 – R-XXXI-010 | The single-artefact principle, the profile, and the parity gate (§XXXI.2) | 10 |
| R-XXXI-011 – R-XXXI-031 | Environments, runtime as code, the pipeline, migration policy, flags (§XXXI.3) | 21 |
| R-XXXI-035 – R-XXXI-054 | Background processing: catalogue, execution contract, queue topology, work placement (§XXXI.4) | 20 |
| R-XXXI-055 – R-XXXI-059 | The no-silent-failure rule and the exception taxonomy (§XXXI.5) | 5 |
| R-XXXI-060 – R-XXXI-088 | Observability: logging, correlation, levels, **redaction (R-XXXI-062)**, health endpoints, metrics, tracing, dashboards, SLOs, alerting (§XXXI.6) | 29 |
| R-XXXI-090 – R-XXXI-095 | The operations console (§XXXI.7) | 6 |
| R-XXXI-100 – R-XXXI-106 | Runbook-borne requirements (§XXXI.8) | 7 |
| R-XXXI-110 – R-XXXI-118 | Capacity planning and performance budgets (§XXXI.9) | 9 |
| R-XXXI-120 – R-XXXI-127 | On-call, incident management, post-incident review (§XXXI.10) | 8 |
| R-XXXI-130 – R-XXXI-134 | Operations data model and retention (§XXXI.12) | 5 |
| **Total** | | **120** |

*(R-XXXI-062 is the redaction anchor cited by name in Part XXVI R-XXVI-102; R-XXXI-004 is the
parity gate cited by name in Part XXVI R-XXVI-002 and the §XXVI.2 table. Both numbers are
fixed and must not be re-assigned.)*

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Every schedule cadence, lock TTL, `max_lookback`, reminder ladder, threshold, bucket boundary, digest cap, audience, retry policy, backoff, expiry, alert threshold, severity, recipient, SLO objective, retention window and capacity horizon is **data**. The forty-five hard-coded values catalogued at `L-…` §Q8 have no successors as literals. Constants in code are platform facts — metric names, event keys, check keys, taxonomy class names — on the declared allow-list | OPS-T-016; the configuration surface §XXXI.11; static analysis over `Domain\*` |
| **2 — no schema change at runtime** | No scheduled job, no queued job and no request path may execute DDL (R-XXXI-042, R-XXXI-132); the migration policy in §XXXI.3.4 makes schema change a pipeline artefact with expand/contract discipline and a verified fingerprint. The nine runtime-created tables and eleven runtime-created columns in this area (`L-…` §2) become migrations | OPS-T-017; the DDL CI check; Part XXVI health check 4 |
| **3 — nothing is overwritten** | `deployments`, `scheduled_job_runs`, `alert_notifications`, `job_dead_letters`, `incident_events` and `slo_windows` are append-only; `alert_rules` and `slo_definitions` are versioned and effective-dated and supersede rather than update; a catch-up run evaluates each missed date against the rules **effective on that date** | R-XXXI-133; OPS-T-020; append-only grants asserted in CI |
| **4 — every number explains itself** | Every health result carries its observed value, threshold, onset and remediation; every alert carries the signal, the threshold and the dwell; every job run carries attempted/succeeded/failed with the failed item references; every dead-letter carries its full attempt history; and every one of them carries the `correlation_id` that retrieves the complete causal chain, in the same shape as the S15 explainability trace | R-XXXI-134; OPS-T-043; §XXXI.6.6 |
| **5 — authorisation fails closed** | Every `/api/v3/ops` route carries an explicit policy binding; the three probe endpoints disclose a status token only; no authorisation, entitlement, licence or tenancy control may fail open, and every transient failure inside a gate is counted and alerted (R-XXXI-057) — the direct repudiation of SmartPRS 2's five deliberate, commented fail-opens | OPS-T-033; OPS-T-044; the route-policy sweep |
| **6 — tenancy is structural** | Every metric, log line, trace, job, dead-letter and incident carries `tenant_id`; every `tenant`-scoped job iterates with per-item fault isolation and per-tenant timezone; the operations tables hold **no** tenant business data and are the documented installation-scoped exception; the affected-tenant set in an incident is derived from telemetry, not typed | R-XXXI-060, R-XXXI-122; OPS-T-012, OPS-T-014, OPS-T-069 |
| **7 — money is exact** | This part performs no money arithmetic. Its contributions are: a payroll run refuses to start on stale inputs (R-XXXI-100); a reconciliation failure aborts and rolls back rather than writing (OPS-T-035); replay cannot duplicate a money effect (R-XXXI-094); and the restore drill asserts an **exact** payslip net after recovery. The four "fail-soft" catches that cause SmartPRS 2 to pay the same overtime twice and to deduct an EMI forever (`PayrollGenController.php:2571-2616`) are unrepresentable under §XXXI.5 | OPS-T-035, OPS-T-061; Part XXVI restore assertions |

---

*End of Part XXXI.*
