# PART XXV — SAAS ARCHITECTURE

*Covers brief Section 87 (the cloud side of the deployment-parity requirement), plus signup,
tenant provisioning, subscriptions and billing.*
*Conforms to spine S0 (configuration-first), S1 (the seven laws), S2 (one deployable
artefact), S3/S3.1 (org model and scope), S4 (effective dating and the draft/publish model),
S5 (SPX), S6 (rule engine), S8 (workflow engine), S13 (security), S14 (audit), S16 (data
conventions), S17 (API), S18 (deployment parity).*
*Depends on: Part VI (configuration lifecycle and templates), Part IX (workflow engine — the
approval shapes used by credit provisioning and cancellation), Part X (employee schema
engine — the single write service every seat-consuming creation passes through), Part XIX
(notifications — the delivery ledger every dunning message is recorded in), Part XXII (roles,
security and tenant isolation), Part XXIII (audit ledger), Part XXIV (API and webhook
contracts), Part XXVIII (schema conventions).*
*Depended on by: Part XXVI (which shares the provisioning pipeline, the backup specification
and the health signals), Part XXVII (which supplies the entitlement model this part resolves
against on-premise), Part XXXI (which owns the observability signals this part consumes).*
*Evidence base: `analysis/I-saas-licensing-deployment.md` and
`analysis/N-product-business-modules.md`, with performance figures from
`analysis/M-quality-performance-testing.md` §6.3. Every claim about SmartPRS 2 carries a
`file.php:line` citation carried through from those files.*

---

## XXV.0 Purpose

This part decides four things.

1. **That tenant provisioning is an automated pipeline that ends in a working configuration,
   not a database shell.** SmartPRS 2's automation ends at "a tenant row, one company and one
   admin user" — no salary structure, no pay cycle, no financial year, no statutory position,
   no shift, no leave type, no holiday calendar, no org structure, no approval routing
   (`SaasController::provisionTenantRecord():132-228`, and the exhaustive absence list at
   `I-…` §10 Q7). Everything a Product Owner would call "implementation" is manual data entry
   through the SPA. This is the strongest single argument in the whole as-is analysis for the
   configuration-first premise of spine S0, and this part is where that argument is cashed.
2. **That a billing period advances on payment and on nothing else.** SmartPRS 2's
   `runRenewals()` advances `current_period_end` and `next_renewal` at the moment the invoice
   row is *created* (`BillingController.php:1003-1013`), and this behaviour is asserted as
   correct by the only test that covers it (`tests/Feature/BillingTest.php:140-156`). The
   consequence is that the entire `active → grace → locked` mechanism is unreachable for
   every tenant the moment the command is run, and the only thing preventing that today is
   that the command is deliberately left off the schedule
   (`routes/console.php:171-178` — a documentation-level control over a code-level defect,
   `I-…` DEF-11).
3. **That a seat has exactly one definition, that the definition is configuration, and that
   every door which creates a person passes through the same counter.** SmartPRS 2 has *two*
   contradictory definitions in one product — `SubscriptionService::seatUsage():88-97` does
   not exclude `archived_at`, `ClientUpdateController::activeEmployeeCount():515-520` does —
   fourteen enumerated bypasses of which four are trivial through the ordinary UI
   (`I-…` §10 Q6, B1–B14), and an error message that tells the customer archiving frees a
   seat when on the SaaS counter it does not (`SubscriptionService.php:141` versus
   `:88-97`; `N-…` NEW-SEAT-01).
4. **That a trial, a demo and a paying tenant are three isolated things with three
   lifecycles, and that none of them is reachable by supplying a parameter.** SmartPRS 2's
   unrestricted demo — described in its own configuration file as *"Known to the Ametecs
   sales team only"* (`config/smartprs.php:24-32`) — is obtained by any member of the public
   posting `entry=full` to a public, unauthenticated endpoint whose validator explicitly
   permits the value (`DemoAccessController.php:242`), which then sets a session flag that
   bypasses both the demo write guard and the licence gate entirely
   (`:402-406`; `DemoWriteGuard.php:57-59`; `LicenseGate.php:36-38`; `I-…` SEC-03).

What this part does **not** own: the on-premise topology, installer, backup execution and
operations console (Part XXVI); the licence artefact, editions, entitlement enforcement and
the update channel (Part XXVII); the metric catalogue, log format, tracing and alerting
(Part XXXI); tenant isolation enforcement at the persistence layer (Part XXII and Part
XXVIII); the audit ledger itself (Part XXIII).

---

## XXV.1 What SmartPRS 2 does today, and why it must change

### XXV.1.1 Provisioning is a shell, and it is not atomic

`SaasController::provisionTenantRecord():132-228` creates, in a linear chain with **no
`DB::transaction()`**: a `tenants` row, exactly one `companies` row flagged
`is_master = 1` (self-healing the column if absent, `:164-178`), exactly one `users` row with
a `Str::password(12, true, true, false)` temporary password — symbols disabled
(`:185`, `I-…` SEC-13) — a hashed password-reset token with a 72-hour expiry, a welcome
email sent inline, and a best-effort `SeedIndustryContent::seedForTenant()` whose exceptions
are swallowed. The caller then adds a `subscriptions` row, `tenants.mrr`, an invoice, a
payment, a coupon redemption, a receipt email and, on the online path, auto-sign-in plus two
WhatsApp messages (`SignupController.php:293-460`).

The failure matrix is recorded verbatim at `I-…` DEF-15:

| Failure point | State left behind |
|---|---|
| `provisionTenantRecord()` throws | Nothing created, `signups.status` stays `pending`, **the customer has paid and received nothing** |
| `subscriptions` insert throws | Tenant live and usable, no subscription row ⇒ `SubscriptionService::state()` returns `none` ⇒ **never gated, never billed** (`SubscriptionService.php:53-54`) |
| Invoice creation throws | Tenant and subscription live; the money is untraceable in-app |

And the Razorpay webhook — the safety net for a closed browser — recovers a pending
*renewal* but has no equivalent lookup for a pending *signup*; it logs *"no matching
invoice"* and returns 200, so nothing self-heals (`BillingController.php:698-713`,
`I-…` DEF-16).

There are **three** provisioners with divergent rules: `SaasController` caps the slug at ten
characters and loops for uniqueness, `InstallController.php:202` and `ClientProvision.php:76`
do neither; on-premise provisioning writes `seats_licensed = 100000`
(`ClientProvision.php:72`, `InstallController.php:198`).

> **Because of this:** SmartPRS 3 has **one** provisioning service with **one**
> implementation, invoked identically by self-service signup, the vendor console, the CLI and
> the on-premise installer (R-XXV-020), it is atomic with an explicit compensation path
> (R-XXV-024), and it provisions a **complete working configuration from a versioned
> template**, not a shell (R-XXV-030).

### XXV.1.2 The billing period advances on invoice creation, not payment

```php
// app/Http/Controllers/BillingController.php:1003-1013
$inv    = self::createInvoiceForTenant((int) $sub->tenant_id);
$months = (new self())->cycleMonths($sub->cycle);
$base   = $sub->next_renewal ? Carbon::parse($sub->next_renewal) : now();
$next   = $base->copy()->addMonths($months);
DB::table('subscriptions')->where('id', $sub->id)->update([
    'current_period_end' => $next->toDateString(),
    'next_renewal'       => $next->toDateString(), ...]);
```

`SubscriptionService::state():45-78` derives `active | grace | locked` purely from `today`
against `current_period_end` with `GRACE_DAYS = 7` (`:34`). If the period end is moved
forward every cycle by the act of raising an invoice, **`state()` can never return `grace`
and can never return `locked`**, for any tenant, ever. The lock-out product does not exist
while `billing:renew` runs. The behaviour is not merely present; it is *asserted as correct*
by `tests/Feature/BillingTest.php:140-156`, so a future engineer removing it breaks a green
test.

The only reason this has not fired is that the command is deliberately absent from the
schedule (`routes/console.php:171-178`) — and `RenewSubscriptions.php:35` returns SUCCESS
even when individual tenants error, and invoice creation and period advance are not atomic,
so a failure between them raises a **duplicate GST invoice** on the next run
(`L-…` DEF-L-32).

> **Because of this:** SmartPRS 3 states the rule as an invariant with a database constraint
> and a test behind it — **a subscription period is extended only by a settled payment
> allocated to the invoice for that period** (R-XXV-050) — and the audit trail records the
> payment id that caused each extension (R-XXV-053).

### XXV.1.3 Fourteen seat bypasses, four of them trivial through the UI

One enforcement function exists (`SubscriptionService::canAddEmployees():127-149`) and four
doors call it: the add-employee form (`AppDataController.php:3042`), the inline CSV/paste
import (`:815`), recruitment→hire (`RecruitmentController.php:602`) and renewal sizing
(`TenantBillingController.php:292-295`). The complete bypass list is `I-…` §10 Q6:

| # | Bypass | Evidence | Effort |
|---|---|---|---|
| B1 | **Bulk employee import** never calls the counter | grep: four call sites only | **Trivial, through the UI** |
| B2 | **Self-onboarding portal** never calls it | same grep | **Trivial, through the UI** |
| B3 | **Archive the employee** — the `.lic` counter excludes `archived_at` while the person stays fully usable in payroll | `ClientUpdateController.php:515-520` | **Trivial, through the UI** |
| B4 | `licensed <= 0` means unlimited | `SubscriptionService.php:131` | Configuration |
| B5 | On-prem installs provisioned with `seats_licensed = 100000` | `ClientProvision.php:72` | Default |
| B6 | A `.lic` issued with `seats = 0` ⇒ `device_limit = null` ⇒ no override | `OnpremClientController.php:341` | Default |
| B7 | Fail-soft to unlimited on any exception | `SubscriptionService.php:116-118` | Provoke a DB error |
| B8 | `SMARTPRS_LICENCE_ENFORCE=false` removes the override | `SubscriptionService.php:109-110` | One line in a plaintext file |
| B9 | Flip the deployment/edition switch | `Edition.php:88-91` | One line |
| B10 | **Edits are never checked** — reactivating an exited employee crosses the cap with no check | no `canReactivate` exists | **Trivial, through the UI** |
| B11 | The `.lic` seat cap is checked at activation only | `ClientUpdateController.php:459-469` | — |
| B12 | Edit the local certificate — `device_limit` is in an unsigned settings blob | `ClientUpdateController.php:498-504` | Trivial with DB access |
| B13 | Server-seat cap computed and discarded | `UpdateServerController.php:92,145` | None required |
| B14 | Off-roll commission agents never count — separate table | `offroll_agents` absent from `seatUsage()` | By design |

`N-…` records the commercial consequence of B14 plainly: *"An agency can run 500 off-roll
agents on a ₹1,000/month 25-seat Starter plan"*.

> **Because of this:** SmartPRS 3 defines seat consumption **once, as configuration**
> (R-XXV-060), computes it in **one** service used by both the SaaS counter and the licence
> counter (R-XXV-061), and enforces it inside the **single employee write service** of spine
> S9 so that no creation door can exist that does not pass through it (R-XXV-065) — which is
> why B1, B2 and B10 are structurally impossible rather than merely fixed.

### XXV.1.4 Archived employees consume a seat while the product says they do not

```php
// app/Services/SubscriptionService.php:141  (the message shown to the customer)
"moving an employee to Inactive or Old-data frees a seat"
```

`seatUsage():88-97` filters only on `deleted_at IS NULL` and a six-value status list
(`SEAT_FREE_STATUSES:37`); it never mentions `archived_at`. Archiving writes `archived_at`
and nothing else (`AppDataController.php:2651-2655`). So on SaaS an archived person is
invisible on every screen except a `limit(1000)` archive list
(`AppDataController.php:545`, `M-…` PERF-7) and still bills; on-premise the same action is an
unlimited seat-cap bypass. `N-…` NEW-SEAT-01 classifies this as *"a billing defect, not a
preference"*.

### XXV.1.5 The "sales-team only" unrestricted demo is public self-service

```php
// app/Http/Controllers/DemoAccessController.php:242   (public, unauthenticated, throttle:8,1)
'entry' => ['required','in:demo,1,2,3,full'],
// :402-406
$request->session()->put('demo_team', 1);   // team-driven pages stay unrestricted
```

`DemoWriteGuard.php:57-59` treats `demo_team` as a complete bypass of all twenty-six blocked
write patterns; `LicenseGate.php:36-38` treats it as a complete licence-gate bypass, with no
check that the session belongs to a demo tenant and no check that this is even the SaaS
server (`I-…` SEC-08). Three form fields and a working email address obtain it. From inside,
the visitor can rewrite the demo tenant's SMTP credentials, WhatsApp provider settings, users
and roles, and delete companies, employees and masters — destroying the shared demo every
other prospect is looking at (`I-…` SEC-03). The demo accounts are additionally ordinary
`users` rows with a published password, so the passkey gate, the lead capture and the
time-box can all be skipped by signing in normally (`DemoReset.php:35,100-123`;
`I-…` SEC-05).

> **Because of this:** SmartPRS 3 separates demo tiers by **authorisation, not by a
> caller-supplied parameter** (R-XXV-085), gives every prospect their **own ephemeral tenant**
> rather than one shared workspace (R-XXV-080), and ships **no demo account that is
> authenticable by password** (R-XXV-088).

### XXV.1.6 The rest of the commercial surface, in one table

| Defect | Evidence | Consequence carried into SmartPRS 3 as |
|---|---|---|
| Nineteen commerce tables created at runtime inside swallowed `try/catch`, DDL on the request path | `LicenseService::ensureTables():26-134`; `SignupController::ensure():33-119`; `CouponService::ensure():27-58`; `I-…` §2.1 | Law 2. Every table in §XXV.14 is migration-owned (R-XXV-004) |
| Inserts routed through `ApprovalService::safeRow()`, which **drops keys the live table lacks** with no error | `I-…` §2.4 | Forbidden. A write to an unknown field is a programming error that throws (R-XXV-005) |
| Two payment recorders with different semantics against one ledger; a credit client who part-pays offline then completes online is double-credited | `recordPayment():375-394` vs `applyPayment():413-446`; webhook always calls the former (`:716`); `I-…` DEF-12 | One payment path, partial-aware, idempotent on a uniquely indexed transaction id (R-XXV-105) |
| Idempotency is SELECT-then-INSERT with **no unique index** on `payments.gateway_txn_id` | `I-…` DEF-13 | Database-constrained idempotency (R-XXV-106) |
| Invoice numbering is `MAX+1` over two tables with no lock, O(all FY invoices) per issue, three duplicate implementations, `onprem_clients.invoice_no` not unique | `nextInvoiceNumber():79-99`; `I-…` DEF-20 | One atomic sequence service (R-XXV-100) |
| The invoice PDF reads the **current** subscription row, so reprinting after an upgrade shows the new plan and seat count | `buildInvoicePdf():769-770`; `I-…` DEF-17 | Documents are snapshots (R-XXV-102) |
| Credit notes do not exist — no table, model, controller, series, PDF or route — while the published refund policy promises them | `resources/views/policies/refund-policy.blade.php:47`; `I-…` DEF-24 | Credit notes are first-class (R-XXV-108) |
| Quotation expiry is displayed and never enforced | `showQuote():742` vs `quoteOrder():779-834`; `I-…` DEF-14 | Validity enforced at order and completion (R-XXV-045) |
| `trialing` is a valid status with no behaviour; `trial_ends_at` is never read; a trialing subscription gets **no expiry warnings at all** | `SubscriptionService.php:56`; `SubscriptionAlerts.php:56`; `I-…` DEF-23 | Trial is a first-class state with its own ladder (R-XXV-070) |
| A tenant with no `subscriptions` row is **never gated and never billed** | `SubscriptionService.php:53-54`; `I-…` Q-13 | No tenant may exist without a subscription (R-XXV-044) |
| Grace reminders do not check status, so a **cancelled** subscription is chased daily for seven days | `SubscriptionAlerts.php:56-59`; `L-…` DEF-L-20 | Dunning is state-guarded (R-XXV-075) |
| The grace-end date shown to the customer is one day earlier than the enforcement | `SubscriptionAlerts.php:80` vs `:60`; `L-…` DEF-L-30 | One date, computed once, shown and enforced (R-XXV-074) |
| `subscription_alerts.status = 'sent'` records **enqueueing**, not delivery | `SubscriptionAlerts.php:107-108`; `MailService.php:119`; `L-…` DEF-L-13 | Delivery states are distinct (Part XIX; asserted here as R-XXV-078) |
| The tenant admin's **plaintext temporary password** is sent over WhatsApp by default and persisted forever in `wa_log.body_values` | `SignupController.php:413-425`; `WaService.php:166-176`; `L-…` SEC-L-03 | Credentials are never transmitted in a message body and never stored in a delivery ledger (R-XXV-038) |
| `/signup/order`, `/signup/complete`, `/quote/{token}/complete`, `/licence/{token}/complete` and the Razorpay webhook carry **no throttle** while every other public POST does | `routes/web.php:127-128,159,164-166,195`; `I-…` SEC-11 | Every public write is rate-limited (R-XXV-041) |
| The sellable-plan set is a hard-coded name whitelist `['Starter','Growth','Professional']`, so renaming a plan silently removes it from signup | `SignupController.php:126`; `TenantBillingController.php:90` | Sellability is a data flag (R-XXV-024 in the plan model, R-XXV-091) |
| `/app/data` fetches `departments`, `designations`, `companies`, `branches`, `teams`, `salary_schedules` **with no tenant filter**, so a shared-cloud tenant receives every other tenant's org names | `AppDataController.php:274-291`; `M-…` PERF-1 | Law 6, enforced at the persistence layer (Part XXII/XXVIII); restated here as the multi-tenancy operating premise §XXV.2.3 |

