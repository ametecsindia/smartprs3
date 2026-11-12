# PART VI — CONFIGURATION ARCHITECTURE

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Conforms to: `SPINE.md` S0–S20, and in particular S3 (organisation, scope, precedence),
S4 (effective dating, versioning, draft/publish), S5 (SPX), S6–S9 (the engines and the
entity schema engine), S12 (rule packs), S13 (security), S14 (audit), S15
(explainability), S16 (data conventions).
Extends: **Part V — Domain Model**, §V.8 "The universal Configuration Artefact", which
fixes the aggregate. This part fixes everything around it: the taxonomy, the lifecycle,
the resolution arithmetic, the tooling, the validation catalogue, the packaging model and
the honest limit.
Physical realisation: **Part XXVIII — Database Architecture and ERD**, whose catalogue
carries the tables defined in VI.17.

---

## VI.0 Purpose

This part decides **how "change configuration, not code" is actually true**, and where it
stops being true. It fixes three things.

1. **One configuration mechanism, not eleven.** Every kind of configurable behaviour —
   a late rule, a salary formula, a leave accrual, a workflow, a payslip template, a
   notification audience, a connector recipe, a field definition — is a version of a
   Configuration Artefact (spine S4), resolved by one function,
   `resolve(type, key, scope, as_of_date)`, validated by one validator, published through
   one lifecycle, and explained by one trace.
2. **That a consultant, not a developer, does the implementation work.** The Configuration
   Studio, the Configuration Wizard, the Sandbox and the Policy Packs exist so that
   80–90% of a customer implementation is configuration authored by an Ametecs
   implementation consultant or the customer's own administrator, with no release.
3. **Where configuration stops.** VI.15 states plainly what configuration cannot absorb
   and what the adapter/plugin path is. That section exists to protect Ametecs from an
   unbounded promise: a blueprint that implies everything is configurable is a
   commitment nobody can keep.

Every other part of this blueprint depends on this one. If the taxonomy, the precedence
arithmetic or the effective-dating semantics move, every module part moves with them.

---

## VI.1 What SmartPRS 2 does today, and why it must change

### VI.1.1 There is no configuration system — there are five stores, and they disagree

| Store | What it holds | Effective-dated | Scoped | Audited | Versioned | Evidence |
|---|---|---|---|---|---|---|
| `statutory_settings` (one JSON blob per tenant) | 36 keys: PF cap and rate, ESI threshold and rates, TDS slabs, standard deduction, cess, weekly-off day, Saturday mode, LOP basis, sandwich rule, bonus %, DRA gate, payslip download policy | **No** | **No** | **No** | **No** | `A-payroll.md` §5.1; `SettingsController::save():142-163`, `rates():91-102` |
| `statutory_configs` | Scoped, dated overrides of a *narrower* key set, plus PT slabs | **Yes** | tenant → state → company → location → branch | Yes | No | `F-statutory-compliance.md` §5.1; spine S19 item 6 calls this the model that was built and then bypassed |
| `app_settings` (JSON per key) | Greetings, absence sweep, probation, mail, branding | No | company for mail/branding only | **No** | No | `L-…` §5.1; `H-…` §5.1 |
| `settings` | Active financial year, landing CMS, `app_state` | No | No | **No** | No | `H-…` §5.1; `G-…` §5.1 |
| Master tables (58 registry types) | Components, shifts, late policy, leave types, holidays, pay cycles, letter templates, points rules, incentive schemes, min wages | **No** (except `min_wages`, whose date column is never read) | employee/team/company only, for two of them | Mostly no | No | `H-…` §5.2 items 1–10; `N-…` §5.1 |

Consequences that are not opinions:

- **`PayrollGenController::compute():1094` calls `SettingsController::rates($tid)` with no
  date argument at all.** Every rate used to recompute a 2025 month is the rate stored
  today (`A-payroll.md` Q5). A tenant that has never opened the Statutory Configuration
  screen has *zero* effective dating, because `StatutoryConfig::applyScope()` is an
  override layer that returns its input untouched when `hasAnyRows()` is false (`:121-123`).
- **`salary_components` has no effective-dating column at all**, and `employees.ctc` is
  overwritten in place by an increment (`IncrementController:342`). Changing a structure
  re-prices every regenerable month (`A-payroll.md` Q5).
- **Ties are resolved by row order.** Late policy scope resolution has three levels and
  breaks ties by whichever row the database returned first (`B-attendance.md` §5.2 item 24).
- **Six configuration keys exist in `defaults()` and are absent from the save contract**,
  so they can never be set through any UI — including `data_retention_months` (DPDP) and
  `contact_window_start`/`_end` (the RBI lawful-contact window)
  (`H-…` §5.2 item 56; `F-…` §5.2 item 31).
- **`pf_rate = 9999` is accepted.** No numeric setting declares bounds
  (`CONSOLIDATED-REQUIREMENTS` H-51).
- **No settings change writes an audit row.** `statutory_settings`, `app_settings.mail`,
  `app_settings.branding` and `settings` are all written unaudited (`H-…` §5.2 item 59).
- **The role→permission matrix is a JSON blob any authenticated user can overwrite, and it
  is applied only as `display:none` in the browser** (`G-…` §5.1; `N-…` §5.2 item 20).

### VI.1.2 The size of the gap

The fourteen as-is analyses each enumerate, in their §5.2, what a customer cannot change
without a code release. The census:

| Analysis | §5.2 items | Character of the gap |
|---|---|---|
| A — Payroll | 61 | Component identification by name substring; 5 divisors; no per-employee override; 4 NEFT layouts; one payslip Blade |
| B — Attendance | 58 | Attendance day = punch calendar date; 3 late tiers; no comp-off; no per-branch weekly off; no recalculation command |
| C — Biometric | 29 | 4 providers in an `if/elseif` repeated 5 times; no vendor addition; no device credential |
| D — Employee master | 52 | Zero custom-field capability; 16 format rules in a PHP const; tabs hard-coded |
| E — Leave/requests/notifications | 74 | 9 request types in a static array; one-hop approval; no accrual, carry-forward, encashment, blackout, half-day |
| F — Statutory | 44 | EPS/EDLI/gratuity/bonus constants; no jurisdiction model; no compliance calendar |
| G — Security/tenancy | 20 | 5 roles hard-coded in 5 disagreeing places; 71 `denyUnlessRole` literals; no data scope |
| H — Recruitment/HR ops/reports | 64 | 58 masters as PHP literals; 6-stage pipeline const; 21-key merge dictionary; 14 hand-written reports |
| I — SaaS/licensing | 48 | GST rate as a const; plan→module gating begins with `return;` |
| J — Database | 26 | Which tables exist; which columns; enum value sets on 30 columns; retention |
| K — Front end | 38 | 146 screen ids and the entire sidebar as literals in a 234 KB HTML file plus a 12,533-line PHP heredoc |
| L — Background ops | 39 | All twelve cron expressions; every threshold and audience |
| M — Quality/lifecycle | 20 | Retention for any entity; whether authorisation fails open |
| N — Product/modules | 57 | The module list itself, in six disagreeing vocabularies, none of them data |
| **Total** | **~630** | |

**R-VI-001** Every one of the ~630 items enumerated in the §5.2 sections of the as-is
analyses must be expressible as configuration in SmartPRS 3, or must appear in the
explicit exclusion list of VI.15.3 with a stated reason. *Test:* a traceability matrix,
maintained as a build artefact, maps each §5.2 item to an `artefact_type` + payload field,
to a rule-pack field, to an adapter contract, or to a VI.15.3 exclusion. An unmapped item
fails the release gate for v3.0 scope sign-off.

### VI.1.3 What SmartPRS 2 got right, and which this part generalises

Spine S19 item 6 names `statutory_configs` — effective-dated, scoped, audited, with a
finalised-period guard — as the right model, built and then bypassed. **This part is
that model, applied universally and made unavoidable.** The design choice to have exactly
one configuration store with one resolution function exists precisely because SmartPRS 2
proved that a correct configuration mechanism sitting beside four incorrect ones is worth
nothing.

---

## VI.2 The configuration taxonomy

### VI.2.1 The eleven kinds, and what distinguishes them

Configuration is not one thing. Five properties distinguish the kinds, and they determine
the store, the lifecycle, the signature requirement and the ownership.

| Property | Meaning |
|---|---|
| **Origin** | Who authors the content: Ametecs platform, Ametecs compliance, an implementation consultant, a tenant administrator |
| **Signature** | Whether the artefact is cryptographically signed by Ametecs and verified on load |
| **Distribution** | Whether it travels with the application release, as a separate signed content channel, or lives only in one tenant's database |
| **Tenant-editable** | Whether a tenant may change it, fork it, or only accept it |
| **Effective-dated** | All of them are. The differences are in who may set the date and whether back-dating is permitted |

| # | Kind | Origin | Signed | Distribution | Tenant may | Store |
|---|---|---|---|---|---|---|
| K1 | **Platform defaults** | Ametecs platform | with the release | application release | read only | `platform_defaults` (no `tenant_id`) |
| K2 | **Statutory rule packs** | Ametecs compliance owner | **yes** (S12) | separate signed content channel | accept / schedule; never edit | `rule_packs` (platform) |
| K3 | **Tenant policy** | tenant admin or consultant | no | tenant database | full CRUD through lifecycle | `config_versions` |
| K4 | **Metadata / field definitions** | consultant, then tenant admin | no | tenant database | full CRUD, within platform-owned system fields | `config_versions` → `field_definitions` (S9) |
| K5 | **Rules** | consultant, then tenant admin | no | tenant database | full CRUD | `config_versions` → `cfg_rules` |
| K6 | **Formulas** | consultant, then tenant admin | no | tenant database | full CRUD | `config_versions` → `cfg_formulas` |
| K7 | **Workflows** | consultant, then tenant admin | no | tenant database | full CRUD | `config_versions` → `cfg_workflows` |
| K8 | **Templates** (payslip, letter, notification, ID card, document layout) | consultant / tenant admin; Ametecs ships starters | no (starters carry a pack signature) | tenant database; starters via packs | full CRUD, fork-on-edit from a pack | `config_versions` → `cfg_templates` |
| K9 | **Report definitions** | consultant / tenant admin; Ametecs ships statutory registers | statutory registers: **yes**, inside a rule pack | mixed | fork-on-edit; a statutory register may be extended, not reduced | `config_versions` → `cfg_report_definitions` |
| K10 | **Integration / connector configuration** | consultant; credentials by tenant admin | no | tenant database | full CRUD; **credentials are write-only** | `config_versions` → `cfg_connector_recipes` + `integration_credentials` |
| K11 | **Feature / module entitlement** | Ametecs commercial | **yes** (inside the licence / subscription record) | licence or subscription | read only; may *disable* a granted feature, never enable an ungranted one | `entitlement_grants` (platform) + `tenant_feature_states` |
| K12 | **Per-employee exceptions** | tenant admin, with reason and approval | no | tenant database | full CRUD through lifecycle | `config_versions` with `scope.employee IN [...]` |

> There are twelve rows because "per-employee exception" is a distinct governance case, not
> a distinct mechanism. Mechanically it is K3 with an employee-scoped scope (specificity
> 1000) and a mandatory reason; governance-wise it must be listed separately because it is
> the single most-abused configuration surface in every HR platform, and because auditors
> ask for it by name.

### VI.2.2 Ownership — who may author, who may approve, who may publish

**R-VI-002** Every `artefact_type` declares, as platform data, the minimum permission
required to create a draft, to submit for approval, to approve, and to publish; and
whether an implementation consultant acting under a support grant may perform each. There
is no artefact whose write permission is a literal role name in PHP. *(SmartPRS 2 has 71
`denyUnlessRole` literal role arrays and one role set governing writes to all 58 masters —
`G-…` §5.2 item 2, `H-…` §5.2 item 7.)*

| Kind | Author | Approve | Publish | Notes |
|---|---|---|---|---|
| K1 Platform defaults | Ametecs engineering | Ametecs product | release pipeline | Never editable in a tenant; a tenant *overrides* by creating a K3 artefact of the same type |
| K2 Statutory rule packs | Ametecs compliance owner (**D-03**) | Ametecs compliance owner + a second signatory | Ametecs signing service | Tenant chooses only *when* to activate, within the statutory effective date |
| K3 Tenant policy | consultant or tenant admin (`config.author`) | tenant approver per workflow (`config.approve`) | `config.publish` | High-risk types require approval (S4) |
| K4 Metadata | consultant or tenant admin (`config.metadata.author`) | tenant approver | `config.publish` | System fields are `is_system` and may be re-labelled, re-ordered and surface-ruled but not deleted or re-typed |
| K5 Rules | consultant or tenant admin (`rules.author`) | tenant approver | `rules.publish` | Conflict detector runs at publish (S6) |
| K6 Formulas | consultant (`formula.author`) | tenant approver | `formula.publish` | **PRODUCT OWNER DECISION REQUIRED — D-VI-01**, see VI.24 |
| K7 Workflows | consultant or tenant admin | tenant approver | `workflow.publish` | In-flight instances complete on their own version (S8) |
| K8 Templates | tenant admin | tenant approver for payslip and letter types; none for notification body | `template.publish` | |
| K9 Report definitions | tenant admin | none for tenant reports; Ametecs for statutory registers | `reporting.publish` | |
| K10 Connectors | consultant | tenant admin | `integration.publish` | Credential values never appear in a read response (S13) |
| K11 Entitlement | Ametecs commercial | Ametecs commercial | licence issue / subscription change | Tenant may only narrow |
| K12 Per-employee exception | tenant admin, **reason mandatory** | always requires approval, regardless of type risk | `config.publish.employee_scope` | Listed on a standing exceptions report |

**R-VI-003** An implementation consultant operates inside the tenant under a **time-boxed,
audited support grant** with a named permission set, never under a shared administrator
login, and every action they take is attributed to their own identity in the audit ledger.
*Test:* an audit query for `actor_type = 'support_grant'` returns the grant id, its
expiry and the granting tenant administrator for every such row.

### VI.2.3 The precedence between kinds

When two kinds speak to the same value, the resolution order is fixed and is not
configurable:

```
1. Statutory rule pack  (K2)   — the floor; a company policy may improve, never reduce
2. Per-employee exception (K12) — highest specificity within tenant policy (weight 1000)
3. Tenant policy         (K3–K10) — resolved among themselves by S3.2 specificity/priority
4. Platform default      (K1)   — the value used when nothing else resolves
```

Entitlement (K11) is **orthogonal**: it does not supply values, it decides whether a
feature exists at all. A disabled feature's configuration remains stored and resolvable
but is never evaluated (VI.14).

**R-VI-004** A tenant policy artefact whose resolved value falls below a statutory rule
pack floor is handled by VI.13's enforcement model (`warn` / `block` /
`exception_required`), never by silent acceptance and never by silent clamping.

---

## VI.3 The universal artefact lifecycle

Spine S4 names the six states. This section specifies them completely: who may perform
each transition, what validates it, what it does to the predecessor, and what happens to
work that is already in flight.

### VI.3.1 The state machine

```
              ┌──────────── return_for_edit ────────────┐
              │                                          │
   [create]   ▼                                          │
  ──────► draft ──promote──► testing ──submit──► pending_approval ──approve──► published
              │                 │                        │                        │
              │                 └───── submit ───────────┘                        │
              │                                                                    │
              ├──discard──► (row deleted; draft only)                              │
              │                                                                    │
              └──archive──► archived ◄──archive── superseded ◄──supersede──────────┘
                                                       ▲                            │
                                                       └─── publish of a successor ─┘
```

`superseded` is entered **only** by the publication of a successor version, never by a
human action. `archived` is a terminal, non-resolvable state.

### VI.3.2 The transition table

| # | From → To | Actor / permission | Validation gate (VI.9 catalogue) | Side effects |
|---|---|---|---|---|
| T1 | — → `draft` | `config.author` on the type, or a support grant carrying it | **Structural only**: required payload fields present, referenced artefacts exist as *some* version | New `config_versions` row, `version_no = MAX+1`, `status='draft'`. Scope and dates may be empty at this point |
| T2 | `draft` → `draft` | author, or any `config.author` holder if the draft is shared | structural | Edit in place. Drafts are the **only** mutable rows in the configuration store |
| T3 | `draft` → `testing` | `config.author` | **Full static validation**: V01–V14 of VI.9. Type-checking of every SPX expression against the declared symbol table | Version becomes visible to the Sandbox (VI.8). Still not resolvable in production |
| T4 | `testing` → `draft` | author | none | Returns to editing |
| T5 | `testing` → `pending_approval` (or `draft` → `pending_approval` where the type does not require a testing pass) | `config.submit` | Full static validation **plus**: stored regression cases executed and green (V15); impact preview computed and attached (VI.10); for `high_risk` types, the diff must have been rendered | Workflow instance opened on the artefact type's declared approval workflow. `submitted_by`, `submitted_at` stamped |
| T6 | `pending_approval` → `draft` | approver, action `return_for_edit` | none | Comment mandatory. Workflow instance closed as returned |
| T7 | `pending_approval` → `published` | `config.publish` **and** the workflow instance must be in `approved` | **Publish-time validation**: V01–V21, re-run against the state of the store *at this instant*, not at submission. Overlap (V02), ambiguity (V03), continuity (V04), statutory floor (V19), entitlement (V20), finalised-period guard (V21) | See T7 side effects below |
| T8 | `published` → `superseded` | never a human action | — | Set by T7 on the predecessor |
| T9 | `superseded` → `archived` | `config.archive` | The version must not be referenced by any derived record produced in the last `retention.config_min_years` (V22) | Removed from resolution. Payload retained |
| T10 | `draft`/`testing` → discarded | author | The version must be `draft` or `testing` and `version_no` must be the highest | Hard delete permitted **only** for these two states (Part XXVIII R-XXVIII-010 `CASCADE` from the version to its own scope-predicate rows). Nothing else in the configuration store is ever hard-deleted |
| T11 | `published` → `published` (correction) | **not permitted** | — | A published version is immutable (Part V A1). A mistake is corrected by publishing a successor, or by the emergency rollback of VI.3.5 |

**T7 side effects, in one transaction:**

1. The predecessor published version *of the same artefact whose scope is identical* has
   its `effective_to` set to `new.effective_from − 1 day` and its status set to
   `superseded`, with `superseded_by_version_id` pointing at the new row.
2. The new row's `published_at`, `approved_by`, `approved_at` and `checksum` are stamped.
   `checksum` is a SHA-256 over the canonicalised payload plus the scope plus the dates;
   it is what makes tampering with a published row detectable.
3. Scope predicates are materialised into `config_version_scopes` (VI.17), which is the
   index the resolver reads. Resolution never parses `scope_json` at request time.
4. The resolution cache is invalidated for `(tenant, artefact_type, artefact_key)`.
5. `config.version.published` is emitted through the transactional outbox (Part V V.9.2).
6. An audit-ledger row is written with the full old→new payload diff (spine S14).

**R-VI-010** No configuration write path exists outside this state machine. *Test:* a CI
sweep asserts that `config_versions` is written only by `ConfigVersionService`, and a
database trigger equivalent (a repository guard plus a `row_version` check) rejects an
`UPDATE` on a row whose current status is `published`, `superseded` or `archived` for any
column other than `effective_to`, `status` and `superseded_by_version_id`.

### VI.3.3 What happens to in-flight dependents

This is the question SmartPRS 2 cannot answer, because it has no concept of "in flight".
Six cases, each with a defined answer.

| In-flight thing | What happens when a configuration version it depends on is published | Rationale |
|---|---|---|
| **A payroll run past `snapshot inputs`** | Nothing. The run holds an input snapshot naming every version id (spine S11) and resolves nothing further. The run's exception list gains an **informational** entry: "Configuration X v4 published on 12-Oct-2026 with effect from 01-Aug-2026; this run used v3. Re-run to adopt." | A run must be reproducible. SmartPRS 2 re-prices a regenerated draft at today's rates with no warning (`A-payroll.md` Q5) |
| **A payroll run before `snapshot inputs`** | The publication is permitted; the run picks up whatever resolves when it snapshots. A run in `preview` shows a **blocking** warning if a high-risk artefact was published since the preview was computed, and the preview must be recomputed | |
| **A locked period** | Publication with an `effective_from` inside a locked period is **blocked** (V21) unless the actor holds `config.publish.locked_period` *and* supplies a reason, which is recorded as a period-reopen intent. It never silently succeeds | SmartPRS 2's guard is called from exactly two places and the tenant-wide blob bypasses it entirely (`A-payroll.md` Q3) |
| **A workflow instance** | Completes on the workflow version it started on (spine S8). The new version applies to instances started after `published_at` | |
| **An attendance recalculation job** | The job carries the generation number and the resolved rule version ids it started with. A publication during the job does not change its inputs; the job completes, and a follow-on recalculation is **offered** (not automatically queued) with an impact count | |
| **An open approval on a *different* draft that references this artefact** | The referencing draft's validation is re-run asynchronously. If it now fails (V05 dangling reference, V06 cycle, V19 floor breach), the approval is suspended and the approver and author are notified naming the publication that caused it | SmartPRS 2 has no dependency graph at all |

**R-VI-011** A derived business record — attendance day, leave ledger entry, payslip line,
compliance gate decision, notification send — stores the ids of **every** configuration
version that contributed to it, and those version rows carry `ON DELETE RESTRICT`.
*Test:* for a sample of 1,000 payslip lines older than one year, `Explain(line)` renders a
complete trace with no missing version.

### VI.3.4 Effective date versus publication date

Three dates exist and are never conflated:

| Date | Meaning | Set by |
|---|---|---|
| `effective_from` / `effective_to` | The business dates over which this version governs | The author; validated by V02/V04/V16 |
| `published_at` | The instant the version became resolvable | The system, at T7 |
| `activation_at` (optional, future-dated activation) | For a version whose `effective_from` is in the future and whose publication is to be automatic | The author; a scheduled job performs T7 |

**R-VI-012** A version whose `effective_from` is in the future may be published
immediately (it simply resolves for nobody yet) **or** scheduled for automatic publication
via `activation_at`. Automatic publication re-runs the full publish-time validation at
activation and, on failure, does **not** publish, raises an operator alert and leaves the
version in `pending_approval`. A silently failed scheduled activation is a defect.
*(SmartPRS 2 has no scheduled configuration change of any kind; an increment is applied by
a manual click rather than on its effective date — `D-…` §5.2 item 47.)*

### VI.3.5 Emergency rollback

Because a published version is immutable, "undo" is not an edit — it is a publication.

**R-VI-013** `rollback(version_id)` creates a **new** version whose payload is a copy of
the last-but-one published version's payload, with `effective_from = today` (or an
explicit date), `reason` mandatory, and the fast-path approval declared on the artefact
type. It is a normal publication and is subject to every validation. The rolled-back
version is **not** deleted; it becomes `superseded` with a `superseded_reason` of
`rollback`. *Test:* after a rollback, `resolve(...)` for a date inside the rolled-back
version's original range still returns the rolled-back version, because history is not
rewritten — only the future is changed.

**PRODUCT OWNER DECISION REQUIRED — D-VI-02** (see VI.24): whether a rollback may
retro-actively close the bad version's range (rewriting what August resolves to) or only
change the future. Recommendation: **only the future**, with a separately-authorised,
separately-audited `retro_correction` operation for the genuine "we published a wrong
statutory rate and August must be recomputed" case.

---

## VI.4 Scope and precedence, worked

Spine S3.1 defines the Scope. Spine S3.2 fixes the weights. This section makes the
arithmetic concrete, because "most specific wins" is exactly the kind of phrase that
different implementers read differently — and SmartPRS 2's three-level scope resolved by
row order (`B-attendance.md` §5.2 item 24) is what happens when nobody writes the
arithmetic down.

### VI.4.1 The arithmetic, restated precisely

For a candidate version `V` and a subject employee `E` on date `D`:

1. **Match.** `V` matches `E` on `D` if, for every dimension `d` in the scope,
   either `V.scope[d] = ALL`, or `E`'s value of `d` **as at `D`** (from the effective-dated
   assignment, Part V V.6) is a member of `V.scope[d]`. A dimension the employee has no
   value for on `D` matches only `ALL`.
2. **Specificity.** `specificity(V) = Σ weight(d)` over the dimensions where
   `V.scope[d] ≠ ALL`. An empty scope has specificity 0 — the tenant default.
3. **Date.** `V.effective_from ≤ D ≤ COALESCE(V.effective_to, '9999-12-31')` and
   `V.status = 'published'`.
4. **Winner.** Highest specificity. On a tie, highest declared `priority`. On a further
   tie, **publication was blocked**, so this state cannot exist at resolution time; if it
   is nevertheless encountered (a data-repair error), the resolver **throws** and the
   calling engine reports a rule-evaluation failure. It never picks one.

**R-VI-020** The weights table lives in `platform_scope_dimensions` and is **not
tenant-editable**, because a customer changing precedence weights silently changes every
historical resolution (spine S3.2). Ametecs may add a dimension in a release; changing an
existing weight requires a migration that re-resolves and re-stamps affected derived
records, and is therefore a release-gated operation, not a configuration change.

### VI.4.2 Worked example 1 — four candidate late policies

**Subject.** Ravi Kumar, employee `HYD-0412`, on **14-Aug-2026**. His effective-dated
assignments on that date:

```
legal_entity  Ametecs Collections Pvt Ltd
company       Ametecs Collections
establishment Hyderabad Unit-1
state         Telangana
branch        Hyderabad
department    Collections
sub_department Voice Collections
team          Night-Team-A
designation   Recovery Agent
grade         G3
employee_category  FIELD
employment_type    permanent
employment_stage   confirmed
shift         Night (22:00–06:00)
location      Gachibowli
cost_centre   CC-COLL-01
client_process HDFC-PL-Bucket-2
```

Four published versions of `attendance.late_policy` / key `LATE-PENALTY` match him:

| Ref | Scope (dimensions ≠ ALL) | Specificity arithmetic | Total | Priority |
|---|---|---|---|---|
| **P1** | *(empty — tenant default)* | 0 | **0** | 0 |
| **P2** | `branch IN [Hyderabad]` | 180 | **180** | 0 |
| **P3** | `department IN [Collections]`, `branch IN [Hyderabad]` | 400 + 180 | **580** | 0 |
| **P4** | `designation IN [Recovery Agent]`, `branch IN [Hyderabad]` | 350 + 180 | **530** | 0 |

**Winner: P3 at 580.** The department-plus-branch policy beats the designation-plus-branch
policy, because `department (400) > designation (350)`. This is a deliberate and
counter-intuitive-to-some ordering, which is exactly why it is written down and shown in
the Studio: the "Why this policy?" panel on the employee's attendance day renders this
table verbatim.

### VI.4.3 Worked example 2 — the OT eligibility trap (brief Section 127 cases 6 and 7 combined)

Two requirements arrive from the same customer on the same day:

- *"Hyderabad branch receives OT; Pune does not."*
- *"Agents are eligible for OT; Managers are not."*

A consultant who authors them as two independent artefacts produces:

| Ref | Scope | Specificity | Payload |
|---|---|---|---|
| **O1** | `branch IN [Hyderabad]` | 180 | `eligible = true` |
| **O2** | `branch IN [Pune]` | 180 | `eligible = false` |
| **O3** | `designation IN [Recovery Agent]` | 350 | `eligible = true` |
| **O4** | `designation IN [Manager]` | 350 | `eligible = false` |
| **O5** | *(tenant default)* | 0 | `eligible = false` |

Resolve for **an Agent in Pune**: O2 (180) and O3 (350) both match. **O3 wins at 350** —
the Pune agent gets OT, which is *not* what the customer asked for.

This is not a flaw in the precedence model; it is the model doing its job and exposing an
under-specified requirement. Three things happen:

1. **The impact preview (VI.10) catches it before publication.** Publishing O3 shows
   `+41 employees newly OT-eligible`, broken down by branch, with Pune's 41 agents listed.
   The consultant sees the number and knows the requirement is under-specified.
2. **The Studio's conflict explorer** (VI.6.5) lets the consultant ask "for employee
   `PUN-0117`, which OT eligibility version wins and why", and renders the arithmetic.