---

## XXV.2 Cloud topology

### XXV.2.1 The premise: one artefact, one schema, many tenants

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** SmartPRS 3 SaaS is the *same* deployable artefact
as an on-premise install (spine S18), run with cloud infrastructure configuration. There is
no SaaS build and no on-premise build. The complete enumeration of legitimate deployment-time
differences is specified once, in **Part XXXI §XXXI.2**, and is not repeated here; nothing in
that enumeration is business logic.

SmartPRS 2 fails this in twenty-one places (`I-…` §10 Q8), and the two switches it uses
(`SMARTPRS_EDITION`, `SMARTPRS_DEPLOYMENT`) can be combined into a state that is **both
unbillable and unenforceable** — an L-edition on a SaaS deployment has no subscription gate,
no licence gate, and cannot reach its own subscription screen because that route is 404'd
(`EnsureSubscriptionActive.php:65-69`; `ClientUpdateController.php:231-236`;
`Edition.php:138`; `I-…` DEF-36). The comment at `ClientUpdateController.php:225-230` implies
this configuration has occurred in production.

- **R-XXV-001** There MUST be exactly one deployment-model discriminator, resolved from
  signed installation configuration, with a closed set of values, and it MUST NOT be
  derivable from two independent variables that can disagree. *Test: no code path reads a
  deployment fact and an edition fact to answer the same question; a configuration in which
  a tenant is neither subscription-gated nor licence-gated is rejected at boot.*
- **R-XXV-002** No domain-layer code may branch on the deployment model. *Test: static
  analysis fails the build on any reference to the deployment discriminator inside
  `Domain\*`.*

### XXV.2.2 Tenancy model

Shared database, shared schema, `tenant_id NOT NULL` on every business table and in every
unique index, applied by the persistence layer (spine Law 6, specified in Part XXII §XXII.5
and Part XXVIII §XXVIII.10). This part adds the **operational** consequences only.

**PRODUCT OWNER DECISION REQUIRED — D-XXV-01: is a dedicated-database tier offered?**
*Recommendation: yes, as a priced tier, but with the same artefact and the same schema — a
tenant may be pinned to its own database on the same cluster (a "silo" tenant) purely by
connection routing, with no code difference.* Enterprise and regulated buyers in India
routinely require it, and the same mechanism serves a private-cloud install. The trade-off is
that per-tenant migrations must then be orchestrated (fan-out) and a cross-tenant platform
query becomes a fan-in; both are bounded and are specified in §XXV.12.6.

| Tier | Isolation | Who it is for | Cost of ownership |
|---|---|---|---|
| **Pooled** (default) | Shared cluster, shared schema, `tenant_id` scoping | The overwhelming majority | Lowest |
| **Silo** (priced) | Own database on a shared cluster; own storage bucket prefix | Regulated buyers, large enterprises | Migration fan-out; per-tenant backup |
| **Private cloud** | Own infrastructure, vendor-operated | A handful | Specified as an on-premise topology (Part XXVI §XXVI.3), operated by Ametecs |

- **R-XXV-003** Tenancy tier MUST be a property of the tenant record, resolved by a
  connection resolver, and MUST NOT change any domain behaviour. *Test: the full domain test
  suite passes identically against a pooled and a silo tenant.*
- **R-XXV-004** Every table this part introduces MUST be created by a versioned migration.
  *Test: `migrate:fresh` produces the production structure; a CI grep fails the build on DDL
  outside `database/migrations` — the direct replacement for the nineteen runtime-created
  commerce tables at `I-…` §2.1.*
- **R-XXV-005** A write of a field the schema does not declare MUST throw. *Test: the
  `ApprovalService::safeRow()` shape — silently dropping unknown keys (`I-…` §2.4) — is
  asserted absent by static analysis.*

### XXV.2.3 Physical topology

```
                    ┌──────────── edge ────────────┐
   clients ───TLS──▶│ WAF · rate limiter · CDN     │
                    └───────────────┬──────────────┘
                                    │
                    ┌───────────────▼──────────────┐
                    │ stateless app tier (N pods)  │  ← the artefact, HTTP role
                    └───┬──────────┬───────────┬───┘
                        │          │           │
        ┌───────────────▼───┐  ┌───▼────────┐  ┌▼───────────────────┐
        │ primary MySQL 8.0 │  │  Redis     │  │ object storage      │
        │  + read replicas  │  │ cache/queue│  │ (documents, exports)│
        └───────────────────┘  │ /locks     │  └─────────────────────┘
                               └────────────┘
                    ┌──────────────────────────────┐
                    │ worker tier (per queue class)│  ← the artefact, worker role
                    │ scheduler leader (1 active)  │  ← the artefact, scheduler role
                    └──────────────────────────────┘
```

The **same image** runs in three roles distinguished only by the process it starts: HTTP,
queue worker (per queue class, §XXV.12.3) and scheduler leader. This is the direct answer to
`L-…` DEF-L-27 — *"the entire operational runtime lives outside the repository"* — and to
`README.md:50-51`, which tells the operator to run the worker and the scheduler by hand in
separate terminals.

- **R-XXV-006** The application tier MUST be stateless: no session state, no cache state, no
  uploaded file and no lock may live on an app node's local disk. *Test: killing any app node
  mid-request loses no committed work and no session.*
- **R-XXV-007** Documents MUST be stored in object storage and served only through
  authorised, signed, expiring URLs (spine S13). *Test: no private object is retrievable
  without a signature; a signature outlives its declared TTL by nothing.*
- **R-XXV-008** Read replicas MAY serve declared read-only query paths; a path that reads
  after its own write MUST be pinned to the primary for the duration of the request. *Test: a
  create-then-read API sequence never observes replica lag.*

### XXV.2.4 Regions and residency

**PRODUCT OWNER DECISION REQUIRED — D-XXV-02: data residency commitments.**
*Recommendation: commit to India-region residency for all tenant data, documents, backups and
logs as the default and the only offered option in v3.0, and record the region on the tenant
record so a second region is an addition rather than a migration.* Indian payroll buyers ask
this in procurement; the DPDP Act 2023 makes the answer contractual. The trade-off is that a
non-India buyer cannot be served without a second region; the recommendation defers, not
forecloses, that.

- **R-XXV-009** Every tenant record MUST carry a `residency_region`, and every store that
  holds tenant data (database, object storage, backup destination, log sink, search index)
  MUST be selected from it. *Test: a tenant pinned to a region has no byte of its data in
  another region's stores, asserted by an automated sweep.*
- **R-XXV-010** A residency change MUST be an explicit, audited migration operation with an
  export, a verified import and a purge of the source — never a configuration edit. *Test:
  changing `residency_region` without running the migration operation is refused.*

---

## XXV.3 Tenant provisioning as an automated pipeline

### XXV.3.1 One service, one implementation, four callers

```
             self-service signup ┐
              vendor console     ├──▶  TenantProvisioningService.provision(ProvisionRequest)
              CLI                │           │
              on-prem installer ─┘           ▼
                                    ProvisionResult { tenant_uuid, admin_uuid,
                                                      template_version, completeness }
```

- **R-XXV-020** There MUST be exactly one provisioning implementation, invoked by
  self-service signup, the vendor console, the CLI and the on-premise installer. *Test: grep
  finds one creator of a tenant; the three divergent provisioners of SmartPRS 2
  (`SaasController:132-228`, `InstallController.php:192-204`, `ClientProvision.php:66-78`)
  have exactly one successor.*
- **R-XXV-021** `ProvisionRequest` MUST be a typed, validated value object. Slug rules,
  seat defaults and company defaults MUST come from the request or from platform
  configuration, never from the call site. *Test: the same request provisions identically
  through all four callers, byte-for-byte in the resulting configuration.*

### XXV.3.2 The pipeline

| # | Stage | Effect | Failure behaviour |
|---|---|---|---|
| 1 | **Validate** | slug, admin email, plan, region, seat and company counts, template key, tax identity | Reject before any write |
| 2 | **Reserve identity** | claim the slug and the admin email atomically under a unique constraint | Reject with a machine-readable code; no partial state |
| 3 | **Create tenant** | `tenants` row with region, tier, residency, status `provisioning` | Compensate: delete the reservation |
| 4 | **Create legal entity and master company** | from the template's org skeleton | Compensating transaction |
| 5 | **Apply the configuration template** | §XXV.3.3 — the substantive stage | Compensating transaction; the tenant never reaches `active` |
| 6 | **Create the first administrator** | user, role assignment, data scope; a one-time activation link, never a password in a message | Compensating transaction |
| 7 | **Create the subscription** | plan version, price book version, seats, companies, cycle, period, state `trialing` or `pending_payment` | Compensating transaction |
| 8 | **Raise the first invoice** *(paid signup only)* | snapshot document, §XXV.10 | Compensating transaction |
| 9 | **Record the payment allocation** *(paid signup only)* | §XXV.10.4 | Compensating transaction |
| 10 | **Compute the completeness model** | §XXV.3.4 | Non-fatal; recorded |
| 11 | **Promote to `active` and emit** | `tenant.provisioned` | — |
| 12 | **Deliver welcome** | activation link by email; SMS/WhatsApp carries **no credential** | Non-fatal; retried by the notification engine (Part XIX) |

- **R-XXV-022** Provisioning MUST be atomic: an induced failure at any stage leaves **no**
  partially provisioned tenant and **no** captured payment without a workspace. *Test: fault
  injection at each of the twelve stages leaves the system in exactly one of "nothing
  created" or "fully created", asserted by a state sweep — the direct fix for
  `I-…` DEF-15.*
- **R-XXV-023** Provisioning MUST be idempotent on a caller-supplied provisioning key. A
  repeat with the same key returns the original result and creates nothing. *Test: 100
  concurrent identical requests create one tenant.*
- **R-XXV-024** Stages 8 and 9 MUST NOT be reachable unless a settled payment exists, except
  on the credit path of §XXV.4.5, which is an approved workflow, not a code branch. *Test: a
  provisioning without payment and without an approved credit authorisation is refused.*
- **R-XXV-025** Every provisioning attempt, success or failure, MUST write an audit entry
  naming the caller, the template version and the outcome (Part XXIII). *Test: a failed
  provisioning is visible to the vendor operator with its reason.*
- **R-XXV-026** Provisioning MUST NOT swallow the failure of starter content. *Test: a
  content-seed failure marks the tenant incomplete and raises an operator condition — the
  direct fix for `ClientProvision.php:112-115` and `SeedIndustryContent`'s swallowed
  exceptions (`L-…` runners-up).*

### XXV.3.3 Configuration templates — what makes a tenant *usable*

A **configuration template** is a first-class, versioned, exportable, importable artefact
under the spine S4 envelope. It is not seed code. It is a signed bundle of configuration
versions that the provisioning service publishes into the new tenant.

```
config_templates
  template_key, name, industry, jurisdiction, description, is_system, owner
config_template_versions
  template_key, version_no, status(draft|published|archived), published_at,
  content_manifest (ordered list of artefact_type + payload),
  requires_rulepack_min, checksum, signature
```

A published template MUST contain, at minimum, a complete answer to every prerequisite the
completeness model declares mandatory:

| Domain | What the template supplies | Part that owns the artefact |
|---|---|---|
| Organisation | legal entity, company, establishment, one branch, one department, designation and grade ladders | Part V, Part X |
| Calendar | financial year, pay cycle definition, cut-off, working-week pattern, holiday calendar skeleton | Part XII |
| Attendance | shift(s), roster policy, late/early policy, OT policy, regularisation workflow | Part XI, Part VII |
| Leave | leave types, entitlement formulas, accrual and carry-forward rules, approval workflow | Part XIII |
| Payroll | salary structure, components with statutory base flags, rounding declarations, proration rule | Part XIV, Part XV |
| Statutory | the applicable rule-pack jurisdiction set and the establishment applicability answers | Part XVII, Part XXVII |
| Workflow | approval routing for leave, attendance regularisation, payroll approval, salary change, bank change | Part IX |
| Templates | payslip, offer, appointment, experience, relieving; the notification template set | Part XVI, Part XIX |
| Security | the shipped role set and their data scopes | Part XXII |

- **R-XXV-030** A newly provisioned tenant MUST be able to run a payroll for one employee
  **without opening a single configuration screen**. *Test: provision from the default
  template, create one employee through the API, run a payroll, assert an exact net figure.*
  This is the acceptance criterion for the whole part.
- **R-XXV-031** Templates MUST be versioned, exportable and importable, and a template MUST be
  producible **from an existing tenant's configuration** (copy-from-customer). *Test:
  exporting tenant A's configuration and importing it into tenant B reproduces identical
  behaviour on the same golden payroll case.*
- **R-XXV-032** A template MUST declare its minimum rule-pack version, and provisioning MUST
  refuse a template whose statutory prerequisites the installation cannot satisfy. *Test: a
  template requiring a newer pack is refused with a named reason.*
- **R-XXV-033** Templates MUST be signed by Ametecs when vendor-supplied, and a
  customer-authored template MUST be distinguishable from a vendor-supplied one at every
  surface. *Test: template provenance appears in the configuration audit and in the
  template picker.*
- **R-XXV-034** Applying a template MUST create **published** configuration versions with an
  `effective_from` of the tenant's declared go-live date, not of the provisioning instant.
  *Test: a tenant provisioned in March with an April go-live resolves April's policies in
  April and nothing in March.*

### XXV.3.4 The configuration completeness model

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** A declarative model of prerequisites, evaluated
continuously, surfaced to the implementer and to the vendor.

```
config_prerequisites
  prereq_key, module, label, severity(blocking|required|advisory),
  applies_when SPX, satisfied_when SPX, remediation_route, depends_on[]
```

Worked example:

```
prereq_key      PAYROLL.STRUCTURE.ASSIGNED
severity        blocking
applies_when    tenant.modules CONTAINS 'payroll'
satisfied_when  agg.count(employee, since(tenant.go_live), employee.structure_id IS NULL) = 0
remediation     /app/structures
depends_on      [PAYROLL.COMPONENTS.DEFINED, ORG.COMPANY.EXISTS]
```

- **R-XXV-035** The system MUST expose, per tenant, the ordered list of unmet prerequisites,
  their severity, what depends on them, and a direct route to fix each. *Test: a fresh tenant
  with the payroll module and no structure reports exactly one blocking prerequisite naming
  the route.*
- **R-XXV-036** A run-blocking operation (payroll run, statutory filing, bank pack) MUST
  refuse while a `blocking` prerequisite is unmet, naming it. *Test: payroll preview refuses
  with the prerequisite key, not a generic error.*
- **R-XXV-037** Completeness MUST be reportable across the estate, so the vendor can see which
  customers are stalled in implementation. *Test: a platform report lists tenants by
  unmet-blocking count and age.*