3. **The correct authoring** is one artefact with combined dimensions:

| Ref | Scope | Specificity | Payload |
|---|---|---|---|
| **O6** | `branch IN [Hyderabad]`, `designation IN [Recovery Agent, Senior Agent]` | 180 + 350 = **530** | `eligible = true` |
| **O5** | *(tenant default)* | 0 | `eligible = false` |

Two artefacts replace five, the Pune agent resolves to O5 (`false`), and the intent is now
literally what is stored. **R-VI-021**: the Studio's rule-authoring surface offers
"combine into one scope" as a one-click remediation whenever the impact preview shows a
cross-dimension spill of this shape.

### VI.4.4 Worked example 3 — a genuine tie, resolved by priority

Customer requirement: *"Employees in the Collections department in Telangana get a 15-minute
grace; employees on grade G3 in Gachibowli get a 20-minute grace."*

| Ref | Scope | Specificity arithmetic | Total | Priority |
|---|---|---|---|---|
| **G1** | `department IN [Collections]`, `state IN [Telangana]` | 400 + 140 | **540** | 20 |
| **G2** | `grade IN [G3]`, `location IN [Gachibowli]` | 340 + 200 | **540** | 10 |

Ravi (Collections, Telangana, G3, Gachibowli) matches both at 540. **Specificity ties.
Priority decides: G1 at priority 20 wins; grace = 15 minutes.**

The Studio shows this tie explicitly at publish time as an **informational** finding, not
an error: *"G2 v1 and G1 v3 tie on specificity (540) for 214 employees. Priority resolves
in favour of G1 (20 > 10). Confirm this is intended."* A tie broken by priority is legal;
a tie the author did not know about is a defect waiting to be discovered in a payslip.

### VI.4.5 Worked example 4 — publication blocked for ambiguity

The consultant, correcting G2 after a customer conversation, sets G2's priority to 20 to
"make it as important as G1".

| Ref | Scope | Total specificity | Priority |
|---|---|---|---|
| **G1** | `department IN [Collections]`, `state IN [Telangana]` | 540 | 20 |
| **G2** | `grade IN [G3]`, `location IN [Gachibowli]` | 540 | **20** |

Publication of G2 v2 is **blocked**. What the administrator sees:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ✖  Cannot publish — ambiguous resolution                          V03       │
├──────────────────────────────────────────────────────────────────────────────┤
│  Artefact          Late Grace Policy — Gachibowli G3   (LATE-GRACE-GCB-G3)    │
│  Version           v2  ·  effective 01-Sep-2026 →                            │
│                                                                              │
│  This version ties with an already-published version on both specificity     │
│  and priority. SmartPRS cannot decide which applies, and will not guess.     │
│                                                                              │
│  ┌────────────────────────────────┬────────────┬──────────┬───────────────┐  │
│  │ Version                        │ Specificity│ Priority │ Value         │  │
│  ├────────────────────────────────┼────────────┼──────────┼───────────────┤  │
│  │ LATE-GRACE-COLL-TG v3          │ 540        │ 20       │ grace 15 min  │  │
│  │   department IN [Collections]  │  +400      │          │               │  │
│  │   state IN [Telangana]         │  +140      │          │               │  │
│  ├────────────────────────────────┼────────────┼──────────┼───────────────┤  │
│  │ LATE-GRACE-GCB-G3 v2  (this)   │ 540        │ 20       │ grace 20 min  │  │
│  │   grade IN [G3]                │  +340      │          │               │  │
│  │   location IN [Gachibowli]     │  +200      │          │               │  │
│  └────────────────────────────────┴────────────┴──────────┴───────────────┘  │
│                                                                              │
│  214 employees are affected, in 1 branch and 2 departments.                  │
│  [ View the 214 employees ]   [ Download list (CSV) ]                        │
│                                                                              │
│  How to resolve — choose one:                                                │
│   1. Give the two versions different priorities.        [ Set priority ]     │
│   2. Narrow one scope so they no longer both match.     [ Edit scope ]       │
│   3. Merge them into one version with a combined scope. [ Merge ]            │
│   4. Add an `except` condition to one of them.          [ Edit condition ]   │
│                                                                              │
│  Nothing has been published. This version remains in Pending Approval.       │
└──────────────────────────────────────────────────────────────────────────────┘
```

**R-VI-022** The ambiguity message names **both** artefacts, both scopes with their
per-dimension weight contributions, both values, the affected employee count, a downloadable
employee list, and at least three concrete remediations. A message that says only
"conflict detected" is not conformant. *(This is the direct replacement for SmartPRS 2's
silent row-order tie-break.)*

### VI.4.6 Scope on a dimension the tenant does not use

**R-VI-023** A dimension a tenant has not populated (no `client_process` nodes, say) is
simply always `ALL` in every scope, contributes 0 to specificity, and is hidden from the
Studio's scope picker until the tenant creates at least one node of that type. Unused
dimensions cost nothing and clutter nothing.

### VI.4.7 Resolution performance

**R-VI-024** Resolution is a single indexed query per `(artefact_type, artefact_key)`
against `config_version_scopes` joined to the employee's assignment set as at `D`, ordered
by `specificity DESC, priority DESC`, limit 2 — two rows, so the tie can be detected
rather than hidden by `LIMIT 1`. Results are memoised per request and cached in Redis
keyed by `(tenant, type, key, employee_scope_hash, date, max_published_version_id)`; the
last element makes the cache self-invalidating on publication.

**R-VI-025** A payroll run resolves configuration **once per distinct scope hash**, not
once per employee. For a 5,000-employee tenant with 30 distinct scope combinations, that
is 30 resolutions per artefact type, not 5,000. *Test:* a run of 5,000 employees issues
fewer than 500 configuration resolution queries in total.

---

## VI.5 Effective dating, worked

### VI.5.1 The rule

**R-VI-030** There is no "current value" read anywhere in SmartPRS 3. Every configuration
read is `resolve(type, key, scope, as_of_date)` and the `as_of_date` is supplied by the
caller from the business context — the payroll period, the attendance date, the leave
date, the transaction date. *Test:* a static-analysis rule fails the build on any call to
a configuration service without an explicit date argument; there is no defaulted-to-today
overload.

This single rule is the fix for the most dangerous property of SmartPRS 2's configuration
model: `PayrollGenController::compute():1094` calls `SettingsController::rates($tid)` with
no date argument at all (`A-payroll.md` Q5).

### VI.5.2 Case 1 — the August payroll after an October policy change

**Facts.** Late Policy `LATE-PENALTY` v1 is published 01-Apr-2026, effective 01-Apr-2026 →
open. On **12-Oct-2026** the customer publishes v2, effective **01-Oct-2026**, changing the
tiered cut from 0.5 day to 0.25 day.

On **20-Oct-2026** the finance team asks for August's run to be regenerated because a loan
was mis-recorded.

| Step | What happens |
|---|---|
| Publication of v2 | v1's `effective_to` closes at **30-Sep-2026**; v1 → `superseded`; v2 published, effective 01-Oct-2026 → open |
| August re-run resolves | `resolve('attendance.rule','LATE-PENALTY', scope, '2026-08-15')` → **v1**, because 2026-08-15 falls in v1's range |
| The re-run's snapshot | Records `rule_version_id = <v1>` among every other version id (spine S11) |
| Reconciliation | The re-run's totals equal the original run's totals for every line not touched by the loan correction. The run's diff names exactly one changed line |
| What the operator sees | The run header shows **"Configuration as at 31-Aug-2026"** and lists the resolved versions. A separate panel notes "3 configuration versions published since the original run; none affect this period." |

**R-VI-031** A re-run whose resolved version set differs from the original run's snapshot
**warns explicitly**, naming each version that changed and the number of payslip lines
affected, and requires acknowledgement before proceeding. A re-run whose version set is
identical proceeds silently. *(SmartPRS 2 re-prices a regenerated draft at today's rates
with no warning and destroys the prior run's payslips — `A-payroll.md` Q7, `J-…` B12.)*

### VI.5.3 Case 2 — a back-dated policy change, and the retro-effect model

**Facts.** On **12-Oct-2026** the customer publishes a change to the OT multiplier,
effective **01-Jul-2026** — three months back-dated. August and September payroll are
already approved and paid.

SmartPRS 3 does **not** decide silently what this means. Every artefact type declares a
`retro_policy`, and every back-dated publication must state which applies.

| `retro_policy` | Meaning | Typical types |
|---|---|---|
| `forbidden` | `effective_from` may not precede today. Publication is rejected | `pay_cycle`, `code_sequence`, entitlement |
| `future_only_by_default` | Back-dating requires an elevated permission and a reason | most tenant policy |
| `arrears_only` | The past is **not** recomputed. The difference is raised as an arrears line in the next open run, computed by the arrears engine from the delta between the old and new resolutions | salary components, OT rates, allowances |
| `full_recompute` | The affected periods are recomputed; superseded payslips are retained; a supplementary or negative-arrears run is created | statutory corrections |
| `declare_only` | The new version governs future evaluations only; historical derived records keep their original version. Nothing is recomputed and no arrears are raised | display-only settings, templates, report definitions |

**R-VI-032** A back-dated publication whose `retro_policy` is `arrears_only` or
`full_recompute` **must** display the retro impact before approval: the list of locked
periods it reaches into, the employee count, the estimated money delta by period and by
component, and whether the delta is positive or negative. Approval of the configuration
change and authorisation of the resulting arrears run are **two separate approvals**.

**Worked.** OT multiplier `1.5 → 2.0`, effective 01-Jul-2026, `retro_policy = arrears_only`:

```
Retro impact preview — OT-MULTIPLIER v4, effective 01-Jul-2026 (back-dated 103 days)

  Periods reached          Jul-2026 (locked, paid)
                           Aug-2026 (locked, paid)
                           Sep-2026 (locked, paid)
                           Oct-2026 (open, draft)

  Employees affected       318 of 1,204  (those with OT minutes > 0 in any period)
  Money delta              Jul  + ₹2,41,600      Aug  + ₹3,08,150
                           Sep  + ₹2,77,400      Oct  computed in-run
                           ─────────────────────────────────────────
                           Arrears to raise      + ₹8,27,150

  Statutory consequence    PF wage increases for 318 employees in 3 periods
                           → PF employee + ₹99,258   PF employer + ₹99,258
                           → ESI applies to 44 of the 318 (gross still below threshold)
                           These are computed by the statutory engine from the
                           resolved rule pack version for EACH period, not today's.

  Locked periods           3.  Publishing does NOT unlock them. Arrears are raised
                           in the next open run as dated arrears lines carrying the
                           period they belong to.

  [ Approve configuration change ]     [ Approve and schedule arrears run ]
```

**R-VI-033** Back-dated arrears lines carry the period they belong to, so that statutory
reporting for the *original* period can be restated where the statute requires it, and so
that the payslip's "How was this calculated?" names both the period the money is paid in
and the period it is earned for. *(SmartPRS 2's arrears path is a private proration model
inside `IncrementController:357-421` that ignores `lop_basis` and forces `tds_rate` to 0 —
`A-payroll.md` Q8 row 15.)*

### VI.5.4 Case 3 — future-dated activation

**Facts.** In January the customer agrees the new financial year's salary structure,
effective 01-Apr-2026.

| Option | Mechanism | When to use |
|---|---|---|
| **Publish now, effective later** | T7 today; `effective_from = 2026-04-01`. The version is published and resolvable — it simply governs no date yet | The default. Preferred, because the validation has already run and nothing can fail later |
| **Schedule activation** | Version sits in `pending_approval` with `activation_at = 2026-03-31T18:00Z`; a scheduled job performs T7, **re-running full publish-time validation** | Only where the content depends on something not yet knowable — e.g. a rule pack expected in March |

**R-VI-034** A future-dated published version is visible everywhere a current one is,
clearly marked "**Takes effect 01-Apr-2026**", and appears on a standing **Scheduled
Changes** board in the Studio (VI.6.2). No configuration change ever arrives without
having been visible in advance. *(SmartPRS 2 has no forward view of any kind.)*

### VI.5.5 Case 4 — how a historical recalculation resolves versions

A recalculation is issued: `recalculate(scope: branch=Hyderabad, range: 2026-06-01 …
2026-08-31)`. Two modes exist and the caller must choose:

| Mode | Resolution | Use |
|---|---|---|
| `as_at_period` (default) | For each date `D` in the range, resolve every rule with `as_of = D`. Rules published later but effective earlier **do** apply — this is the point of a back-dated change | Correcting a genuine policy error; adopting a back-dated statutory pack |
| `as_at_snapshot` | Resolve to exactly the version ids recorded on the existing derived record | Reproducing a historical result byte-for-byte, e.g. for an audit or a dispute |

**R-VI-035** `recalculate` is idempotent and produces a **preview diff** before it writes:
per day, the old status, the new status, the rule versions that differ, and the payroll
consequence. Running it twice with the same inputs produces no second change.
*(SmartPRS 2 has no attendance recalculation command of any kind — `B-attendance.md` §5.2
item 57.)*

**R-VI-036** A recalculation that would change a **settled** period (spine S10 layer 4) is
blocked unless the period is reopened through the audited reopen workflow, and the reopen
is itself a workflow instance with maker-checker.

### VI.5.6 Continuity and gaps

**R-VI-037** An artefact type declared `is_continuous` (pay cycle, financial year, weekly-off
pattern, salary structure assignment, statutory applicability) may not have a gap in its
published timeline for any scope that has ever had a version. Publication that would create
a gap is blocked (V04), with the gap's exact date range in the message. Types not declared
continuous may have gaps; resolution then falls back to the next-less-specific match, and
ultimately to the platform default.

---

## VI.6 The Configuration Studio *(brief Section 100)*

### VI.6.1 What it is, and what it replaces

The Configuration Studio is the single administrative application in which every artefact
in the taxonomy is authored, tested, approved, published, searched, compared and traced.
It is where 80–90% of a customer implementation happens.

It replaces, in SmartPRS 2: the Statutory Rate Settings screen, the Statutory Configuration
screen, 58 generic master screens, the Late Policy wizard, the Salary Setup master, the
Roles & Permissions blob editor, the Greetings/Absence/Probation config screens, the
biometric device setup, the letter-template master and the landing CMS — **eleven
unrelated surfaces with five storage mechanisms and no common lifecycle**
(`H-…` §5.1; `N-…` §5.1).

**R-VI-040** There is exactly one configuration application. A module may deep-link into a
Studio section, but no module ships its own configuration editor with its own save path.
*Test:* a CI sweep asserts every `/api/v3/config/**` write endpoint is served by the
configuration module, and that no module controller writes `config_versions`.

### VI.6.2 Sections

Every section is a view over the same artefact store, filtered by `artefact_type`. The
sections exist for navigation, not for storage.

| # | Section | Artefact types it presents | Primary user |
|---|---|---|---|
| S1 | **Organisation** | `org_unit_type`, org tree, classification dimensions, `establishment`, statutory registrations | consultant |
| S2 | **Calendar & Periods** | `financial_year`, `pay_cycle`, `weekly_off_pattern`, `holiday_calendar`, `attendance_day_rule` | consultant |
| S3 | **People Data Model** | `entity`, `field_definition`, `value_set`, `surface_rule`, `document_type`, `code_sequence` | consultant / HR admin |
| S4 | **Attendance** | `shift`, `shift_group`, `roster_pattern`, attendance `rule` instances (late, early, absence, OT, comp-off, sandwich, regularisation) | HR admin |
| S5 | **Leave & Absence** | `leave_type`, `entitlement_policy`, `accrual_policy`, `carryforward_policy`, `encashment_policy`, leave `rule` instances | HR admin |
| S6 | **Compensation** | `salary_component`, `salary_structure`, `formula`, `rounding_profile`, `proration_basis`, `recovery_order`, `run_policy` | payroll admin |
| S7 | **Statutory** | `statutory_applicability`, `statutory_company_policy`, rule-pack acceptance and scheduling, compliance exceptions | payroll admin + compliance |
| S8 | **Rules** | every `rule` artefact across modules, in one list with a module filter — the cross-module view | consultant |
| S9 | **Workflows** | `workflow` definitions, node configuration, delegation and escalation policy | HR admin |
| S10 | **Templates & Documents** | payslip, letter, ID card, notification and document-layout templates; branding | HR admin |
| S11 | **Reports** | `report_definition`, statutory register bindings, scheduling and distribution | any admin |
| S12 | **Integrations** | `connector_recipe`, device adapter bindings, webhook subscriptions, API scopes, credentials | consultant / IT |
| S13 | **Security** | `role`, `data_scope`, policy bindings, MFA and password policy, session policy | tenant admin |
| S14 | **Notifications** | event subscriptions, audiences, channels, quiet hours, suppression register | HR admin |
| S15 | **Platform & Entitlement** | module enablement (within grant), retention classes, feature states, licence view | tenant admin |
| S16 | **Change Management** | the cross-cutting view: Scheduled Changes board, pending approvals, publication history, diffs, rollback, import/export, packs | tenant admin |

**R-VI-041** Section S16 exists as a first-class destination, not as a tab on each section.
An administrator must be able to answer "what is about to change in my system, and who
approved it?" in one place. *(SmartPRS 2 has no forward view and no configuration history
at all.)*

### VI.6.3 The editing model

Four editing surfaces, chosen per artefact type, all writing the same version row:

| Surface | Used for | Notes |
|---|---|---|
| **Form** | typed payloads with a fixed shape: a shift, a leave type, a pay cycle, a component | Generated from the payload's field metadata, so a new payload field appears in the form without UI work |
| **Visual builder** | rules (condition tree + typed action list), workflows (node graph), report definitions (entity → columns → filters → grouping), templates (block editor with a placeholder picker) | Spine S6 requires the visual rule builder |
| **Expression view** | the SPX source behind any of the above, with autocomplete over the declared symbol table and inline type errors | Spine S6; the two views are two renderings of one AST, and switching is lossless in both directions **only** where the visual builder can express the expression — see below |
| **Table editor** | slab tables, band tables, value sets, holiday lists, mapping tables | Paste from a spreadsheet; validated on paste, not on save |

**R-VI-042** The visual builder and the expression view are two renderings of one stored
AST. Where an expression is too complex for the visual builder to render faithfully, the
builder shows it **read-only** with an explicit "this expression is edited in the
expression view" marker. It never silently drops a clause, and it never re-writes an
expression it did not fully understand.

**R-VI-043** Editing is always on a **draft version**. There is no in-place edit of a
published artefact anywhere in the Studio; the primary action on a published version is
"Create new version from this". *Test:* the Studio's published-version view has no
editable field.

**R-VI-044** Every draft autosaves with optimistic concurrency (`row_version`,
Part XXVIII R-XXVIII-014). A concurrent edit produces a `409` with a field-level diff and
a merge screen, never a last-writer-wins overwrite. *(SmartPRS 2's `app_state` blob is a
full-document last-writer-wins save that silently discards other users' work.)*

**R-VI-045** Every numeric field in every payload declares `min`, `max`, `step` and a unit,
as part of the payload's field metadata, and the Studio enforces them client-side and the
API enforces them server-side. *(SmartPRS 2 accepts `pf_rate = 9999` —
`CONSOLIDATED-REQUIREMENTS` H-51.)*

**R-VI-046** Every configuration field carries help text authored as content (not as a PHP
array), and every artefact carries a mandatory `business_description` in plain language.
The description is what appears in the audit trail, the impact preview and the
explainability trace — so an auditor reads "3 late arrivals over 10 minutes in a payroll
month cost half a day", not `LATE-3X-HALFDAY v4`.

### VI.6.4 Search across configuration

**R-VI-047** One search box searches **every** artefact of every type in the tenant, over:
artefact key and name, business description, payload text, SPX source, scope dimension
values, value-set codes and labels, template placeholders, and change notes. Results are
grouped by type, show status and effective range, and are permission-filtered.

**R-VI-048** Search supports these operators, because they are the questions implementers
actually ask:

| Query | Answers |
|---|---|
| `value:15000` | every artefact whose payload contains 15000 — the "where is the PF ceiling used?" question |
| `uses:BASIC` | every formula, rule, structure and template referencing component code `BASIC` |
| `scope:branch=Hyderabad` | every artefact scoped to that branch |
| `field:linkedin_url` | every surface rule, template, report and import map touching that field |
| `status:pending_approval` | the approval queue |
| `effective:2026-04-01` | everything that changes on that date |
| `changed_by:@ravi since:30d` | audit-style search over authorship |
| `broken:true` | artefacts currently failing validation (V05 dangling references after a delete, say) |

### VI.6.5 "Where is this used?" — the dependency view

**R-VI-049** The platform maintains a **configuration dependency graph**, written at
validation time (T3/T5) from the parsed AST and the typed payload references, stored in
`config_dependencies`, with edge types: `references_component`, `references_field`,
`references_value_set`, `references_formula`, `references_rule`, `references_workflow`,
`references_template`, `references_report`, `references_statutory_code`,
`references_leave_type`, `references_shift`, `references_org_unit`.

Every artefact page shows two panels:

```
USES (this artefact depends on)                 USED BY (depends on this artefact)
  ├ formula  HRA           v3                     ├ structure  Telangana Collections v7
  ├ component BASIC        v5                     ├ structure  Pune Corporate        v2
  ├ value_set attendance_status v2                ├ rule       MIN-WAGE-CHECK        v1
  └ statutory PF.WAGE_CEILING (pack IN-2026.4)    ├ template   Payslip — Standard    v9
                                                  └ report     Wage Register         v3
```

**R-VI-050** The dependency view is what makes deletion safe. An attempt to archive or
retire an artefact that is still referenced by a published version is blocked (V05), and
the block message *is* the "used by" list. *(In SmartPRS 2, deleting a department leaves
`employees.department_id` dangling and `employees.department` untouched, and the product
ships an admin endpoint whose job is sweeping up orphans —
`CONSOLIDATED-REQUIREMENTS` H-8; spine S16.)*

**R-VI-051** The dependency view supports **impact traversal to employees**: from any
artefact, "who does this affect?" resolves the scope against the current workforce and
returns a count and a downloadable list, with a date selector so the question can be asked
as at a past or future date.

### VI.6.6 Studio permissions

**R-VI-052** Studio access is governed by spine S13's orthogonal model: a permission
(`config.author`, `config.publish`, `rules.publish`, `formula.publish`, …) **and** a data
scope. A branch-scoped HR administrator may author artefacts whose scope is within their
own branch subtree and may not author a tenant-default artefact. *Test:* an authorisation
test asserts that a branch-scoped author cannot publish a version whose scope is broader
than their scope, and that the attempt is denied server-side with the scope named.

---

## VI.7 The Configuration Wizard *(brief Section 101)*

### VI.7.1 What it is

The Wizard is a guided, resumable, 18-step journey that takes a new tenant from an empty
database to a tenant that can run its first parallel payroll. It is **not** a separate
configuration mechanism — every step writes ordinary artefact versions through the ordinary
lifecycle. It is a sequencing and completeness device.

**R-VI-060** The Wizard is resumable, re-enterable and non-destructive: a completed step
may be revisited at any time, and revisiting it creates a new draft version rather than
editing a published one. Progress is per-tenant state, not per-user.

**R-VI-061** Each step declares its **prerequisites** (steps that must be complete),
its **outputs** (the artefact types it produces) and its **exit gate** (the validations
that must pass before the next step unlocks). A step may be *skipped* only if it is
declared optional; skipping is recorded with a reason and appears on the go-live checklist.

**R-VI-062** A Policy Pack (VI.12) may pre-populate any subset of steps. The Wizard then
shows those steps as "**pre-filled from pack ‘BPO Standard’ v3 — review and confirm**",
and the consultant's job becomes verification rather than authoring. This is the mechanism
by which an implementation drops from weeks to days.

### VI.7.2 The eighteen steps

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** The step list, its ordering and its gates are
a design proposal; the brief names the count, not the content.

| # | Step | Produces | Exit gate (must pass to continue) |
|---|---|---|---|
| 1 | **Tenant & legal entities** | tenant profile, `legal_entity` nodes, registered addresses, PAN/TAN/GSTIN | At least one legal entity; PAN/TAN format valid; a named tenant administrator exists with MFA enrolled |
| 2 | **Companies & establishments** | `company` and `establishment` nodes; per-establishment statutory registrations (PF code, ESI code, PT registration, S&E registration, LWF registration) with their own effective dates | Every company belongs to a legal entity; every establishment has a state; each declared registration has a number and an effective-from. **Unregistered ≠ exempt**: the absence of a registration must be an explicit "not applicable" with a reason |
| 3 | **Organisation tree** | `state`, `zone`, `branch`, `department`, `sub_department`, `team` nodes; `cost_centre`, `business_unit`, `client_process` typed nodes | Tree is acyclic and single-rooted per tenant; every branch has a state; no node name duplicated within a parent |
| 4 | **Classification dimensions** | `designation`, `grade`, `employee_category`, `employment_type`, `employment_stage`, `pay_group`, `location` value sets | Each dimension has ≥1 active value; `employment_stage` includes at least one terminal stage; codes are unique and stable (a code is never reused after retirement) |
| 5 | **Calendar & financial year** | `financial_year` definition, `holiday_calendar` per scope | FY is continuous with no gap or overlap; holiday calendars cover the whole FY; every branch resolves to exactly one calendar |
| 6 | **Weekly-off patterns** | `weekly_off_pattern` versions, including alternate-Saturday rules | Every employee-resolvable scope resolves to exactly one pattern (no ambiguity, V03); pattern is continuous (V04) |
| 7 | **Pay cycles** | `pay_cycle` versions: period start/end rule, cut-off day, pay day, arrears cut-off, lock policy | Continuous with no gap (V04); cut-off day valid for every month including February (`CLAMP_DAY_TO_MONTH`); every company resolves to exactly one cycle |
| 8 | **Shifts & rosters** | `shift`, `shift_group`, `roster_pattern`, `attendance_day_rule` | Every shift has start, end, break policy and a night flag derived from a rule not from `end <= start`; the attendance-day rule is declared explicitly; every employee-resolvable scope resolves to a default shift |
| 9 | **Attendance sources** | `connector_recipe` / device adapter bindings, identity mapping strategy, timezone policy per device | At least one source configured or an explicit "manual only"; a successful `test()` against each source; identity strategy resolves ≥95% of a sample punch batch, and the unresolved remainder is quarantined not dropped |
| 10 | **Attendance rules** | late, early-exit, absence, half-day, OT, comp-off, sandwich, regularisation `rule` artefacts | No equal-specificity/equal-priority pair (V03); every rule has ≥1 stored test case and all pass (V15); the simulator has been run at least once for the step to be marked complete |
| 11 | **Leave** | `leave_type`s with entitlement, accrual, carry-forward, encashment, eligibility, blackout and clubbing rules | Every leave type has a unit (day/half-day/hour) and a paid flag; accrual never exceeds the declared cap (V09); leave year basis declared; opening balances staged for import |
| 12 | **People data model** | `field_definition`s, `value_set`s, `surface_rule`s, `document_type`s, `code_sequence`s | Every mandatory-on-create field is present on the create surface for at least one role; no field is required on a surface where it is hidden (V12); employee-code sequence is allocatable and its uniqueness scope declared |
| 13 | **Salary components** | `salary_component` versions with statutory-basis flags (PF wage, ESI wage, PT gross, bonus wage, gratuity wage, OT wage, minimum-wage comparison, taxable base) and rounding profile | Every component declares **every** statutory basis flag explicitly — no defaults, because a defaulted flag is a silent statutory decision; component codes unique and stable |
| 14 | **Salary structures & formulas** | `formula`s (SPX), `salary_structure`s and their lines, `proration_basis`, `recovery_order` | Formula dependency graph acyclic (V06); type-check passes; every structure evaluated against ≥3 named sample employees with expected values asserted (V15); balance/residue allocation sums exactly to the whole |
| 15 | **Statutory** | `statutory_applicability` per establishment and per employee class; `statutory_company_policy` (enhanced policy above the floor); rule-pack binding and acceptance policy | A rule pack is bound for every jurisdiction the tenant operates in; every company policy passes the floor check (V19) or carries a recorded compliance exception; probation/internship suppression declared per head rather than assumed |
| 16 | **Security** | `role`s, permission bundles, `data_scope` assignments, MFA/password/session policy, approver hierarchy source | Every role has ≥1 permission; every user has ≥1 role and exactly one data scope per role; MFA mandatory for every role holding `payroll.approve.*`, `config.publish` or `security.*`; no default credential exists |
| 17 | **Workflows & notifications** | `workflow` definitions for leave, requests, payroll approval, configuration approval and maker-checker; notification event subscriptions, audiences, channels, templates | Every workflow reachable and terminating (V07); every approval node resolves to a non-empty approver set for every in-scope employee (V08); every notification event has an audience and a template with no orphaned placeholder (V13) |
| 18 | **Go-live** | employee import, opening balances (leave, loan, YTD statutory, comp-off), parallel-run comparison, sign-off, entitlement activation | Import completed with zero unresolved rows; **parallel run** for at least one period reconciles against the customer's incumbent output within a declared tolerance, with every variance explained; sign-off recorded as a workflow instance; entitlement activated |

**R-VI-063** Step 18's parallel run is not optional for a payroll-bearing tenant. The
go-live gate requires a **reconciliation report** naming every employee whose computed net
differs from the incumbent system, with the explanation trace for each. *(SmartPRS 2's
first-page-load fabrication of twelve months of payroll history —
`AppDataController:2340-2380`, `A-payroll.md` Q8 row 10 — is the anti-pattern this
replaces: SmartPRS 3 never invents historical payroll under any circumstance.)*

**R-VI-064** The Wizard produces, at completion, a **configuration baseline export**
(VI.11) tagged `go-live`, stored immutably. Every subsequent change is diffable against it.

### VI.7.3 What the Wizard validates that the Studio alone cannot

The Studio validates artefacts. The Wizard validates **coverage** — the questions that are
only answerable across artefacts:

| Coverage check | Question |
|---|---|
| Resolution completeness | Does every active employee resolve to exactly one shift, one weekly-off pattern, one pay cycle, one holiday calendar, one salary structure and one statutory applicability, on the go-live date? |
| Approver reachability | Does every employee have a resolvable approver at every level every workflow requires? |
| Statutory coverage | Is every establishment's state covered by a bound rule pack? |
| Component-basis completeness | Does every component in every structure declare every statutory basis flag? |
| Surface completeness | Is every field that is mandatory-on-create present on the create surface, the import template and the API schema? |
| Template completeness | Does every issued document type have an active template, and does every template's placeholder set resolve? |

**R-VI-065** The coverage report is re-runnable at any time after go-live, and is run
automatically before every first payroll of a period. A coverage failure is a payroll
preview blocker (spine S11).

---

## VI.8 The Sandbox and the Simulator *(brief Section 102)*

### VI.8.1 Why this exists

SmartPRS 2 has no way to try a configuration change. The only way to discover what a late
policy does is to run payroll. This is the reason customers fear configuration changes, and
the reason implementers make them at 2 a.m.

**R-VI-070** Every engine that produces a number — attendance, leave, payroll, rules,
statutory — exposes a **pure evaluation entry point** that takes a subject, a date or
period, and an explicit configuration version set, and returns the result plus its trace,
**without writing anything**. The simulator is a thin caller of these entry points, not a
second implementation. *Test:* a simulation and a real run over identical inputs produce
byte-identical traces. *(SmartPRS 2 has a `simulate()` that is a differently-shaped
re-implementation of the payroll assembly — `A-payroll.md` Q8 row 11. Not replicated.)*

### VI.8.2 The Sandbox

| Property | Specification |
|---|---|
| What it is | A named, isolated **configuration overlay** on the live tenant: a set of draft/testing versions that resolve *only* inside the sandbox |
| Data | Reads live employee, attendance and leave data **read-only**, or a synthetic dataset, or a pinned snapshot of a past period |
| Writes | None to business data, ever. The sandbox writes only its own versions and its simulation results |
| Promotion | A sandbox is promoted by submitting its versions as a **change set** (VI.17) through the normal lifecycle — the same approvals, the same validations |
| Lifetime | Declared at creation; expires and is archived. A stale sandbox against months-old configuration is misleading, so it expires rather than rots |
| Isolation | Sandbox resolution is `resolve(..., sandbox_id)`, which prefers sandbox versions and falls through to published ones. Production resolution never sees a sandbox |

**R-VI-071** A sandbox never contains a copy of the tenant's data. It contains a
configuration overlay. This is what makes it cheap enough to create one per change.

### VI.8.3 The four simulators

All four share one input form shape and one output shape.

**Input form (common):**

```
Subject          ○ a real employee (search)    ○ a synthetic employee (attribute builder)
                 ○ a cohort (scope expression, capped at N employees)
Date / period    a date, a date range, or a payroll period
Configuration    ○ published, as at the chosen date        (what happens today)
                 ○ sandbox <name>                          (what would happen if I publish)
                 ○ pinned version set (from an existing derived record)  (reproduce)
Overrides        optional per-run input overrides: punches, leave, OT minutes, variable pay
Comparison       optional: run twice and diff (A/B)
```

| Simulator | Subject | Inputs it accepts | Output |
|---|---|---|---|
| **Attendance** | employee or cohort, date range | punches (real, typed, or generated), roster, corrections, leave | Per-day: resolved shift, first-in/last-out, worked/late/early/break minutes, status, penalties, OT minutes, comp-off earned — each with the rule and version that produced it |
| **Leave** | employee, leave request or date range | leave type, dates, half-day flags, current balance | Units deducted, which days were counted and why, sandwich/clubbing effect, balance before/after, accrual and carry-forward projection to year end, eligibility outcome with the failing condition named |
| **Payroll** | employee or cohort, one period | attendance settlement, leave settlement, variable inputs, loans, arrears | The full payslip with every line, plus employer contributions, CTC reconciliation, and the complete trace per line: component version, formula version, substituted values, rounding mode, statutory pack version |
| **Rule** | employee or synthetic subject, date | the rule set for a module, or one rule | For each candidate rule: matched or not, why (the condition tree with each leaf's evaluated value), specificity and priority, whether it won, and the actions it produced |

### VI.8.4 The output trace

**R-VI-072** Every simulator output is the **same trace object** the live engines emit
(spine S15), rendered with three levels of detail: *summary* (the number), *explanation*
(the human sentence), *derivation* (every input, every intermediate, every version).

Worked example — the attendance simulator, one day:

```
Employee   HYD-0412  Ravi Kumar          Date  14-Aug-2026 (Friday)
Config     published, as at 14-Aug-2026   Sandbox: none

RESOLVED CONTEXT
  Shift              Night 22:00–06:00      from roster_pattern NIGHT-4x2 v2
                                            (scope: team=Night-Team-A, spec 500)
  Attendance day     22:00 13-Aug → 06:00 15-Aug   from attendance_day_rule SHIFT-ANCHORED v1
  Weekly off         no                      weekly_off_pattern SUN+ALT-SAT v4
  Holiday            no                      holiday_calendar TG-2026 v1
  Late policy        LATE-PENALTY v1         spec 580 (department 400 + branch 180)
                                             beat LATE-DESIG v2 (spec 530) — see Why?

PUNCHES (layer 2, normalised)
  22:47:12  IN   device HYD-GATE-01  fingerprint   ← 1 punch collapsed (double-tap 8 s)
  06:02:41  OUT  device HYD-GATE-01  fingerprint

DERIVATION
  late_minutes   = 22:47:12 − 22:00:00 (shift start instant, 13-Aug) = 47 min
  grace          = 15 min                          LATE-GRACE-COLL-TG v3 (spec 540, prio 20)
  late_over_grace= 47 − 15 = 32 min
  occurrence     = agg.occurrence_index('late_arrival', window=payroll_period,
                     predicate: late_minutes > 10) = 3
                     ├ 04-Aug  late 21 min
                     ├ 09-Aug  late 14 min
                     └ 14-Aug  late 47 min   ← this one
  worked_minutes = 06:02:41 − 22:47:12 − break 30 = 445 min
  full_day_hours = 8.0 h (480 min)                 shift Night v3

RULES EVALUATED
  ✓ LATE-3X-HALFDAY v1   when: late_minutes > 10 AND occurrence_index = 3   → MATCHED
       then: SET_STATUS('HALF_DAY') · APPLY_DAY_PENALTY(0.5)
  ✗ SHORT-HOURS v2       when: worked_minutes < 240                          → not matched
                                (445 < 240 is false)
  ✗ ABSENT v1            when: punch_count = 0                               → not matched

OUTCOME
  Status             HALF_DAY
  Payroll impact     0.5 payable day       (LOP 0.5 day, basis: working days, PAY-BASIS v2)
  OT minutes         0                      (OT-ELIGIBILITY v3 → eligible=false for Night shift)
  Comp-off earned    0

VERSIONS USED (7)
  roster_pattern NIGHT-4x2 v2 · attendance_day_rule SHIFT-ANCHORED v1 ·
  weekly_off_pattern SUN+ALT-SAT v4 · holiday_calendar TG-2026 v1 ·
  rule LATE-PENALTY v1 · rule LATE-GRACE-COLL-TG v3 · rule LATE-3X-HALFDAY v1
```

**R-VI-073** The trace names the rule that **did not** match as well as the one that did,
with the evaluated value of each condition. "Why did this employee not get overtime?" is
the single most common HR support question, and it must be answerable without a developer.

### VI.8.5 A/B comparison

**R-VI-074** Any simulation may be run twice — published versus sandbox — and the output
rendered as a diff: which days changed status, which payslip lines changed and by how much,
the net money delta, and the count of employees affected when run over a cohort. This is
the same computation the impact preview (VI.10) uses; the simulator is its interactive form.

### VI.8.6 Pinning a simulation as a regression test

This is the mechanism that stops configuration from rotting.

**R-VI-075** Any simulation result may be **pinned** as a regression case with one action.
Pinning stores:

```
config_test_cases
  artefact_id (the artefact under test)          -- or NULL for a cross-artefact scenario
  name, business_description
  subject_kind      real_employee | synthetic | cohort
  subject_payload   the frozen employee attribute set (synthetic), or the employee uuid
                    + the frozen assignment set as at the test date (real)
  as_of_date / period
  input_payload     frozen punches, leave, variable inputs, overrides
  expected_payload  the asserted outcome — statuses, minutes, units, money lines
  tolerance         exact for money and days; declared tolerance only where genuinely
                    non-deterministic (never for money)
  created_by, created_at, source_simulation_id
```

**R-VI-076** Pinned cases run:
1. **On publish** of the artefact under test, or of any artefact it depends on (via the
   dependency graph). A failure **blocks publication** (V15) and shows expected versus
   actual side by side.
2. **Nightly**, across the whole tenant, as a scheduled job, so drift caused by an
   unrelated change is caught within a day and reported on the operations dashboard.
3. **In CI** for Ametecs-authored Policy Packs, so a pack ships with proven behaviour.

**R-VI-077** A pinned case that references a real employee freezes that employee's
attribute set at pin time, so the test does not silently change meaning when the employee
transfers or leaves. A case whose subject employee has exited continues to run against the
frozen attributes and is flagged for review, never silently skipped.

**R-VI-078** Every Policy Pack ships with its own pinned cases, and applying a pack applies
its cases. A customer who imports "BPO Standard" imports both the configuration and the
proof that it behaves as documented.

---

## VI.9 Configuration validation *(brief Section 103)*

### VI.9.1 The model

**R-VI-080** Validation is a **catalogue of named, numbered checks**, each declaring: when
it runs, what it inspects, its severity, what it blocks, and the exact message shape shown
to the administrator. Validation is not scattered assertions inside save handlers; it is a
registry the Studio, the API, the import path, the pack applier and the scheduled
integrity job all execute identically.

**Run points:**

| Code | Run point | Trigger |
|---|---|---|
| **RP1** | *edit* | keystroke/blur in the Studio; advisory only, never blocking |
| **RP2** | *promote to testing* (T3) | full static validation of this version alone |
| **RP3** | *submit* (T5) | RP2 plus cross-artefact and regression checks |
| **RP4** | *publish* (T7) | RP3 re-run against the store as it is at this instant, plus the checks that can only be answered against published state |
| **RP5** | *import / pack apply* | RP4 for every incoming version, before anything is written |
| **RP6** | *nightly integrity sweep* | RP4 over all published artefacts; findings appear on the operations dashboard and in Studio search as `broken:true` |

**Severities:**

| Severity | Effect |
|---|---|
| `error` | Blocks the transition. No override exists |
| `blocking_override` | Blocks, but a named permission plus a recorded reason may override; the override is audited and appears on a standing exceptions report |
| `warning` | Does not block; must be acknowledged with a click before submit, and the acknowledgement is recorded on the version |
| `info` | Displayed; no acknowledgement required |

### VI.9.2 The catalogue

Each entry: **when it runs · what it blocks · what the administrator sees.**

---

**V01 — Type and symbol validation (SPX)**
*Runs:* RP1 (advisory), RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* every SPX expression in the payload — rule `when`/`except`, formula body,
template condition, report filter, workflow branch condition, `default_expr`,
`validation_expr`, `required` conditional.
*Blocks:* promotion to testing and everything after.
*Message:*
> **Expression error in `when` — line 2, column 18.** `Money * Money` is not a valid
> operation. `BASIC` is Money and `HRA_RATE` is Money; a percentage must be typed as
> Number, or written as `BASIC % 40`. Symbols available here: `employee.*`, `org.*`,
> `salary.component(code)`, `period.*`. `attendance.*` is **not** available in a payroll
> formula context.

---

**V02 — Overlapping versions**
*Runs:* RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* for the same artefact and an **identical** scope, whether the proposed
effective range overlaps an existing published version's range.
*Blocks:* publication.
*Message:*
> **Cannot publish — overlapping effective range.** `LATE-PENALTY v4` would be effective
> 01-Jul-2026 → 31-Dec-2026, but `LATE-PENALTY v3` is already effective 01-Apr-2026 →
> open for the same scope (*department IN [Collections], branch IN [Hyderabad]*).
> Publishing a version normally closes its predecessor automatically; that only happens
> when the new version starts **after** the old one. Choose an `effective_from` on or
> after 01-Jul-2026 with the predecessor open-ended, or close v3 explicitly first.
> `[ Set v3 to end 30-Jun-2026 and publish ]  [ Change this version's dates ]`

---

**V03 — Equal-specificity, equal-priority ambiguity**
*Runs:* RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* every pair of published versions of the same artefact type and key that can
match at least one real employee on at least one date in the overlapping effective range,
with equal specificity **and** equal priority. Implemented as: materialise both scopes
against the current workforce; if the intersection is non-empty, block.
*Blocks:* publication.
*Message:* the full panel shown in VI.4.5 — both artefacts, both scopes with per-dimension
weights, both values, affected employee count, downloadable list, four remediations.
*Note:* the check also runs against **conditions**, not just scopes: two rules of equal
specificity and priority whose `when` clauses can both be true simultaneously are
ambiguous even if their scopes differ in no dimension (spine S6 conflict detector). Where
satisfiability cannot be decided statically, the check falls back to evaluating both
conditions against the workforce sample and reports an ambiguity only on a demonstrated
overlap — a false negative is preferable to blocking on a theoretical one.

---

**V04 — Gap in a continuous artefact**
*Runs:* RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* artefact types declared `is_continuous`; whether the published timeline for a
scope that has ever had a version contains an uncovered date.
*Blocks:* publication (and, at RP6, raises an operations alert).
*Message:*
> **Cannot publish — this would leave a gap.** `PAY-CYCLE-MONTHLY` would have no version
> covering **01-Apr-2026 to 30-Apr-2026** for scope *company IN [Ametecs Collections]*.
> Pay cycles must be continuous: payroll for a date with no cycle cannot resolve a period.
> `[ Extend v6 to 30-Apr-2026 ]  [ Start this version on 01-Apr-2026 ]`

---

**V05 — Dangling reference**
*Runs:* RP2, RP3, RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* every edge in the dependency graph — a rule referencing a deleted or retired
field, a structure referencing a disabled component, a formula referencing a removed
component code, a template referencing a removed placeholder, a workflow referencing a
deleted role, a report referencing a dropped column, a rule referencing a retired
value-set code.
*Blocks:* promotion and publication. Also blocks the **retirement** of the referenced
artefact (this is the same check run in the other direction — VI.6.5).
*Message (forward):*
> **Cannot publish — `LATE-3X-HALFDAY v2` references a field that no longer exists.**
> `employee.shift_allowance_band` was retired on 12-Mar-2026 by Priya Nair
> (change note: "replaced by grade"). Update the condition, or restore the field.
> Referenced at: `when`, line 3.
*Message (reverse — retiring something in use):*
> **Cannot retire component `CONV` — 4 published artefacts use it.**
> `structure Telangana Collections v7` · `structure Pune Corporate v2` ·
> `formula NET-TAXABLE v5` · `template Payslip — Standard v9`.
> Retire or re-version each of these first. `[ Show me ]`

---

**V06 — Cyclic formula dependency**
*Runs:* RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* the formula dependency graph built at validation time (spine S7). Detects
cycles of any length, including self-reference.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — circular formula dependency.** The cycle is:
> `HRA → BASIC → GROSS → HRA`.
> `HRA v4` reads `salary.component('BASIC')`; `BASIC v3` reads `salary.component('GROSS') % 50`;
> `GROSS v2` sums all earnings, which includes `HRA`.
> A component cannot depend on a total that includes itself. Give `BASIC` a fixed value or
> a percentage of CTC, or exclude `HRA` from the `GROSS` used by `BASIC`.
> `[ View dependency graph ]`

---

**V07 — Invalid workflow: unreachable node or non-terminating path**
*Runs:* RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* the workflow graph — every node reachable from `submit`; every path reaching a
terminal node (`finalise`, `reject`, `cancel`); no cycle without an escape edge; every
`condition_branch` exhaustive (an `else` exists); every `parallel_all`/`quorum` joinable.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — workflow `LEAVE-APPROVAL v3` has 2 problems.**
> 1. Node **“Finance review”** is unreachable. No transition leads to it. It was reachable
>    from “HR approval” until the branch condition was changed in this version.
> 2. Path `Submit → Manager approval → (condition: amount > 50000, false branch)` does not
>    reach a terminal node. Every path must end in Finalise, Reject or Cancel.
> `[ Show graph with problems highlighted ]`

---

**V08 — Invalid workflow: missing or unresolvable approver**
*Runs:* RP3, RP4, RP5, RP6. *Severity:* `error` at RP4; `warning` at RP6 for employees who
joined after publication.
*Inspects:* for every approval node, resolve the approver specification (role, named user,
dynamic manager at level N, group) against **every employee in the workflow's scope**.
Reports every employee for whom the set is empty, and every node whose only approver is the
subject themselves where self-approval is prohibited.
*Blocks:* publication.
*Message:*
> **Cannot publish — 23 employees have no approver at node “Manager approval”.**
> The node resolves *dynamic manager at level 1*. These 23 employees have no reporting
> manager assigned as at 01-Sep-2026: `[ list ]` `[ download CSV ]`
> Also: 4 employees would be their own approver at this node, and self-approval is
> prohibited on this workflow: `[ list ]`
> Fix the reporting hierarchy, add a fallback approver to the node, or narrow the
> workflow's scope. `[ Set fallback approver ]`
*Why this is an `error` and not a `warning`:* SmartPRS 2 resolves an unresolvable approver
to "a label with a NULL id" and the request sits in nobody's queue for ever
(`N-…` §5.2 item 30).

---

**V09 — Accrual exceeding cap**
*Runs:* RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* leave accrual policy against its own caps — per-period accrual × periods per
year versus the annual cap; annual cap versus the maximum balance cap; carry-forward cap
versus maximum balance; encashment cap versus balance cap. Also checks that a cap exists
where the policy declares one is required.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — accrual exceeds the cap it is subject to.** `EARNED-LEAVE v3` accrues
> **1.75 days per month** = 21.00 days per year, but the annual accrual cap is
> **18.00 days**. Three months of every year would accrue nothing, which is almost certainly
> not intended.
> Either raise the annual cap to 21.00, lower the monthly accrual to 1.50, or set
> `cap_behaviour = stop_at_cap` and confirm that accrual is meant to stop in October.
> `[ Simulate a full year for a sample employee ]`

---

**V10 — Invalid slab or band table**
*Runs:* RP2, RP3, RP4, RP5 (and RP4 for rule packs before signature verification is
accepted). *Severity:* `error`.
*Inspects:* every slab table (`SLAB`), band table (`BANDS`) and step table (`STEP`) for:
non-ascending `from` values; overlapping bands; a gap between bands; a missing open-ended
top band; a `from` greater than its `to`; a negative bound where the measure cannot be
negative; a duplicated band; and a result type inconsistent across bands.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — slab table `PT-TG` has 3 problems.**
>
> | Row | From | To | Amount | Problem |
> |---|---|---|---|---|
> | 2 | ₹15,001 | ₹20,000 | ₹150 | — |
> | 3 | ₹18,000 | ₹25,000 | ₹200 | **Overlaps row 2** (₹18,000–₹20,000 is in both) |
> | 4 | ₹30,001 | ₹40,000 | ₹200 | **Gap** — nothing covers ₹25,001–₹30,000 |
> | 5 | ₹40,001 | ₹50,000 | ₹200 | **No open band** — a gross above ₹50,000 resolves to nothing |
>
> A slab table must be ascending, contiguous, non-overlapping, and must end in an
> open-ended band. `[ Fix automatically ]` (offers: close the gap, trim the overlap, open
> the last band — each shown as a diff before applying).

---

**V11 — Structure references a disabled or retired component**
*Runs:* RP3, RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* every salary structure line's component reference against the component's
lifecycle state and effective range as at the structure's effective range; also whether the
component is enabled for the structure's scope.
*Blocks:* publication.
*Message:*
> **Cannot publish — structure `Telangana Collections v8` uses a component that is not
> active for its whole effective range.** Component `CONV` (Conveyance Allowance) was
> retired with effect from **31-Mar-2026**; this structure is effective **01-Apr-2026 →**.
> Remove the line, or publish a new version of `CONV` extending its range.
> Note: retiring `CONV` does **not** affect payslips already produced from it; those
> resolve `CONV v4` as at their own period.

---

**V12 — Field surface contradiction**
*Runs:* RP2, RP3, RP4, RP5. *Severity:* `error` for the hidden-and-required case;
`warning` for the others.
*Inspects:* `field_surface_rules` for contradictions: a field `required: always` on a
surface where its visibility is `hidden`; a field required on create but absent from the
bulk-import template; a field marked unique whose uniqueness scope cannot be materialised
as an index (Part XXVIII R-XXVIII-012); a field with `pii_class = sensitive` exposed
unmasked on a surface reachable by a role without the corresponding permission.
*Blocks:* promotion and publication (for the `error` cases).
*Message:*
> **Cannot publish — `pcc_certificate_no` is required but hidden.** On surface
> **Self-onboarding**, role **Candidate**, the field is `required: always` and
> `visibility: hidden`. The candidate would be unable to submit and unable to see why.
> Set visibility to `write`, or make the requirement conditional, or remove the requirement
> on this surface.

---

**V13 — Orphaned template placeholder**
*Runs:* RP2, RP3, RP4, RP5, RP6. *Severity:* `error` for an unresolvable placeholder;
`warning` for a placeholder that resolves but is empty for more than a declared percentage
of the audience.
*Inspects:* every `{{placeholder}}` in every template (letter, payslip, notification, ID
card, document layout, report header) against the merge dictionary derived from the entity
model, and every declared placeholder against actual use.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — template `Offer Letter v6` has 2 unresolvable placeholders.**
> `{{employee.probation_period}}` — no such field. Did you mean
> `{{employee.probation_months}}`? `[ Replace ]`
> `{{company.grievance_officer_phone}}` — this field exists on **Company**, but this
> template's context is **Candidate**; the company must be reached as
> `{{offer.company.grievance_officer_phone}}`. `[ Replace ]`
> Also, as a warning: `{{employee.middle_name}}` is empty for 812 of 1,204 employees.
> `[ Acknowledge ]`
*Why this is an `error`:* SmartPRS 2 prints unknown placeholders verbatim into issued
letters (`CONSOLIDATED-REQUIREMENTS` H-26), so a customer's offer letter can go out
reading `{{employee.probation_period}}`.

---

**V14 — Bounds, units and rounding declaration**
*Runs:* RP1, RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* every numeric payload value against its declared `min`/`max`/`step`; every
`ROUND` call for an explicit mode (spine S5.4 — there is no default rounding); every money
value for a declared currency; every duration for a declared unit; every component for a
declared rounding profile.
*Blocks:* everything from RP2 onward.
*Message:*
> **Value out of range.** `pf_employee_rate` = **99.00**. Permitted range for this field is
> 0.00 – 25.00 (declared by the platform; the statutory value comes from rule pack
> `IN-EPF` and is `<from rule pack>`). If your establishment genuinely contributes above
> 25%, this is a company enhanced policy and belongs in Statutory → Company Policy, where
> it is checked against the statutory floor rather than replacing it.

---

**V15 — Regression case failure**
*Runs:* RP3, RP4, RP5, and nightly. *Severity:* `error` at RP4.
*Inspects:* every pinned test case attached to this artefact, and — via the dependency
graph — every pinned case attached to any artefact that depends on it.
*Blocks:* publication.
*Message:*
> **Cannot publish — 2 of 47 regression cases fail.**
>
> | Case | Expected | Actual | Δ |
> |---|---|---|---|
> | *Night-shift agent, 3 lates, Aug* | HALF_DAY, 0.5 payable day | ABSENT, 0.0 payable day | −0.5 day |
> | *Pune manager, no lates, Aug* | net ₹58,420.00 | net ₹57,980.00 | −₹440.00 |
>
> The second case belongs to `structure Pune Corporate v2`, which depends on the formula
> you are publishing. `[ Open in simulator ]  [ Accept new expected values ]` (accepting
> requires the same approval as the publication and is recorded on both artefacts.)

---

**V16 — Back-dating and finalised-period guard**
*Runs:* RP4, RP5. *Severity:* `blocking_override` (permission `config.publish.locked_period`).
*Inspects:* whether `effective_from` precedes today (retro policy, VI.5.3) and whether it
falls inside a locked payroll period or a settled attendance period.
*Blocks:* publication unless overridden with a reason.
*Message:*
> **This change is back-dated into 3 locked periods.** Jul-2026, Aug-2026 and Sep-2026 are
> locked and paid. Its retro policy is **arrears only**: the past will not be recomputed;
> the difference will be raised as dated arrears in the next open run.
> Estimated arrears: **+₹8,27,150** across **318 employees**. Statutory consequence:
> PF +₹99,258 employee and employer.
> This requires the *Publish into locked period* permission and a recorded reason.
> `[ Enter reason and continue ]  [ Change the effective date ]  [ View full impact ]`

---

**V17 — Scope validity**
*Runs:* RP2, RP3, RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* every scope dimension value against the live dimension catalogue (a branch that
was deleted, a designation that was renamed to a new code, a value not in the value set);
whether the author's own data scope permits the scope being authored (VI.6.6); whether the
scope matches at least one employee (that one is a `warning`, not an error — a scope for a
branch opening next month is legitimate).
*Blocks:* promotion and publication.
*Message:*
> **Scope refers to a branch that no longer exists.** `branch IN [Secunderabad]` — this
> branch was merged into Hyderabad on 01-Jun-2026. Replace it, or, if this version is
> meant to govern dates before the merge, set `effective_to` to 31-May-2026.

---

**V18 — Entitlement consistency**
*Runs:* RP4, RP5. *Severity:* `error`.
*Inspects:* whether the artefact's module is entitled for this tenant, and whether it
references a capability from a module that is not entitled (VI.14).
*Blocks:* publication.
*Message:*
> **Cannot publish — this rule uses a module you do not have.** `agg.balance('comp_off')`
> requires the **Comp-Off** module, which is not included in your plan (*Growth*).
> The rule will be preserved as a draft. `[ Talk to Ametecs about adding Comp-Off ]`

---

**V19 — Statutory floor breach**
*Runs:* RP4, RP5, RP6, and on every rule-pack activation. *Severity:* declared by the rule
pack per rule — `warning`, `blocking_override` (exception required) or `error`.
*Inspects:* every company enhanced policy value against the corresponding statutory rule
pack floor for the establishment's jurisdiction, as at the effective date.
*Blocks:* per severity. See VI.13 for the full enforcement model.
*Message:* see VI.13.3.

---

**V20 — Secret exposure**
*Runs:* RP2, RP3, RP4, RP5. *Severity:* `error`.
*Inspects:* whether any payload field declared `restricted` (credentials, API keys, signing
secrets, device keys) is present in a payload that is exportable, in a template, in a
report definition, or in an SPX expression.
*Blocks:* promotion and publication.
*Message:*
> **Cannot publish — a credential is referenced in exportable content.** Report definition
> `Device Health v2` selects `connector.api_key`. Credentials are never returned by a read
> endpoint and never appear in an export. Use `connector.credential_status` instead.

---

**V21 — Structural integrity of the version row**
*Runs:* RP4, RP5, RP6. *Severity:* `error`.
*Inspects:* `effective_from ≤ effective_to`; a `high_risk` artefact has a rendered diff and
an approved workflow instance; `checksum` matches the payload; the version number is
`MAX+1`; the payload validates against the artefact type's declared JSON schema for the
free-form portion.
*Blocks:* publication.
*Message:* technical; shown as an internal error with a support reference, because reaching
it means a client bypassed the Studio.

---

**V22 — Archive safety**
*Runs:* on T9. *Severity:* `error`.
*Inspects:* whether the version is referenced by any derived record inside the retention
horizon.
*Blocks:* archival.
*Message:*
> **Cannot archive — `LATE-PENALTY v1` produced 14,208 attendance days and 3 payroll runs
> still inside the 7-year retention horizon.** Archiving would make those records
> unexplainable. It becomes archivable on **01-Apr-2033**.

---

### VI.9.3 Two properties the catalogue must have

**R-VI-081** Every validation message names **what**, **where**, **why it matters in
business terms**, and **at least one concrete remediation** — preferably an action button.
A message that states only that something is invalid is not conformant and fails review.

**R-VI-082** No validation is implemented twice. The Studio, the `/api/v3` write path, the
import path, the pack applier and the nightly sweep call the same validator with the same
run point. *Test:* an import of a version that the Studio would reject is rejected with an
identical finding code and message. *(SmartPRS 2 has client-side validation in a 12,533-line
PHP heredoc and different, weaker server-side validation — `K-…` §5.2 item 15.)*

---

## VI.10 Configuration diff and impact preview *(brief Section 105)*

### VI.10.1 The diff

**R-VI-090** Every version, at every state, can be diffed against: its predecessor, any
other version of the same artefact, the go-live baseline, or the corresponding version in
another environment (UAT versus production). The diff is computed over the **canonical
payload**, not over stored JSON text, so key ordering and formatting produce no noise.

Diff rendering, per field:

| Field kind | Rendering |
|---|---|
| Scalar | `old → new`, with the unit, e.g. `grace_minutes 15 → 20 (minutes)` |
| Money | old and new in Indian format, plus the delta: `₹15,000.00 → ₹18,000.00 (+₹3,000.00)` |
| SPX expression | a token-level diff of the source **and** a structural diff of the AST, because a re-formatted expression that is semantically identical must render as "no change" |
| Slab / band table | row-level add/remove/change, with the affected value ranges called out |
| Scope | per dimension: added values, removed values, and the resulting specificity change `540 → 720` |
| List (actions, workflow nodes, structure lines) | ordered add/remove/move/change |
| Effective range | `01-Apr-2026 → open` becomes `01-Jul-2026 → open`, with the predecessor's closure shown |

**R-VI-091** For a `high_risk` artefact type the diff is **mandatory before submission**
(spine S4) and is attached to the version row, so the approver approves a specific diff,
not a moving target. If the draft changes after the diff was rendered, the approval is
invalidated and must be re-requested.

### VI.10.2 The impact preview

The preview answers five questions, in this order, on one screen:

```
IMPACT PREVIEW — OT-ELIGIBILITY v3  (draft)          effective 01-Sep-2026 →

1  WHAT CHANGES
   scope        + designation IN [Recovery Agent, Senior Agent]      spec 180 → 530
   eligible     false → true
   multiplier   —     → 2.0                                          (was inherited 1.5)

2  WHO IS AFFECTED                                        as at 01-Sep-2026
   Employees newly matched by this artefact                                412
     of which: previously resolved to OT-DEFAULT v1 (eligible=false)       412
   Employees no longer matched                                               0
   Employees whose resolved VALUE changes                                  412
     Hyderabad  371        Pune  41  ← unexpected? [ Why are Pune agents here? ]
     Recovery Agent 380    Senior Agent 32

3  WHICH MODULES ARE AFFECTED
   Attendance   OT minutes will be computed for 412 employees        (was: not computed)
   Payroll      an Overtime earning line will appear on 412 payslips
   Statutory    OT enters the PF wage per component flag OT.pf_wage = true
                → PF employee and employer both increase for 412 employees
                ESI: 44 of the 412 are within the ESI threshold; their ESI increases
   Reports      Wage Register, PF ECR, Payslip — column values change; no layout change
   Notifications  none

4  MONEY ESTIMATE                             method: see VI.10.3
   Basis: the last 3 completed periods' actual OT minutes for these 412 employees
   Estimated monthly OT earning                                    + ₹4,86,300
   Estimated monthly employer PF consequence                       +   ₹58,356
   Estimated monthly employer ESI consequence                      +    ₹6,240
   ────────────────────────────────────────────────────────────────────────────
   Estimated monthly employer cost impact                          + ₹5,50,896
   Confidence: MEDIUM — based on historical OT minutes; actual depends on future OT

5  WHEN
   Effective 01-Sep-2026. First affected payroll: Sep-2026 (period 21-Aug → 20-Sep).
   Locked periods reached: none.
```

**R-VI-092** The impact preview reports **employee counts, module impact and effective
date** as a minimum, and a **money estimate with a stated method and confidence** wherever
the artefact can affect money. An estimate with no stated method is not conformant.

**R-VI-093** Every count on the preview is clickable to the underlying employee list, and
downloadable. An administrator must be able to check the number, not merely trust it.

### VI.10.3 How impact is computed without running a full payroll

This is the design constraint that makes the preview usable: a full payroll for 5,000
employees is minutes; a preview must be seconds.

**The method, in four stages:**

| Stage | What it does | Cost |
|---|---|---|
| **1. Scope delta** | Materialise the old scope and the new scope against the workforce as at the effective date, using the same indexed query the resolver uses (VI.4.7). Produces three sets: newly matched, no longer matched, still matched | One indexed query per scope; milliseconds |
| **2. Resolution delta** | For each employee in the union, resolve the artefact **before** and **after** the hypothetical publication. Because resolution is per *scope hash*, not per employee, this is tens of resolutions, not thousands | Tens of queries |
| **3. Module reachability** | Walk the dependency graph *forward* from the artefact to the engines that consume it, and from those engines to their outputs. This is a static graph walk over `config_dependencies`, not a computation — it produces the "which modules are affected" list without evaluating anything | Graph walk; milliseconds |
| **4. Money estimate** | **Not** a payroll run. Re-evaluate only the affected computation, against **historical actual inputs**, for the affected employees. Three modes, chosen by the artefact type and declared on the preview: | see below |

| Estimate mode | Method | Used for | Confidence |
|---|---|---|---|
| `exact_replay` | Re-run the affected engine stage only, over the last completed period's frozen inputs (attendance settlement, leave settlement, variable inputs), with the new configuration. Nothing else in the pipeline is re-evaluated; the delta is the difference in the affected lines | Fixed earnings, deductions, statutory rates, structure changes — anything whose inputs are already known | **HIGH** |
| `historical_projection` | Take the mean of the last N completed periods' actual driver (OT minutes, late counts, leave units) per employee, and evaluate the new configuration against it | Rules whose effect depends on future behaviour — OT, penalties, incentives | **MEDIUM** |
| `population_only` | Report the affected population and the module list; state explicitly that money cannot be estimated | Templates, report definitions, notification audiences, workflows | **N/A** |

**R-VI-094** The preview never fabricates an estimate. Where the method cannot produce one,
it says so. *(SmartPRS 2 fabricates twelve months of payroll history on first page load —
`A-payroll.md` Q8 row 10 — and this part forbids the same instinct in the preview.)*

**R-VI-095** `exact_replay` is a **pure** re-evaluation: it reads the frozen period
settlement (spine S10 layer 4) and writes nothing. It is the same entry point the simulator
uses (R-VI-070), so a preview and a simulation of the same change agree exactly.

**R-VI-096** A preview over a cohort larger than a declared threshold (recommendation:
2,000 employees) runs as a queued job with progress, not synchronously, and its result is
attached to the version so the approver sees the same numbers the author did.

---

## VI.11 Configuration import and export *(brief Section 106)*

### VI.11.1 What a package is

**R-VI-100** A **configuration package** is a signed-or-checksummed, self-describing bundle
of artefact versions plus their dependency closure. It is the unit of promotion, backup,
branch reuse and implementation templating.

```
package.manifest
  package_uuid, name, description, package_version
  source: { tenant_uuid_hash, environment, platform_version, exported_at, exported_by }
  target_compatibility: { min_platform_version, required_modules[], required_dimensions[] }
  contents: [ { artefact_type, artefact_key, version_no, checksum } … ]
  dependency_closure: [ … artefacts pulled in automatically, marked `implicit` … ]
  test_cases: [ … pinned regression cases for the included artefacts … ]
  excluded: [ … see VI.11.3 … ]
  integrity: sha256 of the canonicalised contents
  signature: present only for Ametecs-published packs (VI.12)
```

### VI.11.2 The four use cases

| Use case | Direction | What is included | Conflict mode |
|---|---|---|---|
| **UAT → production promotion** | export from UAT, import to production | the change set under test, plus its dependency closure | `strict` — see VI.11.4 |
| **Branch / entity reuse** | export from one company or branch scope, import into another with a **scope remap** | artefacts scoped to the source, rewritten to the target scope | `remap` |
| **Backup** | export whole tenant | everything, including archived versions | restore only into an empty or matching tenant |
| **Implementation template** | export a curated subset, publish as a Policy Pack (VI.12) | curated; scopes usually blanked to tenant-default | `fork` |

**R-VI-101** A promotion package carries the **change set** (VI.17), not a loose list. The
same set of versions that were validated and approved together in UAT are applied together
in production, atomically: all or nothing.

### VI.11.3 What is excluded, always

**R-VI-102** A configuration package **never** contains:

| Excluded | Why | What travels instead |
|---|---|---|
| Credentials of any kind — SMTP passwords, connector API keys, device keys, signing secrets, gateway secrets | A package moves between environments and is stored as a file; a credential in it is a breach waiting to happen (`CONSOLIDATED-REQUIREMENTS` H-53) | The connector artefact travels with `credential_ref` **empty** and a `credential_required: true` flag. The import produces a post-import task list naming every credential that must be supplied in the target |
| Tenant business data — employees, punches, payslips, leave balances, documents, ledgers | A configuration package is configuration. Data movement is a separate, separately-authorised operation | nothing |
| Employee-scoped configuration (K12 exceptions) unless explicitly opted in, and never with the employee's identity in a cross-tenant package | An exception is about a person | On opt-in and same-tenant only, by employee uuid; cross-tenant, the artefact is dropped and listed |
| Audit ledger rows | The ledger is per-tenant, hash-chained, and not transferable | nothing; the import writes its own audit rows |
| Entitlement grants (K11) | Entitlement is commercial and comes from the licence, never from a file | The manifest *declares* required modules; the import fails if the target lacks them (V18) |
| Statutory rule packs (K2) | Packs are separately signed and separately distributed (spine S12) | The manifest declares the minimum pack version the configuration assumes |
| Org tree nodes and employee master data | These are tenant reality, not configuration | The manifest declares the dimension values the scopes reference; the import maps or fails |

### VI.11.4 Import: matching, conflict and resolution

**R-VI-103** Import is a **three-phase** operation and never writes during the first two.

```
Phase 1  PARSE + VERIFY   integrity hash; signature where present; platform compatibility;
                          required modules entitled (V18); required dimensions resolvable
Phase 2  PLAN             match each incoming artefact to the target; classify; run the
                          full validation catalogue at RP5 against the SIMULATED post-import
                          state; render a complete diff and impact preview
Phase 3  APPLY            one transaction; the whole change set or nothing
```

**Matching** is by `(artefact_type, artefact_key)` — never by name and never by internal
id. *(SmartPRS 2 matches companies, branches and roster rows by display name —
`A-payroll.md` §5.1, `B-…` §5.2 item 6, `C-…` §5.1.)*

**Classification and conflict resolution:**

| Case | Meaning | Default resolution | Alternatives offered |
|---|---|---|---|
| **New** | key absent in target | create as a new artefact, versions imported as `draft` | import directly to `published` (requires `config.publish` and re-runs RP4) |
| **Identical** | key present, payload checksum equal | skip; report as "no change" | — |
| **Target unchanged since last import** | target's published version came from a previous import of the same package lineage and has not been locally edited | **update** — publish the incoming version as a successor | skip |
| **Locally modified** | target's published version has local edits since the last import | **conflict — stop and ask** | `keep_target` · `take_source` · `fork` (import under a new key, both survive, target re-scoped) · `merge` (field-level, only where the payload is field-addressable; never for SPX bodies) |
| **Target ahead** | target has a version with a later effective date than the incoming one | **conflict — stop and ask** | `insert_before` (import with an earlier range, closing correctly) · `skip` |
| **Missing dependency** | incoming artefact references something absent from both package and target | **error** — the package is incomplete | re-export with closure |
| **Scope unmappable** | the incoming scope names a dimension value the target does not have | **error**, with a **scope remap screen**: each unmatched value is mapped to a target value, blanked to `ALL`, or the artefact is dropped | |

**R-VI-104** There is no `overwrite everything` mode, and no silent resolution. Every
conflict is presented with both payloads, a diff and an impact preview. A package of 200
artefacts with 3 conflicts stops on the 3 and applies nothing until they are decided.

**R-VI-105** Import is **idempotent**: re-importing the same package into the same target
produces "no change" for every artefact. *Test:* import a package twice; the second import
writes zero versions and reports 100% identical.

**R-VI-106** Every import writes an `config_import_runs` row and per-artefact
`config_import_items` rows recording the classification, the resolution chosen, the actor
and the resulting version id — so "where did this configuration come from?" is answerable
for every artefact in the tenant.

**R-VI-107** An import may be **reverted** as a unit within a declared window, by publishing
the pre-import versions as successors (the rollback mechanism of VI.3.5, applied to a
change set). Revert is a publication, not a deletion.

---

## VI.12 Customer Policy Packs *(brief Section 107)*

### VI.12.1 What a pack is, and why it is not just a package

A **Policy Pack** is a curated, versioned, Ametecs-authored (or partner-authored)
configuration package with three additional properties a plain export does not have:

1. **It is authored for reuse**, not exported from one customer. Scopes are blanked to
   tenant-default or expressed as *slots* the applier fills.
2. **It carries its own regression cases** (R-VI-078), so it ships with proof.
3. **It has a lineage relationship with the tenant that applied it**, so a pack update can
   be offered later without overwriting the customer's edits.

**R-VI-110** A Policy Pack is signed by Ametecs with the same asymmetric mechanism as a
statutory rule pack (spine S12), and its signature is verified on import. A pack that fails
verification is rejected, not warned about.

### VI.12.2 The starter packs

| Pack | Contains, in outline |
|---|---|
| **BPO Standard** | 24×7 shift set with rotation patterns, night-shift allowance rule, shift-anchored attendance day, tiered late policy with occurrence-based penalties, OT eligibility by designation, comp-off earn/avail/expiry, a two-level leave workflow, a monthly pay cycle with a 21st→20th cut-off, a component set with statutory bases declared, a standard payslip template, absence and late notification subscriptions |
| **6-Day Working** | weekly-off pattern Sunday only, working-day calendar, 26-day and working-day proration bases offered as alternatives, LOP basis, holiday calendar template |
| **5-Day Corporate** | weekly-off pattern Saturday+Sunday, flexi grace, no OT for exempt grades, leave set with accrual and carry-forward, appraisal-linked increment workflow |
| **Collections Agency** | the SmartPRS vertical: DRA and PCC compliance item types with expiry alerting, the compliance-to-money gate policy (`off`/`warn`/`block`) at the declared money points, agent authorisation register, code-of-conduct acknowledgement with versioning, BGV re-verification cadence, off-roll agent payout shapes, the RBI audit pack report set, the lawful-contact-window rule |
| **Telangana Standard** | Telangana-specific: holiday calendar, PT applicability binding to the `IN-TG` rule pack, LWF periodicity, minimum-wage comparison bindings by zone and category, S&E registration fields |

**R-VI-111** Packs compose. Applying **BPO Standard** then **Telangana Standard** then
**Collections Agency** is supported and expected; each declares what it provides and what
it requires, and the applier resolves the order. A pack never silently overwrites another
pack's artefact: an overlap is a conflict, presented as in VI.11.4.

**R-VI-112** No pack contains a statutory *value*. A pack contains *bindings* — "PT
applicability for this establishment resolves from rule pack `IN-TG`" — never a slab table
copied out of a statute. Statutory content lives only in signed rule packs (spine S12,
legal-values discipline).

### VI.12.3 Authoring, versioning and lifecycle

**R-VI-113** A pack is authored in a dedicated Ametecs **pack authoring tenant** using the
ordinary Studio, and exported. It is not written by hand as a file. This guarantees a pack
cannot contain anything the Studio could not have produced, and that it passes the same
validation catalogue.

| Property | Rule |
|---|---|
| Versioning | `major.minor.patch`. **Patch**: message text, help text, a corrected typo. **Minor**: additive artefacts, new optional slots. **Major**: a changed default that alters behaviour, or a removed artefact |
| Compatibility | Each pack version declares `min_platform_version` and the rule-pack versions it assumes |
| Regression | A pack version cannot be published by Ametecs unless all of its pinned cases pass in CI against the target platform version |
| Deprecation | A pack version may be deprecated with a stated successor and a reason; tenants running it are notified, never auto-migrated |

### VI.12.4 Applying a pack, and customising afterwards

**Application** is the import flow of VI.11.4 with `conflict_mode = fork` and a **slot
mapping step**:

```
APPLY PACK — BPO Standard v3.2

  Slots to fill                                    (the pack cannot know these)
    Which companies does this apply to?            [ Ametecs Collections ▾ ]
    Which branches use the 24×7 shift set?         [ Hyderabad, Pune ▾ ]
    Pay cycle cut-off day                          [ 20 ]  (pack default: 20)
    Night-shift allowance amount                   [ ₹150.00 ]  (pack default: ₹0.00 — must set)
    OT-eligible designations                       [ Recovery Agent, Senior Agent ▾ ]

  Will create   38 artefacts   ·   Will update 0   ·   Conflicts 0
  Includes      41 regression cases (will run on apply)
  [ Preview all 38 ]   [ Apply as drafts ]   [ Apply and publish ]
```

**R-VI-114** A pack always applies **as drafts by default**. "Apply and publish" is a
separate, permissioned action that runs the full RP4 validation for every artefact and the
pack's regression cases. A pack that publishes itself silently is not conformant.

**Customisation after import — the fork model:**

**R-VI-115** Every artefact created by a pack carries `origin_pack_id`,
`origin_pack_version` and `origin_checksum`. Its state is one of:

| State | Meaning | Behaviour on a pack update |
|---|---|---|
| `pristine` | The tenant has published no version since the pack's | The update is offered and, if accepted, applied cleanly |
| `forked` | The tenant has published its own version | The update is offered as a **three-way diff** (pack old, pack new, tenant current). The tenant chooses per artefact: keep mine, take theirs, or merge. **Nothing is ever overwritten silently** |
| `detached` | The tenant explicitly severed the lineage | The update is never offered |

**R-VI-116** A pack update never leaks one customer's edits into another, and never
overwrites a customer's edit. *(SmartPRS 2's `kb_topics` are deduplicated by title across
the **entire installation** and always written `tenant_id = NULL`, so one tenant's content
edit is every tenant's — `CONSOLIDATED-REQUIREMENTS` H-58. This is the defect this
requirement exists to prevent.)*

**R-VI-117** A tenant may author and export its own pack (for its own group companies, or
with Ametecs' agreement for a partner channel). A tenant-authored pack is unsigned and is
marked as such at import; the importer sees `Source: tenant-authored, unsigned`.

---

## VI.13 Statutory baseline versus company policy *(brief Section 108)*

### VI.13.1 The layering

Spine S12 fixes the layering: central statutory baseline, then state/UT rule, then
establishment applicability, then company enhanced policy. This section fixes the
**enforcement model** at the boundary between the last two.

```
                        rule pack IN-EPF v2026.4   (signed, Ametecs)
  statutory floor  ───►   pf_employee_rate_min = <from rule pack>
                          pf_wage_ceiling      = <from rule pack>
                          severity per rule    = block | exception_required | warn
                                    │
                                    ▼
  company policy   ───►   statutory_company_policy  (tenant artefact, K3)
                          pf_employee_rate = 12.00        ← equal to floor: fine
                          pf_on_full_wages = true         ← better than floor: fine
                          pf_employee_rate = 8.00         ← below floor: V19 fires
```

**R-VI-120** A company policy may **improve** on a statutory minimum without limit and may
**never reduce** it. "Improve" is declared per rule in the rule pack as a direction
(`higher_is_better`, `lower_is_better`, `must_equal`, `must_be_within`), because for some
heads a higher employee deduction is not an improvement.

### VI.13.2 The three severities

Severity is declared **by the rule pack**, per rule, by Ametecs' compliance owner — not by
the tenant, and not by the application code.

| Severity | Behaviour | When Ametecs uses it |
|---|---|---|
| `block` | Publication is refused. There is no override, at any permission level | Where a breach is unlawful and no lawful exemption exists — e.g. a contribution rate below the statutory minimum for a covered establishment |
| `exception_required` | Publication is refused **unless** a **Compliance Exception** is recorded: the legal basis, the authorising officer, an effective range, an evidence attachment, and an approval through the maker-checker workflow. The exception is then attached to the version | Where a lawful exemption, exclusion or transitional arrangement genuinely exists — e.g. an establishment below a coverage threshold, an employee excluded by definition, a state-specific exemption |
| `warn` | Publication proceeds; the finding is acknowledged, recorded on the version, and appears on the compliance dashboard until resolved | Where the value is advisory, or where the statutory position depends on facts the platform cannot know |

**R-VI-121** A `blocking_override` severity does not exist as a tenant setting. The tenant
cannot downgrade `block` to `warn`. *(SmartPRS 2's `dra_gate` proves the pattern is right —
`off`/`warn`/`block`, per tenant, at three money points, spine S19 item 1 — but it is the
tenant's own gate on the tenant's own policy. A statutory floor is not the tenant's to
soften.)*

### VI.13.3 What the administrator sees

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ✖  Cannot publish — statutory floor breach                        V19       │
├──────────────────────────────────────────────────────────────────────────────┤
│  Artefact   Company Statutory Policy — Ametecs Collections   v6              │
│  Effective  01-Oct-2026 →                                                    │
│                                                                              │
│  Rule       IN-EPF / EMPLOYEE_CONTRIBUTION_RATE                              │
│  Source     Rule pack  IN-EPF  v2026.4   (signed by Ametecs, activated       │
│             12-Aug-2026, effective 01-Apr-2026 →)                            │
│  Reference  <source_reference from the rule pack>                            │
│                                                                              │
│  Statutory minimum   <from rule pack>                                        │
│  Your value          8.00 %                                                  │
│  Direction           higher_is_better                                        │
│  Severity            BLOCK — this cannot be overridden by any role            │
│                                                                              │
│  Applies to  Establishment  Hyderabad Unit-1  (PF code <registered>)         │
│              1,204 employees, of whom 1,181 are PF-covered                   │
│                                                                              │
│  If your establishment is genuinely outside coverage, that is an             │
│  APPLICABILITY question, not a rate question. Set                            │
│  Statutory → Applicability → PF = "not applicable" for this establishment,   │
│  with the exemption basis and evidence. That path is auditable and this      │
│  one is not.                                                                 │
│                                                                              │
│  [ Open Applicability ]   [ Change the rate ]   [ Discuss with Ametecs ]     │
└──────────────────────────────────────────────────────────────────────────────┘
```

And for `exception_required`:

```
⚠  Statutory finding requires a recorded compliance exception          V19

   Rule      IN-ESI / COVERAGE_THRESHOLD          Severity: EXCEPTION REQUIRED
   Finding   Your policy excludes 44 employees whose gross is below the ESI
             threshold from ESI, at an establishment you have declared covered.

   To publish, record a compliance exception:
     Legal basis        [ free text, mandatory, min 40 characters ]
     Statutory reference[ ......................................... ]
     Evidence           [ attach — mandatory ]
     Authorised by      [ must hold compliance.exception.authorise ]
     Effective          [ 01-Oct-2026 ]  to  [ 31-Mar-2027 ]  (bounded, mandatory)
     Review date        [ 01-Mar-2027 ]

   The exception will appear on the Compliance Exceptions register, on the
   RBI/statutory audit pack, and on the compliance dashboard until it expires
   or is closed. It is approved through maker-checker.

   [ Record exception and submit for approval ]     [ Change the policy ]
```

**R-VI-122** Compliance exceptions are **bounded in time and mandatory to review**. An
exception with no end date cannot be recorded. An expired exception re-raises the finding
and blocks the next publication of the artefact it attaches to.

**R-VI-123** The Compliance Exceptions register is a first-class report, exportable, and
included in the statutory audit pack. An auditor asking "where have you departed from the
statutory baseline, on what basis, authorised by whom?" gets one document.

### VI.13.4 Floor checks run on pack activation, not only on publication

**R-VI-124** When a statutory rule pack is activated, **every** existing company policy is
re-checked against the new floors as at the pack's effective date. Findings are raised
before the pack takes effect, with the affected artefacts, the employee counts and the
money consequence, so the customer has the interval between activation and effect to
respond. A pack activation never silently invalidates a live policy.

**R-VI-125** A pack whose activation would raise `block`-severity findings against live
policy is **staged**, not activated: the administrator is shown the findings and must
resolve them, or accept a scheduled activation date, before the pack takes effect. Ametecs
is notified of every staged pack, because a widespread staging is a signal that the pack
itself may be wrong.

---

## VI.14 Feature and module entitlement *(brief Section 80)*

### VI.14.1 What SmartPRS 2 does, and why none of it survives

| Fact | Evidence |
|---|---|
| `applyPlanGate()` begins with an unconditional `return;`, commented *"TEMPORARILY DISABLED (rev 34e)"*. The Plans UI writes `plans.features` that nothing enforces | `G-…` §5.2 item 13 |
| Edition differentiation (L1/L2/L3) has been retired in code while the constants and the marketing remain; `Edition::level()` returns a literal `3` | `G-…` §5.2 item 12; `N-…` §5.2 item 13 |
| `editionHide` is published to the browser, so the client is told exactly which features the customer has **not** bought, by nav id — and does not enforce them | `CONSOLIDATED-DECISIONS` line 1138 |
| Gating, where it existed, was `display:none` in the browser | `N-…` §5.2 item 20 |

Three price points are sold and one product is delivered. **PRODUCT OWNER DECISION
REQUIRED — Q-01/Q-02 in `CONSOLIDATED-DECISIONS`** governs whether editions exist at all in
v3; this section specifies the mechanism on the assumption that some form of entitlement
does, because a licence seat cap already requires one.

### VI.14.2 The model

```
feature_catalogue      (platform)   feature_key, name, module, kind, parent_feature_key,
                                    depends_on[], is_core, description
entitlement_grants     (platform)   tenant_id, feature_key, granted (bool), limit_json,
                                    source (plan | licence | contract_override),
                                    effective_from, effective_to, signature_ref
tenant_feature_states  (tenant)     feature_key, tenant_enabled (bool), reason,
                                    changed_by, effective_from
```

Three layers, resolved in this order:

```
effective(feature) =  granted(feature)                 -- commercial: from plan or licence
                  AND tenant_enabled(feature)          -- tenant may switch OFF what it has
                  AND all( effective(d) for d in depends_on )   -- dependency closure
```

**R-VI-130** A tenant may **disable** a granted feature; it may never **enable** an ungranted
one. `tenant_enabled` is a narrowing, never a widening. *Test:* an authorisation test asserts
that setting `tenant_enabled = true` on an ungranted feature has no effect on `effective()`.

**R-VI-131** Entitlement is resolved **server-side, once per request**, into an immutable
`EntitlementSet` on the request context, and is enforced at four points **identically**:

| Enforcement point | Mechanism | Failure mode |
|---|---|---|
| **HTTP endpoint** | Every route declares the feature(s) it requires alongside its policy binding. Missing feature → `403` with a machine-readable `feature_not_entitled` code and the feature key | fail closed |
| **Scheduler** | Every scheduled task declares its required features; the scheduler skips it for tenants lacking them, and records the skip | skip, recorded |
| **Queue jobs** | Every job re-checks entitlement at execution, because entitlement may have changed between dispatch and run | job fails visibly to the dead-letter queue, never silently |
| **Public API** | The same route declarations serve `/api/v3`; an unentitled endpoint is absent from the tenant's generated OpenAPI document and returns `403` if called | fail closed |
| **UI** | The SPA reads the resolved `EntitlementSet` and hides what is not entitled — **as a convenience only**. Hiding is never the control (spine S1 Law 5) | cosmetic |

**R-VI-132** The `EntitlementSet` published to the browser contains only the features the
tenant **has**. It never enumerates what the tenant lacks. *(SmartPRS 2's `editionHide`
publishes the negative list, which is a commercial leak for no functional benefit.)*

**R-VI-133** A CI sweep asserts every route, every scheduled task and every queue job
carries an explicit feature declaration — including the explicit declaration `core`, which
means "always available". An undeclared route fails the build. *(This is the same shape as
spine S13's policy-binding sweep, and for the same reason: SmartPRS 2's gate was disabled by
one `return;` and nobody noticed.)*

### VI.14.3 How disabling a module cannot break a dependent one

**R-VI-134** The feature catalogue declares `depends_on`. Disabling a feature that others
depend on is **refused**, with the dependants named — the same shape as V05's reverse
message:

```
✖  Cannot disable "Attendance"

   4 enabled features depend on it:
     Payroll            (needs attendance settlement for payable days)
     Overtime           (needs punch-derived OT minutes)
     Comp-Off           (needs worked-on-weekly-off detection)
     Attendance Reports

   Disable those first, or leave Attendance enabled.
   [ Show dependency tree ]
```

**R-VI-135** Where a dependency is *optional* — a module can operate without it, in a
declared degraded mode — the catalogue declares `degrades_to` and the dependant declares
what it does. Example: Payroll without Attendance operates on **declared payable days**
rather than derived ones; the mode is explicit, visible on the payroll run header, and
recorded in the run's input snapshot. There is no `if (module_disabled)` branch inside a
domain algorithm; the degraded mode is a **different configured input source**, resolved
the same way everything else is.

**R-VI-136** Disabling a feature never deletes its configuration or its data. Configuration
remains stored, remains resolvable by `resolve()`, and is simply never evaluated because the
engine that would evaluate it does not run. Re-enabling restores behaviour exactly.
*Test:* disable a module, run a payroll, re-enable it, re-run the same period with the same
inputs — the second run reproduces the pre-disable result.

**R-VI-137** A limit-bearing entitlement (`limit_json`: seats, companies, storage,
API calls per minute, employees) is enforced at the **transactional check-then-write** point
with a row lock (Part XXVIII R-XXVIII-013), never by a read-then-write race, and the limit
is visible to the tenant with current consumption on the Studio's S15 section.

**R-VI-138** Entitlement changes emit `entitlement.changed` and are audited. A tenant losing
a feature at renewal sees an advance notice, and the configuration for the lost feature is
retained for the retention period, so a re-purchase restores rather than rebuilds.

---

## VI.15 The extensibility limit *(brief Section 128)*

This section exists to bound the promise. Everything above describes what configuration can
absorb. This describes what it cannot, and what happens instead. A blueprint that omits this
section is selling something nobody can deliver.

### VI.15.1 The principle

**R-VI-140** Configuration can express **new values, new combinations, new conditions, new
routing and new presentation** over the platform's existing **concepts, verbs and data
shapes**. It cannot create a new concept, a new verb, or a new integration protocol.

Stated as a test an implementer can apply in the field:

> If the customer's requirement can be written as *"when ⟨condition over existing data⟩,
> do ⟨action from the existing catalogue⟩, for ⟨scope over existing dimensions⟩, from
> ⟨date⟩"* — it is configuration.
> If it requires the platform to **know** something it does not know, **do** something it
> cannot do, or **talk** to something it cannot talk to — it is not.

### VI.15.2 What configuration absorbs — the four categories

| Category | Examples | Mechanism |
|---|---|---|
| **New values** | rates, thresholds, caps, grace periods, slab tables, day counts, divisors, message text, labels, colours, cut-off days | payload fields with declared bounds |
| **New combinations and conditions** | "3 late arrivals over 10 minutes"; "second and fourth Saturday off"; "OT for Hyderabad agents but not Pune"; "sandwich includes Sunday but excludes Sick Leave"; "block a commission payment when the DRA certificate is expired" | SPX `when`/`except` over declared namespaces + the aggregation primitives (spine S5.5) |
| **New structure over existing shapes** | a new leave type, a new salary component, a new custom field, a new document type, a new request type, a new master registry, a new report, a new letter template, a new workflow with any node topology, a new notification audience | artefact types K3–K10 + the entity schema engine (spine S9) |
| **New routing and presentation** | who approves, in what order, with what SLA and escalation; what appears on a payslip; what a role can see; which fields are on which surface; what a report contains | workflow engine (S8), templates, surface rules, report definitions |

### VI.15.3 What configuration does **not** absorb

Stated honestly, with the actual path for each.

| # | Not configuration | Why | The path |
|---|---|---|---|
| **L1** | **A new domain concept** — the platform has no notion of, say, *vehicle telematics*, *shift bidding*, *union settlement arbitration*, or *tip pooling* | A concept needs tables, invariants, engine stages and a trace shape. Metadata can add *fields*; it cannot add *behaviour with its own arithmetic* | **Platform release.** Ametecs designs the concept, its aggregate, its engine stage and its artefact types, and ships it as a module. This is roadmap work, quoted and scheduled |
| **L2** | **A new engine action** — the action catalogues (spine S6) are closed, versioned sets per module. A rule cannot invoke an action nobody has implemented | An action mutates state and must be transactional, traceable, reversible and testable. Arbitrary actions are arbitrary code | **Platform release**, adding to the module's action catalogue. Typically small; this is the most common genuine extension request and should be treated as a normal backlog item, not an architecture change |
| **L3** | **A new SPX function or namespace** | The function library is whitelisted and total (spine S5.4/S5.6). Adding one is adding platform capability | **Platform release.** The library is deliberately generous (aggregation primitives, band/slab/step, calendar, service) precisely so this is rare |
| **L4** | **Customer-supplied code in any language** | Spine S5: customer PHP, JavaScript or Python is **never** accepted anywhere, under any configuration, for any customer. This is absolute and is a security, tenancy and supportability position, not a preference | There is no path. The answer is "no", and the alternative offered is L2, L5 or L7 |
| **L5** | **A new device or vendor protocol** — a fingerprint reader speaking a proprietary SDK, MQTT, a serial reader, a vendor's SFTP drop with an undocumented binary format | Protocol handling is I/O, retry, framing and error semantics. Configuration cannot express those safely | **Adapter.** Spine S10.1's `DeviceAdapter` interface. A new make/model requires a new adapter and **never** a change to the attendance engine. Many sources need no PHP at all: REST/SOAP/SQL/CSV/SFTP sources are expressible as a validated **connector recipe**, which *is* configuration |
| **L6** | **A new outbound integration whose target has its own protocol** — an ERP with a proprietary API, a bank with a bespoke host-to-host format, a government portal with a session-based upload | Same reason as L5 | **Connector recipe** where the target is REST/SOAP/SFTP/file-based; **adapter** otherwise |
| **L7** | **A genuinely new UI interaction** — a drag-and-drop shift-bidding board, a map-based route planner, a Gantt roster | The Studio generates forms, builders, tables and graphs from metadata. It does not generate novel interactions | **Platform release.** A customer-specific screen is not built; a general capability is, or the requirement is met with an existing interaction |
| **L8** | **Changing the precedence weights** (S3.2) | A tenant changing them silently changes every historical resolution | Ametecs-only, release-gated, with re-resolution of affected derived records (R-VI-020) |
| **L9** | **Changing what a statutory rule *means*** — as opposed to its values or its applicability | Statutory calculation semantics live in signed rule packs authored by the compliance owner | **Rule pack**, published by Ametecs (spine S12). Never tenant configuration |
| **L10** | **Schema change at runtime** | Spine S1 Law 2. Custom fields never create columns | **Migration**, in a release. A query-hot custom field may be *promoted* to a generated column by an Ametecs migration (spine S9) — a controlled release activity |
| **L11** | **Weakening a security control** — disabling MFA for a privileged role, turning off audit for an entity, making authorisation fail open, removing a tenancy filter | These are platform invariants, not policy | No path. Configuration exposes *strength* knobs (session length, password complexity, lockout thresholds) with secure floors the installer cannot skip |
| **L12** | **Per-tenant business logic branches in the platform** — `if (tenant == X)` anywhere | This is how SmartPRS 2's 12,533-line controller happened | No path, ever. The requirement becomes a scoped artefact, or L1/L2, or it is declined |

### VI.15.4 The adapter and plugin path

**R-VI-141** Where a requirement falls into L1, L2, L3, L5, L6 or L7, the path is an
**Ametecs-authored, versioned, signed platform extension**, delivered in a release. There
is no customer-authored plugin runtime, and no third-party code executes inside SmartPRS 3.

**PRODUCT OWNER DECISION REQUIRED — D-VI-03** (VI.24): whether a **partner adapter SDK**
should exist for device and connector adapters (L5/L6) only, allowing an integration partner
to author an adapter that Ametecs reviews and signs. Recommendation: **yes, but not in
v3.0** — the interface (S10.1) is designed for it, and the connector-recipe mechanism covers
the majority of cases without it.

**R-VI-142** The **extension surface** is exactly four interfaces, and nothing else:

| Interface | Purpose | Who implements |
|---|---|---|
| `DeviceAdapter` (S10.1) | a device or punch source protocol | Ametecs (partner, post-D-VI-03) |
| `ConnectorAdapter` | an outbound or inbound system integration not expressible as a recipe | Ametecs |
| `ActionHandler` | one entry in a module's action catalogue | Ametecs |
| `ReportDataset` | a dataset the report builder can query, where the builder's entity model is insufficient | Ametecs |

Each is versioned, each is covered by contract tests, and each is discoverable in the Studio
so an administrator can see what is installed.

### VI.15.5 What we tell a customer

**R-VI-143** The Studio's rule and formula editors, and the Wizard, must be honest at the
point of authoring: where a customer expresses something the platform cannot do, the
response names the limit and the path, and never fails with a syntax error that implies the
author made a mistake. Example, on an unknown function:

> `TELEMATICS_DISTANCE` is not a SmartPRS function. SmartPRS knows nothing about vehicle
> telematics; there is no data source for it in your tenant. If you need distance-based
> allowances, that is a platform capability — talk to Ametecs about the Field Force module.
> Available functions here: `[ browse ]`.

---

## VI.16 The Section 127 conformance test

This section is the proof of the thesis. For each example the master brief gives, it states
**exactly** which artefact a consultant edits, in which Studio section, what the stored
configuration is, and what SmartPRS 2 would have required instead. Nothing here is a code
change.

Notation: `→` is a Studio navigation path. Payloads are shown in the canonical form the
API accepts and the export writes; the Studio renders them as forms and builders.

---

### 127.1 — "3 late arrivals over 10 minutes = half day"

**Artefact:** `rule` · key `LATE-3X-HALFDAY` · module `attendance`
**Studio:** S4 Attendance → Rules → Late & Penalty → New rule
**Prerequisite (already data):** `value_set attendance_status` contains `HALF_DAY`.

```yaml
artefact_type: rule
artefact_key:  LATE-3X-HALFDAY
name:          "Three late arrivals over 10 minutes = half day"
business_description: >
  A third late arrival of more than 10 minutes in one payroll month costs half a
  day's pay. Grace is applied before the 10 minutes is measured.
module:        attendance
version:
  version_no:      1
  status:          published
  effective_from:  2026-04-01
  effective_to:    null
  scope:
    branch:      IN [HYD]
    department:  IN [COLLECTIONS]        # specificity 180 + 400 = 580
  priority:      100
  on_conflict:   first_match
  payload:
    when: >
      attendance.late_minutes > 10
      AND agg.occurrence_index(
            event:     'late_arrival',
            window:    'payroll_period',
            predicate: attendance.late_minutes > 10
          ) = 3
    except: >
      day.is_holiday OR day.is_weekly_off OR leave.type <> NULL
    then:
      - SET_STATUS(code: 'HALF_DAY')
      - APPLY_DAY_PENALTY(fraction: 0.5)
      - NOTIFY(event: 'attendance.penalty_applied')
  test_cases:
    - name: "3rd late of 47 min in Aug → half day"
      subject: synthetic{ branch: HYD, department: COLLECTIONS }
      inputs:  lates: [21, 14, 47] minutes on 04/09/14-Aug-2026
      expect:  { status: HALF_DAY, payable_day_fraction: 0.5 }
    - name: "2nd late does not penalise"
      inputs:  lates: [21, 14] minutes
      expect:  { status: PRESENT, payable_day_fraction: 1.0 }
    - name: "late of 8 min does not count toward the 3"
      inputs:  lates: [21, 8, 14, 47]
      expect:  { occurrence_of_47: 3, status: HALF_DAY }
```

**Variants a consultant produces by editing values only, with no new concept:**

| Requirement | Change |
|---|---|
| "every 3rd late", not "the 3rd" | `MOD(agg.occurrence_index(...), 3) = 0` |
| "in any rolling 30 days" | `window: rolling(30, 'day')` |
| "over 15 minutes" | the literal `10` → `15` |
| "full day, not half" | `APPLY_DAY_PENALTY(fraction: 1.0)` |
| "and warn the employee at the 2nd" | a second rule, priority 90, `occurrence_index = 2`, `then: NOTIFY(...)` only |
| "first 2 lates free each month" | `agg.count(...) > 2` in the `when` |

**SmartPRS 2 comparison.** Late penalties are three fixed tiers (L1–L3) with a fixed
free-lates count over a fixed payroll-month window, keyed to a policy row scoped only to
company/team/employee, with ties resolved by row order, and late-but-below-L1 silently
charged at `l1_cut` (`B-attendance.md` §5.2 items 19–24; `PayrollGenController:453,475,494`).
An occurrence-indexed rule is not expressible at all.

---

### 127.2 — "2 weekly offs instead of 1"

**Artefact:** `weekly_off_pattern` · key `WO-STANDARD`
**Studio:** S2 Calendar & Periods → Weekly Off Patterns
**Type properties:** `is_continuous: true`, `is_high_risk: true` (it changes the payroll
denominator).

```yaml
artefact_type: weekly_off_pattern
artefact_key:  WO-STANDARD
version:
  version_no:     4
  effective_from: 2026-04-01
  scope:
    company: IN [AMETECS-CORP]           # specificity 100
  payload:
    fixed_days:      [SUNDAY, SATURDAY]  # was [SUNDAY]
    saturday_rule:   null                # not needed when Saturday is a fixed off
    pattern_kind:    fixed_weekdays
    counts_toward:
      working_days:  false
      payable_days:  true
```

The predecessor `WO-STANDARD v3` (`fixed_days: [SUNDAY]`) is closed at 31-Mar-2026 by T7.
August 2026 payroll resolves v4; March 2026 payroll resolves v3. Nothing else changes.

**Impact preview** shows: working days per month 26→22 (indicative), payable-days basis
unchanged, LOP divisor change flagged, 1,204 employees, first affected period Apr-2026.

**SmartPRS 2 comparison.** `weekly_off_day` is a **single** tenant-wide string in an
undated JSON blob (`A-payroll.md` §5.1; `SettingsController:155`), and the attendance
layer keeps a *separate* flat ISO-weekday list per tenant (`AbsenceService:45`) that can
disagree with it. Two weekly offs, per-company, effective-dated, is not expressible.

---

### 127.3 — "second and fourth Saturday off"

**Artefact:** the same `weekly_off_pattern`, different payload.
**Studio:** S2 Calendar & Periods → Weekly Off Patterns → Saturday rule.

```yaml
artefact_type: weekly_off_pattern
artefact_key:  WO-STANDARD
version:
  version_no:     5
  effective_from: 2026-04-01
  scope:
    branch: IN [HYD]                     # specificity 180 — beats the company pattern (100)
  payload:
    fixed_days:    [SUNDAY]
    pattern_kind:  fixed_weekdays_plus_nth
    saturday_rule:
      kind:        nth_weekday_of_month
      weekday:     SATURDAY
      occurrences: [2, 4]                # a list — not a coded 2_4/1_3 enum
      basis:       nth_weekday            # the ONLY correct basis; see below
    counts_toward:
      working_days: false
      payable_days: true
```

Evaluated by the calendar service as `day.date = NTH_WEEKDAY(period.month, 'saturday', n)`
for each `n` in `occurrences` (spine S5.4).

**The defect not replicated.** SmartPRS 2 computes the "Nth Saturday" as
`ceil(day_of_month / 7)` (`A-payroll.md` §5.2 item 16, `PayrollGenController:83`). That is
the *nth week of the month*, not the *nth Saturday*, and the two differ in every month whose
1st falls on a Sunday: 7-Mar-2026 is the **first** Saturday but `ceil(7/7) = 1` only by
coincidence, while 1-Aug-2026 (a Saturday) gives `ceil(1/7) = 1` correctly and 29-Aug-2026
gives `ceil(29/7) = 5` — a fifth Saturday that some months have and some do not, with no
declared behaviour. **SmartPRS 3 uses `NTH_WEEKDAY` and declares `fifth_saturday_behaviour`
explicitly** (`working` | `off` | `same_as_fourth`) rather than leaving it to arithmetic
accident. This is stated here because Law 5 of the author brief requires that a SmartPRS 2
defect be named and not carried forward.

**Variants:** `occurrences: [1, 3]`; `occurrences: [2, 4, 5]`; alternate-Saturday by
*week parity of the ISO week number* is a different `kind` (`alternating_iso_week`) with a
declared anchor date, because "alternate Saturdays" means different things to different
customers and the ambiguity must be resolved in configuration, not in code.

---

### 127.4 — "include Sunday in sandwich leave"

**Artefact:** `sandwich_policy` · key `SANDWICH`
**Studio:** S5 Leave & Absence → Sandwich & Clubbing

```yaml
artefact_type: sandwich_policy
artefact_key:  SANDWICH
version:
  version_no:     2
  effective_from: 2026-07-01
  scope: {}                              # tenant default, specificity 0
  payload:
    enabled:              true
    bridging_day_types:   [WEEKLY_OFF, HOLIDAY]     # WEEKLY_OFF now included → Sunday counts
    require_both_sides:   true            # a bridging day is sandwiched only if BOTH
                                          # adjacent days are uncovered
    max_bridge_length_days: 3             # a 4-day bridge is not a sandwich
    uncovered_means:      [ABSENT, LOP, UNAUTHORISED]
    counts_as:            LOP             # or DEDUCT_FROM_LEAVE_TYPE(code)
    applies_to_leave_types: ALL
    excluded_leave_types:   []            # see 127.5
    applies_to_prefix_suffix: true        # a weekly off immediately before/after the absence
```

**The ambiguity SmartPRS 2 leaves undetermined, resolved as configuration.** SmartPRS 2's
`sandwichDays()` is a single on/off flag whose semantics — whether *both* sides must be
uncovered or one — is fixed in code and documented nowhere (`A-payroll.md` §5.2 item 22,
`:807`). Here `require_both_sides` is an explicit, effective-dated, scoped decision.

---

### 127.5 — "exclude Sick Leave from sandwich"

**Same artefact, one field.**
**Studio:** S5 Leave & Absence → Sandwich & Clubbing → Excluded leave types.

```yaml
  payload:
    excluded_leave_types: [SL]            # by CODE, never by name (Law 1)
```

Two ways to express it, and the Studio offers both:

| Approach | Stored form | When to use |
|---|---|---|
| **Field on the tenant-default version** | `excluded_leave_types: [SL]` on `SANDWICH v3`, scope `{}` | The exclusion applies to everyone. Simplest; one artefact |
| **A scoped override version** | `SANDWICH v1` scope `{}` with `applies_to_leave_types: ALL`; plus `SANDWICH-SL` scope `{}` — **no**: a second artefact of the same type and key with an identical scope is an overlap (V02) | Not used. Leave-type exclusion is a *payload* dimension, not a *scope* dimension, because leave type is not an employee attribute |

**This distinction matters and the Studio teaches it:** scope answers *"which employees?"*;
payload answers *"which cases?"*. Sick Leave is not an employee attribute, so it belongs in
the payload. Hyderabad is an employee attribute, so it belongs in the scope. Getting this
wrong is the most common authoring mistake, and V17's "scope matches no employee" warning is
what catches it.

**SmartPRS 2 comparison.** The sandwich rule is one tenant-wide boolean in an undated blob,
operating on attendance rather than on leave, with no leave-type dimension whatsoever
(`E-…` B-38; `A-payroll.md` §5.1).

---

### 127.6 — "Hyderabad branch receives OT; Pune does not"

**Artefact:** `rule` · key `OT-ELIGIBILITY` · module `attendance`
**Studio:** S4 Attendance → Rules → Overtime → Eligibility

Two published versions plus a default:

```yaml
# 1. the tenant default — the safe answer when nothing more specific matches
artefact_key: OT-ELIGIBILITY
version: { version_no: 1, effective_from: 2026-04-01, scope: {}, priority: 0,
           payload: { eligible: false } }                        # specificity 0

# 2. Hyderabad
version: { version_no: 2, effective_from: 2026-04-01,
           scope: { branch: IN [HYD] },                          # specificity 180
           priority: 0,
           payload:
             eligible: true
             qualifying_minutes_min: 30        # OT below 30 min is not paid
             daily_cap_minutes:      240
             weekly_cap_minutes:     720
             multiplier_ref:         OT-RATE-STD      # a formula artefact
             rounding:               { unit: 15, mode: floor }   # explicit; no default
             requires_pre_approval:  true
             approval_workflow:      OT-APPROVAL }
```

Pune requires **no artefact at all** — it resolves to the tenant default (`eligible: false`)
at specificity 0. A consultant may still author an explicit Pune version for documentary
clarity; both are correct and the Studio says so.

**Money and statutory consequences are configuration too, not code:** the OT earning
component declares its statutory basis flags (`pf_wage: true`, `esi_wage: true`,
`bonus_wage: false`, …) on the component artefact, so no statutory code inspects the string
"Overtime". *(SmartPRS 2 hard-codes the OT hourly rate as `ctc/12/26/8` in **two** places
and writes the literal line name `'Overtime'` — `A-payroll.md` Q4, Q8 rows 11 and 13.)*

---

### 127.7 — "Agents eligible for OT; Managers not"

**Same artefact `OT-ELIGIBILITY`, a different scope dimension** — and this is the case that
proves the precedence model earns its keep.

**Studio:** S4 Attendance → Rules → Overtime → Eligibility.

Naive authoring (two independent artefacts) produces the Pune-agent spill worked through in
VI.4.3. The **correct** stored configuration, which the Studio's impact preview steers the
consultant toward:

```yaml
artefact_key: OT-ELIGIBILITY

# tenant default: nobody
version: { version_no: 1, effective_from: 2026-04-01, scope: {}, priority: 0,
           payload: { eligible: false } }                                   # spec 0

# Hyderabad agents only
version: { version_no: 3, effective_from: 2026-04-01,
           scope: { branch:      IN [HYD],
                    designation: IN [AGENT, SR_AGENT] },                    # spec 530
           priority: 0,
           payload: { eligible: true, multiplier_ref: OT-RATE-STD, … } }

# Managers are never eligible, anywhere — belt and braces, and it documents intent
version: { version_no: 4, effective_from: 2026-04-01,
           scope: { designation: IN [MANAGER, SR_MANAGER] },                # spec 350
           priority: 0,
           payload: { eligible: false } }
```

Resolution table, verified by the Studio's "who resolves to what?" view:

| Employee | Matches | Winner | Result |
|---|---|---|---|
| Agent, Hyderabad | v1 (0), v3 (530) | **v3** | eligible |
| Agent, Pune | v1 (0) | **v1** | not eligible ✓ |
| Manager, Hyderabad | v1 (0), v4 (350) | **v4** | not eligible ✓ |
| Manager, Pune | v1 (0), v4 (350) | **v4** | not eligible |
| Team Leader, Hyderabad | v1 (0) | **v1** | not eligible — *is that intended?* |

**R-VI-150** The Studio renders exactly this table for a sample of the workforce before
publication, and highlights rows resolving to the tenant default, because "an employee
class nobody wrote a rule for" is the failure mode this design must surface rather than
hide. *(SmartPRS 2's late-policy scope has no designation dimension at all, so this
requirement is inexpressible — `B-attendance.md` §5.2 item 23.)*

---

### 127.8 — "show HRA on the payslip; hide Employer PF"

**Artefact:** `template` (payslip) · key `PAYSLIP-STD`
**Studio:** S10 Templates & Documents → Payslip

```yaml
artefact_type: template
template_kind: payslip
artefact_key:  PAYSLIP-STD
version:
  version_no:     9
  effective_from: 2026-04-01
  scope:
    company: IN [AMETECS-CORP]           # per-company payslip layout, spec 100
  payload:
    page:    { size: A4, orientation: portrait, margins_mm: [15,15,15,15] }
    branding_ref: BRAND-AMETECS          # letterhead, logo, colours — a separate artefact
    sections:
      - kind: header
        blocks: [company_block, employee_block, period_block]
      - kind: earnings
        group_by: component_group        # groups are DATA (Fixed / Variable / Reimbursement)
        lines:
          - { component_code: BASIC,  visible: true,  label_override: null }
          - { component_code: HRA,    visible: true,  label_override: null }   # ← shown
          - { component_code: CONV,   visible: true }
          - { component_code: SPL,    visible: true }
        show_unlisted_components: true   # a new component appears without editing this
      - kind: deductions
        lines:
          - { component_code: PF_EE,  visible: true }
          - { component_code: PT,     visible: true }
          - { component_code: TDS,    visible: true }
      - kind: employer_contributions
        visible: false                   # ← the whole section hidden
        lines:
          - { component_code: PF_ER,  visible: false }   # ← explicitly hidden
          - { component_code: ESI_ER, visible: false }
          - { component_code: EDLI,   visible: false }
      - kind: totals
        show_ytd: true
        amount_in_words: true
        number_format: { grouping: indian, decimals: 2 }
      - kind: footer
        blocks: [explain_qr, disclaimer]
    explainability:
      per_line_how_calculated: true      # the "How was this calculated?" affordance
```

**Two properties that matter:**

1. **Everything is by component `code`, never by name.** Renaming "HRA" to "House Rent
   Allowance" changes only the label. *(In SmartPRS 2 the payslip view, the approval sheet
   and the wage register all read literal keys `'Basic'`, `'HRA'`, `'PF'`, `'ESI'`,
   `'Professional Tax'`, `'TDS'`, and the **approval sheet a manager signs shows ₹0** for any
   company that renamed its components — `A-payroll.md` Q4. Worse, the ESI half-year lock is
   a substring search for `esi` in the stored deduction key, so a rename silently disables a
   statutory lock. None of this is replicated.)*
2. **`show_unlisted_components: true`** means adding a component does not require editing
   every template — the default is to show it, and hiding is explicit. The alternative
   (`false`) is offered for statutory registers where the column set is fixed.

**Hiding is presentation only.** Hiding employer PF does not stop it being computed, stored,
reported to the statutory register, or included in CTC. The Studio says so on the field, in
those words, because an administrator who believes "hide" means "do not deduct" will
otherwise discover the difference in a tribunal.

---

### 127.9 — "add a LinkedIn Profile field"

**Artefact:** `field_definition` · entity `employee` · key `linkedin_url`
**Studio:** S3 People Data Model → Fields → Employee → New field
**Mechanism:** the Entity Schema Engine (spine S9). **No DDL. No column. Law 2 holds.**

```yaml
artefact_type: field_definition
artefact_key:  employee.linkedin_url
version:
  version_no:     1
  effective_from: 2026-09-01
  scope: {}
  payload:
    entity_key:    employee
    field_key:     linkedin_url
    storage_kind:  entity_field_value          # a typed row, never a column
    data_type:     Text
    label:         "LinkedIn Profile"
    help_text:     "Full profile URL, e.g. https://www.linkedin.com/in/…"
    group:         "Professional"              # an existing field group
    display_order: 240
    default_expr:  null
    is_system:     false
    pii_class:     identifying
    encryption:    none
    masking:       none
    retention_class: EMP-STANDARD
    validation_expr: >
      value = NULL OR value MATCHES '^https://([a-z]{2,3}\.)?linkedin\.com/in/[A-Za-z0-9_-]{3,100}/?$'
    max_length:    200
    uniqueness_scope: none
    value_set_ref: null
```

Plus surface rules — **one field definition, thirteen surfaces**:

```yaml
artefact_type: surface_rule
artefact_key:  employee.linkedin_url.surfaces
version:
  payload:
    - { surface: employee_directory,  role: ALL,        visibility: hidden }
    - { surface: employee_edit,       role: HR_ADMIN,   visibility: write,  required: never }
    - { surface: employee_edit,       role: MANAGER,    visibility: read }
    - { surface: ess,                 role: EMPLOYEE,   visibility: write,  required: never }
    - { surface: self_onboarding,     role: CANDIDATE,  visibility: write,  required: never }
    - { surface: bulk_import,         role: HR_ADMIN,   visibility: write }
    - { surface: bulk_export,         role: HR_ADMIN,   visibility: read }
    - { surface: public_api,          role: API,        visibility: read }
    - { surface: mobile,              role: EMPLOYEE,   visibility: write }
    - { surface: reports,             role: HR_ADMIN,   visibility: read }
    - { surface: templates,           role: ALL,        visibility: read }   # {{employee.linkedin_url}}
    # payslip, recruitment_conversion, mss: omitted → default hidden
```

**What this single artefact pair automatically produces**, with no further work:

the field on the employee form in the right group and order · the ESS field · the
self-onboarding field · a column in the bulk import template *and* its validation · a column
in the bulk export · a filter and column in the report builder · a `{{employee.linkedin_url}}`
placeholder in every template with autocomplete · a property in the OpenAPI schema for
`/api/v3/employees` · field-level audit on change · inclusion in the DPDP data-subject export
· masking and retention behaviour per its declared classes.

**SmartPRS 2 comparison — this is the headline number.** Adding one employee field requires
a row in `EmployeeFieldRules::FIELDS` plus **6–16 further edit sites**, and the file's own
header documents the procedure as *"add one row to FIELDS, add it to the parity migration"*.
There is **no custom-field capability of any kind**: no custom-field table, no EAV, no JSON
attribute bag, no per-tenant schema (`D-employee-master.md` §5.2 items 1–2 and Q2;
`J-database.md` B3). Meanwhile ~60 columns and 7 tables are created at runtime by controllers,
so the schema depends on which screens have been opened (`A-payroll.md` §5.2 item 60).

---

### 127.10 — "make PCC mandatory during onboarding"

Two artefacts, because "mandatory" means two different things and the Studio separates them.

**(a) The field must be supplied on the onboarding surface.**
**Artefact:** `surface_rule` for `employee.pcc_certificate_no` and its document.
**Studio:** S3 People Data Model → Fields → Employee → PCC → Surfaces.

```yaml
artefact_type: surface_rule
artefact_key:  employee.pcc_certificate_no.surfaces
version:
  version_no:     3
  effective_from: 2026-10-01
  scope:
    employee_category: IN [FIELD]        # only field staff, spec 320
  payload:
    - surface: self_onboarding
      role: CANDIDATE
      visibility: write
      required: always                   # ← the change
    - surface: employee_edit
      role: HR_ADMIN
      visibility: write
      required: conditional
      required_when: "employee.employee_category = 'FIELD'"
```

and the accompanying document requirement:

```yaml
artefact_type: document_type
artefact_key:  PCC
version:
  payload:
    label: "Police Clearance Certificate"
    required_on: [self_onboarding, onboarding_checklist]
    required_when: "employee.employee_category IN ['FIELD','AGENT']"
    accepted_mime: [application/pdf, image/jpeg, image/png]
    max_size_mb:   10
    expiry:        { has_expiry: true, alert_days_before: [90, 30, 7] }
    verification:  { required: true, verifier_permission: compliance.verify }
```

**(b) The onboarding process must not complete without it.**
**Artefact:** `workflow` · key `ONBOARDING`
**Studio:** S9 Workflows → Onboarding.

```yaml
    nodes:
      - id: candidate_submit
        kind: submit
        gate: >
          field_present('pcc_certificate_no')
          AND document_present('PCC')
        gate_message: >
          "A Police Clearance Certificate number and document are required before
           you can submit. Upload it in the Documents step."
      - id: hr_verify_pcc
        kind: approval
        approver: { kind: role, role: COMPLIANCE_OFFICER }
        required_attachment: PCC
        required_comment: true
```

**Why two artefacts and not one.** A required *field* stops a form submitting. A required
*step* stops a process completing. A customer who wants the candidate to be able to submit
and HR to chase the PCC afterwards configures (a) as `required: never` and keeps (b) — a
different, entirely reasonable policy, expressible without code.

**SmartPRS 2 comparison.** `EmployeeFieldRules::FIELDS` has **no `req` key at all**; the only
server-side requirement anywhere on the employee record is `name`
(`D-employee-master.md` §5.2 item 4). The self-onboarding portal's HTML says "Required" and
`submit()` does not check (`D-…` §5.2 item 35). The DRA/PCC mandatory-on-new-hire rule that
does exist is client-side JavaScript at `AppController.php:1917-1928` (`K-…` §5.2 item 16).

---

### 127.11 — "send the absent alert to the employee and to branch HR"

**Artefact:** `notification_subscription` · event `attendance.day.marked_absent`
**Studio:** S14 Notifications → Events → Attendance → Marked absent

```yaml
artefact_type: notification_subscription
artefact_key:  ABSENT-ALERT
version:
  version_no:     2
  effective_from: 2026-09-01
  scope:
    branch: IN [HYD, PUN]                # spec 180
  payload:
    event:      attendance.day.marked_absent
    condition:  "day.is_working_day AND NOT leave.type IN ['SL','CL','EL']"
    audiences:
      - kind: subject_employee                         # ← the employee
        channels: [in_app, email, whatsapp]
        template_ref: TPL-ABSENT-EMPLOYEE
      - kind: role_in_org_scope                        # ← branch HR
        role: HR_EXECUTIVE
        scope_from_subject: branch                     # HR of THE EMPLOYEE'S branch
        channels: [in_app, email]
        template_ref: TPL-ABSENT-HR
        digest: { mode: daily, send_at: '09:30', timezone_from: tenant }
      - kind: reporting_manager
        level: 1
        channels: [in_app]
        template_ref: TPL-ABSENT-MANAGER
        condition: "agg.streak(condition: 'absent', direction: backward) >= 2"
    suppression:
      honour_do_not_contact: true
      quiet_hours: { from: '21:00', to: '08:00', channels: [whatsapp, sms] }
    rate_limit: { max_per_employee_per_day: 1 }
```

**Four things that are configuration here and are code in SmartPRS 2:**

| | SmartPRS 3 | SmartPRS 2 |
|---|---|---|
| Which events notify | `event` on a subscription artefact | every trigger is a hard-coded call site (`E-…` B-55) |
| Who receives | `audiences[]`, resolved dynamically from the subject's org position | hard-coded per call site (`E-…` B-56) |
| Which channels | `channels[]` per audience | `in_app`/`email` booleans as literals at each call site; **WhatsApp is never selected by any HR workflow** (`E-…` B-57) |
| Wording | `template_ref` → a `template` artefact with placeholders | subject, heading, intro and label set are PHP literals per call site (`E-…` B-58) |

Plus: per-user opt-out, quiet hours, digesting and a do-not-contact register — none of which
exist in SmartPRS 2 in any form (`E-…` B-59; `CONSOLIDATED-REQUIREMENTS` H-62).

---

### 127.12 — "change a PF or PT statutory rule"

This one splits into three genuinely different cases, and conflating them is how SmartPRS 2
ended up with a PT slab in a PHP `private const` (`A-payroll.md` §5.2 item 24).

#### Case A — the *statutory value* changes (a PT slab is amended by a state)

**Not tenant configuration. Not an application release.** It is a **signed rule pack**
(spine S12), authored by Ametecs' compliance owner.

```yaml
rule_pack:
  pack_id:        IN-TG-PT
  jurisdiction:   IN-TG
  statute:        "<statute name>"
  pack_version:   2026.5
  effective_from: 2026-10-01
  published_at:   2026-09-12T11:04:00Z
  signature:      <Ed25519 over the canonicalised content>
  public_key_id:  ametecs-rulepack-2026
  content:
    - rule_code:   PT.SLAB
      calculation: "SLAB(statutory.pt_gross, 'PT-TG-2026-5', period.end)"
      eligibility: "org.state = 'TG' AND employee.pt_applicable"
      slabs:
        table_code: PT-TG-2026-5
        bands: [ { from: 0, to: <from rule pack>, amount: <from rule pack> }, … ]
      due_dates:        [ … ]
      report_mappings:  { form: "<form>", columns: [ … ] }
      source_reference: "<gazette / notification reference>"
      floor_severity:   block
```

**Customer experience:** download → verify signature → validate (V10 runs on every slab
table in the pack) → **preview the diff and its impact** (VI.10, `exact_replay` against last
period) → administrator accepts, or an auto-accept policy applies → activates on
01-Oct-2026. **No deployment. No application upgrade.**

*This replaces the situation in which a slab lives in a PHP `private const` and reaching 200
on-premise customers means 200 manual application upgrades (spine S12;
`F-statutory-compliance.md` §5.2 items 10–12).*

#### Case B — the *company's own* PF policy changes (an enhanced policy above the floor)

**Tenant configuration**, subject to the floor check.
**Artefact:** `statutory_company_policy` · **Studio:** S7 Statutory → Company Policy.

```yaml
artefact_type: statutory_company_policy
artefact_key:  PF-POLICY-AMETECS
version:
  version_no:     4
  effective_from: 2026-10-01
  scope:
    establishment: IN [HYD-U1]           # spec 120
  payload:
    head: PF
    employee_rate_pct:        12.00          # ≥ floor from rule pack IN-EPF
    employer_rate_pct:        12.00
    restrict_to_wage_ceiling: false          # pay on full wages — an improvement
    vpf_permitted:            true
    vpf_max_pct:              88.00
    admin_charges_borne_by:   employer
    exclude_on_stages:        [INTERN]       # declared per head, per stage — NOT assumed
    exclude_on_categories:    []
    wage_basis:               "components where component.pf_wage = true"
```

Publication runs V19 against `IN-EPF v2026.4`. Compliant values publish; a breach follows
VI.13's severity model.

**Two defects explicitly not replicated:** SmartPRS 2 hard-codes *which* deduction heads
probation and internship suppress — PF, PT and TDS are suppressed and ESI, LWF and Conveyance
are hardcoded as still applying (`A-payroll.md` §5.2 item 27) — and keys the suppression on
the two literal strings `'probation'` and `'internship'` (item 28). Here the suppression is
declared per head against a `employment_stage` value set the tenant owns.

#### Case C — *applicability* changes (this establishment is now PF-covered)

**Artefact:** `statutory_applicability` · **Studio:** S7 Statutory → Applicability.

```yaml
artefact_type: statutory_applicability
artefact_key:  APPLICABILITY-HYD-U1
version:
  version_no:     2
  effective_from: 2026-11-01
  scope:
    establishment: IN [HYD-U1]
  payload:
    - { head: PF,  applicable: true,  registration_no: "<registered>",
        registered_from: 2026-11-01, basis: employee_count_threshold }
    - { head: ESI, applicable: true,  registration_no: "<registered>", … }
    - { head: PT,  applicable: true,  state: TG, registration_no: "<registered>" }
    - { head: LWF, applicable: true,  state: TG, periodicity: <from rule pack> }
    - { head: BONUS, applicable: true }
    - { head: GRATUITY, applicable: true }
```

**SmartPRS 2 comparison:** `pf_applicable` and `esi_applicable` exist per employee and are
**inert**; there is **no establishment or registration entity at all** — no PF code, no ESI
code, no PT registration, no S&E registration anywhere in the schema; and there is **no
jurisdiction model** (`F-statutory-compliance.md` §5.2 items 24–26).

---

### 127.13 — Summary scorecard

| # | Requirement | Artefact edited | Studio section | Code change? |
|---|---|---|---|---|
| 1 | 3 late arrivals > 10 min = half day | `rule LATE-3X-HALFDAY` | S4 Attendance → Rules | **No** |
| 2 | 2 weekly offs instead of 1 | `weekly_off_pattern WO-STANDARD` | S2 Calendar | **No** |
| 3 | 2nd and 4th Saturday off | `weekly_off_pattern` → saturday_rule | S2 Calendar | **No** |
| 4 | Include Sunday in sandwich | `sandwich_policy` → bridging_day_types | S5 Leave | **No** |
| 5 | Exclude Sick Leave from sandwich | `sandwich_policy` → excluded_leave_types | S5 Leave | **No** |
| 6 | Hyderabad gets OT, Pune does not | `rule OT-ELIGIBILITY`, branch-scoped | S4 Attendance → Rules | **No** |
| 7 | Agents get OT, Managers do not | `rule OT-ELIGIBILITY`, designation-scoped | S4 Attendance → Rules | **No** |
| 8 | Show HRA, hide Employer PF | `template PAYSLIP-STD` | S10 Templates | **No** |
| 9 | Add LinkedIn Profile field | `field_definition` + `surface_rule` | S3 People Data Model | **No** |
| 10 | PCC mandatory during onboarding | `surface_rule` + `document_type` + `workflow ONBOARDING` | S3 + S9 | **No** |
| 11 | Absent alert to employee and branch HR | `notification_subscription ABSENT-ALERT` | S14 Notifications | **No** |
| 12a | A PT slab is amended | **rule pack** `IN-TG-PT` | S7 Statutory → Rule Packs (accept) | **No** — content release |
| 12b | Company PF policy changes | `statutory_company_policy` | S7 Statutory → Company Policy | **No** |
| 12c | Establishment becomes PF-covered | `statutory_applicability` | S7 Statutory → Applicability | **No** |

**R-VI-151** All fourteen rows of this table are **acceptance tests** for v3.0. Each is
implemented as an automated end-to-end test that authors the artefact through
`/api/v3/config`, publishes it, runs the relevant engine, and asserts the exact resulting
value — with no application code deployed between authoring and effect. A row that cannot be
demonstrated this way is a failed acceptance, not a documentation problem.

---

## VI.17 Data model

Specified to the Section 123 standard. Conventions are Part XXVIII's
(`snake_case`; `BIGINT UNSIGNED` PKs; `BINARY(16)` UUIDv7 public ids; `DECIMAL(18,4)` money;
no database `ENUM`; `tenant_id` first in every unique index; `ON DELETE RESTRICT` by default;
`row_version` on every mutable row). The **typed payload tables** (`cfg_*`) belong to their
owning module parts; this part defines the spine that carries them and registers them.

Column notation: `PK` primary key · `FK→` foreign key · `U` participates in a unique
constraint · `IX` indexed · `NN` not null · PII class per R-XXVIII-054.

---

### VI.17.1 `platform_scope_dimensions` — the precedence weights *(platform-owned)*

Purpose: the fixed weight table of spine S3.2, as data rather than as a constant, so an
unusual customer hierarchy can be accommodated by Ametecs without code — and **not**
tenant-editable (R-VI-020).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `dimension_key` | VARCHAR(40) | NN | — | U · `employee`, `team`, `branch`, … |
| `weight` | INT UNSIGNED | NN | — | 1000 … 0; U (two dimensions may not share a weight) |
| `label` | VARCHAR(80) | NN | — | |
| `source_kind` | VARCHAR(20) | NN | — | `org_tree` \| `classification` \| `subject` |
| `is_active` | TINYINT(1) | NN | 1 | |
| `introduced_in_version` | VARCHAR(20) | NN | — | platform version |

Tenant scope: **platform** (no `tenant_id`) · History: none; changes are migrations
· Soft delete: none · Audit: `full`, platform ledger · PII: none.

---

### VI.17.2 `config_artefact_types` — the registry *(platform-owned)*

Purpose: declares every configurable concept, its payload table, its lifecycle properties,
its permissions and its retro policy. **The single point at which a new configurable concept
is added** (Part V R-V-050).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `artefact_type` | VARCHAR(60) | NN | — | U · e.g. `rule`, `formula`, `weekly_off_pattern` |
| `module` | VARCHAR(40) | NN | — | owning bounded context (Part V) |
| `payload_table` | VARCHAR(64) | YES | NULL | the `cfg_*` table; NULL where the payload is genuinely free-form JSON |
| `payload_schema_ref` | VARCHAR(120) | YES | NULL | JSON-schema id for the free-form portion |
| `is_high_risk` | TINYINT(1) | NN | 0 | forces diff + approval (S4) |
| `is_continuous` | TINYINT(1) | NN | 0 | gaps rejected (V04) |
| `is_scopable` | TINYINT(1) | NN | 1 | a few types are tenant-singleton |
| `retro_policy` | VARCHAR(30) | NN | `future_only_by_default` | VI.5.3 |
| `perm_author` / `perm_submit` / `perm_approve` / `perm_publish` | VARCHAR(60) | NN | — | permission keys (S13) |
| `approval_workflow_key` | VARCHAR(60) | YES | NULL | default workflow for T5 |
| `default_priority` | INT | NN | 0 | |
| `exportable` | TINYINT(1) | NN | 1 | 0 for credential-bearing types |
| `feature_key` | VARCHAR(60) | YES | NULL | entitlement gate (V18) |
| `retention_class_code` | VARCHAR(40) | NN | — | FK→ `cfg_retention_classes` |

Tenant scope: **platform** · History: migrations only · Audit: platform ledger · PII: none.

---

### VI.17.3 `config_artefacts` — the artefact root

Purpose: the stable identity of a configurable thing. Versions hang off it.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | U(`uuid`) · UUIDv7; the only id on any external surface |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK→ `tenants(id)` RESTRICT |
| `artefact_type` | VARCHAR(60) | NN | — | FK→ `config_artefact_types(artefact_type)` RESTRICT |
| `artefact_key` | VARCHAR(80) | NN | — | **U(`tenant_id`,`artefact_type`,`artefact_key`)** |
| `name` | VARCHAR(160) | NN | — | |
| `business_description` | TEXT | NN | — | mandatory (R-VI-046) |
| `owning_module` | VARCHAR(40) | NN | — | denormalised from the type for query locality |
| `origin_pack_id` | BIGINT UNSIGNED | YES | NULL | FK→ `policy_pack_versions(id)` RESTRICT |
| `origin_state` | VARCHAR(20) | NN | `local` | `local` \| `pristine` \| `forked` \| `detached` (R-VI-115) |
| `lifecycle_state` | VARCHAR(20) | NN | `active` | `active` \| `retired` — retirement is blocked by V05 |
| `row_version` | INT UNSIGNED | NN | 1 | |
| `created_by` / `created_at` / `updated_at` | | | | standard |

Indexes: `IX(tenant_id, artefact_type, lifecycle_state)`, `IX(tenant_id, origin_pack_id)`,
FULLTEXT(`name`,`business_description`) for VI.6.4 search.
Tenant scope: tenant · History: in versions · Soft delete: **none** — retirement is a state
· Audit: `full` · PII: none.

---

### VI.17.4 `config_versions` — the unit of publication

Purpose: spine S4's version row. **The most important table in SmartPRS 3.**

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | U |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK→ `tenants(id)` RESTRICT |
| `artefact_id` | BIGINT UNSIGNED | NN | — | FK→ `config_artefacts(id)` RESTRICT |
| `version_no` | INT UNSIGNED | NN | — | **U(`tenant_id`,`artefact_id`,`version_no`)** |
| `status_code` | VARCHAR(30) | NN | `draft` | FK→ `status_values` (Part XXVIII XXVIII.4). Never an `ENUM` |
| `priority` | INT | NN | 0 | S3.2 tie-break |
| `specificity` | INT UNSIGNED | NN | 0 | **computed at publish** from the scope; stored so resolution never recomputes it |
| `scope_json` | JSON | NN | `{}` | the authored form; the *index* is `config_version_scopes` |
| `scope_hash` | BINARY(32) | NN | — | canonicalised scope digest; IX, used for cache keys and V02 identity |
| `effective_from` | DATE | NN | — | |
| `effective_to` | DATE | YES | NULL | NULL = open-ended |
| `activation_at` | DATETIME(3) | YES | NULL | scheduled publication (R-VI-012) |
| `payload_row_id` | BIGINT UNSIGNED | YES | NULL | id in the type's `cfg_*` table |
| `payload_json` | JSON | YES | NULL | only where the type declares no payload table |
| `checksum` | BINARY(32) | YES | NULL | SHA-256 over canonical payload+scope+dates; set at publish |
| `change_set_id` | BIGINT UNSIGNED | YES | NULL | FK→ `config_change_sets(id)` RESTRICT |
| `sandbox_id` | BIGINT UNSIGNED | YES | NULL | FK→ `config_sandboxes(id)` CASCADE; non-NULL = invisible to production |
| `reason` | VARCHAR(500) | YES | NULL | mandatory for employee-scoped and back-dated versions |
| `change_note` | TEXT | YES | NULL | |
| `diff_ref` | BIGINT UNSIGNED | YES | NULL | the rendered diff attached at submit (R-VI-091) |
| `impact_ref` | BIGINT UNSIGNED | YES | NULL | the impact preview attached at submit |
| `workflow_instance_id` | BIGINT UNSIGNED | YES | NULL | FK→ workflow instances RESTRICT |
| `compliance_exception_id` | BIGINT UNSIGNED | YES | NULL | FK→ `compliance_exceptions(id)` RESTRICT (VI.13) |
| `superseded_by_version_id` | BIGINT UNSIGNED | YES | NULL | FK→ self RESTRICT |
| `superseded_reason` | VARCHAR(40) | YES | NULL | `successor` \| `rollback` \| `retro_correction` |
| `created_by`,`created_at`,`submitted_by`,`submitted_at`,`approved_by`,`approved_at`,`published_at`,`published_by` | | | | actor columns are `BIGINT UNSIGNED FK→ users(id)` — **never a name string** |
| `origin_import_item_id` | BIGINT UNSIGNED | YES | NULL | provenance (R-VI-106) |
| `row_version` | INT UNSIGNED | NN | 1 | |

Constraints and indexes:

- `CHECK (effective_to IS NULL OR effective_to >= effective_from)`
- **Resolution index:** `IX(tenant_id, artefact_id, status_code, effective_from, effective_to, specificity DESC, priority DESC)`
- `IX(tenant_id, status_code, activation_at)` — the scheduler's query
- `IX(tenant_id, effective_from)` — the Scheduled Changes board
- `IX(sandbox_id)`; `IX(change_set_id)`; `IX(scope_hash)`
- Overlap (V02) is enforced by the publish transaction under a row lock on the artefact
  (Part XXVIII R-XXVIII-013), because MySQL 8 has no exclusion constraint. **This is stated
  explicitly because it is the one invariant that cannot be a declarative constraint**, and
  it therefore carries a nightly RP6 sweep as a second line of defence.

Tenant scope: tenant · History: **this table is the history** · Soft delete: none · Audit:
`full` with old→new payload diff · PII: `none` (payloads may reference employees by id;
employee-scoped versions inherit the employee's PII handling).

---

### VI.17.5 `config_version_scopes` — the resolution index

Purpose: the normalised, indexed form of `scope_json`. Resolution never parses JSON.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `version_id` | BIGINT UNSIGNED | NN | FK→ `config_versions(id)` **CASCADE** (composition; the only CASCADE in this part) |
| `dimension_key` | VARCHAR(40) | NN | FK→ `platform_scope_dimensions(dimension_key)` RESTRICT |
| `dimension_value_id` | BIGINT UNSIGNED | YES | FK→ the dimension's own table, polymorphic by `dimension_key`; NULL where the value is a code |
| `dimension_value_code` | VARCHAR(60) | YES | for classification dimensions held as value-set codes |
| `weight` | INT UNSIGNED | NN | denormalised from the dimension for the sum |

Unique: `U(tenant_id, version_id, dimension_key, dimension_value_id, dimension_value_code)`.
Indexes: `IX(tenant_id, dimension_key, dimension_value_id)` — the join the resolver drives.
Tenant scope: tenant · Audit: `event-only` (derived from the version) · PII: none.

---

### VI.17.6 `config_change_sets` and `config_change_set_items`

Purpose: a group of versions validated, approved and published **atomically** — a
salary component and the formula that references it; a workflow and the role it routes to;
a promotion package's contents.

`config_change_sets`: `id`, `uuid` U, `tenant_id` FK, `name` NN, `description`,
`status_code` NN (`open`|`submitted`|`approved`|`published`|`abandoned`),
`workflow_instance_id`, `source_kind` (`studio`|`import`|`pack_apply`|`sandbox_promote`),
`source_ref`, `published_at`, `published_by`, actor and timestamp columns, `row_version`.

`config_change_set_items`: `id`, `tenant_id`, `change_set_id` FK CASCADE,
`version_id` FK RESTRICT, `apply_order` INT NN.
Unique: `U(tenant_id, change_set_id, version_id)`.

**R-VI-160** Every version in a change set publishes in one transaction or none does. A
validation failure on any member aborts the whole set. *Test:* a change set of 3 versions,
one of which fails V03, leaves zero published rows.

---

### VI.17.7 `config_dependencies` — the "where is this used?" graph

Purpose: powers VI.6.5, V05, VI.10 stage 3, and the regression fan-out of R-VI-076.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `from_version_id` | BIGINT UNSIGNED | NN | FK→ `config_versions(id)` CASCADE — the dependant |
| `to_artefact_id` | BIGINT UNSIGNED | YES | FK→ `config_artefacts(id)` RESTRICT — resolved reference |
| `to_artefact_type` | VARCHAR(60) | NN | |
| `to_artefact_key` | VARCHAR(80) | NN | kept even when unresolved, so V05 can name the missing thing |
| `edge_kind` | VARCHAR(40) | NN | `references_component` \| `references_field` \| `references_value_set` \| `references_formula` \| `references_rule` \| `references_workflow` \| `references_template` \| `references_report` \| `references_statutory_code` \| `references_leave_type` \| `references_shift` \| `references_org_unit` \| `references_role` |
| `locator` | VARCHAR(120) | YES | where in the payload — `when:line3`, `lines[4].component_code` |
| `is_resolved` | TINYINT(1) | NN | 0 → V05 fires |

Unique: `U(tenant_id, from_version_id, to_artefact_type, to_artefact_key, edge_kind, locator)`.
Indexes: `IX(tenant_id, to_artefact_id)` (reverse lookup — the "used by" panel),
`IX(tenant_id, is_resolved)`.
Written at RP2/RP3 from the parsed AST and typed payload. Audit: `event-only`.

---

### VI.17.8 `config_validation_findings`

Purpose: the durable record of every validation result, so a finding survives a page reload,
appears in search (`broken:true`), and can be acknowledged.

`id`, `tenant_id` FK, `version_id` FK CASCADE, `run_point` (`RP1`…`RP6`),
`finding_code` VARCHAR(8) NN (`V01`…`V22`), `severity` VARCHAR(20) NN,
`message` TEXT NN, `details_json` JSON, `affected_count` INT UNSIGNED,
`affected_sample_ref` VARCHAR(120) (a stored employee-list artefact for download),
`acknowledged_by`, `acknowledged_at`, `acknowledgement_reason` VARCHAR(500),
`override_by`, `override_at`, `override_reason` VARCHAR(500),
`resolved_at`, `created_at`.
Indexes: `IX(tenant_id, version_id)`, `IX(tenant_id, finding_code, resolved_at)`,
`IX(tenant_id, severity, resolved_at)`.
Audit: `full` on override and acknowledgement — these are the rows an auditor reads.

---

### VI.17.9 `config_test_cases` and `config_test_runs`

`config_test_cases`: `id`, `uuid` U, `tenant_id` FK, `artefact_id` FK RESTRICT (nullable for
cross-artefact scenarios), `name` NN, `business_description` NN,
`subject_kind` (`real_employee`|`synthetic`|`cohort`), `subject_json` JSON NN (frozen
attribute set — R-VI-077), `as_of_date` DATE, `period_ref` VARCHAR(20),
`input_json` JSON NN, `expected_json` JSON NN, `tolerance_json` JSON,
`origin_pack_id` FK, `source_simulation_id` FK, `is_active` TINYINT NN default 1,
actor/timestamp columns, `row_version`.
Unique: `U(tenant_id, artefact_id, name)`. Index: `IX(tenant_id, is_active)`.
PII: `subject_json` for a real employee carries `identifying` data → the column is
application-encrypted and the case is included in the employee's DPDP export.

`config_test_runs`: `id`, `tenant_id`, `test_case_id` FK CASCADE, `trigger`
(`publish`|`nightly`|`manual`|`import`|`ci`), `version_id_under_test` FK,
`passed` TINYINT NN, `actual_json` JSON NN, `diff_json` JSON, `duration_ms` INT,
`ran_at` DATETIME(3) NN, `ran_by`.
Indexes: `IX(tenant_id, test_case_id, ran_at DESC)`, `IX(tenant_id, passed, ran_at DESC)`.
Retention: runs retained per `cfg_retention_classes` code `CONFIG-TESTRUN`; cases retained
as long as their artefact.

---

### VI.17.10 `config_sandboxes` and `config_simulations`

`config_sandboxes`: `id`, `uuid` U, `tenant_id` FK, `name` NN
(`U(tenant_id,name)`), `description`, `base_date` DATE NN (the date its overlay is anchored
to), `expires_on` DATE NN, `status_code` (`open`|`promoted`|`expired`|`abandoned`),
`promoted_change_set_id` FK, actor/timestamp columns.
*A sandbox holds no business data (R-VI-071); it holds `config_versions` rows carrying its id.*

`config_simulations`: `id`, `uuid` U, `tenant_id` FK, `kind`
(`attendance`|`leave`|`payroll`|`rule`), `subject_json` JSON NN, `as_of_json` JSON NN,
`config_source` (`published`|`sandbox`|`pinned`), `sandbox_id` FK,
`pinned_version_ids_json` JSON, `input_json` JSON, `result_json` JSON NN,
`trace_json` JSON NN, `compare_with_simulation_id` FK, `duration_ms`,
`ran_by`, `ran_at`.
Index: `IX(tenant_id, kind, ran_at DESC)`.
Retention: 90 days by default (`CONFIG-SIM`), except simulations referenced by a pinned test
case, which are retained with the case.
PII: `subject_json`, `input_json`, `result_json` may carry salary → `sensitive`,
application-encrypted, role-masked, access-audited.

---

### VI.17.11 Packaging: `config_packages`, `config_package_items`, `config_import_runs`, `config_import_items`

`config_packages`: `id`, `uuid` U, `tenant_id` FK (the exporting tenant),
`name` NN, `description`, `package_version` VARCHAR(20) NN, `kind`
(`promotion`|`branch_reuse`|`backup`|`template`), `manifest_json` JSON NN,
`integrity_hash` BINARY(32) NN, `artifact_path` VARCHAR(500) NN (private disk; signed,
expiring URLs only — spine S13), `size_bytes` BIGINT UNSIGNED,
`change_set_id` FK, `exported_by`, `exported_at`, `expires_on`.
`config_package_items`: `id`, `tenant_id`, `package_id` FK CASCADE, `artefact_type`,
`artefact_key`, `version_no`, `item_checksum` BINARY(32), `inclusion`
(`explicit`|`implicit_dependency`), `apply_order`.

`config_import_runs`: `id`, `uuid` U, `tenant_id` FK, `package_uuid` BINARY(16) NN,
`package_name`, `package_version`, `source_env` VARCHAR(40),
`phase` (`parsed`|`planned`|`applied`|`aborted`|`reverted`),
`conflict_mode` (`strict`|`remap`|`fork`), `scope_remap_json` JSON,
`change_set_id` FK, `plan_json` JSON NN, `counts_json` JSON NN
(new/identical/updated/conflicted/skipped/errored), `started_by`, `started_at`,
`completed_at`, `reverted_at`, `reverted_by`.
`config_import_items`: `id`, `tenant_id`, `import_run_id` FK CASCADE,
`artefact_type`, `artefact_key`, `classification`
(`new`|`identical`|`update`|`locally_modified`|`target_ahead`|`missing_dependency`|`scope_unmappable`),
`resolution` (`create`|`skip`|`update`|`keep_target`|`take_source`|`fork`|`merge`|`error`),
`resulting_version_id` FK RESTRICT, `message` TEXT.
Index: `IX(tenant_id, import_run_id)`, `IX(tenant_id, artefact_type, artefact_key)` — this
is how "where did this configuration come from?" is answered (R-VI-106).

---

### VI.17.12 Policy packs: `policy_packs`, `policy_pack_versions`, `tenant_pack_applications`

`policy_packs` *(platform-owned)*: `id`, `uuid` U, `pack_key` VARCHAR(60) U,
`name`, `description`, `vertical` VARCHAR(40), `publisher`
(`ametecs`|`partner`|`tenant`), `is_listed` TINYINT.

`policy_pack_versions` *(platform-owned)*: `id`, `uuid` U, `pack_id` FK RESTRICT,
`pack_version` VARCHAR(20) NN (`U(pack_id, pack_version)`),
`min_platform_version` NN, `required_modules_json` JSON NN,
`required_dimensions_json` JSON NN, `assumed_rule_packs_json` JSON,
`slots_json` JSON NN (the fill-in-the-blanks of VI.12.4),
`manifest_json` JSON NN, `integrity_hash` BINARY(32) NN,
`signature` VARBINARY(256) NN, `public_key_id` VARCHAR(60) NN,
`status_code` (`draft`|`published`|`deprecated`), `deprecated_by_version_id` FK,
`published_at`, `published_by`.

`tenant_pack_applications`: `id`, `tenant_id` FK, `pack_version_id` FK RESTRICT,
`applied_at`, `applied_by`, `slot_values_json` JSON NN,
`change_set_id` FK, `artefacts_created` INT UNSIGNED, `artefacts_updated` INT UNSIGNED,
`test_cases_imported` INT UNSIGNED, `status_code`
(`applied`|`partially_applied`|`reverted`).
Index: `IX(tenant_id, pack_version_id)`.
This is the row that makes a pack update offer possible: it names what the tenant took, and
`config_artefacts.origin_state` names what they have since done to it.

---

### VI.17.13 `compliance_exceptions`

Purpose: the recorded departure from a statutory baseline (VI.13.2), and the register an
auditor reads.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` / `uuid` | | NN | U on uuid |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `rule_pack_id` / `rule_code` | | NN | which floor is departed from |
| `finding_code` | VARCHAR(8) | NN | always `V19` today |
| `establishment_id` | BIGINT UNSIGNED | YES | FK→ org units RESTRICT |
| `scope_json` | JSON | NN | who it covers |
| `legal_basis` | TEXT | NN | min length enforced |
| `statutory_reference` | VARCHAR(300) | NN | |
| `evidence_document_id` | BIGINT UNSIGNED | NN | FK→ documents RESTRICT — **mandatory** |
| `authorised_by` | BIGINT UNSIGNED | NN | FK→ users; must hold `compliance.exception.authorise` |
| `workflow_instance_id` | BIGINT UNSIGNED | NN | maker-checker |
| `effective_from` / `effective_to` | DATE | NN | **both NN** — an open-ended exception cannot be recorded (R-VI-122) |
| `review_on` | DATE | NN | |
| `status_code` | VARCHAR(20) | NN | `active`\|`expired`\|`closed`\|`superseded` |
| `closed_at`,`closed_by`,`closure_reason` | | YES | |

Indexes: `IX(tenant_id, status_code, effective_to)` — drives the expiry sweep;
`IX(tenant_id, rule_code)`.
Audit: `full` · Retention: statutory floor, never purgeable before it · PII: none.

---

### VI.17.14 Entitlement: `feature_catalogue`, `entitlement_grants`, `tenant_feature_states`

`feature_catalogue` *(platform-owned)*: `id`, `feature_key` VARCHAR(60) U, `name`,
`description`, `module` NN, `kind` (`module`|`capability`|`limit`),
`parent_feature_key` FK→ self RESTRICT, `depends_on_json` JSON NN,
`degrades_to_json` JSON, `is_core` TINYINT NN, `introduced_in_version`.

`entitlement_grants` *(platform-owned, but keyed by tenant)*: `id`, `tenant_id` FK RESTRICT,
`feature_key` FK RESTRICT, `granted` TINYINT NN, `limit_json` JSON,
`source` (`plan`|`licence`|`contract_override`), `source_ref` VARCHAR(120),
`signature_ref` VARCHAR(120) (the licence or subscription record it derives from),
`effective_from` DATE NN, `effective_to` DATE, actor/timestamp columns.
Unique: `U(tenant_id, feature_key, effective_from)`. Index:
`IX(tenant_id, feature_key, effective_from, effective_to)`.
**This table is written only by the licensing/subscription subsystem, never by the Studio.**

`tenant_feature_states`: `id`, `tenant_id` FK, `feature_key` FK RESTRICT,
`tenant_enabled` TINYINT NN, `reason` VARCHAR(300), `effective_from` DATE NN,
`changed_by`, `changed_at`, `row_version`.
Unique: `U(tenant_id, feature_key, effective_from)`.
Audit: `full` — a disabled module is a support question six months later.

---

### VI.17.15 `platform_defaults` *(platform-owned)*

Purpose: K1. The value used when nothing else resolves. Shipped with the release, read-only
in every tenant, and **overridden by creating a tenant artefact of the same type**, never by
editing this table.

`id`, `artefact_type` FK RESTRICT, `artefact_key` VARCHAR(80),
`payload_json` JSON NN, `platform_version` VARCHAR(20) NN,
`effective_from` DATE NN, `notes`.
Unique: `U(artefact_type, artefact_key, platform_version, effective_from)`.

**R-VI-161** Every `artefact_type` that any engine resolves must have a platform default, or
must declare `resolution_required: true` on the type — in which case a tenant with no
matching artefact produces a **loud, blocking, named** configuration error rather than a
silent zero. *Test:* for every artefact type, either a default row exists or a fixture
asserts the named error. *(SmartPRS 2's unknown-leave-type default is a silent "paid" —
`A-payroll.md` §5.2 item 15 — and its unknown-state PT fallback is unreachable code —
item 24. Neither shape is permitted.)*

---

## VI.18 API surface

All endpoints are `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed on every
unsafe method, and returning the platform error envelope (spine S17). Public identifiers are
UUIDs only. Every endpoint declares a permission **and** a feature (R-VI-133).

### VI.18.1 Artefacts and versions

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/config/artefact-types` | `config.read` | the registry; drives the Studio's generated forms |
| `GET` | `/config/artefacts` | `config.read` | filters: `type`, `key`, `module`, `status`, `origin_pack`, `q`, `broken` |
| `POST` | `/config/artefacts` | `config.author.{type}` | creates the root plus v1 draft |
| `GET` | `/config/artefacts/{uuid}` | `config.read` | includes `uses` / `used_by` (VI.6.5) |
| `GET` | `/config/artefacts/{uuid}/versions` | `config.read` | |
| `POST` | `/config/artefacts/{uuid}/versions` | `config.author.{type}` | new draft, optionally `from_version` |
| `PATCH` | `/config/versions/{uuid}` | `config.author.{type}` | **draft/testing only**; `If-Match: row_version`; PATCH semantics — only supplied fields change |
| `DELETE` | `/config/versions/{uuid}` | `config.author.{type}` | discard; draft/testing and highest version only |
| `POST` | `/config/versions/{uuid}/validate` | `config.read` | run the catalogue at a named run point; returns findings |
| `POST` | `/config/versions/{uuid}/promote` | `config.author.{type}` | T3 |
| `POST` | `/config/versions/{uuid}/submit` | `config.submit.{type}` | T5; body carries the acknowledged warnings |
| `POST` | `/config/versions/{uuid}/publish` | `config.publish.{type}` | T7; `409` on any blocking finding, with the findings in the envelope |
| `POST` | `/config/versions/{uuid}/rollback` | `config.publish.{type}` | R-VI-013 |
| `POST` | `/config/versions/{uuid}/archive` | `config.archive` | T9 |
| `GET` | `/config/versions/{uuid}/diff?against=` | `config.read` | predecessor \| version uuid \| `baseline` \| `env:<name>` |
| `GET` | `/config/versions/{uuid}/impact` | `config.read` | VI.10; `202` + job for large cohorts |

### VI.18.2 Resolution and explanation

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/config/resolve` | `config.read` | query: `type`, `key`, `employee` (uuid) \| `scope[dim]=…`, `as_of` (**required**). Returns the winning version, its specificity arithmetic, and the runners-up |
| `GET` | `/config/resolve/explain` | `config.read` | the full precedence table of VI.4.2 for a subject and date |
| `GET` | `/config/scheduled-changes` | `config.read` | the S16 board: everything with a future `effective_from` or `activation_at` |
| `GET` | `/config/search` | `config.read` | VI.6.4, with the operator grammar |

**R-VI-170** `GET /config/resolve` **rejects a request without `as_of`** with a `400` and a
message naming the requirement. There is no defaulted-to-today behaviour anywhere in the API
(R-VI-030).

### VI.18.3 Change sets, sandboxes, simulation, tests

| Method | Path | Permission |
|---|---|---|
| `POST` `GET` | `/config/change-sets`, `/config/change-sets/{uuid}` | `config.author` |
| `POST` | `/config/change-sets/{uuid}/publish` | `config.publish` — atomic (R-VI-160) |
| `POST` `GET` `DELETE` | `/config/sandboxes[/{uuid}]` | `config.sandbox` |
| `POST` | `/config/sandboxes/{uuid}/promote` | `config.submit` |
| `POST` | `/config/simulate/{attendance\|leave\|payroll\|rule}` | `config.simulate` + the module's read permission on the subject |
| `POST` | `/config/simulations/{uuid}/pin` | `config.test.author` |
| `GET` `POST` `PATCH` `DELETE` | `/config/test-cases[/{uuid}]` | `config.test.author` |
| `POST` | `/config/test-cases/run` | `config.test.run` |

**R-VI-171** `/config/simulate/*` writes nothing and is safe to call repeatedly. It is
nevertheless rate-limited and cost-bounded per tenant, because a cohort payroll simulation is
expensive; the limit is an entitlement `limit_json` value, not a literal.

### VI.18.4 Packaging, packs, entitlement, compliance exceptions

| Method | Path | Permission |
|---|---|---|
| `POST` | `/config/packages` (export) | `config.export` |
| `GET` | `/config/packages/{uuid}/download` | `config.export` — signed, expiring URL |
| `POST` | `/config/imports` (phase 1+2: parse and plan) | `config.import` |
| `GET` | `/config/imports/{uuid}/plan` | `config.import` |
| `POST` | `/config/imports/{uuid}/apply` | `config.import.apply` — body carries per-item resolutions and the scope remap |
| `POST` | `/config/imports/{uuid}/revert` | `config.import.apply` |
| `GET` | `/config/policy-packs` | `config.read` |
| `POST` | `/config/policy-packs/{uuid}/apply` | `config.pack.apply` — body carries slot values |
| `GET` | `/config/policy-packs/updates` | `config.read` — offers with three-way diffs |
| `GET` | `/config/entitlements` | authenticated | the tenant's **positive** feature set only (R-VI-132) |
| `PATCH` | `/config/entitlements/{feature_key}` | `entitlement.manage` — narrowing only |
| `GET` `POST` `PATCH` | `/compliance/exceptions[/{uuid}]` | `compliance.exception.*` |

### VI.18.5 Cross-cutting API rules

**R-VI-172** Every configuration write endpoint is **idempotent** on `Idempotency-Key`, and
every list endpoint is cursor-paginated with a visible total. *(SmartPRS 2 has zero
`paginate()` calls and silent truncating caps — `M-…` §5.2 item 13.)*

**R-VI-173** No configuration read endpoint ever returns a value whose payload field is
declared `restricted`. Credentials are write-only: they are set through a dedicated
`PUT /config/connectors/{uuid}/credentials` and read back only as
`{ status: 'set', last_rotated_at, fingerprint }`. *(FINDING-H-02: SmartPRS 2 returns SMTP
passwords and provider API keys from read endpoints.)*

**R-VI-174** Configuration read endpoints are role-gated and data-scoped like every other
endpoint (`CONSOLIDATED-REQUIREMENTS` H-54); there is no "configuration is not sensitive"
exemption. Salary structures, statutory policies and connector configuration are among the
most sensitive objects in the system.

---

## VI.19 Explainability

Spine S15 requires that every derived value explain itself. Configuration's contribution to
that is twofold.

### VI.19.1 What the configuration trace contributes to every other trace

**R-VI-180** Every engine trace names, for every decision: the `artefact_type`,
`artefact_key`, `version_no`, `effective_from`, the **specificity and priority that won**,
and — on demand — the runners-up and why they lost. A trace that names a rule without naming
its version is not conformant.

The attendance example is rendered in full in VI.8.4. The payslip equivalent, per line:

```
HRA                                                              ₹6,000.00
  How was this calculated?
    formula      HRA v3      published 12-Mar-2026, effective 01-Apr-2026 →
                 artefact HRA-STANDARD, scope { company: AMETECS-CORP } (spec 100)
                 chosen over HRA-LEGACY v7 (spec 0, tenant default)
    expression   BASIC % 40
    inputs       BASIC = ₹15,000.00
                   from structure "Telangana Collections" v7, line 1
                   which resolved for this employee at spec 580
                   (department 400 + branch 180 = 580)
    arithmetic   ₹15,000.00 × 40% = ₹6,000.00
    rounding     2 decimal places, half-up   (rounding profile MONEY-STD v1)
    statutory    enters PF wage (component flag pf_wage = true)
                 enters ESI wage (esi_wage = true)
                 does not enter bonus wage (bonus_wage = false)
    period       Aug-2026 (21-Jul → 20-Aug), cycle PAY-CYCLE-MONTHLY v6
```

**R-VI-183** A trace shows the **arithmetic**, not only the conclusion — including the
specificity sum that selected each artefact. A trace that states a result without the
substituted values and the operation performed cannot be checked by the person defending it,
and therefore does not satisfy Law 4.

### VI.19.2 Explaining configuration itself

**R-VI-181** `Explain(artefact, version)` — reachable from every artefact page and from every
engine trace — returns:

| Section | Content |
|---|---|
| **What it says** | the payload rendered in business language, from the type's declared renderer, not raw JSON |
| **Who it applies to** | the scope, the specificity arithmetic, the resolved employee count as at a chosen date |
| **When** | the effective range, the predecessor and successor, and the publication history |
| **Why it exists** | `business_description`, `reason`, `change_note` |
| **Who decided** | author, submitter, approver, publisher, the workflow instance, the diff they approved |
| **What it depends on** | the `uses` graph |
| **What depends on it** | the `used_by` graph |
| **What it has produced** | a count of derived records naming this version, with a link to a sample |
| **Findings** | outstanding acknowledgements, overrides and compliance exceptions |

**R-VI-182** The audit ledger row for a configuration publication carries the full old→new
payload diff, not merely the changed field names. *(SmartPRS 2 records field names only, and
`AuditTrail::change()` — which exists and captures old→new — is never called in the
request/approval area at all: `E-…` B-71; `CONSOLIDATED-REQUIREMENTS` H-11.)*

---

## VI.20 Events

Emitted through the transactional outbox (Part V V.9.2). All carry the standard envelope.

| Event | Payload highlights | Principal consumers |
|---|---|---|
| `config.version.created` | artefact uuid/type/key, version_no, actor | audit, Studio activity feed |
| `config.version.promoted` | + findings summary | Studio |
| `config.version.submitted` | + diff ref, impact ref, workflow instance | workflow, notifications |
| `config.version.approved` | + approver, workflow instance | Studio |
| `config.version.published` | + effective range, specificity, priority, superseded version | **resolution cache invalidation**, dependent-draft revalidation, regression fan-out, in-flight run notice, search index, webhook subscribers |
| `config.version.superseded` | + successor | audit |
| `config.version.archived` | | audit |
| `config.version.rolled_back` | + target version, reason | notifications, compliance dashboard |
| `config.validation.failed` | finding codes, severities, affected counts | operations dashboard (RP6), Studio |
| `config.regression.failed` | test case, expected, actual | operations dashboard, author notification |
| `config.change_set.published` | member versions | promotion tracking |
| `config.package.exported` / `.imported` / `.import_reverted` | package uuid, counts | audit, operations |
| `config.pack.applied` / `config.pack.update_available` | pack, version, slot values / three-way diff summary | Studio, notifications |
| `config.simulation.completed` | kind, subject, duration | usage metering |
| `entitlement.changed` | features gained and lost, effective date | UI refresh, scheduler re-evaluation, notifications |
| `compliance.exception.recorded` / `.expiring` / `.expired` | rule code, establishment, dates | compliance dashboard, statutory audit pack |
| `rule_pack.activated` / `.staged` | pack, jurisdiction, effective date, findings | statutory engine, floor re-check (R-VI-124) |

**Consumed** by this area: `org.unit.changed` and `employment.assignment.changed` (invalidate
scope materialisations and re-run V17/V08 for affected artefacts);
`payroll.period.locked` / `.reopened` (V16's guard); `licence.changed` /
`subscription.changed` (write `entitlement_grants`).

**R-VI-190** `config.version.published` is the **only** signal that invalidates the
resolution cache. There is no time-based expiry, because a cache that heals itself in five
minutes is a cache that produced five minutes of wrong payslips.

---

## VI.21 Migration implication

### VI.21.1 What comes across, and how

| SmartPRS 2 source | Becomes | Transformation | Risk |
|---|---|---|---|
| `statutory_configs` (scoped, dated) | `config_versions` of type `statutory_company_policy` and `statutory_applicability` | Direct, one row per scoped/dated override. **This is the only clean migration in the whole configuration area** because the source has the right shape (spine S19 item 6) | Low |
| `statutory_settings` blob (36 keys, undated) | split across ~8 artefact types | Each key mapped to its artefact type and payload field. Given `effective_from` = **the tenant's earliest payroll period**, on the explicit stated assumption that the blob's current value has always applied — which is false but is the only defensible reconstruction, and is recorded as such on every migrated version's `reason` | **High** — see R-VI-200 |
| `salary_components` | `salary_component` + `salary_structure` artefacts | Component identity is preserved by code. **Name-based behaviour is converted to explicit flags**: the `str_contains(code.' '.name,'basic')` Basic test, the DA regex, the `reimburs` substring and the HRA `stripos` become declared `is_basic`, `is_da`, `component_group` and statutory-basis flags, computed once at migration and **presented for consultant confirmation** | **High** — every mapping must be reviewed per tenant |
| `late_policy` rows (company/team/employee scope) | `rule` artefacts, tiered | Each tier becomes a rule with an `agg.count`/`occurrence_index` condition. The tie-broken-by-row-order behaviour cannot be reproduced and must not be: colliding rows are surfaced as a **migration finding** requiring a scope or priority decision | Medium |
| `shifts`, `roster`, `holidays`, `leave_types`, `pay_cycles`, `point_rules`, `incentive_schemes`, `min_wages`, `letters` (templates), `sms_templates`, `wa_templates`, `biometric_configs` | their respective artefact types | Structural; values preserved | Low–Medium |
| The 58 `MasterController` registry types | either a first-class artefact type, or a tenant-defined entity via the entity schema engine (spine S9) | Decided per type at migration design; the generic self-creating all-string tables are **not** carried forward | Medium |
| `app_settings` (greetings, absence, probation, mail, branding) | `notification_subscription`, `template`, `branding`, `connector` artefacts | Structural; **credentials are re-entered, never migrated in plaintext** | Medium |
| `settings.app_state` role→permission blob | `role` + `data_scope` artefacts | **Reconstruction, not migration.** The blob is client-side-only, unenforced and writable by any user, so it is *evidence of intent* and nothing more. Roles are re-designed with the customer | **High** |
| `plans.features`, `Edition::*` constants | `feature_catalogue` + `entitlement_grants` | Blocked on **Q-01/Q-02** (`CONSOLIDATED-DECISIONS`) — whether editions exist in v3 at all | Blocked |

### VI.21.2 What does not come across

| Not migrated | Why |
|---|---|
| The undated-ness itself | Every migrated artefact gets an explicit `effective_from`; there is no "always was" state |
| Row-order tie-breaks | Ambiguity is a configuration error in v3 (S3.2). Colliding configurations become migration findings a consultant must resolve **before** go-live |
| Name-keyed behaviour of any kind | Basic/DA/HRA/reimbursement identification, the `esi` substring statutory lock, roster keyed by employee display name, company matched by name. All become code/id/flag-based |
| The six unreachable settings keys | They were never settable; they are re-authored as real artefacts with real UI |
| Client-side-only configuration | The role matrix, the SPA's duplicated rate table (`AppController:11161`), the client-side PF/ESI/TDS calculators (`:12081-12110`) — these are not configuration, they are duplicated logic, and they are deleted |
| Runtime-created tables and columns | ~49 tables and ~60 columns created by controllers. Their **data** is migrated where it is real; their existence-by-accident is not preserved |
| The fabricated payroll history | `ensurePayrollHistory()` invents twelve months of payslips on first page load, eleven marked `paid`. **Migration must detect and quarantine fabricated runs**, not carry them into a system whose payslips are supposed to be defensible |

**R-VI-200** Every migrated configuration version carries `reason = 'migrated from
SmartPRS 2 <source table/key>'`, a `migration_confidence` marker
(`exact` | `reconstructed` | `assumed`), and — for `reconstructed` and `assumed` — appears on
a mandatory **migration review queue** that must be cleared before the tenant's go-live gate
(Wizard step 18). A migration that silently publishes assumed configuration is not
acceptable, because the assumption becomes an unexplainable payslip three years later.

**R-VI-201** Migration writes **drafts**, not published versions, except for the artefacts
required to reproduce the customer's last closed period. The consultant publishes after
review, and the parallel run (R-VI-063) is the proof.

---

## VI.22 Edge cases and failure states

Unglamorous, specific, and each with a defined behaviour. A blueprint that omits these is
where the ambiguity re-enters.

| # | Situation | Defined behaviour |
|---|---|---|
| E1 | An employee's org assignment changes **mid-period** (transfer on the 12th) | Resolution is per **date**, so the first half of the period resolves the old branch's artefacts and the second half the new. Payroll aggregates per-day resolutions; where an artefact is period-level (a pay cycle, a structure), the type declares `mid_period_rule`: `at_period_start` \| `at_period_end` \| `pro_rata` \| `block`. There is no implicit answer |
| E2 | Two artefacts of the same type match, one scoped `employee` and one scoped `team`, and the employee-scoped one has a **lower** priority | Specificity wins first (1000 > 500). Priority is only consulted on a specificity tie. The Studio warns when an author sets a priority that can never matter |
| E3 | An employee has **no value** for a dimension a scope constrains (no grade assigned) | The version does not match. The employee falls through to a less specific version, ultimately the tenant default. The Wizard's coverage check (VI.7.3) is what catches "23 employees have no grade and therefore no structure" **before** payroll |
| E4 | A version is published with `effective_from` **before** the artefact's earliest existing version | Permitted; it becomes the earliest version and the previously-earliest version's `effective_from` is *not* changed — instead the new version's `effective_to` is closed the day before. If they overlap, V02 blocks and the author must state the intent |
| E5 | A rule's `when` throws (division by zero, an error value from a total function) | SPX functions are total (S5.6): the error value propagates to a **rule evaluation failure**, which is surfaced as a blocking exception on the payroll preview or as a flagged attendance day. It is never swallowed and never treated as `false`. *(SmartPRS 2 destroys 50.5% of its caught exceptions.)* |
| E6 | A rule's node/time budget is exceeded | Same as E5: a named failure, attributed to the rule and version, with the budget in the message. The engine does not return a partial result |
| E7 | A formula references a component that resolves to **no version** for this employee on this date | V05 catches this at publish for *static* references. For a dynamically-resolved reference, the formula returns a typed error and the payroll preview blocks with "component `X` has no version effective 15-Aug-2026 for employee `HYD-0412`" |
| E8 | Two consultants edit the same draft concurrently | Optimistic concurrency (`row_version`): `409` with a field-level diff and a merge screen. No last-writer-wins |
| E9 | A publish transaction fails partway (deadlock, constraint) | Nothing is published; the version stays in `pending_approval`; the failure is recorded as a finding, not as a silent retry. Retry is explicit |
| E10 | Scheduled activation fires while the tenant is in maintenance or its queue worker is stopped | The activation job is durable and idempotent; it runs when the worker returns. **If it runs after `effective_from` has passed**, it publishes with the original `effective_from` (so August still resolves correctly) and raises an operator alert naming the delay |
| E11 | A rule pack activates while a payroll run is in progress | The run holds its snapshot; unaffected. Its exception list gains an informational entry. A run in `preview` must recompute |
| E12 | A rule pack's signature fails verification | Rejected outright. Not warned about, not staged, not partially applied. An operator alert names the pack, the key id and the failure mode. *(Spine S18: installations carry only the public key.)* |
| E13 | The tenant's clock and the platform's differ | Effective dating uses **business dates** in the tenant's declared business timezone; `published_at` is UTC. The two are never compared without conversion. A version whose `effective_from` is "today" is effective from today **in the tenant's timezone** |
| E14 | A cohort impact preview exceeds its budget | Runs as a queued job with progress (R-VI-096). It does not degrade to a sample without saying so; a sampled estimate is labelled as such with its sample size |
| E15 | An import package references a platform version newer than the target | Phase 1 rejects with the required version named. There is no forward-compatibility guess |
| E16 | An import's scope remap maps two source values to one target value | Permitted, but the resulting artefacts may now collide (V03). The plan phase runs the full catalogue against the simulated post-import state, so the collision is shown **before** anything is written |
| E17 | A policy pack update conflicts with a tenant's fork | Three-way diff, per artefact, tenant decides. Never overwritten (R-VI-115/116) |
| E18 | A compliance exception expires while the artefact it covers is still published | The exception's expiry sweep raises the V19 finding again, flags the artefact `broken:true`, notifies the compliance officer, and **blocks the next publication** of that artefact. It does **not** retro-actively unpublish — that would be a worse outcome than a flagged breach |
| E19 | An entitlement is lost at renewal while configuration for it exists | Configuration is retained (R-VI-136), the engine stops evaluating it, the Studio marks it "not entitled", and the artefact is excluded from validation sweeps so it does not generate noise |
| E20 | A tenant disables a module, then imports a package that requires it | V18 rejects at phase 1 with the feature key named |
| E21 | An artefact is scoped to an org unit that is later merged or deleted | V17 fires on the nightly sweep (`broken:true`). Deletion of the unit is itself blocked by the reverse dependency check. A **merge** rewrites nothing automatically; it produces a remediation task listing every artefact to re-scope |
| E22 | Someone attempts to edit a published version directly in the database | `checksum` no longer matches the payload; the nightly integrity sweep raises a `severity: error` finding naming the version, and the audit ledger's hash chain shows no corresponding entry. This is detectable, which is the point |
| E23 | A tenant has 40,000 configuration versions after five years | Resolution is unaffected (the index is on the artefact, not the tenant). Archival (T9) removes superseded versions from resolution once outside the retention horizon. The Studio's default views filter to `published` + `draft` |
| E24 | A sandbox outlives the configuration it was branched from | It expires (VI.8.2). Promoting an expired sandbox is refused; the versions must be rebased onto current published state, and the Studio shows what changed underneath |
| E25 | An employee-scoped exception exists for an employee who has left | The version remains (history is not rewritten) but appears on the standing exceptions report as `subject_exited`, prompting closure. It is never auto-deleted |
| E26 | A formula's dependency graph is acyclic per artefact but cyclic **across scopes** (structure A for Hyderabad references a component whose Pune version references back) | V06 builds the graph **per resolved scope**, not per artefact, precisely to catch this. Where the number of distinct scopes makes exhaustive checking expensive, the check runs over the tenant's actual distinct scope set (tens, not thousands — VI.4.7) |
| E27 | The resolution cache is stale because Redis was flushed | Correctness is unaffected: a cache miss re-resolves from the database. The cache key includes `max_published_version_id`, so a stale entry cannot outlive a publication |
| E28 | An artefact type is removed in a platform release | Its existing versions become `archived` and unresolvable; the release migration is required to supply a replacement mapping or to fail. A type is never silently dropped while tenant data references it |

---

## VI.23 Tests

Scenarios that must have **exact-value** regression tests in CI. Each is written as a
statement that either passes or fails; none is a judgement call.

### VI.23.1 Precedence and resolution

| # | Test |
|---|---|
| T-01 | The four late policies of VI.4.2 resolve to P3 for `HYD-0412` on 14-Aug-2026, with specificity exactly 580 |
| T-02 | An Agent in Pune with artefacts O1–O5 of VI.4.3 resolves to O3 (`eligible = true`) — asserting the **trap**, so that the fix in O6 is a deliberate change and not an accident |
| T-03 | With O5/O6 only, an Agent in Pune resolves `eligible = false` and an Agent in Hyderabad resolves `eligible = true` |
| T-04 | G1 (540, prio 20) beats G2 (540, prio 10); grace resolves to exactly 15 minutes |
| T-05 | Publishing G2 with priority 20 is rejected with finding code `V03`, and the response names both artefacts, both scopes with per-dimension weights, and the count 214 |
| T-06 | A dimension the employee has no value for on the date matches only `ALL` |
| T-07 | A 5,000-employee payroll run issues fewer than 500 configuration resolution queries |
| T-08 | `GET /config/resolve` without `as_of` returns `400` |

### VI.23.2 Effective dating

| # | Test |
|---|---|
| T-10 | Late Policy v2 published 12-Oct-2026 effective 01-Oct-2026 does not change any August value; the August re-run's totals equal the original run's to the paisa |
| T-11 | v1's `effective_to` is exactly 30-Sep-2026 after v2 publishes |
| T-12 | A re-run whose resolved version set differs from the snapshot warns, names each changed version, and refuses to proceed without acknowledgement |
| T-13 | A back-dated `arrears_only` change raises arrears of exactly ₹8,27,150 for 318 employees across 3 periods, with each arrears line carrying its earned period |
| T-14 | The same change with `retro_policy = declare_only` raises zero arrears and changes zero historical records |
| T-15 | A future-dated version is published, resolves for no date before `effective_from`, and resolves from `effective_from` with no further action |
| T-16 | A scheduled activation whose publish-time validation fails does **not** publish, leaves the version in `pending_approval`, and raises an alert |
| T-17 | `recalculate(mode: as_at_snapshot)` reproduces the stored derived record exactly; `mode: as_at_period` adopts the back-dated version |
| T-18 | `recalculate` run twice with identical inputs writes zero changes the second time |
| T-19 | Publishing into a locked period without `config.publish.locked_period` is refused with `V16` |

### VI.23.3 The validation catalogue

**R-VI-210** Every finding code `V01`–`V22` has at least one positive test (it fires when it
should, with the exact message shape) and one negative test (it does not fire when it should
not). Specific exact-value cases:

| # | Test |
|---|---|
| T-20 | A slab table with an overlap, a gap and no open band produces exactly three `V10` findings identifying rows 3, 4 and 5 |
| T-21 | `HRA → BASIC → GROSS → HRA` produces one `V06` finding naming the cycle in that order |
| T-22 | A workflow with an unreachable node and a non-terminating path produces exactly two `V07` findings |
| T-23 | A dynamic-manager approval node with 23 employees lacking a manager produces one `V08` finding with count 23 and a downloadable list of 23 |
| T-24 | Monthly accrual 1.75 against an annual cap of 18.00 produces `V09` |
| T-25 | A structure referencing a component retired 31-Mar-2026, effective 01-Apr-2026, produces `V11`; the same structure effective 01-Mar-2026 does not |
| T-26 | A field `required: always` and `visibility: hidden` on one surface produces `V12` |
| T-27 | A template with `{{employee.probation_period}}` produces `V13` with a "did you mean" suggestion |
| T-28 | `pf_employee_rate = 99` produces `V14`; `= 12` does not |
| T-29 | Publishing a formula whose change breaks a dependent structure's pinned case produces `V15` and blocks; the response shows expected and actual |
| T-30 | Retiring a component used by 4 published artefacts produces `V05` reverse, naming all 4 |
| T-31 | A report definition selecting `connector.api_key` produces `V20` |
| T-32 | An identical finding is produced with the identical code by the Studio, the API, the import path and the pack applier for the same input |

### VI.23.4 Lifecycle and integrity

| # | Test |
|---|---|
| T-40 | An `UPDATE` on a published version's payload is rejected at the repository layer |
| T-41 | A change set of 3 versions, one failing V03, publishes none |
| T-42 | Concurrent publication of two overlapping versions: one succeeds, one gets `409`; the database never holds two overlapping published versions for one scope |
| T-43 | Rollback creates a new version; the rolled-back version remains resolvable for dates in its original range |
| T-44 | Archiving a version referenced by a payslip inside the retention horizon is refused with `V22` |
| T-45 | A tampered payload (checksum mismatch) is detected by the nightly sweep |
| T-46 | Every derived record type stores its contributing version ids; a sample of 1,000 year-old payslip lines all render a complete trace |

### VI.23.5 Packaging, packs, entitlement

| # | Test |
|---|---|
| T-50 | Exporting and re-importing a package into the same tenant reports 100% identical and writes zero versions |
| T-51 | An exported package contains no credential value, no employee record and no audit row — asserted by scanning the artefact for every `restricted` field key and for any employee identifier |
| T-52 | Importing a package with 200 artefacts and 3 conflicts writes nothing until all 3 are resolved |
| T-53 | Applying "BPO Standard" then "Telangana Standard" then "Collections Agency" produces zero unresolved conflicts and all pack regression cases pass |
| T-54 | A pack update against a forked artefact offers a three-way diff and, on "keep mine", changes nothing |
| T-55 | A pack applied to tenant A leaves tenant B's copy of the same pack's artefacts untouched |
| T-56 | Setting `tenant_enabled = true` on an ungranted feature has no effect on `effective()` |
| T-57 | Disabling Attendance is refused, naming Payroll, Overtime, Comp-Off and Attendance Reports |
| T-58 | Disable a module, run payroll, re-enable, re-run the same period with the same inputs → identical result |
| T-59 | Every route, scheduled task and queue job carries an explicit feature declaration (CI sweep) |
| T-60 | An unentitled endpoint is absent from the tenant's generated OpenAPI document and returns `403` when called |

### VI.23.6 Statutory boundary

| # | Test |
|---|---|
| T-70 | A company policy below a `block`-severity floor cannot be published by any role, including platform super-admin |
| T-71 | A policy below an `exception_required` floor publishes only with a complete, time-bounded, evidenced, approved exception |
| T-72 | An exception with no `effective_to` cannot be created |
| T-73 | An expired exception re-raises V19 and blocks the next publication of its artefact |
| T-74 | Activating a rule pack re-checks every existing company policy and stages the pack if any `block` finding results |
| T-75 | A rule pack with an invalid signature is rejected, and no content from it is written |

### VI.23.7 The Section 127 acceptance suite

**R-VI-211** All fourteen rows of VI.16's scorecard are automated end-to-end tests
(R-VI-151): author via `/api/v3/config`, publish, run the engine, assert the exact value,
with no deployment between authoring and effect. Each asserts an exact number — 0.5 payable
days; 22 working days; the 2nd and 4th Saturdays of a month whose 1st is a Saturday; a
sandwich LOP of exactly 1.0 day; sick leave producing 0.0 sandwich days; `eligible = true`
for a Hyderabad agent and `false` for a Pune agent; HRA present and employer PF absent in the
rendered payslip PDF's text layer; `linkedin_url` present in the employee API schema, the
import template, the export and the merge dictionary; an onboarding submit rejected without
a PCC; two notification sends with the correct two audiences; and a PT amount that changes
on 01-Oct-2026 and not before, from a signed pack with no application deployment.

---

## VI.24 Open items

### **PRODUCT OWNER DECISION REQUIRED — D-VI-01: may a tenant administrator author formulas, or only an Ametecs consultant?**

| Option | For | Against |
|---|---|---|
| **A. Consultant only** (recommendation) | A formula produces money. A wrong formula is a wrong payslip, a wrong statutory deduction and a defensibility problem for Ametecs, not just the customer. The type system, the simulator and the regression harness reduce but do not remove the risk | Every change is a service request; customers who want self-service will resent it; it caps the "configuration not code" promise at the most visible point |
| B. Tenant admin with mandatory approval and mandatory passing test cases | Full self-service; the guard rails are real (type checking, cycle detection, regression, impact preview, maker-checker) | A customer can still author a correct-but-wrong formula. Support burden shifts to diagnosing customer-authored arithmetic |
| C. Tiered: tenant may edit *values* inside a formula (a percentage, a cap) but not its *structure* | Most requests are value changes; structure changes are rare | Requires a parameterised-formula concept — real work, and a new artefact shape |

**Recommendation: A for v3.0, with C as the v3.1 path**, because C is the honest answer to
what customers actually ask for and it can be built once the formula engine is proven.

### **PRODUCT OWNER DECISION REQUIRED — D-VI-02: does rollback rewrite history?**

Stated in VI.3.5. **Recommendation: no.** Rollback changes the future only. A separate,
separately-permissioned `retro_correction` operation exists for the genuine case (a wrong
statutory rate was published and August must be recomputed), and it is always accompanied by
a recomputation with a full diff and an approval. Trade-off: two operations where customers
may expect one, and the need to explain the difference at training time.

### **PRODUCT OWNER DECISION REQUIRED — D-VI-03: is there a partner adapter SDK?**

Stated in VI.15.4. **Recommendation: yes for `DeviceAdapter` and `ConnectorAdapter` only,
signed and reviewed by Ametecs, and not in v3.0.** Trade-off: it accelerates integration
breadth and reduces Ametecs' bottleneck, but every signed partner adapter is Ametecs'
reputational liability, and a review process must exist before the SDK does.

### **PRODUCT OWNER DECISION REQUIRED — D-VI-04: who owns configuration in a SaaS tenant by default — Ametecs or the customer?**

| Option | Implication |
|---|---|
| **A. Ametecs-managed by default** (recommendation for the first 12 months) | Implementation and change requests go through Ametecs consultants; the customer gets `config.read` plus a small self-service set (holidays, notice board, notification wording, value-set additions). Predictable quality; a services revenue line; slower for the customer |
| B. Customer-managed by default | Faster for the customer; support cost and misconfiguration risk land on Ametecs anyway, because the customer will call when a payslip is wrong |
| C. Per-tenant, chosen at contract | Most honest, most operationally complex |

**Recommendation: A, with a defined graduation path** — a tenant moves to self-service on a
named artefact-type set once its administrators have completed training and its regression
suite has a minimum coverage. The mechanism already exists: `config_artefact_types` declares
the permissions, and permissions are assignable per tenant.

### **PRODUCT OWNER DECISION REQUIRED — D-VI-05: how many concurrent sandboxes, and are they entitled?**

Sandboxes are cheap (configuration only, no data copy) but simulations are not.
**Recommendation:** unlimited sandboxes; simulation **cohort size** and **monthly simulation
minutes** are entitlement limits (`limit_json`), generous on higher plans. Trade-off: a limit
customers will occasionally hit during implementation, mitigated by a consultant override.

### **PRODUCT OWNER DECISION REQUIRED — D-VI-06: does the customer see the platform defaults (K1)?**

**Recommendation: yes, read-only, clearly labelled, with a one-click "override this for my
tenant" that creates a K3 draft.** This makes the platform's assumptions inspectable, which
is the opposite of SmartPRS 2, where `SettingsController::defaults()` is a PHP array
duplicated in JavaScript that drifts silently (`A-payroll.md` Q8 row 4). Trade-off: it
exposes design decisions to scrutiny — which is a benefit, not a cost, for a product being
sold on defensibility.

### **INFORMATION REQUIRED — I-VI-01: the statutory floor severities.**

VI.13.2 requires Ametecs' compliance owner to declare, per statutory rule, whether a company
policy below it is `block`, `exception_required` or `warn`. That declaration is a legal
judgement this blueprint cannot make and must not guess. It is a prerequisite for the first
rule pack, and it depends on **D-03** (the named compliance owner) in the spine.

### **INFORMATION REQUIRED — I-VI-02: the v3.0 artefact-type list.**

Part V V.8.3 lists 24 artefact types. VI.2 and VI.16 imply several more
(`weekly_off_pattern`, `sandwich_policy`, `statutory_applicability`,
`statutory_company_policy`, `notification_subscription`, `surface_rule`, `document_type`,
`entitlement`-related). The consolidated list must be fixed before the module parts are
written, because each module part must claim its types. **Recommendation:** this part's
VI.2.1 taxonomy plus V.8.3's table are merged into a single registry appendix owned by
Part V, and this part's VI.17.2 defines its schema.

---

## VI.25 Requirements index

| Requirement | Subject | Origin |
|---|---|---|
| R-VI-001 | ~630 as-is gaps must map to configuration or to an explicit exclusion | all §5.2 sections |
| R-VI-002 | Per-type permissions as data, not literal role names | `G-…` §5.2 item 2; `H-…` §5.2 item 7 |
| R-VI-003 | Consultant support grants: time-boxed, audited, individually attributed | S13 |
| R-VI-004 | Statutory floor handled by the enforcement model, never silently | S12 |
| R-VI-010 | One state machine; published rows immutable | S4, Law 3 |
| R-VI-011 | Derived records store contributing version ids; `RESTRICT` | Law 4; `J-…` §Q8 |
| R-VI-012 | Scheduled activation re-validates and fails loudly | `D-…` §5.2 item 47 |
| R-VI-013 | Rollback is a publication, not an edit | Law 3 |
| R-VI-020 | Precedence weights are platform data, not tenant-editable | S3.2 |
| R-VI-021 | Studio offers scope-combination remediation | VI.4.3 |
| R-VI-022 | Ambiguity message content standard | `B-…` §5.2 item 24 |
| R-VI-023 | Unused dimensions cost and clutter nothing | — |
| R-VI-024 | Resolution query shape, `LIMIT 2`, self-invalidating cache | performance |
| R-VI-025 | Resolve per scope hash, not per employee | performance |
| R-VI-030 | No "current value" read; `as_of` always explicit | `A-payroll.md` Q5 |
| R-VI-031 | Re-run warns on a changed version set | `A-payroll.md` Q7 |
| R-VI-032 | Back-dated retro impact shown before approval | — |
| R-VI-033 | Arrears lines carry their earned period | `A-payroll.md` Q8 row 15 |
| R-VI-034 | Future-dated changes visible on a Scheduled Changes board | — |
| R-VI-035 | `recalculate` previews and is idempotent | `B-…` §5.2 item 57 |
| R-VI-036 | Settled periods need an audited reopen | S10 |
| R-VI-037 | Continuity enforced for `is_continuous` types | S4 |
| R-VI-040 | Exactly one configuration application | `H-…` §5.1 |
| R-VI-041 | Change Management is a first-class section | — |
| R-VI-042 | Visual builder and expression view share one AST; never silently rewrite | S6 |
| R-VI-043 | No in-place edit of a published artefact | Law 3 |
| R-VI-044 | Optimistic concurrency on drafts | `M-…` §6.2 C-4 |
| R-VI-045 | Every numeric field declares bounds and a unit | H-51 |
| R-VI-046 | Mandatory business description; help text as content | `H-…` §5.2 item 62 |
| R-VI-047 | One search across all configuration | — |
| R-VI-048 | Search operator grammar | — |
| R-VI-049 | Dependency graph written at validation time | H-8 |
| R-VI-050 | Deletion blocked by the "used by" list | spine S16 |
| R-VI-051 | Impact traversal to employees, date-selectable | — |
| R-VI-052 | Studio access is permission × data scope | S13 |
| R-VI-060 | Wizard resumable and non-destructive | — |
| R-VI-061 | Steps declare prerequisites, outputs and exit gates | — |
| R-VI-062 | Packs pre-populate Wizard steps | Section 107 |
| R-VI-063 | Parallel run mandatory before go-live | `A-payroll.md` Q8 row 10 |
| R-VI-064 | Go-live baseline export | — |
| R-VI-065 | Coverage report re-runnable; blocks payroll preview | S11 |
| R-VI-070 | Pure evaluation entry points; simulator is a thin caller | `A-payroll.md` Q8 row 11 |
| R-VI-071 | Sandbox is a configuration overlay, not a data copy | — |
| R-VI-072 | One trace object, three detail levels | S15 |
| R-VI-073 | Trace names non-matching rules and their evaluated conditions | S15 |
| R-VI-074 | A/B simulation shares the impact-preview computation | — |
| R-VI-075 | Pin a simulation as a regression case | S6 |
| R-VI-076 | Pinned cases run on publish, nightly and in CI | S6 |
| R-VI-077 | Real-employee cases freeze the attribute set | — |
| R-VI-078 | Packs ship their own regression cases | Section 107 |
| R-VI-080 | Validation is a named, numbered catalogue with declared run points | Section 103 |
| R-VI-081 | Message content standard: what, where, why, remediation | — |
| R-VI-082 | No validation implemented twice | `K-…` §5.2 item 15 |
| R-VI-090 | Diff against predecessor, any version, baseline or environment | Section 105 |
| R-VI-091 | Diff mandatory before submission for high-risk types; invalidated by later edits | S4 |
| R-VI-092 | Impact preview minimum content; estimates state their method | Section 105 |
| R-VI-093 | Every count clickable and downloadable | — |
| R-VI-094 | Never fabricate an estimate | `A-payroll.md` Q8 row 10 |
| R-VI-095 | `exact_replay` is pure and agrees with the simulator | — |
| R-VI-096 | Large previews are queued and attached to the version | — |
| R-VI-100 | Package definition and manifest | Section 106 |
| R-VI-101 | Promotion carries a change set, applied atomically | — |
| R-VI-102 | Exclusion list: credentials, tenant data, audit, entitlement, packs | H-53 |
| R-VI-103 | Import is parse → plan → apply; nothing written before phase 3 | — |
| R-VI-104 | No overwrite-everything mode; no silent resolution | — |
| R-VI-105 | Import is idempotent | — |
| R-VI-106 | Provenance recorded per artefact | — |
| R-VI-107 | Import revertible as a unit, by publication | — |
| R-VI-110 | Packs are signed and verified | S12 |
| R-VI-111 | Packs compose; overlaps are conflicts, not overwrites | — |
| R-VI-112 | Packs contain bindings, never statutory values | S12 legal-values discipline |
| R-VI-113 | Packs authored in a Studio, not hand-written | — |
| R-VI-114 | Packs apply as drafts by default | — |
| R-VI-115 | Fork model: `pristine` / `forked` / `detached`; three-way diff | — |
| R-VI-116 | A pack update never overwrites or leaks a customer edit | H-58 |
| R-VI-117 | Tenant-authored packs permitted, marked unsigned | — |
| R-VI-120 | Company policy may improve, never reduce; direction declared per rule | S12 |
| R-VI-121 | Severity is the rule pack's, not the tenant's | S19 item 1 |
| R-VI-122 | Exceptions are time-bounded and review-dated | — |
| R-VI-123 | Exceptions register is a first-class auditable report | — |
| R-VI-124 | Floor re-check on pack activation | — |
| R-VI-125 | A pack raising `block` findings is staged, not activated | — |
| R-VI-130 | Tenant may narrow entitlement, never widen | `G-…` §5.2 item 13 |
| R-VI-131 | One `EntitlementSet`, five identical enforcement points | Section 80 |
| R-VI-132 | Publish the positive set only | `CONSOLIDATED-DECISIONS` 1138 |
| R-VI-133 | CI sweep: every route, task and job declares a feature | `G-…` §5.2 item 13 |
| R-VI-134 | Disabling a depended-on feature is refused with dependants named | — |
| R-VI-135 | Degraded modes are configured input sources, not code branches | Law 1 |
| R-VI-136 | Disabling never deletes configuration or data | — |
| R-VI-137 | Limits enforced transactionally under a row lock | `M-…` §6 |
| R-VI-138 | Entitlement changes are audited and pre-notified | — |
| R-VI-140 | The extensibility principle and its field test | Section 128 |
| R-VI-141 | Extensions are Ametecs-authored, versioned, signed | Section 128 |
| R-VI-142 | Exactly four extension interfaces | S10.1 |
| R-VI-143 | Honest authoring-time messages at the limit | — |
| R-VI-150 | Pre-publication resolution table highlighting default fall-through | `B-…` §5.2 item 23 |
| R-VI-151 | The Section 127 scorecard is an acceptance suite | Section 127 |
| R-VI-160 | Change sets publish atomically | — |
| R-VI-161 | Every resolvable type has a default or a named blocking error | `A-payroll.md` §5.2 items 15, 24 |
| R-VI-170 | `as_of` is mandatory on the resolve API | — |
| R-VI-171 | Simulation is safe, rate-limited and cost-bounded by entitlement | — |
| R-VI-172 | Idempotency keys and cursor pagination everywhere | `M-…` §5.2 item 13 |
| R-VI-173 | Credentials are write-only | FINDING-H-02 |
| R-VI-174 | Configuration reads are role-gated and data-scoped | H-54 |
| R-VI-180 | Every engine trace names artefact, version, specificity and priority | Law 4 |
| R-VI-181 | `Explain(artefact, version)` content standard | S15 |
| R-VI-182 | Audit carries the full old→new payload diff | E-… B-71; H-11 |
| R-VI-183 | A trace shows the arithmetic, including the specificity sum | Law 4 |
| R-VI-190 | Publication is the only cache invalidation signal | — |
| R-VI-200 | Migrated versions carry confidence and enter a review queue | — |
| R-VI-201 | Migration writes drafts; the parallel run is the proof | — |
| R-VI-210 | Every finding code has a positive and a negative test | — |
| R-VI-211 | The Section 127 suite asserts exact values end to end | Section 127 |

**Totals for this part: 113 numbered requirements · 25 tables specified to the Section 123
standard · 22 named validation findings (V01–V22) · 6 PRODUCT OWNER DECISIONS REQUIRED · 2
INFORMATION REQUIRED items · 14 Section 127 acceptance cases.**

---

*End of Part VI.*