### XXV.3.5 Credentials at provisioning

- **R-XXV-038** No provisioning path may transmit a password, a temporary password or an OTP
  in a message body, and no delivery ledger may store one. The first administrator receives a
  single-use, short-lived, IP-agnostic activation link and sets their own password behind MFA
  enrolment. *Test: grep across the notification templates and the delivery ledger for a
  credential-bearing variable returns nothing — the direct fix for `SignupController.php:413-425`
  and `WaService.php:166-176`, where the plaintext temporary password is sent over WhatsApp by
  default and persisted in `wa_log.body_values` forever (`L-…` SEC-L-03).*
- **R-XXV-039** No default credential may exist in any seeder, installer, template or release
  (spine S13). *Test: a full-tree grep for a literal password returns nothing — the direct fix
  for `OnPremWorkspaceSeeder.php:70,79`, `DemoCompanySeeder.php:69`, `DemoReset.php:35` and
  the five logins published at `README.md:56-64`.*
- **R-XXV-040** The first administrator MUST be enrolled in MFA before any privileged action
  (spine S13). *Test: a newly provisioned admin cannot reach a privileged route until MFA
  enrolment completes.*

---

## XXV.4 The self-service signup journey

### XXV.4.1 The journey

```
 /pricing ──▶ configure (plan · seats · companies · cycle · coupon)
          ──▶ live quote (server-computed, signed, TTL-bound)
          ──▶ identity + tax details + terms acceptance
          ──▶ POST /api/v3/public/signup/orders        (idempotency key required)
          ──▶ hosted payment                            (gateway of record)
          ──▶ POST /api/v3/public/signup/orders/{id}/confirm
                 └─ verify signature ─▶ provision (§XXV.3) ─▶ activation email
          ──▶ webhook safety net (independent, idempotent, recovers signup AND renewal)
```

- **R-XXV-041** Every public endpoint in this journey MUST be rate-limited per IP and per
  email, and the quote and order endpoints MUST additionally be protected against automated
  enumeration of coupon codes. *Test: no unthrottled public write endpoint exists — the direct
  fix for `routes/web.php:127-128,159,164-166,195` (`I-…` SEC-11).*
- **R-XXV-042** Price MUST be computed server-side from the effective price book and never
  accepted from the client. *Test: a tampered client price is ignored; the order records the
  price book version used.* (SmartPRS 2 gets this right — `BillingController::priceFor()` —
  and it is retained deliberately.)
- **R-XXV-043** Order creation MUST require an idempotency key and MUST be idempotent on it
  (spine S17). *Test: a retried order creates one order and one gateway intent.*
- **R-XXV-044** Confirmation MUST be idempotent, MUST verify the gateway signature in
  constant time, and MUST NOT be the only path to provisioning: the webhook MUST recover a
  pending **signup** as well as a pending **renewal**. *Test: closing the browser after
  payment still produces a provisioned tenant within the webhook SLA — the direct fix for
  `I-…` DEF-16.*
- **R-XXV-045** A quotation MUST carry a validity that is enforced at order creation and at
  confirmation, not merely displayed. *Test: an expired quotation cannot be paid — the direct
  fix for `I-…` DEF-14.*

### XXV.4.2 Signature verification, once

SmartPRS 2 contains **six identical copies** of the Razorpay HMAC verification
(`BillingController.php:638-641`; `SignupController.php:276-279,859-862,906-909`;
`TenantBillingController.php:389-392`; `OnpremClientController.php:635-638`).

- **R-XXV-046** Gateway signature verification MUST exist in exactly one place, behind a
  payment-provider interface, in constant time. *Test: one implementation; a provider added
  without touching any controller.*

### XXV.4.3 Payment provider abstraction

**PRODUCT OWNER DECISION REQUIRED — D-XXV-03: is more than one payment provider supported at
launch?** *Recommendation: build the abstraction, ship one provider.* The abstraction costs
little and the second provider (or a change of provider on commercial terms) is otherwise a
rewrite of six call sites.

```
interface PaymentProvider {
    createIntent(Order): Intent
    verifyCallback(payload, signature): VerifiedPayment
    verifyWebhook(rawBody, headers): VerifiedEvent
    fetchPayment(reference): PaymentFacts     // authoritative amount, never inferred
    refund(paymentRef, amount, reason): RefundResult
}
```

- **R-XXV-047** The recorded payment amount MUST come from `fetchPayment()` — the provider's
  authoritative record — never from a locally recomputed balance. *Test: a partial capture
  records the captured amount — the direct fix for `OnpremClientController.php:643`, which
  records the balance computed at completion time rather than what was captured
  (`I-…` DEF-18).*

### XXV.4.4 Coupons

SmartPRS 2's coupon design is sound and is retained: nine sequential validations, revalidation
at order time, redemption only after a verified payment, and a redeemed coupon disabled rather
than deleted (`CouponService.php:75-133, 219-233`; `CouponController::destroy():161-175`).
Three things change.

- **R-XXV-048** Coupon `max_uses` MUST be enforced by an atomic decrement or a uniquely
  constrained redemption, not by check-then-increment. *Test: two simultaneous checkouts of a
  single-use coupon produce one redemption — the direct fix for `I-…` DEF-21.*
- **R-XXV-049** A percentage discount MUST be clamped at apply time as well as at creation.
  *Test: a coupon row with `value = 120` produces a zero-floor discount, never a negative
  invoice — the direct fix for the unclamped `apply()` at `CouponService.php:140-158`.*
- **R-XXV-050** A discount MUST be able to apply to a stated number of periods, and the
  subscription MUST record both the list rate and the effective rate. *Test: a "20% for four
  quarters" coupon bills discounted for four renewals — SmartPRS 2 can only ever discount the
  first invoice because it writes the full rate to `subscriptions.amount`
  (`SignupController.php:340-346`; `N-…` BR-BIL-002).*

### XXV.4.5 Quotation, credit and offline provisioning

Retained as a capability — `I-…` §9 row 3 records it as *"genuinely needed by the Indian
market"* — and rebuilt as a workflow rather than a controller branch.

- **R-XXV-051** Provisioning on credit MUST be an approval workflow instance (spine S8) with
  a named approver, a credit limit, a due date and a recorded justification, not a
  super-admin form field. *Test: no code path provisions on credit without an approved
  workflow instance.*
- **R-XXV-052** A quotation MUST be a snapshot document with its own number series, its own
  validity, and a public payment link bound to a single-use token with a declared TTL.
  *Test: the link expires; a used link cannot be replayed.*

---

## XXV.5 Trials and demos, with real isolation

### XXV.5.1 Three pre-purchase products, three lifecycles

| | **Guided demo** | **Sandbox trial** | **Paid trial** |
|---|---|---|---|
| Who | Any visitor | Qualified prospect | Contracted prospect |
| Tenant | **Own ephemeral tenant**, seeded from a demo template | Own tenant, real | The real tenant |
| Data | Vendor-authored sample data only | Prospect's own, capped | Prospect's own |
| Writes | Permitted **inside their own tenant** | Permitted | Permitted |
| Outbound comms | Muted at the transport, logged as suppressed | Muted except to the prospect | Live |
| Duration | Declared window (default from configuration) | Declared trial length | Declared trial length |
| End state | Purged | Convert or purge | Convert or lapse |
| Entitlement | The demo entitlement set | The trial entitlement set | The purchased plan |

The single most important change: **a demo visitor gets their own tenant.** SmartPRS 2 puts
every prospect into one shared workspace identified by `tenants.subdomain = 'demo'`
(`N-…` BR-TEN-001), which is why one visitor can destroy another's session, why a privacy
wipe is needed every fifteen minutes, and why the wipe failing open silently
(`DemoReset::resetDue():180-182`, `L-…` SEC-L-05) leaves one prospect's typed data — possibly
real employee names and salaries — visible to the next.

- **R-XXV-080** Each demo session MUST provision its own tenant from a demo template and MUST
  purge it at the end of its window. *Test: two concurrent demo visitors cannot observe each
  other's data by any route.*
- **R-XXV-081** Demo tenant creation MUST be cheap enough to be synchronous within the
  visitor's patience budget, or MUST be served from a warm pool. *Test: p95 demo entry under
  the declared budget; a pool exhaustion degrades to a queue with an honest message, never to
  a shared tenant.*
- **R-XXV-082** Demo purge MUST be a scheduled, monitored, **fail-closed** operation: an
  error MUST raise an operator condition and MUST NOT be interpreted as "nothing due".
  *Test: injecting an exception into the purge predicate raises an alert and retries — the
  direct fix for `DemoReset.php:180-182`.*
- **R-XXV-083** A demo tenant MUST NOT be able to send outbound mail, SMS or WhatsApp to any
  address, and every suppressed message MUST be recorded as suppressed. *Test: no transport
  call escapes a demo tenant — the mechanism SmartPRS 2 gets right at `MailService.php:79-90`
  and `WaService.php:130-140`, retained.*
- **R-XXV-084** A demo tenant MUST NOT be able to alter platform-level or transport-level
  configuration. *Test: SMTP, WhatsApp provider, gateway and role configuration are
  unreachable inside a demo tenant — the direct fix for `I-…` SEC-03, where the unrestricted
  demo can rewrite exactly those.*

### XXV.5.2 Demo tiers are an authorisation decision

- **R-XXV-085** Demo tiers MUST be separated by authorisation, never by a caller-supplied
  parameter. An unrestricted or extended demonstration MUST require a vendor-issued, signed,
  expiring invitation bound to a named recipient. *Test: a public visitor cannot obtain any
  tier beyond the public one by varying any request field — the direct fix for
  `DemoAccessController.php:242`, where `entry=full` is a validated public value
  (`I-…` SEC-03).*
- **R-XXV-086** No session flag may bypass a write guard, an entitlement check or a licence
  check. *Test: fault injection asserts that no session key grants any bypass — the direct fix
  for `DemoWriteGuard.php:57-59` and `LicenseGate.php:36-38`, which treat `demo_team` as a
  complete bypass of both.*
- **R-XXV-087** The invitation MUST record who issued it, to whom, for what tier, and for how
  long, in the audit ledger. *Test: every unrestricted demo session is attributable.*
- **R-XXV-088** No demo account may be authenticable by password. Entry is only through the
  gated flow. *Test: the demo accounts have no password credential at all — the direct fix for
  `I-…` SEC-05, where `demo-admin@smartprs.in` / `smartprs-demo` is an ordinary login whose
  session never expires because it carries no `demo_pass`.*
- **R-XXV-089** Passkeys and OTPs MUST be stored hashed with a TTL, never in plaintext.
  *Test: no readable passkey exists at rest — the direct fix for `demo_requests.pin`, stored
  in plaintext and indexed by explicit design comment (`DemoAccessController.php:91`,
  `I-…` DEF-43).*

### XXV.5.3 Trial as a first-class subscription state

- **R-XXV-090** `trialing` MUST be a real state with a trial length, an entitlement set, its
  own reminder ladder, a defined expiry action and a conversion path. *Test: a trial that is
  not converted transitions on its declared date and the customer receives every configured
  reminder — the direct fix for `I-…` DEF-23, where `trialing` exists in the schema, is never
  set, is never read, and receives **no** expiry warnings because
  `SubscriptionAlerts.php:56` only warns when the status is `active`.*
- **R-XXV-091** Trial length, entitlement set, reminder ladder and expiry action MUST be
  per-plan configuration. *Test: two plans with different trial lengths behave differently
  with no code change.*

---

## XXV.6 Plan and entitlement model

### XXV.6.1 The four separable things SmartPRS 2 conflates

| Concept | Question it answers | SmartPRS 2 |
|---|---|---|
| **Plan** | What is this customer sold? | `plans` row, name-whitelisted for sale (`SignupController.php:126`) |
| **Entitlement set** | What may they use? | Nothing. `Edition::level()` returns the literal `3` (`Edition.php:93-97`); every edition is full |
| **Limits and quotas** | How much may they use? | `subscriptions.seats`, `companies`; enforced at four doors of many |
| **Price book** | What does it cost? | PHP constants: `GST = 18.0`, `COMPANY_FEE = 1000.0`, the cycle-discount table (`BillingController.php:35,49-53,56`) |

- **R-XXV-092** Plan, entitlement set, limit set and price book MUST be four separately
  versioned, effective-dated artefacts under the spine S4 envelope, joined by a subscription.
  *Test: changing a price does not change an entitlement; changing an entitlement does not
  reprice.*
- **R-XXV-093** Sellability MUST be a data flag on a plan version, never a name whitelist.
  *Test: renaming a plan does not remove it from signup — the direct fix for
  `SignupController.php:126` and `TenantBillingController.php:90`.*

### XXV.6.2 Entitlements

The entitlement grammar is owned by **Part XXVII §XXVII.3**, because it must serve the
licence artefact identically. This part states only how a SaaS subscription resolves one.

```
entitlement_set
  set_key, version, name,
  features[]      { feature_key, granted, config_json }
  limits[]        { limit_key, value, enforcement(hard|soft|meter) }
  quotas[]        { quota_key, value, window, on_exceed(block|throttle|meter) }
```

- **R-XXV-094** A subscription MUST resolve exactly one entitlement set version for a given
  date, and the resolution MUST be traceable (spine Law 4). *Test: "why can I not use this
  feature?" names the plan, the entitlement set, its version, and the missing feature key.*
- **R-XXV-095** An entitlement decision MUST be enforced from **one declaration** at both the
  navigation layer and the endpoint layer. *Test: hiding a feature and blocking its endpoints
  cannot diverge — the direct fix for `Edition::hiddenNavIds()` and
  `Edition::blockedPatterns()`, two parallel lists that silently converged into a no-op
  (`I-…` DEF-02, R-05).*
- **R-XXV-096** Entitlement enforcement MUST fail **closed** on a deterministic error
  (malformed set, missing version) and MUST fail **open with a counted, alerting metric** on a
  transient error (store unreachable). *Test: a corrupt entitlement blocks; a simulated
  database timeout does not, and increments a visible counter — the direct answer to the six
  deliberate fail-open catches at `I-…` SEC-07, whose availability bias is correct and whose
  invisibility is not.*

### XXV.6.3 Grandfathering

**PRODUCT OWNER DECISION REQUIRED — D-XXV-04: how are existing customers treated when a plan
version changes?** *Recommendation: a subscription is pinned to the plan version and price
book version it was sold on until an explicit, audited migration moves it, and the vendor
console reports how many customers sit on each version.* The alternative — everyone follows
the latest version — is simpler and reprices existing customers without their consent, which
is a contractual problem in India where annual advance payment is the norm.

- **R-XXV-097** A subscription MUST record the plan version, entitlement set version and price
  book version it is bound to, and a change MUST be an explicit operation with an audit entry
  and a customer-visible effective date. *Test: publishing a new plan version changes no
  existing subscription.*

---

## XXV.7 The subscription lifecycle

### XXV.7.1 States

```
                 ┌──────────┐
                 │ pending  │  created, no payment and no credit approval yet
                 └────┬─────┘
        payment settled│ or credit approved
                 ┌────▼─────┐   trial configured   ┌──────────┐
                 │  active  │◀─────────────────────│ trialing │
                 └────┬─────┘  converted           └────┬─────┘
      period end passed│                   trial expired │
                 ┌────▼─────┐                            │
                 │  grace   │  full access, warned       │
                 └────┬─────┘                            │
       grace exhausted│                                  │
                 ┌────▼──────┐                           │
                 │ suspended │  read-only + billing only ◀┘
                 └────┬──────┘
       cancellation   │  or customer cancels from any state
                 ┌────▼──────┐
                 │ cancelled │  export window open
                 └────┬──────┘
        retention end │
                 ┌────▼──────┐
                 │  purged   │  terminal, evidenced
                 └───────────┘
```

### XXV.7.2 Transition table

| From | To | Trigger | Guard | Effect | Reversible |
|---|---|---|---|---|---|
| pending | active | settled payment allocated to the opening invoice | allocation covers the opening invoice, or credit approval exists | period set; entitlements resolved | n/a |
| pending | cancelled | abandonment timeout | no payment | tenant purged on the abandoned-tenant schedule | no |
| trialing | active | conversion payment settled | — | period starts at conversion; trial data retained | n/a |
| trialing | suspended | trial end reached | expiry action = `suspend` | read-only + billing | yes, by payment |
| trialing | cancelled | trial end reached | expiry action = `cancel` | export window opens | by re-signup only |
| active | grace | `today > current_period_end` | grace length > 0 | banner; dunning ladder starts | yes, by payment |
| active | cancelled | customer cancellation | cancellation workflow approved | takes effect at period end by default | until effect date |
| grace | active | settled payment | allocation covers the renewal invoice | **period advances** (§XXV.7.3) | n/a |
| grace | suspended | grace exhausted | auto-suspend enabled for this plan | read-only + billing only | yes, by payment |
| suspended | active | settled payment | allocation covers all outstanding | period advances from the *unpaid* period start | n/a |
| suspended | cancelled | cancellation policy elapsed, or customer cancels | — | export window opens | no |
| cancelled | purged | retention elapsed, no legal hold | export completed or window elapsed | purge executed and evidenced | **no** |

- **R-XXV-060** The lifecycle MUST be table-driven: states, transitions, guards, grace length,
  dunning ladder, auto-suspend behaviour and retention windows are configuration per plan and
  overridable per contract. *Test: a plan with a 30-day grace and no auto-suspend behaves
  correctly with no code change — the direct fix for `GRACE_DAYS = 7`
  (`SubscriptionService.php:34`), the `[15,7,3,1,0]` ladder (`SubscriptionAlerts.php:35`) and
  the unconditional auto-suspend at `SubscriptionAlerts.php:60-69`.*
- **R-XXV-061** Every transition MUST write an audit entry with the actor (human or system),
  the trigger, the guard evaluation and the resulting dates (Part XXIII). *Test: "why is this
  tenant suspended?" is answerable from the ledger alone.*
- **R-XXV-062** `suspended` MUST degrade to **read-only plus the billing surface**, never to
  data loss and never to a full lock-out of the people who must pay. *Test: a suspended
  tenant's administrator can view, export and pay; nobody can write business data.* SmartPRS 2
  gets the *intent* right (`EnsureSubscriptionActive.php:82-120`) and the *scope* wrong: a
  non-admin is logged out entirely, and the allow-list omits `app/first-password`, so a freshly
  provisioned administrator on a gated install is signed out instead of reaching the mandatory
  create-password screen (`LicenseGate.php:25`, `I-…` BR-LIC-022).
- **R-XXV-063** Suspension MUST NOT cascade to individual user records. *Test: reactivating a
  tenant does not re-enable users an administrator had individually disabled — the direct fix
  for `BR-TEN-004`, where suspension sets every user to `disabled` and reactivation cannot
  distinguish them from the ones the customer disabled deliberately.*
- **R-XXV-064** No tenant may exist without a subscription in a defined state. *Test: a
  nightly sweep asserting zero tenants with no subscription passes — the direct fix for
  `SubscriptionService.php:53-54`, where a missing row means "never gated, never billed"
  (`I-…` Q-13).*

### XXV.7.3 The period-advance invariant

> **A subscription period is extended only by a settled payment allocated to the invoice for
> that period. Issuing an invoice extends nothing. Nothing else extends anything.**

- **R-XXV-050** *(restated as the governing invariant)* `current_period_start` and
  `current_period_end` MUST be written only by the payment-allocation service, and only when
  the allocation settles an invoice whose `period_covered` follows the current period.
  *Test: raising a renewal invoice and never paying it leaves the period unchanged and the
  tenant reaching grace on schedule — the direct fix for `BillingController.php:1003-1013`
  and the green test at `tests/Feature/BillingTest.php:140-156` that asserts the defect.*
- **R-XXV-051** *(restated)* The period-advance operation MUST be atomic with the payment
  allocation. *Test: a fault between allocation and advance leaves neither applied — the
  direct fix for `L-…` DEF-L-32, where a failure between invoice creation and period advance
  raises a duplicate GST invoice on the next run.*
- **R-XXV-052** Renewing early MUST NOT lose days: the new period starts at
  `max(current_period_end, today)`. *Test: renewing 20 days early yields a period end 20 days
  later than a renewal on the due date.* (SmartPRS 2 does this correctly at
  `TenantBillingController::applyRenewal():430-441`; retained.)
- **R-XXV-053** Every period change MUST record the payment id and the invoice id that caused
  it. *Test: for every subscription, the sum of paid periods reconciles to the period history
  exactly.*
- **R-XXV-054** A mid-term upgrade MUST raise limits immediately and MUST NOT move the period
  end; the pro-rata charge MUST use a **declared** day basis, not an averaged constant.
  *Test: the pro-rata for a 45-day remainder is reproducible and explainable — SmartPRS 2
  divides by the hard-coded `30.44` (`TenantBillingController::prorataFor():176-200`) and
  computes tax with a literal `18` rather than its own constant (`:189`).*
- **R-XXV-055** Downgrades MUST be expressible as scheduled changes effective at the next
  period boundary. *Test: a downgrade requested mid-term applies at renewal and is visible in
  advance.* (SmartPRS 2 simply refuses with a 422 at `:229-231`.)

### XXV.7.4 Dunning

The dunning ladder is a rule set (spine S6) over the **Commerce** action catalogue, not a
`private const` array.

```
RULE   DUNNING-STD-T-15
module commerce
scope  { plan: IN ['standard'] }
when   period.days_to_end = 15 AND subscription.state = 'active'
then   NOTIFY('billing.renewal_due', audience_ref: 'billing_contacts')

RULE   DUNNING-STD-GRACE
when   subscription.state = 'grace'
       AND agg.count(dunning_sent, window: day, predicate: kind = 'grace') = 0
then   NOTIFY('billing.grace_reminder', audience_ref: 'billing_contacts')

RULE   DUNNING-STD-SUSPEND
when   subscription.state = 'grace'
       AND period.days_past_end > policy.grace_days
       AND policy.auto_suspend = TRUE
then   SET_SUBSCRIPTION_STATE('suspended'), NOTIFY('billing.suspended')
```

- **R-XXV-070** Ladder evaluation MUST be **"first run at or past the threshold, deduped on
  the ledger key"**, never exact day equality. *Test: skipping a day's run still delivers the
  reminder on the next run — the direct fix for `in_array($days,[15,7,3,1,0],true)` at
  `SubscriptionAlerts.php:56`, where a missed run loses the reminder permanently
  (`L-…` DEF-L-05).*
- **R-XXV-071** The dunning audience MUST be configurable per tenant as roles, named users or
  a distribution list, MUST fail closed, and MUST raise an operator condition when it resolves
  to nobody. *Test: a tenant with no billing contact produces an alert, not silence.*
- **R-XXV-072** Dunning MUST be guarded by state: a `cancelled` subscription MUST NOT receive
  renewal chasing. *Test: cancelling stops the ladder immediately — the direct fix for
  `L-…` DEF-L-20, where the grace branch omits the status check the pre-expiry branch has.*
- **R-XXV-073** The date shown to the customer and the date enforced MUST be the same value,
  computed once. *Test: the suspension date in the email equals the date the sweep acts on —
  the direct fix for the off-by-one at `SubscriptionAlerts.php:80` versus `:60`
  (`L-…` DEF-L-30).*
- **R-XXV-074** Auto-suspension MUST be per-plan configurable, MUST be preceded by every
  configured warning having been **delivered** (not merely enqueued), and MUST be blockable by
  an account flag for contracted enterprise customers. *Test: with the notification worker
  stopped, no tenant is auto-suspended — the direct fix for the compounding failure at
  `L-…` §Q9(B), where the suspend write is direct and unqueued while every warning is queued,
  so a customer is locked out having received none of the five warnings.*
- **R-XXV-075** A dunning message MUST be recorded in the delivery ledger with distinct
  `queued`, `sent`, `delivered`, `failed` and `suppressed` states, and no caller may treat
  `queued` as `sent`. *Test: `subscription_alerts` successor rows never claim delivery before
  the transport confirms — the direct fix for `L-…` DEF-L-13.*

---

## XXV.8 Seats: one definition, one counter, every door

### XXV.8.1 The definition is configuration

**PRODUCT OWNER DECISION REQUIRED — D-XXV-05: what consumes a seat?**

This is a pricing decision, not an engineering one, and it must be answered before v3.0
prices are published. The mechanism below is neutral to the answer; the *default* is not.

*Recommendation:* **a seat is consumed by any person whose record was in a
seat-consuming employment stage for at least one day of the billing period, counted at the
period's peak, pooled across all companies in the tenant.** Rationale: it bills for the work
the system actually did, it cannot be gamed by archiving on the last day of the month, and it
matches how the product is used — a person who was paid this month cost the customer a
payslip, a statutory line and an attendance register whether or not they are still employed
on the 30th.

| Option | For | Against |
|---|---|---|
| **A. Peak active-in-period (recommended)** | Ungameable; matches delivered value; simple to explain | Higher invoice than a point-in-time count; customers must be told plainly |
| B. Point-in-time at period end | Simplest; what SmartPRS 2 approximates | Trivially gamed by exiting on the 29th; penalises honest record-keeping |
| C. Average daily active | Fairest for churn-heavy staffing | Hard to explain on an invoice; an unfamiliar basis in this market |

**Sub-decision D-XXV-05a: does an archived ("old data") employee consume a seat?**
*Recommendation: no.* An archived person is out of every operational surface; billing for
them while telling the customer otherwise is the defect at `N-…` NEW-SEAT-01. But the
retention of their data is still a cost, so archive should be **bounded** (Part XXVI/XXXI
retention classes) rather than free and infinite.

**Sub-decision D-XXV-05b: which employment stages are seat-free by default?**
*Recommendation: the shipped default reproduces SmartPRS 2's six statuses —
`exited, inactive, resigned, terminated, left, absconded` (`SEAT_FREE_STATUSES:37`) — plus
`archived`, and every one of them is tenant-editable configuration, because customers define
their own exit vocabulary and SmartPRS 2 cannot express `suspended` or `long_leave`
(`N-…` BR-SUB-001a).*

**PRODUCT OWNER DECISION REQUIRED — D-XXV-06: are off-roll agents billable?**

Off-roll commission agents live in `offroll_agents`, are excluded from `seatUsage()` entirely,
and are described by `N-…` as *"the strongest vertical asset in the product"* — a workforce
paid per visit or per rupee collected, holding no seat and no login. Today **an agency can run
500 off-roll agents on a ₹1,000/month 25-seat Starter plan** (`N-…`, seat row 288).

*Recommendation:* **yes, but on a separate, cheaper meter, not as a seat.** An off-roll agent
consumes KYC storage, a payout ledger, a public earnings page and compliance scanning, but no
payslip, no attendance register and no statutory computation. Pricing them as a full seat
would make the collections vertical uncompetitive against manual processes; pricing them at
zero gives away the vertical the product is strongest in.

| Option | For | Against |
|---|---|---|
| **A. Separate agent meter, lower unit price (recommended)** | Captures value; keeps the vertical price-competitive; honest to what is consumed | A second meter to explain and invoice |
| B. Count as a full seat | Simplest | Prices the collections vertical out; a five-fold invoice increase for existing agencies |
| C. Free, as today | No change for existing customers | Unpriced consumption of the product's most differentiated capability; unbounded |

Whichever is chosen, it must be **configuration**, because the answer will change.

### XXV.8.2 The mechanism

```
seat_policy   (a spine S4 artefact: versioned, effective-dated, scoped, published)
  policy_key, version, effective_from, effective_to
  consumes_seat        SPX Boolean over an employment record and a date
  meter_key            which meter this consumption counts against ('seat' | 'agent' | …)
  basis                point_in_time | peak_in_period | average_daily
  pooling              tenant | company | legal_entity
```

```
consumes_seat :=
      employment.stage NOT IN policy.seat_free_stages
  AND employment.archived_at IS NULL
  AND (employment.exit_date IS NULL OR employment.exit_date >= period.start)
```

- **R-XXV-060** *(seat definition)* Seat consumption MUST be defined by a published, versioned
  `seat_policy` artefact and MUST NOT appear as a literal anywhere in code. *Test: static
  analysis finds no status list, no archive predicate and no pooling rule in `Domain\*` — the
  direct fix for `SEAT_FREE_STATUSES` at `SubscriptionService.php:37`.*
- **R-XXV-061** There MUST be exactly one seat counter, used by the SaaS meter, the licence
  meter (Part XXVII) and every enforcement door. *Test: for every dataset, the SaaS-side count
  and the licence-side count are identical — the direct fix for the two contradictory
  definitions at `SubscriptionService.php:88-97` versus
  `ClientUpdateController.php:515-520` (`I-…` DEF-04).*
- **R-XXV-062** The message shown when a seat limit is reached MUST be generated from the
  active policy, so it can never describe behaviour the policy does not implement. *Test: a
  policy in which archiving does not free a seat produces a message that does not say it does
  — the direct fix for `SubscriptionService.php:141`.*

### XXV.8.3 Enforcement at every creation door

Enforcement lives **inside** the single employee write service of spine S9, not at call sites.
This is what makes bypasses structurally impossible rather than merely fixed.

| Door | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| Add-employee form | checked (`AppDataController.php:3042`) | via the write service |
| Inline CSV/paste import | checked (`:815`) | via the write service |
| Recruitment → hire | checked (`RecruitmentController.php:602`) | via the write service |
| **Bulk import wizard** | **bypass B1** | via the write service; pre-flight reserves the whole batch |
| **Self-onboarding portal** | **bypass B2** | via the write service |
| **Reactivation / status change** | **bypass B10** — no `canReactivate` exists | via the write service; a transition **into** a consuming stage is a creation event for metering |
| Public API `POST /employees` | n/a | via the write service |
| Migration/import tooling | n/a | via the write service, with an explicit, audited `over_limit_grant` |

- **R-XXV-065** Every path that creates a person or moves a person into a seat-consuming stage
  MUST pass through the single write service, and the seat decision MUST be taken there.
  *Test: an integration test that adds a person through each of the eight doors above is
  refused identically at the limit — the direct fix for bypasses B1, B2 and B10.*
- **R-XXV-066** A bulk operation MUST evaluate the seat decision for the **whole batch** before
  committing any row, and MUST NOT commit a partial batch on a limit breach. *Test: importing
  50 people with 10 seats free imports none and reports the shortfall — the direct fix for
  `AppDataController.php:815`, which blocks mid-import and returns a partial count.*
- **R-XXV-067** A seat decision MUST fail **closed** on a deterministic error and MUST fail
  open **with an alert and a counter** on a transient one. *Test: a corrupt seat policy blocks
  creation; a database timeout permits it and increments a visible metric — the direct fix for
  `SubscriptionService.php:116-118`, `// fail-soft → unlimited`, bypass B7.*
- **R-XXV-068** `licensed = 0` MUST mean **zero**, not unlimited. Unlimited MUST be an explicit
  `unlimited` value. *Test: a subscription with zero seats permits no employee — the direct fix
  for bypass B4 at `SubscriptionService.php:131`.*
- **R-XXV-069** Over-limit handling MUST be a per-plan choice among `block`, `warn_and_meter`
  and `auto_expand_and_bill`, and the choice MUST be visible on the subscription. *Test: a
  plan configured to auto-expand adds seats, raises a pro-rata charge and notifies; a plan
  configured to block refuses.*

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** Prefer `warn_and_meter` as the shipped default for
SaaS. Blocking a payroll department from onboarding a new joiner on the 1st of the month
because a seat purchase has not cleared is a support incident and a churn risk; metering the
overage and invoicing it is the behaviour every mature SaaS in this category has converged on.
Blocking remains available for customers who require a hard cap.

### XXV.8.4 Reconciliation

- **R-XXV-076** A daily reconciliation job MUST recompute each tenant's meters from source and
  MUST raise a discrepancy when the stored counter and the recomputed value differ. *Test:
  manually corrupting a counter raises a discrepancy within one cycle.*
- **R-XXV-077** Meter history MUST be retained for the full invoicing dispute window, so an
  invoice can be defended line by line. *Test: an invoice from 18 months ago reproduces its
  seat count from stored daily snapshots.*

---

## XXV.9 Usage metering

### XXV.9.1 What is metered

| Meter | Unit | Basis | Billed? | Why it exists |
|---|---|---|---|---|
| `seat` | person | per D-XXV-05 | yes | the primary meter |
| `agent` | off-roll agent | peak in period | per D-XXV-06 | the collections vertical |
| `company` | legal entity | point in time | yes | SmartPRS 2 charges ₹1,000/month per extra company as a PHP constant (`COMPANY_FEE`, `BillingController.php:56`), repeated as a literal inside an error string at `SubscriptionService.php:194` |
| `payslip` | payslip issued | count in period | no (fair-use) | detects a tenant using far more than the seat count implies |
| `punch_ingest` | punches accepted | count in period | no (fair-use) | drives the noisy-neighbour controls of §XXV.12 |
| `api_call` | request | count in window | no (quota) | quota enforcement, §XXV.12.2 |
| `storage_documents` | bytes | end of period | quota | retention pricing |
| `outbound_message` | message | count in period | quota / pass-through | SMS and WhatsApp cost real money per message |

- **R-XXV-078** Every meter MUST be defined as data — key, unit, basis, window, aggregation,
  whether billable, whether quota-enforced — and a new meter MUST require no code change to
  record, aggregate, report or quota-enforce. *Test: adding a meter is a configuration
  operation.*
- **R-XXV-079** Meter readings MUST be snapshotted daily per tenant per meter and MUST be
  immutable once written. *Test: a period's invoice is reproducible from the snapshots alone.*
- **R-XXV-080** *(numbering note: see §XXV.5)* — reserved.

### XXV.9.2 Metering must not be a hot-path cost

- **R-XXV-098** Meter increments MUST be asynchronous and aggregated; no request may block on
  a meter write. *Test: p99 latency is unaffected by metering being disabled.*
- **R-XXV-099** Meter aggregation MUST be idempotent on `(tenant, meter, business_date)` so a
  replayed aggregation cannot double-count. *Test: running the aggregator twice for one day
  yields one row.*

---

## XXV.10 Invoicing, tax, payments and credit notes

### XXV.10.1 Documents are snapshots

- **R-XXV-100** Numbering MUST use a single atomic sequence service, one series per document
  type per financial year per legal entity, with a configurable format and a configurable
  financial-year boundary. *Test: 100 concurrent invoice creations produce 100 distinct
  consecutive numbers with no gap and no duplicate — the direct fix for the unlocked `MAX+1`
  scan at `nextInvoiceNumber():79-99`, its three duplicate implementations, and the
  unindexed `onprem_clients.invoice_no` that races silently (`I-…` DEF-20, DEF-22).*
- **R-XXV-101** Historical numbers MUST be preserved verbatim on migration and the sequence
  seeded at the observed maximum. *Test: no existing customer's invoice number changes.*
- **R-XXV-102** An invoice MUST snapshot every value it displays — plan name, seat count,
  company count, cycle, unit rates, discounts, tax treatment, seller identity, buyer identity
  and the meter evidence — at issue. Reprinting MUST reproduce the original document
  regardless of subsequent changes. *Test: reprinting after an upgrade reproduces the original
  — the direct fix for `buildInvoicePdf():769-770`, which reads the current subscription row
  (`I-…` DEF-17).*
- **R-XXV-103** Invoice payment terms, settlement tolerance, currency, currency scale and
  document templates MUST be configuration. *Test: a customer on 30-day terms is invoiced with
  30-day terms — SmartPRS 2 hard-codes `due_on = today + 7` at `BillingController.php:321` and
  a ₹0.01 settlement tolerance at `:432`.*

### XXV.10.2 Tax

> **Legal-values discipline (spine S12).** No tax rate, threshold or code is asserted here as
> legally correct. Every rate is `<from the tax rule pack>` (Part XXVII §XXVII.5). The values
> recorded below are *the values SmartPRS 2's code currently uses*, cited as evidence, not as
> law.

SmartPRS 2 carries the tax rate as `GST = 18.0` (`BillingController.php:35`) plus two
duplicate PHP literals (`TenantBillingController.php:189`, `OnpremClientController.php:56`)
and four more literals rendered inside PDF templates
(`onprem-invoice-pdf.blade.php:60-63`, `quotation-pdf.blade.php:119-122`); it hard-codes two
different service codes for the same company (`998314` for SaaS, `997331` for on-premise); and
it hard-codes the seller's legal name, GSTIN, address, phone and state as **PHP defaults**, so
a deployment that does not edit `.env.production.example:80-86` issues tax invoices carrying
the vendor's own GSTIN (`I-…` SEC-14).

- **R-XXV-104** Tax rates, service/HSN codes, the intra/inter determination rule, seller
  identity and place-of-supply logic MUST be effective-dated configuration sourced from the
  tax rule pack, with **no** rate, code or identity literal in code or in a template. *Test:
  changing a rate affects documents issued after its effective date only, with no code change
  and no reprint of historical documents.*
- **R-XXV-105** The tax split MUST reconcile exactly: the sum of the split components MUST
  equal the total tax to the last paise, with residue allocated deterministically (spine Law 7,
  `ALLOCATE`). *Test: an odd-paise tax splits without loss — SmartPRS 2 computes each half as
  `round($tax / 2, 2)` so the halves can differ from the total by ₹0.01 by design
  (`invoice-pdf.blade.php:117-123`).*

### XXV.10.3 Money discipline

- **R-XXV-106** All commercial arithmetic MUST use integer minor units through a Money value
  object; floats MUST never touch money (spine Law 7). *Test: a type-level ban on float
  arithmetic in the commerce domain; `priceFor()`'s `(float)` casts and doubled `round(…, 2)`
  have no successor.*
- **R-XXV-107** Every rounding point MUST be declared. There is no default rounding. *Test:
  every price and tax computation names its rounding mode and unit in its trace.*

### XXV.10.4 Payments

- **R-XXV-108** There MUST be exactly one payment-recording path, partial-aware, idempotent on
  a **uniquely indexed** provider transaction identifier, recording the provider's
  authoritative amount. *Test: a webhook retry concurrent with a browser confirmation records
  one payment; an invoice can never accumulate allocations exceeding its total — the direct fix
  for `I-…` DEF-12 and DEF-13.*
- **R-XXV-109** Payments MUST be **allocated** to invoices explicitly; the allocation, not the
  payment, drives the lifecycle. *Test: an unallocated payment extends nothing.*
- **R-XXV-110** A pre-migration reconciliation MUST identify every invoice where
  `SUM(allocations) > total` and every tenant with no subscription. *Test: the reconciliation
  report is empty before cutover — the direct answer to `I-…` Q-13 and Q-14.*

### XXV.10.5 Credit notes and refunds

- **R-XXV-111** Credit notes MUST exist as first-class documents with their own number series,
  their own template, a mandatory reason, a link to the invoice they adjust, and an effect on
  the ledger. *Test: the published refund policy is executable in the product — the direct fix
  for `I-…` DEF-24, where credit notes do not exist while
  `resources/views/policies/refund-policy.blade.php:47` promises them.*
- **R-XXV-112** Refunds MUST be recordable against a payment, MUST reach the provider through
  the payment abstraction, and MUST produce a credit note where the tax treatment requires one.
  *Test: `payments.status = 'refunded'` — a value no SmartPRS 2 code ever writes — has a real
  writer and a real document.*
- **R-XXV-113** A refund or credit note MUST NOT silently reverse a period advance; any effect
  on service MUST be an explicit, audited lifecycle transition. *Test: refunding a paid period
  produces an explicit state change with a reason.*

---

## XXV.11 Tenant offboarding: export, hold and purge

### XXV.11.1 The lifecycle

```
cancellation requested ──▶ effective at period end (default)
   │
   ├─▶ EXPORT WINDOW      customer may export everything, self-service, repeatedly
   │        duration: configuration (PO decision D-XXV-07)
   ├─▶ HOLD PERIOD        read-only, no new writes, data retained, recoverable on payment
   │        duration: configuration
   └─▶ PURGE              executed, verified, evidenced; irreversible
```

**PRODUCT OWNER DECISION REQUIRED — D-XXV-07: the export window and the hold period.**
*Recommendation: a 30-day export window and a further 60-day hold, both configurable per
contract, with the purge date shown on the subscription from the moment of cancellation.*
Indian payroll records carry statutory retention obligations that sit with the **employer**,
not the vendor, so the vendor's job is to make the export complete and the deadline
unmissable — not to retain indefinitely, which is itself a DPDP-Act liability.

### XXV.11.2 Export

- **R-XXV-114** Export MUST be self-service, complete and machine-readable: every business
  record, every document, every configuration version, the audit ledger extract and the
  statutory registers, in a documented format with a manifest and a checksum. *Test: an
  exported tenant can be re-imported into a fresh tenant and reproduces the same payroll
  results.*
- **R-XXV-115** Export MUST be resumable and MUST not be bounded by a request timeout. *Test:
  a 10,000-employee tenant with five years of history exports successfully.*
- **R-XXV-116** Every export MUST be audited with who requested it, what it contained and where
  it was delivered. *Test: the ledger names every export of a cancelled tenant.*

### XXV.11.3 Purge

- **R-XXV-117** Purge MUST be a scheduled, two-person-authorised operation that produces a
  **purge certificate**: tenant identity, scope, row counts per table, object counts per
  bucket, backup-expiry date, executor, timestamp and signature. *Test: a purge produces a
  verifiable certificate and a subsequent sweep finds no residue.*
- **R-XXV-118** Purge MUST respect legal holds and MUST refuse while any hold is active,
  naming it. *Test: a held tenant is not purged and the reason is visible.*
- **R-XXV-119** Purge MUST extend to backups: the certificate MUST state the date on which the
  last backup containing the tenant expires, and no restore after that date may reintroduce
  the data. *Test: a restore from an expired archive is refused for a purged tenant.*
- **R-XXV-120** The audit record of the purge MUST outlive the purged data. *Test: the purge
  entry is present in the ledger after every business row is gone (Part XXIII §XXIII.8.4).*

---

## XXV.12 Scale and multi-tenancy operations

The performance figures below are from `M-…` §6.3 and are the failure baseline this section
exists to prevent. The budgets themselves are specified in **Part XXXI §XXXI.9** and are not
restated here.

### XXV.12.1 Noisy-neighbour control

SmartPRS 2 has no isolation of any kind between tenants at runtime: `/app/data` returns every
employee, every payroll run and every payslip the tenant has ever had with no pagination
(`AppDataController::bootstrap():247-590`), fabricates up to 120,012 single-row payroll inserts
inside one web request on a first load at 10,000 employees (`ensurePayrollHistory():2340-2381`),
and `Audit::verify()` reads every row of every tenant into memory whenever any administrator
opens Activity Logs (`Audit.php:111`). Any one tenant can exhaust a shared cluster.

- **R-XXV-121** Every tenant MUST have declared per-tenant limits on concurrent requests,
  concurrent background jobs, database connections, query time budget and result-set size, and
  exceeding them MUST throttle that tenant and no other. *Test: a synthetic tenant issuing
  pathological queries does not raise p95 latency for a second tenant beyond a declared
  tolerance.*
- **R-XXV-122** No endpoint may return an unbounded result set; pagination with a
  server-enforced maximum page size is mandatory. *Test: a sweep asserts every collection
  endpoint paginates — `->paginate(` appears **zero** times in SmartPRS 2's `app/` while 308
  `->get()` calls run unbounded (`M-…` §6.3).*
- **R-XXV-123** A long-running operation (payroll run, report, export, PDF, import) MUST be a
  queued, resumable, observable job with progress, never an HTTP request. *Test: no request
  handler exceeds the declared request time budget under the 10,000-employee fixture.*
- **R-XXV-124** The application MUST NEVER create business records as a side effect of a read.
  *Test: a `GET` that writes is a build failure — the direct fix for
  `ensurePayrollHistory()`, which fabricates `status='paid'` payroll runs during a page load,
  and `processDueBackups()` at `AppDataController.php:257`, which archives employees inside a
  page load and records whoever loaded the page as the actor.*

### XXV.12.2 Per-tenant quotas

```
tenant_quotas
  tenant_id, quota_key, window, soft_limit, hard_limit,
  on_soft(notify|meter), on_hard(throttle|block), effective_from, effective_to
```

- **R-XXV-125** Quotas MUST be resolvable from the plan's quota set with per-tenant overrides,
  and every quota decision MUST be traceable and reportable. *Test: "why was I throttled?"
  names the quota key, the window, the limit and the observed value.*
- **R-XXV-126** A hard-limit breach MUST return a documented error code with a retry hint, and
  MUST NOT fail silently or truncate results. *Test: an over-quota API client receives the
  documented envelope (Part XXIV).*

### XXV.12.3 Background-job fairness

SmartPRS 2 has one queue, literally named `mail`, used by two job classes with three dispatch
sites (`SendNotification.php:41`; `NotifyLateArrivals.php:43`; `L-…` §Q2). Everything else
runs in the request. A single large tenant's work cannot be separated from anyone else's,
because there is nothing to separate.

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** Queue classes by *work shape*, with per-tenant
fair scheduling inside each class:

| Queue class | Latency target | Examples | Fairness |
|---|---|---|---|
| `interactive` | seconds | a user-triggered recalculation, an on-demand payslip | round-robin per tenant, small batch size |
| `bulk` | minutes | payroll run, import, export, bulk recalculation | **weighted fair queueing**: a tenant may occupy at most a declared share of the class's workers |
| `integration` | minutes | device pulls, connector syncs, webhook delivery | per-tenant and per-endpoint concurrency caps |
| `notification` | seconds–minutes | every message | per-tenant rate cap; provider rate cap |
| `maintenance` | hours | retention, purge, reconciliation, archival | off-peak windows |

- **R-XXV-127** Work MUST be routed to named queue classes by declared work shape, never to a
  single queue. *Test: a payroll run for one tenant cannot delay another tenant's
  notifications.*
- **R-XXV-128** Each queue class MUST enforce a per-tenant concurrency share so that no tenant
  can occupy more than its declared portion of the class. *Test: one tenant enqueueing 10,000
  jobs does not starve a tenant enqueueing one.*
- **R-XXV-129** Queue depth, oldest-job age, per-tenant share and worker liveness MUST be
  exported as metrics (Part XXXI §XXXI.6). *Test: a stopped worker is visible within the
  declared detection window.*
- **R-XXV-130** Every job MUST implement a failure handler, and a failed job MUST be visible in
  an operator surface with its tenant, its class and its error. *Test: `failed_jobs` has a
  reader and an alert — SmartPRS 2's has neither, and there is no `Queue::failing()` listener
  anywhere (`L-…` DEF-L-23).*
- **R-XXV-131** Time-sensitive jobs MUST declare an expiry so that a drained backlog cannot
  deliver stale notifications. *Test: restarting a worker after a week does not send last
  week's renewal warnings — the direct fix for `L-…` §Q9(D).*

### XXV.12.4 Tenant-level observability

The signals, their names and their emission are specified in **Part XXXI §XXXI.6**. What this
part requires is that **every one of them is dimensioned by tenant**, because an incident is
almost always one tenant's incident.

- **R-XXV-132** Every operational metric MUST carry a tenant dimension, and the platform
  console MUST be able to render any metric for one tenant. *Test: a per-tenant latency, error
  rate, queue depth and job failure view exists.*
- **R-XXV-133** Every request and every job MUST carry a correlation id and a tenant id in its
  log context. *Test: one identifier retrieves every log line, span and audit entry for one
  operation — SmartPRS 2 has no correlation id at all; grep for `request_id`, `X-Request-Id`,
  `correlation` and `trace_id` returns nothing (`L-…` §Q7).*
- **R-XXV-134** A per-tenant health view MUST show: subscription state and dates, seat and
  meter usage against limits, configuration completeness, integration freshness, queue backlog,
  failed jobs, last successful payroll run, last backup covering this tenant, and open
  incidents. *Test: a support engineer answers "is this customer healthy?" from one screen.*

### XXV.12.5 The single-tenant incident runbook

> **Trigger:** a per-tenant alert fires (error rate, latency, queue backlog, failed payroll,
> integration staleness, quota breach), or a customer reports an incident.

| # | Step | Tooling | Time budget |
|---|---|---|---|
| 1 | Identify the tenant and open the per-tenant health view (R-XXV-134) | platform console | 1 min |
| 2 | Establish blast radius: this tenant only, or the shard, or the estate? Compare the tenant's metrics against the shard aggregate | dashboards | 3 min |
| 3 | If estate-wide, exit to the platform incident runbook (Part XXXI §XXXI.8) | — | — |
| 4 | Capture a correlation id from the failing operation; retrieve logs, spans and audit entries for it | log search | 5 min |
| 5 | Apply the least-blunt containment that stops the harm: throttle the tenant (R-XXV-121), pause one queue class for that tenant, disable one integration, or set the tenant to read-only | platform console, all audited | 5 min |
| 6 | Record a customer-visible status entry on the tenant, so support and the customer see the same fact | tenant status feed | 5 min |
| 7 | Diagnose using the operation's trace; if a configuration version is implicated, use the S4 diff to show what changed and when | explainability (§XXV.17) | — |
| 8 | Remediate. If a configuration change is the cause, roll forward with a new version — never edit in place (Law 3) | configuration console | — |
| 9 | Lift containment in reverse order, verifying each step against the metric that triggered it | platform console | — |
| 10 | Close: root cause, the audit entries for every containment action, and any requirement or test the incident proves missing | incident record | 24 h |

- **R-XXV-135** Every containment action available in step 5 MUST be reversible, audited, and
  visible to the tenant's administrator. *Test: throttling a tenant appears in that tenant's
  own status feed and in the audit ledger.*
- **R-XXV-136** No containment action may require a code deployment. *Test: every step in the
  runbook is executable from the console.*

### XXV.12.6 Silo tenants and migrations

- **R-XXV-137** A schema migration MUST be applied to every tenant database in a controlled
  fan-out with per-tenant status, resumability and a halt-on-failure policy, and the estate
  MUST NOT be left in a mixed-schema state longer than a declared window. *Test: a fan-out
  migration reports per-tenant status and can be resumed after an interruption.*
- **R-XXV-138** A tenant MUST be movable between pooled and silo without a business-logic
  change, as an export-verify-import-purge operation with a declared downtime window. *Test: a
  moved tenant reproduces identical payroll results before and after.*

---

## XXV.13 Configuration surface

Tested against the brief's Section 127 standard: nothing here may require code.

| # | What an administrator can change | Where | Scope | Effective-dated? |
|---|---|---|---|---|
| 1 | Seat definition: which employment stages consume a seat; whether archived consumes; pooling; basis | `seat_policy` artefact | tenant, scoped | yes |
| 2 | Which meters exist, their units, windows, aggregation, billability and quotas | meter registry | platform | yes |
| 3 | Plan catalogue: name, description, sellability, trial length, entitlement set, limit set | plan versions | platform | yes |
| 4 | Price book: base rate, per-unit rates, included quantities, cycle set, cycle discounts, additional-company fee, minimum term | price book versions | platform, overridable per contract | yes |
| 5 | Tax: rates, service/HSN codes, place-of-supply rule, seller identity per legal entity | tax rule pack + seller registry | platform | yes |
| 6 | Invoice and quotation number formats, series scope, financial-year boundary | numbering configuration | platform / legal entity | yes |
| 7 | Payment terms, settlement tolerance, currency and scale | commercial configuration | platform, per customer | yes |
| 8 | Grace length, dunning ladder offsets, audience, channels, templates | lifecycle policy + rule set | plan, per contract | yes |
| 9 | Auto-suspend on/off, suspension scope, reactivation behaviour | lifecycle policy | plan, per contract | yes |
| 10 | Trial length, trial entitlements, trial expiry action, trial reminder ladder | plan version | plan | yes |
| 11 | Cancellation notice period, export window, hold period, purge schedule | offboarding policy | plan, per contract | yes |
| 12 | Over-limit behaviour: block / warn-and-meter / auto-expand | plan version | plan, per tenant | yes |
| 13 | Coupon definitions and their nine validation parameters, plus the number of periods a discount applies to | coupon catalogue | platform | yes (validity) |
| 14 | Configuration templates: content, industry, jurisdiction, version, publication | template registry | platform + tenant-authored | yes |
| 15 | Configuration prerequisites: which are blocking, required, advisory; their remediation routes | prerequisite registry | platform, per module | yes |
| 16 | Demo tier definitions, window lengths, entitlements, seeded template, purge cadence | demo policy | platform | yes |
| 17 | Per-tenant quotas and limits, and their soft/hard actions | tenant quota table | platform per tenant | yes |
| 18 | Queue class shares and per-tenant concurrency caps | queue policy | platform | yes |
| 19 | Residency region, tenancy tier, connection routing | tenant record | platform per tenant | change is a migration |
| 20 | Notification templates and audiences for every commercial event | Part XIX template registry | platform + tenant | yes |

**The Section 127 test.** *"3 late arrivals over 10 minutes = half day must not require code."*
The commercial equivalent is: *"a reseller's contract says warn at 30/14/7/1, grace is 21 days,
and this customer is never auto-suspended."* All three are rows in items 8 and 9 above, with no
code change and no deployment. In SmartPRS 2 all three are `private const` arrays
(`SubscriptionAlerts.php:35`, `SubscriptionService.php:34`, `SubscriptionAlerts.php:60-69`).

---

## XXV.14 Data model

Section 123 standard; conventions per spine S16 and Part XXVIII. Every business table carries
`tenant_id BIGINT UNSIGNED NOT NULL` participating in every unique index (Law 6), applied by
the persistence layer. **Platform-scoped tables** (plans, price books, coupons, templates,
meters) are explicitly marked; they hold no tenant business data and are the documented
exception. **No table here is created at runtime** (Law 2) — the direct replacement for the
nineteen runtime-created commerce tables at `I-…` §2.1.

### XXV.14.1 `tenants`

*Purpose:* the tenant of record.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | UUIDv7, the only external identifier (spine S2) |
| `slug` | varchar(63) | N | — | branded sign-in path; format is configuration, not the `3..10` literal at `SignupController.php:169-177` |
| `legal_name` | varchar(255) | N | — | |
| `residency_region` | varchar(32) | N | — | R-XXV-009 |
| `tenancy_tier` | varchar(16) | N | `pooled` | `pooled\|silo` |
| `db_connection_ref` | varchar(64) | Y | NULL | silo only |
| `status` | varchar(24) | N | `provisioning` | reference table, not an ENUM (S16) |
| `deployment_model` | varchar(16) | N | — | `saas\|private_cloud\|onprem`; **read by infrastructure, never by `Domain\*`** (R-XXV-002) |
| `template_version_id` | bigint unsigned | Y | NULL | FK, provenance of the initial configuration |
| `go_live_on` | date | Y | NULL | drives template `effective_from` (R-XXV-034) |
| `tax_identifiers` | json | Y | NULL | typed per jurisdiction; PII: business identifiers |
| `created_at`, `updated_at` | datetime(6) | N | — | |

PK `id` · Unique `(uuid)` · Unique `(slug)` · Index `(status, created_at)` ·
Index `(residency_region, tenancy_tier)`.
Tenant scope: this **is** the tenant. Hist: status transitions in `tenant_status_history`.
SD: no — a tenant is cancelled and purged, never soft-deleted. Audit: every column.
PII: `legal_name` and `tax_identifiers` are business-confidential.

### XXV.14.2 `subscriptions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` / `uuid` | bigint unsigned / char(36) | N | — | |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants` ON DELETE RESTRICT |
| `plan_version_id` | bigint unsigned | N | — | FK; pinned (R-XXV-097) |
| `entitlement_set_version_id` | bigint unsigned | N | — | FK |
| `price_book_version_id` | bigint unsigned | N | — | FK |
| `state` | varchar(24) | N | `pending` | reference table |
| `cycle_key` | varchar(24) | N | — | reference table; `{quarterly, halfyear, annual}` is data, not the literal at `BillingController.php:42-47` |
| `current_period_start` | date | N | — | **written only by the allocation service** (R-XXV-050) |
| `current_period_end` | date | N | — | as above |
| `next_billing_on` | date | Y | — | derived, informational |
| `trial_ends_on` | date | Y | NULL | real behaviour (R-XXV-090) |
| `grace_ends_on` | date | Y | NULL | computed once, shown and enforced (R-XXV-073) |
| `cancellation_requested_at` | datetime(6) | Y | NULL | |
| `cancellation_effective_on` | date | Y | NULL | |
| `purge_scheduled_on` | date | Y | NULL | visible from cancellation |
| `list_amount_minor` | bigint | N | — | integer minor units (Law 7) |
| `effective_amount_minor` | bigint | N | — | after multi-period discounts (R-XXV-050) |
| `currency` | char(3) | N | — | |
| `auto_suspend` | tinyint(1) | N | per plan | per-contract override |
| `over_limit_mode` | varchar(24) | N | per plan | R-XXV-069 |
| `created_at`, `updated_at` | datetime(6) | N | — | |

PK `id` · Unique `(uuid)` · **Unique `(tenant_id)` where not terminal** — one live
subscription per tenant, the constraint that makes R-XXV-064 enforceable ·
Index `(state, current_period_end)` · Index `(next_billing_on)`.
Hist: `subscription_period_history` (below). SD: no. Audit: every column.
PII: none.

### XXV.14.3 `subscription_period_history`

*Purpose:* the evidence behind every period advance — the record SmartPRS 2 cannot produce.

`tenant_id`, `subscription_id`, `period_start`, `period_end`, `advanced_at`,
`caused_by_payment_id`, `caused_by_invoice_id`, `caused_by_allocation_id`, `actor_id`,
`reason`.
PK `id` · Unique `(tenant_id, subscription_id, period_start)` ·
Index `(caused_by_payment_id)`. Append-only; no `UPDATE` grant. R-XXV-053.

### XXV.14.4 `subscription_state_transitions`

`tenant_id`, `subscription_id`, `from_state`, `to_state`, `trigger`, `guard_trace json`,
`actor_id`, `occurred_at`, `audit_entry_id`.
PK `id` · Index `(tenant_id, subscription_id, occurred_at)`. Append-only. R-XXV-061.

### XXV.14.5 `plans`, `plan_versions` *(platform-scoped)*

`plans`: `plan_key`, `name`, `is_active`.
`plan_versions`: `plan_key`, `version_no`, `status`, `effective_from`, `effective_to`,
`is_sellable` (R-XXV-093), `entitlement_set_version_id`, `limit_set_version_id`,
`price_book_version_id`, `trial_days`, `trial_expiry_action`, `grace_days`,
`dunning_ruleset_id`, `auto_suspend_default`, `over_limit_mode_default`,
`export_window_days`, `hold_days`, `published_at`, `published_by`, `checksum`.
PK `id` · Unique `(plan_key, version_no)` · Index `(status, effective_from)`.
Governed by the spine S4 envelope: draft → published → superseded, never edited in place.

### XXV.14.6 `price_books`, `price_book_versions`, `price_book_lines` *(platform-scoped)*

`price_book_lines`: `price_book_version_id`, `meter_key`, `included_quantity`,
`base_amount_minor`, `unit_amount_minor`, `tier_json` (optional graduated tiers),
`currency`, `rounding_mode`, `rounding_unit`.
Unique `(price_book_version_id, meter_key)`.
Cycle discounts live in `price_book_cycle_discounts`
(`price_book_version_id`, `cycle_key`, `discount_bp`) — basis points, integer, no float.
This is the successor to `cycleDiscount()`'s `['halfyear'=>0.10,'annual'=>0.25]`
(`BillingController.php:49-53`).

### XXV.14.7 `entitlement_sets`, `entitlement_set_versions`, `entitlement_features`, `entitlement_limits`, `entitlement_quotas` *(platform-scoped)*

Structure per §XXV.6.2. Owned jointly with Part XXVII, which specifies the grammar and the
licence-side resolution. Unique `(entitlement_set_version_id, feature_key)` etc.

### XXV.14.8 `seat_policies` *(spine S4 artefact)*

Stored as a `config_artefact` of type `seat_policy` with typed child rows:
`seat_free_stages` (value-set references), `consumes_seat_expr` (SPX AST + source),
`meter_key`, `basis`, `pooling`. Resolution via `resolve('seat_policy', key, scope, as_of)`.

### XXV.14.9 `meters`, `meter_readings`, `meter_snapshots`

`meters` *(platform)*: `meter_key`, `unit`, `basis`, `window`, `aggregation`, `is_billable`,
`is_quota_enforced`.
`meter_readings`: `tenant_id`, `meter_key`, `business_date`, `value`, `computed_at`,
`source_generation`. PK `id` · **Unique `(tenant_id, meter_key, business_date)`** — the
constraint that makes aggregation idempotent (R-XXV-099) · Index `(business_date)`.
Immutable once written (R-XXV-079).
`meter_snapshots`: the period aggregate presented on an invoice —
`tenant_id`, `meter_key`, `period_start`, `period_end`, `basis`, `value`, `evidence_json`,
`invoice_line_id`. Unique `(tenant_id, meter_key, period_start, period_end)`.

### XXV.14.10 `invoices`, `invoice_lines`

`invoices`: `tenant_id`, `uuid`, `number`, `series_key`, `document_type`, `legal_entity_id`,
`issued_on`, `due_on`, `period_start`, `period_end`, `currency`,
`subtotal_minor`, `discount_minor`, `tax_minor`, `total_minor`,
`tax_treatment_json` (**snapshot**), `seller_snapshot_json`, `buyer_snapshot_json`,
`status`, `pdf_object_ref`, `pdf_checksum`, `created_at`.
PK `id` · Unique `(uuid)` · **Unique `(legal_entity_id, series_key, number)`** ·
Index `(tenant_id, issued_on)` · Index `(status, due_on)`.
Hist: immutable after issue; corrections are credit notes. SD: no. Audit: issue, cancel,
credit. PII: buyer identity.

`invoice_lines`: `invoice_id`, `line_no`, `meter_key`, `description_snapshot`, `quantity`,
`unit_amount_minor`, `discount_minor`, `taxable_minor`, `tax_component_json`,
`meter_snapshot_id`, `plan_version_id`, `price_book_version_id`.
Unique `(invoice_id, line_no)`. Every line names the versions that produced it (spine S4).

### XXV.14.11 `credit_notes`, `credit_note_lines`

Same shape as invoices, with `against_invoice_id` (FK, RESTRICT), a mandatory `reason_code`
and `reason_text`, and its own series. R-XXV-111.

### XXV.14.12 `payments`, `payment_allocations`, `refunds`

`payments`: `tenant_id`, `uuid`, `provider_key`, `provider_txn_id`, `amount_minor`,
`currency`, `received_at`, `method`, `provider_facts_json`, `status`, `recorded_by`.
PK `id` · **Unique `(provider_key, provider_txn_id)`** — the index SmartPRS 2 lacks
(`I-…` DEF-13) · Index `(tenant_id, received_at)`.

`payment_allocations`: `payment_id`, `invoice_id`, `amount_minor`, `allocated_at`,
`allocated_by`. PK `id` · Unique `(payment_id, invoice_id)` ·
**CHECK: `SUM(allocations per invoice) <= invoice.total_minor`**, enforced by a constrained
allocation service and asserted nightly (R-XXV-108).

`refunds`: `payment_id`, `credit_note_id`, `amount_minor`, `provider_refund_id`, `status`,
`reason_code`, `requested_by`, `approved_by`.
Unique `(provider_key, provider_refund_id)`.

### XXV.14.13 `document_number_sequences`

`legal_entity_id`, `series_key`, `fin_year`, `next_value`, `format_key`, `updated_at`.
PK `(legal_entity_id, series_key, fin_year)`. Allocated under `FOR UPDATE` inside the issuing
transaction. R-XXV-100.

### XXV.14.14 `signups`, `quotations`

Migration-owned successors to the runtime-created `signups`
(`SignupController::ensure():33-119`, plus six separate self-healing column blocks).
`signups`: `uuid`, `provisioning_key`, `plan_version_id`, `requested_seats`,
`requested_companies`, `cycle_key`, `quoted_amount_minor`, `coupon_code`, `state`,
`gateway_intent_ref`, `tenant_id`, `terms_version`, `terms_accepted_at`, `expires_at`.
Unique `(uuid)` · Unique `(provisioning_key)` · Index `(state, created_at)`.
`quotations`: `number`, `series_key`, `token_hash`, `valid_until`, `snapshot_json`,
`state`. **Unique `(series_key, number)`** and **Unique `(token_hash)`** — the indexes
`signups.quote_no` and `signups.quote_token` lack (`I-…` DEF-22, §6.6).

### XXV.14.15 `coupons`, `coupon_redemptions` *(platform-scoped)*

Migrated 1:1 from SmartPRS 2's shape, with `used_count` under an atomic constraint and
`periods_applicable` added (R-XXV-048, R-XXV-050).
Unique `(code)` · Unique `(coupon_id, tenant_id)` where `once_per_customer`.

### XXV.14.16 `config_templates`, `config_template_versions`

Per §XXV.3.3. Platform-scoped for vendor templates; tenant-scoped for customer-authored ones,
with `owner_tenant_id` nullable and participating in the unique index.

### XXV.14.17 `config_prerequisites`, `tenant_prerequisite_status`

`tenant_prerequisite_status`: `tenant_id`, `prereq_key`, `satisfied`, `evaluated_at`,
`blocking`, `detail_json`. Unique `(tenant_id, prereq_key)`. R-XXV-035.

### XXV.14.18 `tenant_quotas`, `quota_events`

`quota_events`: `tenant_id`, `quota_key`, `window_start`, `observed`, `limit`, `action_taken`,
`occurred_at`. Index `(tenant_id, occurred_at)`. Feeds R-XXV-125's explainability.

### XXV.14.19 `provisioning_runs`

`provisioning_key`, `caller`, `request_json`, `template_version_id`, `state`,
`stage_reached`, `error_code`, `error_detail`, `tenant_id`, `started_at`, `finished_at`,
`audit_entry_id`. Unique `(provisioning_key)`. R-XXV-023, R-XXV-025.

### XXV.14.20 `demo_sessions`

`uuid`, `tier`, `invitation_id`, `requester_name`, `requester_email_hash`,
`requester_mobile_hash`, `tenant_id`, `passkey_hash`, `expires_at`, `entered_at`,
`purged_at`. Unique `(uuid)` · Index `(expires_at, purged_at)`.
**No plaintext passkey column exists** (R-XXV-089). Contact details are stored for lead
capture in the CRM path with consent recorded; the session table holds hashes only.

### XXV.14.21 `demo_invitations`

`uuid`, `tier`, `issued_by`, `issued_to_email`, `max_uses`, `used_count`, `expires_at`,
`signature`, `revoked_at`. Unique `(uuid)`. R-XXV-085, R-XXV-087.

### XXV.14.22 `tenant_offboarding`

`tenant_id`, `cancelled_at`, `effective_on`, `export_window_ends_on`, `hold_ends_on`,
`purge_on`, `legal_hold`, `export_completed_at`, `purge_certificate_ref`, `purged_at`.
PK `tenant_id`. R-XXV-114 … R-XXV-120.

---

## XXV.15 API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed on every unsafe
method, with the common error envelope (spine S17, Part XXIV). Permissions are
`verb.resource` bound to a policy that also answers the data-scope question (spine S13).

### XXV.15.1 Public (unauthenticated, rate-limited)

| Method | Path | Purpose | Controls |
|---|---|---|---|
| GET | `/public/plans` | sellable plan versions and their price books | cached; sellability is a data flag |
| POST | `/public/quotes` | server-computed quote, signed, TTL-bound | throttle per IP and email |
| POST | `/public/signup/orders` | create signup + payment intent | idempotency key required; throttle |
| POST | `/public/signup/orders/{uuid}/confirm` | verify and provision | idempotent; constant-time signature check |
| POST | `/public/webhooks/payments/{provider}` | provider events | signature + timestamp + nonce, replay window (spine S17) |
| POST | `/public/demo/sessions` | request a public-tier demo | throttle; tier fixed by policy, **never by a request field** |
| POST | `/public/demo/sessions/{uuid}/enter` | redeem a passkey | rate-limited; hashed comparison |
| POST | `/public/quotations/{token}/orders` | pay a quotation | validity enforced |

### XXV.15.2 Tenant-facing

| Method | Path | Permission |
|---|---|---|
| GET | `/subscription` | `billing.subscription.view` |
| GET | `/subscription/usage` | `billing.usage.view` |
| POST | `/subscription/renewals` | `billing.renew` |
| POST | `/subscription/upgrades` | `billing.upgrade` |
| POST | `/subscription/scheduled-changes` | `billing.downgrade` |
| POST | `/subscription/cancellation` | `billing.cancel` (maker-checker, spine S8) |
| GET | `/invoices`, `/invoices/{uuid}`, `/invoices/{uuid}/pdf` | `billing.invoice.view` |
| GET | `/credit-notes`, `/credit-notes/{uuid}/pdf` | `billing.creditnote.view` |
| GET | `/payments` | `billing.payment.view` |
| GET | `/onboarding/prerequisites` | `config.completeness.view` |
| POST | `/onboarding/templates/{key}/apply` | `config.template.apply` (maker-checker) |
| POST | `/offboarding/exports` | `tenant.export` |
| GET | `/offboarding/exports/{uuid}` | `tenant.export` |
| GET | `/tenant/status-feed` | `tenant.status.view` — the customer-visible incident feed |

### XXV.15.3 Platform-facing (vendor)

| Method | Path | Permission |
|---|---|---|
| POST | `/platform/tenants` | `platform.tenant.provision` |
| GET | `/platform/tenants`, `/platform/tenants/{uuid}/health` | `platform.tenant.view` |
| POST | `/platform/tenants/{uuid}/containment` | `platform.tenant.contain` — throttle, pause, read-only |
| POST | `/platform/tenants/{uuid}/purge` | `platform.tenant.purge` (two-person, R-XXV-117) |
| POST | `/platform/plans`, `/platform/plans/{key}/versions` | `platform.plan.publish` (maker-checker) |
| POST | `/platform/price-books/*` | `platform.pricebook.publish` (maker-checker) |
| POST | `/platform/coupons` | `platform.coupon.manage` |
| POST | `/platform/demo-invitations` | `platform.demo.invite` |
| GET | `/platform/reconciliation/discrepancies` | `platform.finance.view` |
| GET | `/platform/estate/completeness` | `platform.tenant.view` |

- **R-XXV-140** Every route above MUST carry an explicit policy binding, asserted by a CI
  sweep (spine Law 5). *Test: the sweep passes with zero unbound routes.*
- **R-XXV-141** No platform endpoint may read or write tenant business data without a
  time-boxed, tenant-approved support grant, doubly recorded (Part XXII, Part XXIII). *Test:
  a platform operator cannot read a payslip without a live grant.*

---

## XXV.16 Rules and formulas in SPX

### XXV.16.1 The Commerce action catalogue

Added to the spine S6 catalogue, owned by this part, closed and versioned:

```
Commerce   SET_SUBSCRIPTION_STATE(state) · APPLY_DUNNING_STEP(step_key)
           · RAISE_INVOICE(series_key, period) · ALLOCATE_PAYMENT(payment_ref, invoice_ref)
           · METER(meter_key, quantity) · BLOCK_CREATION(reason_key)
           · GRANT_OVERAGE(meter_key, quantity, reason) · SCHEDULE_CHANGE(change_ref, on)
           · OPEN_EXPORT_WINDOW(days) · SCHEDULE_PURGE(on)
```

### XXV.16.2 Worked example — pricing

Price book version 7, plan `growth` version 3, the values SmartPRS 2's seeder currently uses
(`StandardPlanSeeder.php:35-37`): base ₹2,500/month, 75 seats included, ₹50/month per seat
beyond, additional company ₹1,000/month.

```
BILLABLE_SEATS   = MAX(0, meter.seat - price.included_seats)
MONTHLY_BASE     = price.base
                 + price.per_seat  * BILLABLE_SEATS
                 + price.per_company * MAX(0, meter.company - 1)
CYCLE_GROSS      = MONTHLY_BASE * cycle.months
CYCLE_NET        = CYCLE_GROSS - ROUND(CYCLE_GROSS * cycle.discount_bp / 10000,
                                       mode: HALF_UP, unit: 0.01)
TAX              = SLAB(CYCLE_NET, 'saas_service_tax', period.start)   -- <from tax rule pack>
TOTAL            = CYCLE_NET + TAX
```

Growth, 80 seats, 1 company, annual (`cycle.months = 12`, `discount_bp = 2500`):

```
BILLABLE_SEATS = 80 - 75 = 5
MONTHLY_BASE   = ₹2,500.00 + ₹50.00 × 5 + ₹0.00 = ₹2,750.00
CYCLE_GROSS    = ₹2,750.00 × 12          = ₹33,000.00
discount       = ₹33,000.00 × 25%        = ₹8,250.00
CYCLE_NET                                 = ₹24,750.00
TAX            = <from tax rule pack>     (at the 18% literal SmartPRS 2 uses: ₹4,455.00)
TOTAL                                     = ₹29,205.00
```

This reproduces `tests/Feature/BillingTest.php:17-35` exactly, which is deliberate: the
pricing *behaviour* is retained and only the *implementation* changes (`I-…` §9 row 5).

Growth, 80 seats, **3 companies**, quarterly:

```
MONTHLY_BASE = ₹2,500.00 + ₹250.00 + ₹1,000.00 × 2 = ₹4,750.00
CYCLE_GROSS  = ₹4,750.00 × 3 = ₹14,250.00   (no cycle discount at quarterly)
```

### XXV.16.3 Worked example — seat consumption

Tenant "Apex Collections", `seat_policy` v4, basis `peak_in_period`, pooling `tenant`,
seat-free stages `[exited, resigned, terminated, absconded, archived]`:

| Person | Stage on 1 Aug | Change during Aug | Consumes? | Why |
|---|---|---|---|---|
| E-1001 | active | — | **yes** | consuming stage throughout |
| E-1002 | active | exited 14 Aug | **yes** | consuming for at least one day (peak basis) |
| E-1003 | — | joined 20 Aug | **yes** | consuming from 20 Aug |
| E-1004 | archived | — | no | `archived` is seat-free under this policy |
| E-1005 | probation | confirmed 5 Aug | **yes** | `probation` is not seat-free |
| A-201 (off-roll) | active | — | **no seat**; counts on the `agent` meter | D-XXV-06 |

Peak seat count for August = 4. Under a point-in-time-at-period-end basis it would be 3, and
E-1002 — for whom a payslip, a full-and-final settlement and a statutory line were all
produced — would be free. That is the substance of D-XXV-05.

### XXV.16.4 Worked example — the dunning ladder for a negotiated contract

Reseller contract: warn at 30/14/7/1, grace 21 days with a reminder every third day, no
auto-suspension.

```
plan_version.grace_days           = 21
plan_version.auto_suspend_default = FALSE
dunning ruleset 'reseller-a':
  T-30, T-14, T-7, T-1   → NOTIFY('billing.renewal_due')
  grace, every 3rd day   → NOTIFY('billing.grace_reminder')
  grace exhausted        → NOTIFY('billing.grace_expired'), RAISE_ITEM('collections.escalate')
```

No code changes. In SmartPRS 2 this requires editing three PHP files.

---

## XXV.17 Explainability

Per spine S15, every derived commercial number offers "How was this calculated?".

**An invoice line:**

```
Line 2 — Additional companies
  Meter        company · peak in period · 3 observed, 1 included
  Quantity     2
  Unit rate    ₹1,000.00 / month   (price book "SaaS INR" v7, effective 01-Apr-2026 →)
  Months       3                   (cycle "quarterly", plan "Growth" v3)
  Gross        ₹6,000.00
  Discount     ₹0.00               (cycle discount 0 bp at quarterly)
  Taxable      ₹6,000.00
  Tax          <from tax rule pack IN-GST v2026.2, service code from pack>
  Rounding     HALF_UP to 0.01, applied once at the taxable subtotal
  Evidence     meter_snapshot #48211 — daily readings 01-Jul … 30-Sep
```

**A seat refusal:**

```
Cannot add employee
  Policy       seat_policy "Standard" v4 (effective 01-Apr-2026 →)
  Consuming    stage NOT IN [exited, resigned, terminated, absconded, archived]
  Used         250 of 250   (peak in the current period; 248 today)
  Limit        250          from subscription → plan "Growth" v3 → limit set v2
  Over-limit   block        (this tenant's plan; warn_and_meter is available)
  Options      buy 10 more seats (₹500.00/month pro-rated) · change an employee's stage
```

**A lock-out:**

```
Why is this workspace read-only?
  State        suspended since 18-Aug-2026
  Because      grace period of 7 days ended on 17-Aug-2026
  Period       01-May-2026 → 31-Jul-2026, last extended by payment #P-9912 on 28-Apr-2026
  Invoice      PRS-2026-27-08-0142, ₹29,205.00, unpaid
  Warnings     T-15 delivered 16-Jul · T-7 delivered 24-Jul · T-3 delivered 28-Jul
               · T-1 delivered 30-Jul · grace ×7 all delivered
  Restore      settle the invoice; access is restored on allocation
```

- **R-XXV-150** Every commercial number and every commercial refusal MUST carry a trace naming
  the artefact versions, the inputs, the arithmetic and the rounding (spine Law 4). *Test: no
  commercial engine returns a value without a trace.*
- **R-XXV-151** A lock-out explanation MUST state which warnings were **delivered** — not
  enqueued — with dates. *Test: a tenant suspended without delivered warnings is impossible
  under R-XXV-074, and the trace proves it.*

---

## XXV.18 Events

Emitted (Part XXIV webhook contracts; Part XIX consumes for notification):

| Event | Payload highlights |
|---|---|
| `tenant.provisioning.started` / `.completed` / `.failed` | provisioning key, template version, stage, error |
| `tenant.activated` | tenant uuid, plan version |
| `tenant.status.changed` | from, to, reason |
| `subscription.state.changed` | from, to, trigger, guard trace, effective dates |
| `subscription.period.advanced` | new period, causing payment and invoice |
| `subscription.trial.started` / `.ending` / `.expired` | dates, expiry action |
| `subscription.cancelled` / `.reinstated` | effective date, purge date |
| `invoice.issued` / `.settled` / `.overdue` / `.cancelled` | number, totals, period |
| `creditnote.issued` | number, against invoice, reason |
| `payment.received` / `.allocated` / `.refunded` | provider ref, amount, allocation |
| `dunning.step.executed` | step key, channel, delivery id |
| `meter.threshold.crossed` | meter, observed, limit, soft/hard |
| `seat.limit.reached` / `.overage.granted` | policy version, counts |
| `quota.exceeded` | quota key, window, action taken |
| `demo.session.created` / `.entered` / `.purged` | tier, invitation, tenant uuid |
| `tenant.export.completed` | manifest checksum, destination |
| `tenant.purged` | certificate reference |
| `tenant.containment.applied` / `.lifted` | action, operator, reason |

Consumed:

| Event | From | Effect |
|---|---|---|
| `employee.stage.changed`, `employee.created`, `employee.archived` | Part X | meter recomputation |
| `offroll_agent.created` / `.deactivated` | Part XX | agent meter |
| `company.created` / `.closed` | Part V | company meter and limit check |
| `payroll.run.completed` | Part XIV | payslip meter; last-successful-run health signal |
| `punch.batch.ingested` | Part XI | ingest meter; noisy-neighbour signal |
| `message.delivered` / `.failed` | Part XIX | dunning delivery state (R-XXV-075) |
| `rulepack.activated` | Part XXVII | tax configuration refresh |

---

## XXV.19 Migration implication

| Capability | Verdict | Transformation | Does not come across |
|---|---|---|---|
| `tenants`, `companies`, `users` | migrate | add region, tier, template provenance; recompute slug against the new format | the `100000` seat default (`ClientProvision.php:72`) |
| `plans` | migrate as plan v1 | split into plan version + price book version + entitlement set + limit set | the `['Starter','Growth','Professional']` name whitelist |
| `subscriptions` | migrate | pin to the derived versions; recompute `grace_ends_on`; **audit every tenant whose period was advanced without a matching settled payment** | `next_renewal` as a driver of anything |
| `invoices` | migrate verbatim | **preserve every number**; seed sequences at the observed maximum; backfill `tax_treatment_json` and identity snapshots from the stored PDF where present, else mark `snapshot_reconstructed` | nothing |
| `payments` | migrate | reconcile first (R-XXV-110); split into payment + allocation; build the unique index and fail the migration on a duplicate | the two-recorder semantics |
| `coupons`, `coupon_redemptions` | migrate 1:1 | add the atomic uses constraint and `periods_applicable` | nothing |
| `signups` (incl. quoted) | migrate | add unique indexes on quotation number and token | rows in a state no successor state maps to (recorded as an exception list) |
| `renewals` | migrate as scheduled changes | | the "pending renewal" recovery path, replaced by allocation |
| `subscription_alerts` | migrate as delivery-ledger history | mark every historical row `queued`, **never** `delivered`, because the source cannot distinguish them (`L-…` DEF-L-13) | the claim of delivery |
| `demo_requests` | **do not migrate** | leads move to the CRM path with consent recorded | plaintext passkeys (`I-…` DEF-43) |
| Seat counts | **recompute at cutover** | expect them to move — two definitions become one | both old definitions |
| `wa_log` credential-bearing rows | **purge before migration** | `body_values` containing temporary passwords and OTPs must be destroyed, not carried | all of it (`L-…` SEC-L-03) |

- **R-XXV-160** Cutover MUST NOT proceed while any of the following is non-empty: invoices
  over-allocated, tenants without a subscription, subscriptions whose period was advanced with
  no settled payment, or duplicate provider transaction identifiers. *Test: the four
  reconciliation reports are empty.*
- **R-XXV-161** Every customer whose seat count changes at cutover MUST be identified,
  quantified and communicated before the first invoice under the new definition. *Test: the
  delta report exists and is signed off by the Product Owner.*

---

## XXV.20 Edge cases and failure states

| # | Situation | Required behaviour |
|---|---|---|
| 1 | Payment succeeds at the provider; provisioning fails | The order stays `paid_awaiting_provisioning`; an operator alert fires; the customer sees an honest status page; retry is idempotent on the provisioning key. Never a silent nothing (`I-…` DEF-15) |
| 2 | Browser closed between payment and confirmation | The webhook completes provisioning; the customer receives the activation email regardless (R-XXV-044) |
| 3 | Webhook arrives before the browser callback | Both are idempotent on the provider transaction id; one payment, one provisioning |
| 4 | Provider reports a partial capture | The captured amount is recorded; the invoice is partly allocated; the period does **not** advance |
| 5 | Duplicate webhook after a retry storm | Unique index rejects; the handler returns success |
| 6 | Customer pays the wrong amount by NEFT | Recorded as an unallocated payment; allocation is an explicit operator action; the period advances only on full allocation |
| 7 | Coupon exhausted between quote and order | Revalidated at order; the customer is shown the new price before payment, never after |
| 8 | Seat limit reached mid-import | Whole batch refused with the shortfall named (R-XXV-066) |
| 9 | Seat policy republished mid-period | The period's meter uses the policy effective on each day; the invoice trace names both versions |
| 10 | An employee is exited and rehired in the same period | Peak basis counts one seat; the trace shows both spans |
| 11 | Tenant exceeds seats while `over_limit_mode = auto_expand` | Seats expand, a pro-rata charge is raised, the administrator is notified; no refusal |
| 12 | Grace ends while the notification worker is stopped | **No suspension occurs** (R-XXV-074); an operator alert fires instead |
| 13 | Clock skew across app nodes | All lifecycle dates derive from the database's clock, never a node's |
| 14 | Tenant cancels, then pays before the effective date | Cancellation is revoked as an explicit audited transition; the export window closes |
| 15 | Tenant cancels with a legal hold in force | Purge refuses and names the hold (R-XXV-118) |
| 16 | Purge scheduled but backups still contain the tenant | Certificate states the backup-expiry date; restores after it refuse the tenant (R-XXV-119) |
| 17 | Silo tenant's database unavailable during estate billing | That tenant's billing is deferred and reported; the estate run completes for everyone else (per-item fault isolation, `L-…` DEF-L-03) |
| 18 | A tenant's own configuration change makes them fail a blocking prerequisite | Payroll refuses with the prerequisite named; the tenant is notified; nothing is silently skipped |
| 19 | Demo tenant pool exhausted | Honest queueing message with a wait estimate; never a fallback to a shared tenant (R-XXV-081) |
| 20 | A demo invitation is forwarded to a third party | Single-use and recipient-bound; a second redemption is refused and the issuer is notified |
| 21 | Two administrators cancel and renew simultaneously | Serialised on the subscription row; the losing transaction is told what changed |
| 22 | A tenant is over quota on outbound messages during dunning | Dunning messages are exempt from the tenant's own message quota; provider limits still apply |
| 23 | Tax rule pack changes mid-period | Documents issued after the effective date use the new treatment; issued documents are never restated (R-XXV-102) |
| 24 | Migration cutover finds an invoice with allocations exceeding its total | Cutover halts (R-XXV-160) |
| 25 | An operator applies containment to the wrong tenant | Reversible, audited, visible to the tenant; the reversal is a first-class action (R-XXV-135) |

---

## XXV.21 Tests

Exact-value regression scenarios. Each must assert a specific number or a specific state, not
merely absence of error.

| # | Scenario | Assertion |
|---|---|---|
| T1 | Growth, 80 seats, 1 company, annual | net ₹24,750.00; tax and total from the pack; reproduces `BillingTest.php:17-35` |
| T2 | Growth, 80 seats, 3 companies, quarterly | gross ₹14,250.00 |
| T3 | Growth, 40 seats, half-yearly | net ₹13,500.00 |
| T4 | 20% coupon on T1 for four periods | period 1 net ₹19,800.00; period 2 net ₹19,800.00; period 5 net ₹24,750.00 |
| T5 | **Renewal invoice raised, never paid** | `current_period_end` unchanged; state reaches `grace` on schedule; **the direct regression for `I-…` DEF-11** |
| T6 | Payment allocated to the renewal invoice | period advances by exactly the cycle months; `subscription_period_history` names the payment |
| T7 | Early renewal 20 days before period end | new end = old end + cycle, not today + cycle |
| T8 | Mid-term upgrade, 45 days remaining | pro-rata computed on the declared day basis; period end unchanged; value asserted exactly |
| T9 | Webhook retry concurrent with browser confirm | exactly one payment row; exactly one provisioned tenant |
| T10 | 100 concurrent invoice creations | 100 distinct consecutive numbers, no gap, no duplicate |
| T11 | Seat cap reached; add via **each** of the eight doors | all eight refused identically; **regression for bypasses B1, B2, B10** |
| T12 | Import 50 people with 10 seats free | zero rows committed; shortfall reported |
| T13 | Archive an employee under a policy where archive is seat-free | count drops by one and the message matches the policy |
| T14 | Archive under a policy where it is not | count unchanged and the message does **not** claim it frees a seat; **regression for `SubscriptionService.php:141`** |
| T15 | Corrupt seat policy | creation blocked (fail closed) |
| T16 | Simulated database timeout in the counter | creation permitted, counter incremented, alert raised; **regression for bypass B7** |
| T17 | `licensed = 0` | zero employees permitted; **regression for bypass B4** |
| T18 | Provisioning fault injected at each of the twelve stages | no partial tenant in any case |
| T19 | Provision from the default template, hire one employee, run payroll | exact net asserted with **no configuration screen opened**; the acceptance test for R-XXV-030 |
| T20 | Export tenant A's configuration, import into tenant B, run the same payroll | identical net |
| T21 | Missed dunning run for a day | the reminder is delivered on the next run; **regression for `L-…` DEF-L-05** |
| T22 | Notification worker stopped, grace exhausted | no suspension; alert raised; **regression for `L-…` §Q9(B)** |
| T23 | Cancelled subscription in the grace window | no renewal chasing; **regression for `L-…` DEF-L-20** |
| T24 | Grace-end date in the email vs the enforcing sweep | identical; **regression for `L-…` DEF-L-30** |
| T25 | Reprint an invoice after an upgrade | original plan name and seat count; **regression for `I-…` DEF-17** |
| T26 | Odd-paise tax split | components sum exactly to the total |
| T27 | Credit note against a paid invoice | ledger balances; document produced; refund recorded |
| T28 | Public POST `entry`-style parameter attempting a higher demo tier | refused; **regression for `I-…` SEC-03** |
| T29 | Attempt to authenticate a demo account by password | refused; **regression for `I-…` SEC-05** |
| T30 | Two demo visitors concurrently | no cross-visibility by any route |
| T31 | Demo purge predicate throws | alert raised and retried, not treated as "nothing due"; **regression for `L-…` SEC-L-05** |
| T32 | Trial expiry with each configured action | correct terminal state, and every configured reminder delivered; **regression for `I-…` DEF-23** |
| T33 | Tenant with no subscription | the nightly sweep reports it; the tenant is not silently ungated |
| T34 | Pathological single-tenant load | second tenant's p95 within tolerance |
| T35 | One tenant enqueues 10,000 bulk jobs | second tenant's interactive job completes within its target |
| T36 | Purge a cancelled tenant | certificate produced; residue sweep empty; audit entry survives |
| T37 | Restore from an archive predating a purge | refused for the purged tenant |
| T38 | Migration reconciliation on a production-shaped dataset | four reports empty, or cutover halts |
| T39 | Cross-tenant read attempt on every endpoint in §XXV.15 | denied (Law 6 generated suite) |
| T40 | Fault injected inside authorisation on every route | denied (Law 5) |

---

## XXV.22 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Decision | Recommendation | Trade-off if refused |
|---|---|---|---|
| **D-XXV-01** | Is a dedicated-database (silo) tier offered? | Yes, priced, same artefact and schema | Regulated and enterprise buyers are unservable; a private-cloud install needs a separate mechanism |
| **D-XXV-02** | Data residency commitments | India-region only in v3.0, recorded on the tenant record | Procurement objections; a later second region becomes a migration rather than an addition |
| **D-XXV-03** | More than one payment provider at launch? | Build the abstraction, ship one | A provider change becomes a rewrite of six call sites, as it is today |
| **D-XXV-04** | Grandfathering on plan-version change | Pin the subscription; migrate explicitly | Silent repricing of customers who have paid a year in advance |
| **D-XXV-05** | **What consumes a seat?** Basis, archived treatment, seat-free stage list | Peak-in-period, pooled tenant-wide; archived does **not** consume; the six SmartPRS 2 statuses plus `archived` as the shipped default, all tenant-editable | Without an answer, v3.0 pricing cannot be published and the migration cannot compute a delta. This is the single most commercially consequential decision in this part |
| **D-XXV-06** | **Are off-roll agents billable?** | Yes, on a separate cheaper meter | Today an agency runs 500 agents on a 25-seat plan; pricing them as full seats prices the collections vertical out of the market |
| **D-XXV-07** | Export window and hold period before purge | 30 days export, 60 days hold, both per-contract | Either an unbounded retention liability under the DPDP Act, or customers losing statutory records they are obliged to keep |
| **D-XXV-08** | Default over-limit behaviour | `warn_and_meter` for SaaS; `block` available | Blocking a payroll department on the 1st of the month is a churn and support cost |
| **D-XXV-09** | Is auto-suspension permitted for enterprise contracts? | Per-contract flag, default on for self-serve, default off for contracted | An enterprise customer suspended automatically is a contractual breach |
| **D-XXV-10** | Minimum commitment and cycle set | Retain `{quarterly, halfyear, annual}` as the shipped default **as data**, and allow monthly per contract | SmartPRS 2 rejects monthly everywhere while its own `cycleMonths()` still maps it — a latent inconsistency |
| **D-XXV-11** | Does the tenant see a customer-facing status feed? | Yes | Every incident becomes a support ticket, and containment actions look like outages |
| **D-XXV-12** | Who owns the compliance/tax configuration of the seller identity? | The named finance owner at Ametecs, with maker-checker | Today the vendor's GSTIN is a PHP default that a misconfigured deployment issues invoices under (`I-…` SEC-14) |

---

## XXV.23 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XXV-001 – R-XXV-010 | One artefact, tenancy model, topology, residency (§XXV.2) | 10 |
| R-XXV-020 – R-XXV-040 | Provisioning pipeline, templates, completeness, credentials (§XXV.3) | 21 |
| R-XXV-041 – R-XXV-052 | Signup, payment abstraction, coupons, quotations, credit (§XXV.4) | 12 |
| R-XXV-050 – R-XXV-055 | The period-advance invariant and lifecycle arithmetic (§XXV.7.3) | 6 |
| R-XXV-060 – R-XXV-079 | Lifecycle states, dunning, seats, meters (§XXV.7–XXV.9) | 20 |
| R-XXV-080 – R-XXV-091 | Trials and demos with real isolation (§XXV.5) | 12 |
| R-XXV-092 – R-XXV-099 | Plans, entitlements, grandfathering, metering cost (§XXV.6, §XXV.9.2) | 8 |
| R-XXV-100 – R-XXV-113 | Invoicing, tax, money discipline, payments, credit notes (§XXV.10) | 14 |
| R-XXV-114 – R-XXV-120 | Offboarding: export, hold, purge (§XXV.11) | 7 |
| R-XXV-121 – R-XXV-138 | Scale, quotas, job fairness, tenant observability, incident runbook (§XXV.12) | 18 |
| R-XXV-140 – R-XXV-141 | API constraints (§XXV.15) | 2 |
| R-XXV-150 – R-XXV-151 | Explainability (§XXV.17) | 2 |
| R-XXV-160 – R-XXV-161 | Migration gates (§XXV.19) | 2 |
| **Total** | | **134** |

*(R-XXV-050 and R-XXV-051 are stated once in §XXV.4 as journey requirements and restated in
§XXV.7.3 as the governing invariant; they are counted once, in the lifecycle range. R-XXV-080
appears as a reserved number in §XXV.9.1 and is allocated in §XXV.5.)*

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Seat definitions, seat-free stages, grace lengths, dunning ladders, cycle sets, cycle discounts, per-company fees, tax rates and codes, payment terms, settlement tolerances, trial lengths, export and hold windows, quota limits, over-limit behaviour, demo tiers and windows are **all** data. The only constants in code are structural (queue class names, state machine identifiers) and are on the declared allow-list | Static analysis over `Domain\Commerce`; T1–T4, T11–T17, T21–T24, T32 |
| **2 — no schema change at runtime** | All twenty-two tables in §XXV.14 are migration-owned. There is no successor to the nineteen runtime-created commerce tables (`I-…` §2.1), to `SignupController::ensure()`'s six self-healing column blocks, or to `ApprovalService::safeRow()`'s silent key-dropping | CI grep for DDL outside migrations; `migrate:fresh` parity; R-XXV-004, R-XXV-005 |
| **3 — nothing overwritten** | Plans, price books, entitlement sets, seat policies and templates are S4 artefacts: published, superseded, never edited. Invoices and credit notes are immutable snapshots. `subscription_period_history` and `subscription_state_transitions` are append-only. Meter readings are immutable once written | T25, T33; the append-only grants asserted in CI |
| **4 — every number explains itself** | Every invoice line, seat decision, quota decision and lock-out carries a trace naming artefact versions, inputs, arithmetic and rounding (§XXV.17). A commercial engine returning an untraced value throws | R-XXV-150; T1–T8 assert the trace alongside the value |
| **5 — authorisation fails closed** | Every route in §XXV.15 carries an explicit policy binding; entitlement and seat decisions fail closed on deterministic errors and fail open only on transient ones, with a counter and an alert; no session flag grants any bypass; platform access to tenant data requires a time-boxed tenant-approved grant | T15, T16, T28, T29, T40; the `demo_team` bypass shape asserted absent |
| **6 — tenancy is structural** | `tenant_id NOT NULL` in every business table and unique index here; platform-scoped tables are enumerated and hold no tenant business data; every meter, quota, metric and log line carries a tenant dimension; the unfiltered org queries at `AppDataController.php:274-291` have no successor | T39 generated cross-tenant suite; R-XXV-132, R-XXV-133 |
| **7 — money is exact** | All commercial arithmetic in integer minor units through a Money value object; every rounding point declared; tax splits allocate residue deterministically; `SUM(invoice lines) = invoice total` and `SUM(allocations) <= invoice total` asserted as constraints | T1–T4, T8, T26, T27; type-level float ban in `Domain\Commerce` |

---

*End of Part XXV.*
