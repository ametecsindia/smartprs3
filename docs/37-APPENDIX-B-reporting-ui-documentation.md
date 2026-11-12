# APPENDIX B — REPORTING, DASHBOARDS, FORMS, USER INTERFACE, LOCALISATION AND DOCUMENTATION

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Owner: Ametecs India Private Limited
Authority: subordinate to `SPINE.md`. Where this appendix and a numbered part
disagree, the **owning part** wins and the disagreement is recorded in §B.10.
Brief sections owned here: **57, 76, 77, 94, 95, 99, 117, 118, 119.**

---

## B.0 Why this appendix exists

### B.0.1 The audit finding, named

This appendix is not a late idea. It exists because of a specific, recorded
finding in **Part XXXV — Traceability Matrix**, produced by the traceability
audit of the thirty-four-part blueprint:

> **§XXXV.8.1** — twelve governing-brief sections were traced to no owning part.
> *"The shape of the gap set is not random. Six of the seven absences — 57, 76,
> 77, 94, 99, and the three documentation sections — are the parts of the product
> a human being touches directly: the forms they fill in, the reports they read,
> the language they read them in, the interface they read them through, and the
> manual that explains it. The blueprint specifies the engine room to an
> exceptional standard and has not yet specified the bridge."*
>
> *"As it stands, the blueprint could be built by an engineering team and could
> not be operated by a customer."*

Five of the gaps §XXXV.8.2 records in detail are closed here:

| Gap | Subject | Brief § | Closing requirement in Part XXXV | Closed by |
|---|---|---|---|---|
| GAP 1 | The report builder is **named by six parts and specified by none** | 76 (77 in part) | `R-XXXV-012` | **§B.1** |
| GAP 2 | The custom form builder **does not exist in the blueprint** — zero occurrences of `form builder` in thirty-four parts | 57 | `R-XXXV-024` | **§B.3** |
| GAP 3 | Localisation is **an as-is finding, never a to-be requirement** — three mentions, none specifying anything | 94 (95 with it) | `R-XXXV-025` | **§B.5** |
| GAP 4 | **There is no UI or UX specification** — no design system, no navigation model, no accessibility standard, no screen inventory | 99 | `R-XXXV-026` | **§B.4** |
| GAP 5 | **No documentation deliverable is specified anywhere** — `documentation set`, `user documentation` and `administrator guide` return zero occurrences | 117, 118, 119 | `R-XXXV-027` | **§B.6** |

`R-XXXV-011` requires each gap to be closed by an appendix, by a named
requirement, or by an explicit deferral with its consequence stated. This
appendix takes the first path for five of them. It does **not** close GAP 6
(the overtime engine, Section 31 — assigned to Part XI by `R-XXXV-028`) or
GAP 7 (the assembled business-rule register, Section 124).

`R-XXXV-012` states the commercial consequence plainly: **until a reporting and
dashboard-authoring architecture exists, Part XXXII's Phase 8 is sequenced but
not costable.** §B.1 and §B.2 exist to make Phase 8 costable.

**R-B-001** This appendix MUST be read as a full part for the purposes of spine
S20: numbered testable requirements, Section 123-grade tables, an API surface,
events, edge cases, tests and Product Owner decisions. A traceability closure
that is thinner than the parts it repairs is not a closure.

### B.0.2 The blueprint should be honest about how it was assembled

Thirty-four parts were authored in parallel against a shared spine. Parallel
authoring produces exactly two failure modes: the same thing specified twice in
incompatible ways (Appendix A's subject), and a thing every author assumed
somebody else owned (this appendix's subject). Both were found by mechanical
audit rather than by review, because 78,898 lines exceed what a reviewer can hold
in their head.

The report builder is the clearest case. Six parts make promises only a report
builder can keep — `report_definition` is a declared artefact type in Parts V, VI
and XXVIII; `report` is one of Part X's thirteen field surfaces; Part VI:2543
promises a new field appears as *"a filter and column in the report builder"*;
Part XXXII Phase 8 scopes the builder — and **no part specified it**. Each author
saw the reference and reasonably concluded it was somebody's.

This is recorded here, in the document, rather than corrected quietly, because a
blueprint that cannot describe its own assembly cannot be trusted about anything
harder.

### B.0.3 What this appendix owns, and what it does not

| Subject | Owned by | This appendix |
|---|---|---|
| Report datasets, report definitions, runs, exports, scheduling | **§B.1** | owns |
| The dashboard **mechanism** — widget model, scoping, thresholds, drill-through | Part XXI §XXI.11 (`R-XXI-119`…`127`) | **adopts unchanged**; §B.2 adds the builder, dataset binding, caching, personal dashboards, and the presentation vocabulary |
| Compliance dashboards and their widget content | Part XXI | cross-references only |
| Custom forms as configured entity + workflow | **§B.3** | owns |
| Fields, surfaces, validation, masking | Part X | composes |
| Approval routing, delegation, escalation, SLA | Part IX | composes |
| Delivery of anything to a human | Part XIX | composes; §B.1 and §B.2 never send |
| Front-end architecture, navigation, accessibility | **§B.4** | owns |
| Which SPA framework | Spine S2 `D-02` (React + TypeScript) | assumes |
| Localisation, formatting, timezone resolution | **§B.5** | owns |
| Attendance-day derivation and cross-midnight arithmetic | Part XI, spine S10 | cross-references |
| Statutory **return, challan and file** generation | Part XVII (`R-XVII-131`…`138`) | explicitly **not** owned |
| Statutory **readable registers** as report definitions | **§B.1 §B.1.11** | owns the mechanism, Part XVII owns the content |
| Documentation programme and its release gate | **§B.6** | owns |
| Permission catalogue | Part XXII (`R-XXII-010`, closed and platform-owned) | requests eleven additions; §B.10 records the conflict |

**R-B-002** Nothing in this appendix MAY introduce a second mechanism for
something the spine or an owning part already defines. Where a capability here
needs an engine, it calls the engine. *Test:* a review checklist item, and the
cross-part reference check that Appendix A §A.5 already runs.

### B.0.4 Compliance with the seven laws

| Law | How this appendix satisfies it |
|---|---|
| **1 — no business literal in code** | Report definitions, dataset column labels, widget thresholds, form fields, screen registry rows, message text, date and number formats, timezone and the documentation index are all data. §B.4 extends the static-analysis ban to the **client**, where SmartPRS 2's worst literals live (`AppController.php:11160-11166` — seventeen statutory literals and a seven-band slab table in browser JavaScript). |
| **2 — no schema change at runtime** | A new report column, a new dashboard widget, a new custom form and a new translation create **rows**, never columns. Custom-form values are typed rows in `entity_field_values` (spine S9). |
| **3 — nothing overwritten** | Report definitions, dashboards, form definitions and message translations are `config_versions` artefacts under spine S4. A run records the definition version it used. |
| **4 — every number explains itself** | A report run carries a `ReportTrace`; a widget number carries the definition, filter, scope predicate and as-at instant that produced it; a custom-form derived field carries its formula trace. |
| **5 — authorisation server-side, fails closed** | A report runs with the **requester's** permissions and scope, never the author's. Navigation is server-computed. Every `ui_screens` row carries a `NOT NULL` permission. Every widget is evaluated in the viewer's scope with the scope hash inside the cache key. |
| **6 — tenancy structural** | The dataset compiler injects `tenant_id` and the scope predicate; a report definition cannot express a join the dataset did not declare, and cannot suppress either predicate. |
| **7 — money is exact** | No money arithmetic in the client (§B.4). Report totals aggregate integer minor units and are formatted at the edge (§B.5), never computed in a formatter. |

---

## B.1 The Custom Report Builder *(brief Section 76)*

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION** in its entirety. SmartPRS 2 has no
report builder to extend.

### B.1.1 Purpose

This section decides how a customer gets a report SmartPRS 3 did not ship with:
what a dataset is, who declares it, how a report definition is expressed, how it
is scoped and permissioned at run time rather than at authoring time, how it is
executed within a bound, how it is exported without being assembled in memory,
and how the statutory registers a labour inspector asks for become definitions
rather than code.

### B.1.2 What SmartPRS 2 does today, and why it must change

`ReportController.php` is 521 lines. It contains a register of **fourteen fixed
datasets** (`:22-40`) and one **360-line `build()` method** (`:58-417`) holding a
hand-written query with a hard-coded column list for each.

| # | Evidence | Consequence |
|---:|---|---|
| 1 | Fourteen datasets, each a hand-written query with a hard-coded column list (`ReportController.php:22-40`, `:58-417`) — `H/NEW-RPT-101` | *"A customer cannot add a report, add a column, remove a column, reorder or rename columns, add a filter beyond the fixed month + company pair, save a view, schedule delivery, or choose an output format other than CSV"* (`H` §Q6) |
| 2 | Export passes `limit = null` and builds the entire CSV as **one PHP string in memory** (`:435`, `:470`, `:470-479`) — `H/BR-RPT-001`, `M` PERF table | A 500-agent export is a synchronous, unbounded memory allocation inside one web request |
| 3 | `compliance-scorecard` calls `scoreFor()` inside the row map — **~5 queries per employee** (`:322-328`) — `H/BR-RPT-011` | *"a 500-agent export is ~2 500 queries plus a full in-memory CSV, synchronously, in one web request"* (`H` §Q6); `reg-muster` ignores the limit even on preview |
| 4 | An unknown dataset key **silently falls back to `employees`** (`:429-431`, `:464-466`) — `H/BR-RPT-002` | The user receives a different report from the one they asked for, with no signal |
| 5 | Month normalisation is permissive: a malformed month **silently widens the report to all time** (`:42-52`) — `H/BR-RPT-003` | A typo turns a monthly register into a full-history extract |
| 6 | The minimum-wage check uses the **maximum** notified wage across the whole tenant, ignoring state, zone and category (`:139`) — `H/BR-RPT-004`, `H/DEFECT-RPT-01` | *"A Mumbai minimum is applied to a Nagpur agent, producing false BELOW MIN verdicts on a compliance report"* |
| 7 | Form B deduction bucketing is **substring matching on payslip deduction JSON keys** (`:171-190`) — `H/BR-RPT-005` | *"Staff PF Loan" lands in PF*; the four columns need not sum to Total Deductions |
| 8 | The full-and-final working assumes **basic = 50 % of monthly gross** (`:252-286`) — `H/BR-RPT-006` | The payroll engine's own salary structure is ignored by the report that settles an employee's exit |
| 9 | The bank advice payment mode is the **literal string `NEFT`** (`:247`) — `H/BR-RPT-007` | No IMPS, RTGS or UPI; no amount-based mode; no per-bank file format |
| 10 | Muster-roll present days = `COUNT(DISTINCT log_date)` with **no direction filter** (`:199-214`) — `H/BR-RPT-008` | An out-only punch counts as present; a zero-punch employee is absent from the statutory register entirely |
| 11 | The ex-employee access audit joins `users` on **email, unscoped by tenant** (`:333-361`) — `H/BR-RPT-009` | An ex-employee whose email exists in another tenant is reported as having an active login |
| 12 | Every export writes an `activity_logs` row by **direct INSERT, bypassing `Audit::record()`** (`:481-493`) — `H/BR-RPT-012`, `H/DEFECT-RPT-02` | The row carries no `row_hash` and *"breaks or is excluded from the very hash chain the `audit-trail` report verifies and prints as verified/BROKEN"* |
| 13 | CSV writing has **no UTF-8 BOM and no CSV-injection guard** (`:507-518`) — `H/BR-RPT-013` | A cell beginning `=` is a formula in the recipient's spreadsheet; the drive and campaign exporters emit a BOM and this one does not |
| 14 | Both report endpoints return **HTTP 200 with the raw exception message** (`:449-451`, `:501-503`) — `H/BR-RPT-014` | A client cannot distinguish success from failure, and internal detail leaks |
| 15 | `EmployeeExportController:246-320` builds a full in-memory XLSX, then `file_get_contents($tmp)` reads the finished file **back into a PHP string** — `M` PERF table | Two full copies of the export in memory |
| 16 | `AttendanceReportController::index` accepts **any date range** (`:105-123`) — `M` finding 14 | An unbounded report is a denial of service the customer performs on themselves |
| 17 | The attendance summarisation loop is **pasted twice** (`AttendanceReportController::index:137-161` and `::pdf:575-583`) — `M/DUP-12` | Screen and PDF drift on any change |
| 18 | `AttendanceReportController.php:501-503` upserts on a match key that **omits `tenant_id`**, and the table's unique index omits it too — `M/S-4` | Two tenants sharing an `emp_code` on the same date: one silently overwrites the other's attendance |

Two consolidated requirements name the destination:

- `H` requirement 40 — *"The platform must provide a report builder over the
  entity model: a user selects an entity, columns, filters, groupings, sort order
  and aggregations, and saves it as a named report."*
- `H` requirement 43 / `M` requirement 20 — *"Every report must paginate or
  stream; no report may materialise its full result set in memory."*

**The design consequence.** Findings 6, 7, 8, 9, 10 and 11 are not reporting
defects. They are **business logic that leaked into reports** because there was
nowhere else to put it: a minimum-wage resolution, a component-to-statutory-head
mapping, a settlement working, a payout mode, a presence definition and an
identity join. SmartPRS 3's report builder must have no place to put such logic,
which is why §B.1.4 forbids a report definition from computing anything the
domain has not already derived and stored.

### B.1.3 The three-layer model

```
  LAYER 1   report_datasets          PLATFORM METADATA.  Declared by a module.
            + columns + joins        Versioned. Carries the scope predicate.
            + measures               A tenant cannot create one.
                    │
                    │  a definition binds one dataset + one major version
                    ▼
  LAYER 2   cfg_report_definitions   TENANT CONFIGURATION (spine S4 artefact).
            columns · filters ·      Draft → testing → pending_approval →
            parameters · groups ·    published → superseded. Scoped, priority,
            sorts · totals ·         effective-dated, diffed, approvable.
            computed columns (SPX)   Statutory registers ship as system rows.
                    │
                    │  a run resolves parameters, requester scope, locale
                    ▼
  LAYER 3   report_runs              AN EXECUTION.  Records the definition
            + report_run_artefacts   version, dataset version, parameters,
                                     the resolved scope hash, row count,
                                     query count, duration, and every artefact
                                     produced with its hash. Audited.
```

**R-B-003** These three layers MUST be distinct artefacts with distinct
lifecycles. A dataset is a platform release; a definition is a tenant
configuration change; a run is an event. *Rationale — SmartPRS 2 fuses all three
into one method, which is why none of them can be changed independently.*

**R-B-004** A report definition MUST NOT be able to express a query the dataset
did not declare: no ad-hoc table, no ad-hoc join, no raw SQL fragment, no
subquery. The definition selects from what the dataset offers.
*Test:* the compiler accepts only declared column keys, declared join keys and
declared measures; anything else is a publish-time error naming the unknown
symbol.

### B.1.4 The dataset model

A **dataset** is a declared, versioned, permission-aware, scope-bearing view over
one grain of data, published by the module that owns that data.

```
report_dataset
  dataset_key            'employee.current' | 'payslip.line' | 'attendance.day'
  module                 owning module namespace
  version_major/minor    breaking / additive
  grain                  one row per WHAT — stated in words and enforced by a test
  subject_entity_key     nullable → the entity whose field_definitions extend it
  required_permission    a verb.resource code from Part XXII
  scope_anchor_column    the column the ScopeResolver predicate binds to
  scope_anchor_kind      employment | org_unit | requester | none_platform_only
  as_at_semantics        point_in_time | effective_dated | period_bounded
  default_row_limit, max_row_limit, max_scanned_rows, budget_ms
  supports_streaming     always true for a tenant-facing dataset
  description_key        message key (§B.5)

report_dataset_column
  column_key, dataset_key, source_kind
        column          a physical column on the dataset's base relation
        entity_field    a Part X field_definition, surface = 'report'
        measure         a declared aggregate, safe under the declared joins
        derived         a platform-authored expression over the same row
  data_type              an SPX type (spine S5.1)
  is_dimension, is_measure, allowed_aggregates[]
  filterable, sortable, groupable
  pii_class, masking_profile, required_permission (nullable, column-level)
  unit, default_format_profile, label_key, description_key
  null_semantics         how NULL renders and how it sorts

report_dataset_join
  join_key, from_dataset_key, to_dataset_key
  cardinality            one_to_one | many_to_one | one_to_many
  fan_out                0|1  — a one_to_many join fans out and invalidates
                         naive measure sums; declared, not discovered
  predicate              platform-authored, parameterised, never tenant-editable
  requires_permission    nullable
```

**R-B-005** Datasets MUST be **platform metadata**, declared by the owning module
and shipped in a release. A tenant MAY NOT create, edit or delete a dataset.
*Rationale — a dataset is a query surface over the physical schema; letting a
tenant author one reintroduces the class of defect the report builder exists to
remove. Precedent: `entity_definitions` (spine S9) is tenant-free platform
metadata for the same reason.*

**R-B-006** Every dataset MUST declare a **scope anchor**, and the query compiler
MUST inject `tenant_id = :tenant AND <ScopeResolver predicate for the requester>`
into every query built on it. The predicate is composed by the compiler, not
written by the definition, and cannot be suppressed, widened or overridden by any
filter, join, parameter or computed column.
*Test:* a generated cross-tenant and cross-scope isolation test exists **per
dataset** — two tenants with identical employee codes, and two users of the same
tenant with disjoint scopes — asserting zero leaked rows through preview, run,
export, schedule delivery and every dashboard widget bound to it.
*Rationale — `R-XXXV-012` names reporting as the place tenant data-scope
enforcement is most often defeated in practice, and `G/SEC-04` records 616
hand-written filters with 405 unfiltered queries beside them. A report builder
that can join two datasets without re-applying the caller's scope reintroduces
that defect in a new costume.*

**R-B-007** A dataset's declared grain MUST be asserted by a test (`COUNT(*)`
equals the count of distinct grain keys on a seeded fixture). A fan-out join MUST
set `fan_out = 1`, and the compiler MUST refuse a naive `SUM` over a measure
across a fan-out join, requiring an explicitly declared fan-out-safe measure
instead.
*Rationale — silent double-counting across a one-to-many join is the single most
common defect in customer-authored reporting, and it produces a number that looks
plausible.*

**R-B-008** Column-level permission and masking MUST be enforced at **run** time
from the requester's identity: a column whose `required_permission` the requester
lacks is handled by the definition's declared `restricted_column_policy` —
`drop_and_disclose` (the column is omitted and the run trace names it) or
`refuse_run` (the run fails with `report.column_forbidden`). Masking profiles come
from the field definition (Part X) and apply identically in preview, export,
schedule delivery, API and dashboard drill-through.
*Rationale — `H/BR-REC-042`: any user with the `employee` role can download any
document in the tenant, because the check was on the screen and not on the data.
A report is the widest data surface in the product.*

**R-B-009** A dataset MUST declare `budget_ms`, `max_scanned_rows` and
`max_row_limit`. A run exceeding a budget is **stopped and reported**, never
truncated silently.
*Rationale — SmartPRS 2 caps the candidate board at 2 000 rows with no truncation
warning (`H/BR-REC-021`) and caps nothing on export.*

**R-B-010** A dataset MUST NOT execute per-row work. A value requiring a
per-subject computation is a declared **measure** compiled into the single
statement, or it is not offered.
*Test:* a query-count assertion per dataset on a 10 000-employee fixture: the run
issues a bounded, declared number of statements independent of row count.
*Rationale — `H/BR-RPT-011`, the compliance scorecard's ~5 queries per employee.*

**R-B-011** Where a dataset genuinely cannot be expressed declaratively, it is
implemented against the `ReportDataset` interface (Part VI `R-VI-142`), which is
**Ametecs-authored, versioned and contract-tested**, and which MUST still return
the scope predicate as a composable expression. An implementation that filters
scope inside its own body rather than exposing the predicate fails its contract
test.

#### B.1.4.1 The dataset catalogue at v3.0

Datasets are owned by modules; this is the v3.0 set, and each owning part is
responsible for its columns.

| Dataset key | Grain | Owning part | Scope anchor |
|---|---|---|---|
| `employee.current` | one row per employment, as at a date | X | employment |
| `employee.history` | one row per employment-attribute change | X | employment |
| `employee.assignment` | one row per effective-dated org/classification assignment | V, X | employment |
| `attendance.day` | one row per employee per attendance-day | XI | employment |
| `attendance.punch` | one row per normalised punch | XI | employment |
| `attendance.period_settlement` | one row per employee per period | XI | employment |
| `leave.transaction` | one row per leave ledger entry | XIII | employment |
| `leave.balance` | one row per employee per leave type as at a date | XIII | employment |
| `payroll.run` | one row per run | XIV | org_unit |
| `payroll.payslip` | one row per payslip | XIV, XVI | employment |
| `payroll.payslip_line` | one row per payslip component line | XIV, XV | employment |
| `payroll.statutory_contribution` | one row per employee per head per period | XVII | employment |
| `payroll.recovery` | one row per loan/advance instalment or recovery | XVIII | employment |
| `commission.claim` | one row per claim | XVIII | employment |
| `compliance.item` | one row per compliance obligation instance | XXI | employment / org_unit |
| `compliance.gate_decision` | one row per gate evaluation | XXI | employment |
| `workflow.instance` | one row per workflow instance | IX | subject-anchored |
| `workflow.task` | one row per task, with SLA state | IX | subject-anchored |
| `recruitment.candidate` | one row per candidate | XX | org_unit |
| `document.instance` | one row per document with validity | X, XXI | employment |
| `audit.entry` | one row per audit-ledger entry | XXIII | tenant (permissioned) |
| `notification.delivery` | one row per recipient per channel | XIX | tenant (permissioned) |
| `form.<form_key>` | one row per custom-form request | §B.3 | requester / subject |
| `platform.tenant` | one row per tenant — **platform roles only** | XXV | none_platform_only |

**R-B-012** Every module part's own report list (each part's *Reports* section
under the Section 125 standard) MUST be expressible as definitions over this
catalogue. Where it is not, the module part MUST declare the missing dataset, and
the dataset is a platform deliverable of that module — not a bespoke query.
*Test:* a CI check enumerates every report named in every part and asserts a
dataset exists that can serve it.

#### B.1.4.2 How a field added in Part X becomes a report column automatically

This is the mechanism Part VI:2543 already promises and no part specified.

```
1  An administrator publishes a field definition (Part X):
       entity_key = 'employee', field_key = 'linkedin_profile',
       data_type = Text, pii_class = internal,
       surface_rules: report → visibility = read for roles [hr_manager, admin]

2  Part X emits  field_definition.published   (Part X §X.15 — the event already
   names "report builder" as a consumer)

3  The dataset registry invalidates the column cache for every dataset whose
   subject_entity_key = 'employee'

4  On the next resolution, report_dataset_columns for 'employee.current' is
   the union of:
        declared platform columns
      + every field_definition where surface = 'report' and the requester's
        resolved surface rule is 'read'
   carrying the field's label key, data type, value set, pii_class,
   masking profile and format profile

5  The column appears in the builder's column picker, in the filter picker,
   in the export, and as a groupable dimension where its type permits —
   with no code change and no migration
```

**R-B-013** A field published with `surface = report` MUST become available as a
report column and, where its type permits, a filter and a group, **in the same
resolution cycle**, with no deployment.
*Test:* publish a field; assert it appears in `GET /report-datasets/employee.current`
for a permitted role and is absent for a role whose surface rule denies it; assert
a run including it returns the value, masked per its profile.

**R-B-014** A column sourced from a field definition MUST carry that field's
**PII class and masking profile into every output**, including CSV, XLSX, PDF,
scheduled delivery and the API. Masking is not a screen behaviour.
*Rationale — `K` §7.5: SmartPRS 2's bootstrap returns `ctc`, `pan`, `uan`, bank
account, IFSC, date of birth, address and national identifier for every employee
in scope, and its report exports have no masking concept at all.*

**R-B-015** Retiring a field MUST NOT break a published report definition
silently. The definition validator lists every definition referencing the field,
the publish is blocked or the definitions are migrated in the same change, and a
run against a retired column renders the column with a `retired` marker rather
than an empty value.
*Rationale — Law 3, and `E/DEF-LVE-07` (renaming a leave type orphans all history)
is the same defect one module over.*

### B.1.5 The report definition artefact

A report definition is a `config_versions` artefact (spine S4) with a typed child
payload, presented in Configuration Studio section **S11 — Reports** (Part VI
§VI.6.2).

```
cfg_report_definition
  report_key, name_key, description_key, category_key
  dataset_key, dataset_version_major          -- pinned; a major bump is a migration
  columns[]        { seq, column_key | computed_key, label_key_override,
                     aggregate (none|sum|avg|min|max|count|count_distinct),
                     format_profile, width_hint, is_visible }
  computed_columns[] { key, spx_expression, result_type, rounding_profile,
                       label_key, format_profile }
  filters[]        { seq, column_key, operator, value_expr | parameter_key,
                     is_locked, group (AND/OR tree node) }
  parameters[]     { key, data_type, label_key, is_required, default_expr,
                     value_set_ref, is_multi, ui_control }
  date_semantics   { date_column_key, range_kind, calendar_ref, zone_source }
  groups[]         { seq, column_key, show_subtotal, page_break }
  sorts[]          { seq, column_key | computed_key, direction, nulls }
  totals[]         { column_key, aggregate, level (group|grand) }
  row_limit, sync_row_limit_override
  output_formats[] { xlsx | csv | pdf | json }
  page_layout_ref                              -- for PDF: Part XIX template family
  restricted_column_policy   drop_and_disclose | refuse_run
  visibility       private | shared | published
  required_permission_key, required_feature_keys[]
  is_system, origin_pack_key, locked_column_keys[], locked_filter_keys[]
  owner_user_id (private only)
  -- spine S4 envelope: scope_json, priority, effective_from/to, status,
  --                    version_no, checksum, reason, change_note
```

**R-B-016** A report definition MUST be a spine S4 artefact: versioned, scoped,
effective-dated, diffable and — where declared `high_risk` — approval-gated
before publication. A statutory register definition is `high_risk` by default.

**R-B-017** A computed column MUST be an **SPX expression** (spine S5) over the
row's own declared columns and parameters, type-checked at publish time, bounded
by the SPX node and time budget, and side-effect free. It MAY NOT read another
row, another dataset, the database, the clock (other than the injected evaluation
date) or any namespace not declared available for the `report` evaluation context.
*Rationale — this is the boundary that keeps business logic out of reports. A
customer who needs a value the domain has not derived needs a rule or a formula,
not a report column, because a report column is not applied consistently anywhere
else.*

**R-B-018** A computed column that fails to evaluate MUST render a typed error
cell naming the failure, and the run MUST be flagged `completed_with_errors` with
a count. It MUST NOT render zero, blank or the previous row's value.
*Rationale — spine S5.6 (an error value propagates to a rule evaluation failure,
surfaced to the operator) and `H` §Q6's HTTP 200-with-raw-exception behaviour.*

**R-B-019** An unknown `report_key` MUST return `404 report.not_found`. There is
no fallback report.
*Rationale — `H/BR-RPT-002`: an unknown dataset silently becomes `employees`,
so the user reads a different report from the one they requested.*

#### B.1.5.1 Date-range semantics

**R-B-020** Every definition MUST declare `date_semantics`: which column bounds
the report, what kind of range it accepts, which calendar resolves named periods,
and which timezone source resolves "today".

| `range_kind` | Meaning | Resolution |
|---|---|---|
| `named_period` | `payroll_period`, `calendar_month`, `quarter`, `financial_year`, `service_year` | Resolved from the tenant's effective-dated cycle and financial-year definitions (Part VI S2), never from `date('Y-m')` |
| `rolling` | `rolling(n, day\|week\|month)` | Anchored to the run's business date in the resolved zone (§B.5) |
| `explicit` | from/to supplied as parameters | Both required; `from <= to` validated |
| `as_at` | a single point-in-time snapshot | Resolves effective-dated joins at that instant |
| `since` | open-ended from a date | Requires an explicit `max_range_days` on the dataset |

**R-B-021** An unresolvable, malformed or absent required range MUST be a
**validation error**, never "no filter".
*Rationale — `H/BR-RPT-003`: a malformed month silently widens the report to all
time, which on `payroll.payslip` is every payslip the tenant has ever produced.*

**R-B-022** Every dataset with a date semantic MUST declare `max_range_days`, and
a run exceeding it is refused with the maximum stated.
*Rationale — `M` finding 14: `AttendanceReportController::index` accepts any
range.*

**R-B-023** "Today", "yesterday" and every named period MUST resolve through the
single `BusinessCalendar` service in the **resolved business timezone** (§B.5.6),
identically for a report, a dashboard widget, a scheduled run and the domain.
*Test:* the cross-surface determinism test in §B.5.9.

#### B.1.5.2 Parameterisation

**R-B-024** Parameters MUST be typed (SPX types), validated, and where they name
a code list, bound to a `value_set`. An unsupplied parameter with no default is a
`422 report.parameter_required`, naming the parameter.

**R-B-025** A parameter MUST NOT be able to influence scope. `company`,
`branch` and `department` parameters **narrow** the already-scoped result set and
can never widen it. A parameter naming an org unit outside the requester's scope
returns an empty result with a disclosure line, never a permission-denied oracle
that reveals the unit exists.

**R-B-026** A definition MAY declare a filter `is_locked`, meaning a user running
the report cannot remove or relax it. Statutory register definitions lock their
statutory filters.

### B.1.6 Saved, shared and scheduled reports

**R-B-027** A **saved** report is a definition with `visibility = private` and an
`owner_user_id`. It is visible to its owner and to platform support acting under
an audited impersonation (Part XXII), and to nobody else.

**R-B-028** **Sharing** a report grants the right to *run the definition*. It
never grants data. Two users running the same shared definition legitimately see
different rows and different totals, and the run header states the scope under
which it was produced.
*Test:* a manager and an HR manager run the same shared definition on the same
seeded tenant and receive different, correct row counts; both traces name their
own scope.

**R-B-029** **Publishing** a report (`visibility = published`) makes it available
to every holder of its `required_permission_key` within its S4 scope, and is a
configuration change under Law 3 — versioned, attributed and diffable.

#### B.1.6.1 Scheduling and delivery

```
report_schedule
  schedule_key, report_key, report_version_pin (nullable → latest published)
  parameter_bindings[]      literal | relative expression
                            ("previous calendar month", "current financial year")
  cron_expression + timezone_source     tenant | company | location | explicit
  next_run_at_utc                       materialised, recomputed on change
  output_format, delivery_mode          link | attachment (size-gated)
  recipients[]              audience_ref (Part XIX) | explicit user list
  render_identity           per_recipient | reporting_identity   (D-B-01)
  on_empty                  send | suppress | send_with_notice
  on_failure                notify_owner | notify_operations | both
  retry_policy, max_runtime_ms
  is_active, effective_from/to
```

**R-B-030** Delivery MUST go through the **notification engine** (Part XIX). The
report engine has no mail, WhatsApp, SMS or push code path, and no direct SMTP
access.
*Rationale — `H/BR-CMP-026`: emailing a letter rewrites the process's mail config
to a company's SMTP and does not restore it. Every send in SmartPRS 3 is one
engine's responsibility.*

**R-B-031** A scheduled run MUST fire at the **tenant's** (or company's, or
location's) local hour, resolved per §B.5.7, never at the server's.
*Rationale — `L:462`: `config/app.php:68` reads `env('APP_TIMEZONE', 'UTC')`, a
single global for the whole installation; `L/DEF-L-24` records that `config:cache`
then freezes it.*

**R-B-032** A scheduled report MUST be rendered under a declared identity, and the
identity MUST be recorded on the run.

**PRODUCT OWNER DECISION REQUIRED — D-B-01: under whose scope is a scheduled
report rendered?**

| Option | For | Against |
|---|---|---|
| **A. Per recipient (recommended)** | Every recipient sees exactly what they are entitled to see; identical to running it themselves; no leakage path | N renders for N recipients; expensive for large audiences |
| B. One render under a declared reporting identity | One render, cheap, and the file is identical for everyone — which auditors sometimes require | The identity's scope is by definition wider than some recipients'; a scheduled email becomes a standing data-leak channel |

**Recommendation: A**, with an optimisation that batches recipients whose
resolved scope hash is identical (in practice most role-based audiences collapse
to a handful of renders). Where a customer genuinely needs one identical file for
an audience — a statutory register for a filing pack — that is B, declared per
schedule, requiring the `reporting.schedule.render_as_identity` permission and
recorded on every delivery.

**R-B-033** An artefact above `attachment_max_bytes` (configuration) MUST be
delivered as a **signed, expiring, permission-checked link**, never as an
attachment, and the link MUST re-check the recipient's permission at download
time.
*Rationale — spine S13: nothing private ever sits on a publicly readable disk
path; and an emailed export outlives every access control the product has.*

**R-B-034** A schedule whose recipient has lost the permission or the scope MUST
fail closed for that recipient: no delivery, an entry on the schedule's failure
log, and a notification to the schedule owner. It MUST NOT deliver a narrower
report silently, and MUST NOT deliver the wider one.

**R-B-035** A schedule MUST record every fire: run id, recipients, per-recipient
delivery outcome, and the reason for any suppression. `on_empty = suppress` still
writes the run.

### B.1.7 Execution, budgets and asynchrony

```
run(report_key, params, requester)
  1  resolve definition version           (published, in scope, effective today)
  2  resolve dataset version              (pinned major; minor = latest)
  3  resolve requester permissions + data scope  → scope predicate + scope hash
  4  resolve locale, timezone, format profiles   (§B.5)
  5  validate parameters, resolve the date range
  6  compile                              → one parameterised statement
                                            + declared measure statements
  7  authorise columns                    → drop_and_disclose | refuse_run
  8  estimate                             → scanned-row and cost estimate
  9  IF estimate > sync budget OR row estimate > sync_row_limit
        → enqueue an async run, return 202 with the run id
     ELSE execute synchronously with a statement timeout
 10  stream rows                          → renderer (never a full materialisation)
 11  write report_run + artefacts + hashes; emit events; audit the export
```

**R-B-036** Every report result MUST be produced by **streaming**: a server-side
cursor or keyset pagination feeding a streaming writer that emits to a temporary
stream and then to object storage. **No report may assemble its result set, or its
output file, as an in-memory string or array.**
*Test:* a memory-ceiling test runs a 250 000-row export under a hard memory limit
and asserts completion; a code rule forbids `implode`-style accumulation in the
export path.
*Rationale — `H/BR-RPT-001` (`limit = null`, one PHP string) and `M`'s
`EmployeeExportController:246-320`, which builds the XLSX in memory and then reads
the finished file back into a second string.*

**R-B-037** Above `sync_row_limit` (per dataset, configuration, with a platform
default) a run MUST become **asynchronous**: queued, progress-reported,
notification on completion, artefact retrievable by run id. The user is never
left holding a request that will time out.

**R-B-038** Every run MUST record `query_count`, `duration_ms`, `rows_returned`,
`rows_scanned_estimate` and `peak_memory_bytes`, and a run exceeding its dataset's
budget MUST emit `report.budget_exceeded` to the operations dashboard (spine S18).
*Rationale — SmartPRS 2 has no telemetry on report execution at all, so the
2 500-query scorecard export is invisible until a customer reports a timeout.*

**R-B-039** A run MUST be **cancellable** by its requester and by an operator, and
cancellation MUST release the database statement.

**R-B-040** Identical `(definition version, parameter fingerprint, scope hash,
locale)` MAY reuse a cached artefact within a definition-declared TTL. The cache
key MUST include the scope hash. A cache that omits scope is a cross-user data
leak.

### B.1.8 Export formats

| Format | Writer | Rules |
|---|---|---|
| **XLSX** | streaming writer, one sheet per group where declared | Typed cells — a date is a date, money is a number with a currency format, never a pre-formatted string; a frozen header row; column widths from `width_hint`; a metadata sheet carrying the run trace (§B.1.10) |
| **CSV** | streaming writer | UTF-8 with a **configurable BOM** (default on, because Excel on Windows is the destination); RFC 4180 quoting; `\r\n`; **CSV-injection neutralisation** on every cell whose first character is one of `= + - @`, TAB or CR — prefixed with a single quote or wrapped per the tenant's `csv_injection_policy` |
| **PDF** | paged renderer over a Part XIX page layout | Landscape/portrait declared; repeating header; page x of y; the run trace in the footer; **locale-correct fonts embedded** (§B.5.4) so a Devanagari or Telugu register does not render as boxes |
| **JSON** | cursor-paginated API response | The same rows the file would contain; for integration, not for humans |

**R-B-041** CSV output MUST neutralise formula-injection vectors and MUST emit a
BOM by default. *Rationale — `H/BR-RPT-013`: no BOM and no injection guard, while
two other exporters in the same codebase do emit a BOM.*

**R-B-042** No output format may contain a value the requester could not see on
screen. The formatter is the last step, never a permission bypass.

**R-B-043** Every produced artefact MUST be stored with its **SHA-256 hash**, its
run id, its definition version and its parameters, so "what exactly did we send
to the auditor in March?" is answerable, and a regeneration that differs is
detectable. *This mirrors `R-XVII-132` for statutory files, deliberately.*

**PRODUCT OWNER DECISION REQUIRED — D-B-02: are export artefacts retained, and
for how long?** They contain the widest PII in the product.
**Recommendation:** retain, encrypted at rest, under a dedicated retention class
`report_artefact` with a **default of 30 days** and a per-definition override up
to the tenant's data-retention class; purge is scheduled and audited; a statutory
register artefact attached to a filing obligation inherits the obligation's
retention instead (Part XVII). The alternative — never store, regenerate on demand
— is cheaper but makes `R-B-043` impossible and removes the evidence trail.

### B.1.9 Permissions — the report runs as the requester

**R-B-044** **A report MUST execute with the permissions and data scope of the
user who runs it, resolved at run time, never those of its author, and never
those captured when it was saved.** There is no "run with elevated rights", no
stored credential, and no definition-level scope grant.
*Test:* an author with tenant scope publishes a report; a user with department
scope runs it and receives only their department; the author's scope appears
nowhere in the executed statement. A fault-injection test asserts that an
exception inside scope resolution **denies** (Law 5).

**R-B-045** Permission codes used by this appendix. Part XXII's catalogue
(`R-XXII-010`, closed and platform-owned) already contains four of the fourteen
below; **ten are requested additions**, and `search.global` (§B.4.15) is an
eleventh. All are recorded as a reconciliation item in §B.10.

| Code | Status | Purpose |
|---|---|---|
| `reporting.run` | exists | Run a report |
| `reporting.definition.manage` | exists | Create and edit definitions |
| `reporting.export` | exists | Produce a file artefact |
| `attendance.report.view` | exists | Run attendance registers |
| `reporting.definition.publish` | **new** | Publish a definition (separates maker from checker) |
| `reporting.schedule.manage` | **new** | Create and edit schedules |
| `reporting.schedule.render_as_identity` | **new** | Use `D-B-01` option B |
| `reporting.artefact.download` | **new** | Retrieve a stored artefact |
| `reporting.dataset.view` | **new** | Browse the dataset catalogue in the Studio |
| `dashboards.view` | **new** | See a dashboard (§B.2) |
| `dashboards.definition.manage` | **new** | Author dashboards (§B.2) |
| `dashboards.personalise` | **new** | Alter one's own layout (§B.2) |
| `forms.definition.manage` | **new** | Author custom forms (§B.3) |
| `forms.definition.publish` | **new** | Publish a custom form (§B.3) |

**PRODUCT OWNER DECISION REQUIRED — D-B-03: who may author report definitions?**
Part VI §VI.6.2 assigns Studio section S11 to *"any admin"*.
**Recommendation:** split it — `reporting.definition.manage` for any admin and for a
`report_author` role that can be granted to a power user (an operations manager
who builds their own views is the normal case and is the point of the feature);
`reporting.definition.publish` restricted to tenant admin, so a shared or published
report is always checked by someone. Private saved reports need only `manage`.
Dataset authoring stays with Ametecs (`R-B-005`).

### B.1.10 Explainability *(spine S15)*

**R-B-046** Every run MUST carry a `ReportTrace`, rendered as "How was this report
produced?" and embedded in every artefact.

```
Report          Wage Register (Form B)        definition v7, published 01-Apr-2026
Dataset         payroll.payslip_line          v3.2
Run             2026-09-03 11:42:07 IST       run 01J8…  by A. Sharma
Parameters      period = Aug-2026 (01-Aug-2026 → 31-Aug-2026, Asia/Kolkata)
                establishment = Hyderabad Unit 2
Scope applied   company IN (Ametecs Collections Pvt Ltd)
                establishment IN (HYD-02)             ← from your role "Payroll Operator"
Filters         payslip.run_status = approved         ← locked by the definition
Columns         18 shown · 1 omitted: `bank_account` (you lack `employee.bank.view`)
Statutory       PF/ESI columns bound to rule pack IN-PF v2026.2 (accepted 12-Apr-2026)
Rows            412 of 412 (no truncation) · 6 statements · 1.9 s
Integrity       artefact sha256 9f3c… · stored 30 days · retention class report_artefact
```

**R-B-047** Where a report shows a derived number that the domain also explains
(a payslip line, an attendance status, a leave unit), the trace MUST link to that
value's own explanation (Part XVI, Part XI, Part XIII) rather than restating it.
A report never becomes a second explanation of the same number.

### B.1.11 Statutory registers and returns as report definitions *(cross-reference Part XVII)*

The division is exact and it matters, because one half is evidence filed with a
regulator and the other half is a document a customer reads.

| Artefact | Owner | Mechanism |
|---|---|---|
| ECR, 24Q/26Q, Form 16, challans, contribution returns — **anything filed** | **Part XVII** | Pack-declared `form` + `report_mapping`, generated by `R-XVII-131`'s seven-step procedure from **stored** contributions, with reconciliation assertions that abort the file. **Not** a tenant report definition. |
| Form B wage register, Form D muster roll, Form E deduction register, PT employee-wise register, bonus Forms A–D as read, the statutory leave register, the minimum-wage breach register — **anything read** | **§B.1** mechanism, **Part XVII** content | Ships as `is_system = 1` report definitions bound to a rule pack version, over the datasets that hold the stored statutory rows |

**R-B-048** A readable statutory register MUST be a **system report definition**
authored by Ametecs, bound to the rule-pack version whose form it implements,
with `locked_column_keys` and `locked_filter_keys` covering every statutorily
required column and filter. Per Part VI K9, a tenant MAY **extend** it (add a
column, add a sort, brand the layout) and MAY NOT reduce it; editing forks a
tenant version that still carries the locked set.

**R-B-049** A statutory register MUST read **stored, observed values** — the same
`statutory_contributions` and payslip rows the return reads (`R-XVII-131` step 2)
— and MUST NOT recompute from CTC, from a percentage assumption, or over an
unfiltered employee set.
*Rationale — this single requirement retires four SmartPRS 2 defects at once:
`BR-RPT-005` (deduction bucketing by substring), `BR-RPT-006` (basic = 50 % of
gross in the settlement working), `BR-RPT-008` (present days from distinct punch
dates) and `DEFECT-RPT-01` (the maximum notified minimum wage across the tenant).*

**R-B-050** A statutory register MUST print its **provenance** — pack version,
authority reference and the run trace — per `R-XVII-016` and §XVII.4.10, and MUST
NOT print an integrity claim it cannot substantiate (`R-XVII-136`).

**R-B-051** The `audit.entry` dataset MUST expose the ledger's verification state
as a **column derived from the ledger's own verification** (Part XXIII), and
exporting it MUST write its audit entry **through `Audit::record()`**, never by
direct insert.
*Rationale — `H/BR-RPT-012` / `DEFECT-RPT-02`: the export of the audit-trail
report currently pollutes, or is excluded from, the very hash chain it prints a
verdict on.*

### B.1.12 The fourteen SmartPRS 2 datasets — migration

**R-B-052** Every SmartPRS 2 report MUST have a named successor definition before
cutover, and the successor MUST be validated against the old output on a migrated
tenant, with every difference **explained** rather than reconciled away.

The analysis names or describes eleven of the fourteen; the remaining three are
not individually named in `H` and MUST be enumerated from `ReportController.php:22-40`
during migration design (`R-B-053`).

| SmartPRS 2 dataset | SmartPRS 3 dataset | Definition | Defect repaired on the way |
|---|---|---|---|
| Employee list | `employee.current` | System: "Employee Directory" | Columns become field definitions; masking applied |
| Form B wage register | `payroll.payslip_line` | System statutory (pack-bound) | `BR-RPT-005` substring bucketing → component statutory flags (Part XV `R-XV-007`) |
| Form D muster roll (`reg-muster`) | `attendance.day` | System statutory | `BR-RPT-008` distinct punch dates → derived `attendance_days.status`; zero-punch employees appear as absent rather than vanishing; the limit is honoured |
| Form E deduction register | `payroll.payslip_line` | System statutory | As Form B |
| Bank NEFT advice | `payroll.payslip` + payout profile | System: "Payment Advice" | `BR-RPT-007` literal `'NEFT'` → the payout profile's declared mode and per-bank file format |
| Full-and-final working (`fnf`) | `payroll.payslip_line` + settlement | System: "F&F Statement" | `BR-RPT-006` basic = 50 % of gross → the resolved salary structure version |
| Minimum-wage check (`min-wage-check`) | `payroll.payslip_line` | System: "Minimum Wage Compliance" | `DEFECT-RPT-01` MAX across tenant → spine S3.2 precedence on state/zone/category |
| Compliance scorecard (`compliance-scorecard`) | `compliance.item` | System: "Compliance Scorecard" | `BR-RPT-011` ~5 queries per employee → one statement with declared measures |
| Ex-employee access audit | `employee.current` + identity | System: "Ex-employee Access" | `BR-RPT-009` join on email unscoped by tenant → join on user id within the tenant |
| Audit-trail export | `audit.entry` | System: "Audit Trail" | `DEFECT-RPT-02` direct `activity_logs` insert → `Audit::record()` |
| Attendance report (`AttendanceReportController`) | `attendance.day` | System: "Attendance Register" | `M/DUP-12` screen and PDF pasted twice → one definition, two renderers; `M`'s finding 14 unbounded range → `max_range_days`; `M/S-4` tenant-less upsert has no successor — the report writes nothing |

**R-B-053** The three unnamed datasets MUST be enumerated from source during
migration design and given successors or an explicit, recorded decision not to
carry them. *Where SmartPRS 2's behaviour is undetermined by the analysis, this
appendix says so rather than guessing.*

**R-B-054** No SmartPRS 2 report **code** is carried across. What is carried is
the **column list and the business intent**, both of which are genuine domain
capital — the register layouts encode real knowledge of what an Indian labour
inspector asks for.

### B.1.13 Configuration surface — the Section 127 test

| # | What a tenant administrator can change without code | Where | Scoped | Effective-dated | Approval |
|---:|---|---|---|---|---|
| 1 | Create a report: pick a dataset, columns, filters, groups, sorts, totals | Studio → S11 | yes | yes | on publish |
| 2 | Add a computed column as an SPX expression | same | yes | yes | on publish |
| 3 | Rename any column heading (label override, per locale) | same | yes | yes | no |
| 4 | Add a filter on any filterable column, including a custom field | same | yes | yes | no |
| 5 | Declare parameters and their value sets | same | yes | yes | no |
| 6 | Set the date semantics and default range | same | yes | yes | no |
| 7 | Choose output formats and the PDF page layout | same | yes | yes | no |
| 8 | Save privately, share with roles, or publish | same | yes | yes | publish only |
| 9 | Schedule it — cadence, timezone source, parameters, recipients, format | Studio → S11 → Schedules | yes | n/a | no |
| 10 | Set the row limit within the dataset's maximum | same | yes | yes | no |
| 11 | Extend a statutory register with extra columns | same | yes | yes | **yes** |
| 12 | Set CSV BOM and injection policy, and artefact retention within class | Studio → S11 → Output | tenant | no | no |
| 13 | Grant `reporting.definition.manage` to a power-user role | Studio → S13 | n/a | n/a | yes |

**Not tenant-changeable, by design:** datasets and their columns' source
expressions; the scope predicate; join predicates; dataset budgets and maxima;
the locked columns of a statutory register; the run-as-requester rule; and the
streaming requirement.

*Section 127 worked case, for this section:* **"a report of all agents whose PCC
expires within 45 days, grouped by branch, emailed to each branch manager on the
1st of every month at 09:00 local"** — dataset `compliance.item`; filter
`item_type = PCC AND due_on BETWEEN today AND today + 45d` where 45 is a
parameter with a default; group `org.branch`; schedule `0 9 1 * *` with
`timezone_source = location`; recipients an audience of `role = branch_manager`
rendered per recipient. **No code, no release, no Ametecs involvement.**

### B.1.14 API surface

All under `/api/v3`, per Part XXIV conventions: cursor pagination, idempotency
keys on unsafe methods, the single error envelope, and the same authorisation the
product's own UI uses.

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/report-datasets` | `reporting.dataset.view` | The catalogue the requester may use |
| GET | `/report-datasets/{key}` | `reporting.dataset.view` | Columns resolved **for the caller** — a denied column is absent |
| GET | `/reports` | `reporting.run` | Definitions the caller may run, filtered by permission and scope |
| POST | `/reports` | `reporting.definition.manage` | Creates a draft |
| GET | `/reports/{key}` | `reporting.run` | Definition metadata; versions for authors |
| PATCH | `/reports/{key}` | `reporting.definition.manage` | Draft only |
| POST | `/reports/{key}/versions/{v}/validate` | `reporting.definition.manage` | Type-check, budget-estimate, impact |
| POST | `/reports/{key}/versions/{v}/publish` | `reporting.definition.publish` | Diff + approval when high-risk |
| POST | `/reports/{key}/runs` | `reporting.run` | Idempotency-keyed; `202` when async |
| GET | `/reports/{key}/runs/{run}` | `reporting.run` | State, counts, trace |
| GET | `/reports/{key}/runs/{run}/rows` | `reporting.run` | Cursor-paginated JSON |
| POST | `/reports/{key}/runs/{run}/artefacts` | `reporting.export` | Requests a format; audited |
| GET | `/reports/{key}/runs/{run}/artefacts/{id}` | `reporting.artefact.download` | Signed, expiring, permission re-checked |
| DELETE | `/reports/{key}/runs/{run}` | `reporting.run` | Cancels, or purges the artefact early |
| GET/POST | `/reports/{key}/schedules` | `reporting.schedule.manage` | |
| PATCH/DELETE | `/reports/{key}/schedules/{id}` | `reporting.schedule.manage` | |
| POST | `/reports/{key}/schedules/{id}/test` | `reporting.schedule.manage` | Renders to the caller only, never to the audience |

**R-B-055** The product's own reporting UI MUST use these endpoints and no
others, so the public API is proven by the product (spine S2, Part XXIV).

**R-B-056** A failed run MUST return a `4xx`/`5xx` with the standard envelope and
a machine-readable code, and MUST NOT return the exception text.
*Rationale — `H/BR-RPT-014`: HTTP 200 with `{"ok":false,"error":"<raw message>"}`.*

### B.1.15 Events

| Event | Payload | Consumed by |
|---|---|---|
| `report_definition.published` | key, version, dataset, impacted schedules | Studio history, schedule revalidation, cache invalidation, docs generation |
| `report_definition.retired` | key, superseded_by | Schedules (deactivated with notice), dashboards bound to it |
| `report.run.started` | run id, report key, requester, parameter fingerprint | Operations dashboard |
| `report.run.completed` | run id, rows, duration, query count, errors | Notifications, dashboards, audit |
| `report.run.failed` | run id, failure code, budget or error | Operations dashboard, schedule owner |
| `report.budget_exceeded` | dataset, budget, observed | Operations dashboard, alerting (spine S18) |
| `report.export.produced` | run id, format, bytes, sha256 | **Audit ledger** (`R-B-051`) |
| `report.artefact.downloaded` | artefact id, actor | Audit ledger |
| `report.schedule.delivered` / `.failed` | schedule, recipients, outcomes | Part XIX delivery ledger, schedule owner |
| `report_dataset.version_published` | dataset, version, breaking changes | Definition validator, docs generation |

Consumed: `field_definition.published` / `.retired` (Part X) → column cache
invalidation; `statutory_pack.activated` (Part XVII) → register rebinding;
`period.locked` / `.reopened` (Part XIV) → cached artefact invalidation.

### B.1.16 Edge cases and failure states

| # | Situation | Behaviour |
|---:|---|---|
| 1 | The result set is empty | A rendered report with zero rows and an explicit "0 rows matched" line, plus the filters that produced it. **Never a blank file.** |
| 2 | The query fails | The run is `failed` with a code. **Never zero rows presented as a result** — `R-XXI-123` generalised |
| 3 | A referenced column was retired mid-life | The definition validator flags it; a run renders the column marked `retired`; publication of the retirement lists every affected definition (`R-B-015`) |
| 4 | A referenced value-set item was retired | Historic rows still render the retired label with a marker; the filter picker no longer offers it |
| 5 | The dataset's major version bumped | Definitions pinned to the old major keep working until its support window closes; the Studio shows a migration task per definition; nothing changes silently |
| 6 | The requester's scope is empty (a new manager with no reports) | Zero rows and a disclosure line naming the empty scope, never an error and never the unscoped set |
| 7 | The requester's scope resolution throws | **Deny** (Law 5), `403`, operator alert |
| 8 | Two definitions tie on S4 specificity and priority | Publication is blocked (spine S3.2), naming both |
| 9 | A parameter names an org unit outside scope | Empty result plus disclosure; no existence oracle (`R-B-025`) |
| 10 | A period is locked mid-run | The run completes on the snapshot it started from and states the lock time |
| 11 | A period is reopened after an artefact was delivered | The artefact is marked superseded; the schedule owner is notified; the delivered file is not retracted (it cannot be) but is traceable |
| 12 | An async run outlives its definition version | It completes on the version it started with (spine S4 in-flight semantics) |
| 13 | A run exceeds `max_scanned_rows` | Stopped with the estimate and the offending filter named; suggests a narrower range |
| 14 | A cell contains a leading `=` | Neutralised per `csv_injection_policy` (`R-B-041`) |
| 15 | A locale has no font for a script in a PDF | The run **fails** with `report.font_missing` naming the script. It does not render boxes (§B.5.4) |
| 16 | The same report is scheduled and run manually in the same minute | Two runs, two artefacts, distinct run ids; the cache may serve both if the fingerprint matches |
| 17 | A recipient is deactivated between schedule and delivery | Suppressed for that recipient, logged, owner notified |
| 18 | Storage is full or object storage is unreachable | The run fails cleanly, the queue retries with backoff, the operations dashboard shows it. No partial artefact is published |
| 19 | A tenant deletes a dataset's underlying records mid-run | Rows already streamed are in the artefact; the trace records the run window. Reports are point-in-time by construction |
| 20 | A definition is imported from another tenant (Part VI packs) | Dataset compatibility is validated; column keys that do not exist in the target tenant block the import and are named |

### B.1.17 Tests — exact-value regression scenarios

| ID | Scenario | Expected |
|---|---|---|
| T-B-001 | Two tenants, identical `emp_code`s; every dataset run by each | Zero cross-tenant rows, for every dataset, on preview, run, export, schedule and widget |
| T-B-002 | One tenant, a department-scoped manager and a tenant-scoped admin run the same published definition | Different, correct row counts; each trace names its own scope; neither statement contains the other's predicate |
| T-B-003 | An exception injected into scope resolution | The run is denied, not widened (Law 5) |
| T-B-004 | A 250 000-row export under a hard memory ceiling | Completes; peak memory bounded; no full materialisation |
| T-B-005 | `compliance.item` report over 10 000 employees | Bounded, declared statement count independent of row count (`R-B-010`) |
| T-B-006 | A malformed period parameter | `422`, named parameter; **not** an all-time report (`H/BR-RPT-003`) |
| T-B-007 | An unknown report key | `404 report.not_found`; **not** the employee list (`H/BR-RPT-002`) |
| T-B-008 | A field is published with `surface = report` | Appears as a column and filter for a permitted role in the same cycle; absent for a denied role |
| T-B-009 | A masked field (`bank_account`) exported by a user without `employee.bank.view` | Column dropped and disclosed, or run refused per policy; never present unmasked in CSV, XLSX, PDF or JSON |
| T-B-010 | Form B for August, then the salary structure is amended in October, then Form B for August is re-run | Byte-identical output; the trace names the same structure and pack versions |
| T-B-011 | Muster roll for a month containing an employee with zero punches | The employee appears, marked absent — not omitted (`H/BR-RPT-008`) |
| T-B-012 | Wage register where one deduction is named "Staff PF Loan" | It lands in the recovery column, not PF, because bucketing is by component flag (`H/BR-RPT-005`) |
| T-B-013 | Minimum-wage check with Mumbai and Nagpur agents under different notified wages | Each is compared against its own resolved wage (`DEFECT-RPT-01`) |
| T-B-014 | Export the audit-trail report | The export's own audit entry is hash-chained and verifiable; chain verification still passes (`DEFECT-RPT-02`) |
| T-B-015 | A CSV cell whose value is `=cmd\|' /c calc'!A1` | Neutralised on output |
| T-B-016 | A scheduled report at 09:00 for a tenant in `Asia/Kolkata` while the server runs UTC | Fires at 03:30 UTC; the run's business date is the tenant's local date (`R-B-031`) |
| T-B-017 | A scheduled report with three recipients of two distinct scopes | Two renders, three deliveries; each recipient's file matches what they would have run themselves |
| T-B-018 | A recipient loses permission between schedule and fire | No delivery for that recipient; owner notified; no narrowed file sent |
| T-B-019 | Two concurrent runs of the same definition with identical fingerprints | Both succeed; cache reuse is permitted and the scope hash matches |
| T-B-020 | A fan-out join with a naive `SUM` | Publish-time refusal naming the join (`R-B-007`) |
| T-B-021 | A dataset's grain assertion on a seeded fixture | `COUNT(*)` equals distinct grain keys |
| T-B-022 | A run whose computed column divides by zero | Typed error cell, run flagged `completed_with_errors`, count reported; no zero, no blank |
| T-B-023 | A published report re-run after the definition is edited and republished | The new run uses the new version and says so; the prior run's artefact still names the prior version |
| T-B-024 | 45-day PCC expiry report (the Section 127 case) built end-to-end in the Studio | Configured, scheduled and delivered without a code change; asserted by an acceptance test |

### B.1.18 Data model *(Section 123 standard)*

Conventions per spine S16 and Part XXVIII: `id BIGINT UNSIGNED PK`, `uuid
BINARY(16)` on aggregate roots, `tenant_id BIGINT UNSIGNED NOT NULL` in every
unique index on a tenant table, `created_at`/`updated_at`, no database `ENUM`,
money `DECIMAL(18,4)` at rest, instants UTC `DATETIME` with a business date and
zone. Archetypes: **A1** aggregate root · **A3** child · **A4** append-only ·
**A5** effective-dated · **PM** platform metadata (tenant-free, precedent:
`entity_definitions`, spine S9) · **cfg payload** (immutable once its
`config_version_id` is published).

#### `report_datasets` — PM

*Purpose:* the registry of declared query surfaces. Shipped in a release.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `dataset_key` | VARCHAR(80) | N | — | `employee.current` |
| `module_key` | VARCHAR(40) | N | — | Owning module |
| `version_major` | SMALLINT UNSIGNED | N | 1 | Breaking |
| `version_minor` | SMALLINT UNSIGNED | N | 0 | Additive |
| `grain_description` | VARCHAR(255) | N | — | Stated in words; asserted by `R-B-007` |
| `subject_entity_key` | VARCHAR(60) | Y | NULL | FK → `entity_definitions.entity_key`; drives §B.1.4.2 |
| `base_relation` | VARCHAR(120) | N | — | Platform-authored; never tenant-visible |
| `required_permission` | VARCHAR(80) | N | — | Part XXII code |
| `scope_anchor_column` | VARCHAR(80) | Y | NULL | NULL only when `scope_anchor_kind = none_platform_only` |
| `scope_anchor_kind_code` | VARCHAR(30) | N | — | Ref table |
| `as_at_semantics_code` | VARCHAR(30) | N | — | Ref table |
| `default_row_limit` | INT UNSIGNED | N | 10000 | |
| `max_row_limit` | INT UNSIGNED | N | 1000000 | |
| `sync_row_limit` | INT UNSIGNED | N | 5000 | Above this, async (`R-B-037`) |
| `max_scanned_rows` | BIGINT UNSIGNED | N | — | Budget |
| `budget_ms` | INT UNSIGNED | N | — | Budget |
| `max_range_days` | INT UNSIGNED | Y | NULL | Required where a date semantic exists (`R-B-022`) |
| `label_key`, `description_key` | VARCHAR(120) | N | — | Message keys (§B.5) |
| `is_active` | TINYINT(1) | N | 1 | |
| `deprecated_from_version` | VARCHAR(20) | Y | NULL | Support window |

PK `id` · Unique `(dataset_key, version_major)` · Index `(module_key)`,
`(subject_entity_key)` · **Tenant scope:** none (platform metadata) ·
**History:** release-versioned · **Soft delete:** no (deprecate) · **Audit:**
release manifest · **PII:** none.

#### `report_dataset_columns` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `dataset_id` | BIGINT UNSIGNED | N | — | FK → `report_datasets` ON DELETE RESTRICT |
| `column_key` | VARCHAR(80) | N | — | Stable; never reused after retirement |
| `source_kind_code` | VARCHAR(20) | N | — | `column`/`entity_field`/`measure`/`derived` |
| `source_expression` | TEXT | Y | NULL | Platform-authored SQL fragment or measure definition; never tenant-visible |
| `data_type_code` | VARCHAR(20) | N | — | SPX type (spine S5.1) |
| `unit_code` | VARCHAR(20) | Y | NULL | `days`, `hours`, `minutes`, `INR` |
| `is_dimension` | TINYINT(1) | N | 0 | |
| `is_measure` | TINYINT(1) | N | 0 | |
| `allowed_aggregates_json` | JSON | Y | NULL | Closed set per column |
| `is_fan_out_safe` | TINYINT(1) | N | 0 | `R-B-007` |
| `filterable`, `sortable`, `groupable` | TINYINT(1) | N | 1/1/0 | |
| `pii_class_code` | VARCHAR(20) | N | `none` | From the field definition where sourced |
| `masking_profile_key` | VARCHAR(60) | Y | NULL | Part X |
| `required_permission` | VARCHAR(80) | Y | NULL | Column-level (`R-B-008`) |
| `default_format_profile_key` | VARCHAR(60) | Y | NULL | §B.5.4 |
| `label_key`, `description_key` | VARCHAR(120) | N | — | |
| `retired_from_version` | VARCHAR(20) | Y | NULL | |

PK `id` · Unique `(dataset_id, column_key)` · Index `(dataset_id, is_dimension)`,
`(pii_class_code)` · **Tenant scope:** none · **History:** release-versioned ·
**Soft delete:** no · **Audit:** release manifest · **PII:** classifies, holds
none.

#### `report_dataset_joins` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `from_dataset_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `to_dataset_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `join_key` | VARCHAR(60) | N | — | Referenced by definitions |
| `cardinality_code` | VARCHAR(20) | N | — | Ref table |
| `fan_out` | TINYINT(1) | N | 0 | `R-B-007` |
| `predicate_expression` | TEXT | N | — | Platform-authored, parameterised |
| `required_permission` | VARCHAR(80) | Y | NULL | |
| `label_key` | VARCHAR(120) | N | — | |

PK `id` · Unique `(from_dataset_id, join_key)` · **Tenant scope:** none ·
**Audit:** release manifest.

#### `cfg_report_definitions` — cfg payload (A5 via `config_versions`)

*Purpose:* the tenant's report. Registered in outline by Part XXVIII §XXVIII.5;
this is its specification.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | Law 6 |
| `config_version_id` | BIGINT UNSIGNED | N | — | FK → `config_versions` CASCADE; carries scope, priority, effective range, status |
| `uuid` | BINARY(16) | N | — | Public id |
| `report_key` | VARCHAR(80) | N | — | Stable across versions |
| `dataset_key` | VARCHAR(80) | N | — | FK (logical) → `report_datasets.dataset_key` |
| `dataset_version_major` | SMALLINT UNSIGNED | N | — | Pinned |
| `name_key`, `description_key` | VARCHAR(120) | N | — | Message keys, tenant-overridable (§B.5.2) |
| `category_key` | VARCHAR(60) | Y | NULL | Studio grouping |
| `date_column_key` | VARCHAR(80) | Y | NULL | `R-B-020` |
| `range_kind_code` | VARCHAR(30) | Y | NULL | Ref table |
| `zone_source_code` | VARCHAR(20) | N | `tenant` | `tenant`/`company`/`location`/`explicit` |
| `row_limit` | INT UNSIGNED | Y | NULL | ≤ dataset max |
| `output_formats_json` | JSON | N | `["xlsx","csv"]` | |
| `page_layout_ref` | VARCHAR(80) | Y | NULL | Part XIX template |
| `restricted_column_policy_code` | VARCHAR(30) | N | `drop_and_disclose` | `R-B-008` |
| `visibility_code` | VARCHAR(20) | N | `private` | |
| `owner_user_id` | BIGINT UNSIGNED | Y | NULL | Required when `private` |
| `required_permission_key` | VARCHAR(80) | N | `reporting.run` | |
| `required_feature_keys_json` | JSON | Y | NULL | Entitlement |
| `is_system` | TINYINT(1) | N | 0 | Ametecs-shipped (`R-B-048`) |
| `origin_pack_key`, `origin_pack_version` | VARCHAR(80)/(20) | Y | NULL | Rule-pack binding |
| `locked_column_keys_json`, `locked_filter_keys_json` | JSON | Y | NULL | Extend-not-reduce |
| `cache_ttl_seconds` | INT UNSIGNED | N | 0 | 0 = no cache |
| `created_by_user_id` | BIGINT UNSIGNED | N | — | |
| `created_at`, `updated_at` | DATETIME | N | — | UTC |

PK `id` · Unique `(tenant_id, report_key, config_version_id)`, `(tenant_id, uuid)`
· Index `(tenant_id, dataset_key)`, `(tenant_id, visibility_code)`,
`(tenant_id, owner_user_id)` · **Tenant scope:** yes · **History:** spine S4
versions · **Soft delete:** no (superseded/archived) · **Audit:** full,
field-level on publish · **PII:** none directly; **filter values may embed
identifiers** and are therefore treated as `internal`.

#### `cfg_report_definition_columns` — cfg payload child

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `report_definition_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `seq` | SMALLINT UNSIGNED | N | — | Display order |
| `column_key` | VARCHAR(80) | Y | NULL | Dataset column; XOR with `computed_key` |
| `computed_key` | VARCHAR(80) | Y | NULL | Local computed column |
| `spx_expression` | TEXT | Y | NULL | Required when `computed_key` set |
| `spx_ast` | JSON | Y | NULL | Compiled AST stored at publish (spine S5) |
| `result_type_code` | VARCHAR(20) | Y | NULL | Computed only |
| `rounding_profile_key` | VARCHAR(60) | Y | NULL | Mandatory for `Money` results (Law 7) |
| `aggregate_code` | VARCHAR(20) | Y | NULL | Within `allowed_aggregates` |
| `label_key_override` | VARCHAR(120) | Y | NULL | |
| `format_profile_key` | VARCHAR(60) | Y | NULL | |
| `width_hint` | SMALLINT UNSIGNED | Y | NULL | |
| `is_visible` | TINYINT(1) | N | 1 | Present for grouping/sorting but not shown |

PK `id` · Unique `(report_definition_id, seq)`,
`(report_definition_id, COALESCE(column_key, computed_key))` · CHECK exactly one
of `column_key`/`computed_key` · **Tenant scope:** yes · **Audit:** with parent.

#### `cfg_report_definition_filters` — cfg payload child

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `report_definition_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `seq` | SMALLINT UNSIGNED | N | — | |
| `parent_group_id` | BIGINT UNSIGNED | Y | NULL | Self-FK: AND/OR tree |
| `boolean_op_code` | VARCHAR(5) | Y | NULL | `AND`/`OR` on a group node |
| `column_key` | VARCHAR(80) | Y | NULL | NULL on a group node |
| `operator_code` | VARCHAR(20) | Y | NULL | SPX comparison set (spine S5.2) |
| `value_expr` | TEXT | Y | NULL | SPX literal or expression |
| `parameter_key` | VARCHAR(60) | Y | NULL | XOR with `value_expr` |
| `is_locked` | TINYINT(1) | N | 0 | `R-B-026` |

PK `id` · Index `(report_definition_id, parent_group_id, seq)` · **Tenant scope:**
yes · **PII:** `value_expr` may hold an identifier — classified `internal`,
masked in the Studio's diff view per the column's masking profile.

#### `cfg_report_definition_parameters` — cfg payload child

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `report_definition_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `parameter_key` | VARCHAR(60) | N | — | |
| `data_type_code` | VARCHAR(20) | N | — | SPX type |
| `label_key` | VARCHAR(120) | N | — | |
| `is_required` | TINYINT(1) | N | 1 | |
| `default_expr` | TEXT | Y | NULL | SPX, evaluated at run time |
| `value_set_ref` | VARCHAR(60) | Y | NULL | FK (logical) → `value_sets` |
| `is_multi` | TINYINT(1) | N | 0 | |
| `ui_control_code` | VARCHAR(30) | N | `auto` | Rendering hint only |
| `seq` | SMALLINT UNSIGNED | N | — | |

PK `id` · Unique `(report_definition_id, parameter_key)` · **Tenant scope:** yes.

#### `report_schedules` — A1

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `uuid` | BINARY(16) | N | — | |
| `report_key` | VARCHAR(80) | N | — | |
| `report_version_pin` | INT UNSIGNED | Y | NULL | NULL = latest published |
| `schedule_key` | VARCHAR(80) | N | — | |
| `cron_expression` | VARCHAR(120) | N | — | |
| `timezone_source_code` | VARCHAR(20) | N | `tenant` | §B.5.7 |
| `explicit_timezone` | VARCHAR(64) | Y | NULL | IANA; required when source = `explicit` |
| `next_run_at_utc` | DATETIME | N | — | Materialised; recomputed on change and on zone-database update |
| `next_run_local_on` | DATE | N | — | Business date of the next fire |
| `parameter_bindings_json` | JSON | Y | NULL | Literals and relative expressions |
| `output_format_code` | VARCHAR(10) | N | `xlsx` | |
| `delivery_mode_code` | VARCHAR(20) | N | `link` | `link`/`attachment` |
| `render_identity_code` | VARCHAR(30) | N | `per_recipient` | `D-B-01` |
| `render_identity_user_id` | BIGINT UNSIGNED | Y | NULL | Required for `reporting_identity` |
| `on_empty_code` | VARCHAR(20) | N | `send_with_notice` | |
| `on_failure_code` | VARCHAR(20) | N | `both` | |
| `max_runtime_ms` | INT UNSIGNED | N | — | |
| `retry_policy_json` | JSON | Y | NULL | |
| `is_active` | TINYINT(1) | N | 1 | |
| `effective_from`, `effective_to` | DATE | N/Y | — | |
| `owner_user_id` | BIGINT UNSIGNED | N | — | |
| `created_at`, `updated_at`, `created_by_user_id` | | N | — | |

PK `id` · Unique `(tenant_id, schedule_key)`, `(tenant_id, uuid)` · Index
`(next_run_at_utc, is_active)` — the scheduler's only scan · **Tenant scope:**
yes · **History:** audited changes · **Soft delete:** `is_active` · **Audit:**
full · **PII:** none.

#### `report_schedule_recipients` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `schedule_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `recipient_kind_code` | VARCHAR(20) | N | — | `audience`/`user`/`role_in_scope` |
| `audience_ref` | VARCHAR(80) | Y | NULL | Part XIX audience |
| `user_id` | BIGINT UNSIGNED | Y | NULL | |
| `role_key` | VARCHAR(60) | Y | NULL | |
| `data_scope_code` | VARCHAR(30) | Y | NULL | With `role_key` |
| `channel_plan_ref` | VARCHAR(80) | Y | NULL | Part XIX |

PK `id` · Index `(schedule_id)` · **Tenant scope:** yes · **PII:** recipient
identity — `internal`.

#### `report_runs` — A4 (append-only)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `uuid` | BINARY(16) | N | — | Public run id |
| `report_key` | VARCHAR(80) | N | — | |
| `report_config_version_id` | BIGINT UNSIGNED | N | — | The exact version used (Law 3) |
| `dataset_key` | VARCHAR(80) | N | — | |
| `dataset_version` | VARCHAR(20) | N | — | major.minor |
| `trigger_code` | VARCHAR(20) | N | — | `manual`/`schedule`/`api`/`widget_drill` |
| `schedule_id` | BIGINT UNSIGNED | Y | NULL | FK SET NULL |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | The identity whose scope applied |
| `render_identity_user_id` | BIGINT UNSIGNED | Y | NULL | Where different (`D-B-01` B) |
| `parameters_json` | JSON | Y | NULL | Values as supplied, masked on read per column policy |
| `parameter_fingerprint` | CHAR(64) | N | — | Cache key component |
| `scope_hash` | CHAR(64) | N | — | Hash of the resolved scope predicate — **cache key component** |
| `locale_code`, `timezone` | VARCHAR(20)/(64) | N | — | §B.5 |
| `business_date_on` | DATE | N | — | Run's business date in the resolved zone |
| `state_code` | VARCHAR(20) | N | `queued` | `queued`/`running`/`completed`/`completed_with_errors`/`failed`/`cancelled` |
| `failure_code` | VARCHAR(60) | Y | NULL | Machine-readable |
| `rows_returned` | BIGINT UNSIGNED | Y | NULL | |
| `rows_scanned_estimate` | BIGINT UNSIGNED | Y | NULL | |
| `query_count` | INT UNSIGNED | Y | NULL | Budget assertion |
| `duration_ms` | INT UNSIGNED | Y | NULL | |
| `peak_memory_bytes` | BIGINT UNSIGNED | Y | NULL | `R-B-036` evidence |
| `error_cell_count` | INT UNSIGNED | N | 0 | `R-B-018` |
| `dropped_columns_json` | JSON | Y | NULL | `R-B-008` disclosure |
| `trace_json` | JSON | N | — | The `ReportTrace` (`R-B-046`) |
| `started_at`, `finished_at` | DATETIME | Y | NULL | UTC |
| `created_at` | DATETIME | N | — | UTC |

PK `id` · Unique `(tenant_id, uuid)` · Index
`(tenant_id, report_key, created_at)`, `(tenant_id, requested_by_user_id, created_at)`,
`(schedule_id, created_at)` · **Tenant scope:** yes · **History:** the row *is*
history · **Soft delete:** no · **Audit:** the run is itself an audit subject;
exports additionally write the ledger · **PII:** `parameters_json` may contain
identifiers — retention class `report_run`, purged with its artefacts.

#### `report_run_artefacts` — A3, append-only

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `run_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `uuid` | BINARY(16) | N | — | |
| `format_code` | VARCHAR(10) | N | — | `xlsx`/`csv`/`pdf`/`json` |
| `storage_key` | VARCHAR(255) | N | — | Private object-store path; never a public disk path (spine S13) |
| `bytes` | BIGINT UNSIGNED | N | — | |
| `sha256` | CHAR(64) | N | — | `R-B-043` |
| `encryption_key_id` | VARCHAR(60) | N | — | Application-layer encryption |
| `retention_class_code` | VARCHAR(40) | N | `report_artefact` | `D-B-02` |
| `expires_at` | DATETIME | N | — | UTC; purge job's index |
| `download_count` | INT UNSIGNED | N | 0 | |
| `superseded_at` | DATETIME | Y | NULL | Set when its period reopens (edge case 11) |
| `created_at` | DATETIME | N | — | |

PK `id` · Unique `(tenant_id, uuid)`, `(run_id, format_code)` · Index
`(expires_at)` · **Tenant scope:** yes · **Soft delete:** no — purged by
retention · **Audit:** creation and every download · **PII:** **the artefact is
the PII** — classified by the highest class among its columns, encrypted at rest,
served only by signed expiring URL.

---

## B.2 The Dashboard Builder *(brief Section 77)*

### B.2.1 The design principle, stated once and enforced everywhere

> **SHOW ACTIONS, NOT ONLY COUNTS.**

The governing brief is explicit about it, and every requirement in this section
exists to make it structural rather than aspirational. A number a user cannot act
on is a report; a number a user can act on, in one click, in the same permission
context, is a widget.

### B.2.2 Ownership — what this section adds, and what it adopts

**Part XXI §XXI.11 already specifies the dashboard widget model.** It was written
for the compliance command centre and, in the course of doing so, defined the
general mechanism: `cfg_dashboard`, `cfg_dashboard_widget`, eight widget kinds,
and `R-XXI-119` … `R-XXI-127`. That work is **adopted here unchanged and is not
restated.**

| Already specified by Part XXI — adopted, not duplicated | Requirement |
|---|---|
| Every widget resolves to a work queue; a count-only widget fails the build | `R-XXI-119` |
| The number and its drill list come from the **same** query definition | `R-XXI-120` |
| Widgets, thresholds, ordering, audiences and empty states are configuration | `R-XXI-121` |
| A widget is evaluated in the **viewer's** data scope, server-side; a role check is never a branch selector | `R-XXI-122` |
| A widget whose source errors renders a **visible degraded state** and never renders zero | `R-XXI-123` |
| Widget queries are bounded and budgeted, asserted in CI on a 10 000-employee tenant | `R-XXI-124` |
| The platform dashboard is a distinct dashboard, not the tenant dashboard with different numbers | `R-XXI-125` |
| Every widget declares an empty state that reads as completion | `R-XXI-126` |
| A compliance widget never mixes assessed and unassessed counts | `R-XXI-127` |
| The `cfg_dashboard` / `cfg_dashboard_widget` model and the eight widget kinds | §XXI.11.2, §XXI.11.3 |
| The eight role dashboards and their widget sets | §XXI.11.4 |
| The worked examples with their drill-through and actions | §XXI.11.5 |

**This section owns the six things Part XXI did not need and therefore did not
specify**, because a compliance command centre uses dashboards and does not
author them:

1. the **builder** — how a tenant creates and edits a dashboard and a widget;
2. **data binding to §B.1** — a widget's source is a report dataset or definition,
   never its own SQL;
3. the **presentation vocabulary** the brief names (count, amount, trend, pie,
   bar, table, alert, KPI, compliance, attendance) and how it maps onto Part XXI's
   eight behavioural kinds;
4. **personal dashboards** alongside role dashboards;
5. **refresh, caching and staleness**, including the rule that makes a cache safe;
6. the **drill-through contract** as a testable interface.

**R-B-057** `cfg_dashboard` and `cfg_dashboard_widget` (Part XXI §XXI.11.2) are
the platform's single dashboard model. This appendix MUST NOT define a second
one. Where this section adds a field, it is recorded in §B.2.9 as an extension to
Part XXI's tables, owned by Part XXI.
*Rationale — Appendix A's finding: the failure mode of parallel authoring is two
mechanisms for one concept.*

### B.2.3 What SmartPRS 2 does today, and why it must change

`DashboardController` returns **six fixed cards** chosen by
`hasRole('super_admin')` — *"a branch selector, not a guard"* — with *"no card
selection, no reordering, no custom KPIs, no thresholds, no drill-through
configuration"* (`H/NEW-DSH-101`, `DashboardController.php:35-49`).

| Evidence | Consequence |
|---|---|
| A `tenant_id IS NULL` non-super-admin falls through to the company card set **with every tenant filter skipped** (`:44-45`) — `H/NEW-DSH-102` | Cross-tenant totals rendered as one tenant's dashboard |
| Notices are chosen by a **deny-list** of statuses (`:52-74`) — `H/NEW-DSH-103` | An unrecognised status is shown; an allow-list is the safe shape |
| The first tile carries a **deliberate colour test marker with an un-actioned revert instruction** (`:157-160`) — `H/NEW-DSH-104` | Shipped to every client |
| Global search on the same controller has **no role guard and no self-scope** (`:198-225`) — `H/NEW-DSH-105` | A self-service employee can enumerate every colleague's contact details |
| A second dashboard (`index()` + `dashboard.blade.php`) is **dead code** that would throw `RouteNotFoundException` and reads a schema that no longer exists (`H` §1.17, `K` §6.1) | Two dashboards, one running |
| Every card is a count with no destination | The user reads "3 pending approvals" and then navigates by memory to find them |

The migration verdict in the analysis is **REPLACE**: *"needs a configurable
widget model over the report/metric layer"* (`H` §9). §B.1 is that layer.

### B.2.4 Data binding — a widget is a bounded query over a declared dataset

**R-B-058** A widget's `data_source` MUST be one of exactly three things, and
nothing else:

| `data_source_kind` | Meaning | Number is | Drill list is |
|---|---|---|---|
| `report_definition` | a published §B.1 definition | an aggregate over its result | the definition's rows |
| `report_dataset` | a dataset plus an inline filter expression and one aggregate | that aggregate | the same dataset, same filter |
| `metric` | a platform-declared metric (a health signal: queue depth, scheduler heartbeat, last restore test) | the metric value | the operations screen |

A widget MAY NOT carry SQL, a table name, a join, or a scope clause.
*Rationale — this is what makes `R-XXI-120` (count equals list) structurally true
rather than a discipline: there is only one query, and the drill-through is the
same query without the aggregate.*

**R-B-059** A widget bound to a `report_dataset` MUST inherit that dataset's
scope predicate, budgets, column permissions and masking **unchanged**. A widget
is not a privileged read path.
*Test:* the §B.1 cross-scope isolation test (`T-B-001`) runs through the widget
surface as well as the report surface.

**R-B-060** A widget's aggregate MUST be one of the dataset column's
`allowed_aggregates`, and a `Money` aggregate MUST sum integer minor units and
format at the edge (Law 7). A widget MUST NOT compute a ratio, a percentage or a
target attainment in the client.

**R-B-061** A `metric` widget's source MUST be a declared platform metric with an
owner and a definition; a metric with no owning module cannot be published.

### B.2.5 The presentation vocabulary

The brief names ten widget types. They are **presentations**, not behaviours:
each renders one of Part XXI's eight behavioural kinds. Separating the two is
what stops "pie chart" from becoming a licence to show a number with no queue.

| Brief's type | Renders | Underlying kind (§XXI.11.3) | Drill-through is | Notes |
|---|---|---|---|---|
| **count** | one integer | `action_queue` / `exception_list` | the rows counted | The canonical widget |
| **amount** | one `Money` value | `metric_with_target` | the contributing rows | Currency and grouping per §B.5.4 |
| **trend** | a measure over N periods | `trend` | the selected period's contributors | Period axis from the tenant's calendar, not `date('M')` |
| **pie** | a part-to-whole split, ≤ 8 slices + "other" | `exception_list` grouped | the slice's rows | A slice that does not drill fails publish |
| **bar** | a measure by one dimension | `exception_list` grouped | the bar's rows | Horizontal for long labels — a localisation constraint (§B.5.4) |
| **table** | the top N rows with inline actions | `action_queue` | the full list | `max_rows` mandatory; this is a queue, not a report |
| **alert** | a threshold breach with severity | `exception_list` | the breaching rows | Severity from `thresholds[]`; never a bare colour |
| **KPI** | a value against a target with a delta | `metric_with_target` | the contributors | Target is configuration, per scope |
| **compliance** | a score or a ladder position | `metric_with_target` | the failing items | `R-XXI-127`: assessed and unassessed shown separately |
| **attendance** | today's/yesterday's status split | `action_queue` | the employees in that status | "Today" resolves per §B.5.6 in the location's zone |

**R-B-062** Every presentation MUST declare a drill target and at least one
action, **including the chart presentations**. A pie chart whose slices do not
open a permitted, actionable list is refused at publish time with the message
naming the slice dimension.
*Test:* extend `R-XXI-119`'s dashboard-conformance test to assert
`drill_target IS NOT NULL AND actions <> []` for every presentation, chart kinds
included.

**R-B-063** A chart MUST be readable without colour alone: pattern or label
encoding in addition to hue, and a contrast-checked palette in both themes
(§B.4.11 accessibility).

**R-B-064** A chart's underlying rows MUST be retrievable as a table by keyboard
and by screen reader — a chart is a presentation of a queue, and the queue is the
accessible representation.

### B.2.6 Scoping, refresh, caching and staleness

**R-B-065** A widget's cache key MUST include **the resolved scope hash**, the
locale, the timezone, the definition version, the dataset version and the
parameter fingerprint. A cache keyed on `(dashboard, widget)` alone is a
cross-user data leak and is prohibited.
*Test:* two users of different scopes load the same dashboard within the TTL and
receive their own numbers; a cache-key unit test asserts the scope hash is
present.

**R-B-066** `refresh_policy` (Part XXI's field) resolves as:

| Policy | Behaviour |
|---|---|
| `on_load` | Computed per load, subject to the cache TTL |
| `interval(n)` | Re-fetched every *n* seconds while the dashboard is visible; **n is configuration**, never a literal |
| `on_event(event_key)` | Invalidated by a domain event and re-fetched — e.g. `workflow.task.assigned` invalidates the approvals widget |

*Rationale — `K/BR-NOT-700`: the notification bell polls every 60 000 ms as a
literal in browser JavaScript, unconfigurable and unrelated to the actual event
rate.*

**R-B-067** A value served from cache MUST display its **as-at instant** in the
viewer's timezone. A dashboard that does not say how old its numbers are invites
a decision on stale data.

**R-B-068** Event-driven invalidation MUST be best-effort and MUST NOT be the
only freshness guarantee: every widget also carries a TTL ceiling, so a dropped
event degrades freshness rather than freezing a number indefinitely.

**R-B-069** A widget over budget (`R-XXI-124`) MUST render "not computed" with a
retry control and MUST emit `dashboard.widget.budget_exceeded`. It MUST NOT
render a partial or estimated number.

**R-B-070** A dashboard MUST have a **whole-dashboard budget** — total statements
and total wall time — asserted in CI against the seeded 10 000-employee, 5-year
tenant, in addition to the per-widget budget. Widgets load independently so one
slow widget never blocks the page.

### B.2.7 Personal dashboards

**R-B-071** Role dashboards (Part XXI §XXI.11.4) are the default and are
configuration. A user with `dashboards.personalise` MAY additionally:
reorder widgets, hide widgets, add widgets **from the catalogue their permissions
already allow**, and set widget-level parameter defaults (a branch filter, a
period). They MAY NOT create a widget, change a threshold that is a tenant
policy, or bind a data source.

**R-B-072** A personal layout MUST be additive over the published role dashboard,
stored per user, and **resettable to the published layout in one action**. When
the role dashboard's published version changes, new widgets appear and removed
widgets disappear; personal ordering is preserved where it still applies.

**R-B-073** A personalisation MUST NOT be able to widen scope, reveal a widget
the user's permissions deny, or persist a widget after the permission is revoked.
Permission is re-evaluated on every load.

**R-B-074** Personal layouts MUST be excluded from configuration export/import
and from packs — they are user preference, not tenant configuration.

**PRODUCT OWNER DECISION REQUIRED — D-B-04: is personalisation in v3.0?**
**Recommendation: yes, but only the reorder/hide/add-from-permitted-catalogue
subset above.** It is cheap on top of the widget model, it is the single most
common user request against fixed dashboards, and withholding it pushes customers
back to asking Ametecs for a card. Full personal *authoring* (a user creating
widgets) is not recommended for v3.0, because a widget is a query and a query
budget is a shared resource.

### B.2.8 The drill-through contract

**R-B-075** Drill-through MUST be a typed, testable interface, not a URL
convention:

```
DrillTarget
  target_kind        screen | report | register_view
  target_key         ui_screens.screen_key | report_key | view_key
  filter_bindings[]  { target_filter_key ← widget filter or parameter }
  as_at              the widget's as-at instant, carried through
  sort               the widget's sort, carried through
  expected_count     the widget's number, carried for assertion
```

**R-B-076** On arrival, the target MUST show a list whose row count equals
`expected_count` at the same as-at instant, or MUST display a reconciliation
notice naming the difference and its cause (typically: the data changed between
the cached widget and the live list).
*Test:* `R-XXI-120`'s count-equals-list assertion runs through the full
drill-through path, not only against the query.

**R-B-077** Every action offered in the drilled list MUST be permission-checked
server-side at render **and** at execution, and bulk actions MUST be idempotent
and individually audited.
*Rationale — Law 5, and `K` §7.1: in SmartPRS 2 the only enforcement is that the
button is hidden.*

**R-B-078** A widget MUST NOT offer an action the viewer cannot perform. Where a
count includes rows the viewer may see but not act on, the widget states both
figures — "12 expiring, 4 you can action" — rather than offering an action that
will fail.

### B.2.9 The brief's worked examples, with dataset, threshold and action

Part XXI §XXI.11.5 gives these examples with their drill-through and actions.
This table adds the two columns a builder needs — **which dataset produces the
number, and where the threshold lives** — and is the acceptance criterion for
§B.2's data binding.

| Widget | Number | Dataset + filter | Threshold (configuration) | Drill target | Actions |
|---|---|---|---|---|---|
| **Missing PCC** | "7 employees missing PCC" | `compliance.item` · `item_type = PCC AND state IN (due, overdue)` | none — any count > 0 is actionable; severity band from the item type's risk level | Command Centre register view `compliance.by_type` | request document (bulk) · upload on behalf · assign owner · waive with reason · view gate exposure |
| **Payroll approvals** | "3 payroll records require approval" | `workflow.task` · `subject_entity = payroll_run_line AND assignee resolves to me AND state = pending` | none | Payroll approvals inbox | approve · reject with reason · return for edit · "How was this calculated?" |
| **Absent yesterday** | "5 absent yesterday" | `attendance.day` · `date = yesterday(location zone) AND status_code = absent` | `alert_above` (default 0) — a tenant may want the tile only above N | Attendance day list | mark leave · raise regularisation · notify employee · view punches |
| **Devices offline** | "2 biometric devices offline" | `metric: device.health` · `state = offline` | `offline_after_minutes` — per tenant, per device (Part XI §XI.7.1 schedule) | Devices screen filtered `health = offline` | test connection · view last punch · view pending punches · raise ticket |
| **PF due** | "PF due in 4 days" | `compliance.item` · `head = PF AND period = current AND state IN (open, due)` | `lead_days` per obligation type — the alert ladder's rung offsets (Part XXI §XXI.12 row 9) | Command Centre obligation | prepare ECR · view reconciliation · record payment · record filing · reassign owner |
| **Documents expiring** | "12 documents expire this month" | `document.instance` · `valid_to BETWEEN today AND end_of_month(zone)` grouped by type | `horizon_days` (default: end of current month) — configuration, not a literal | Command Centre grouped by document type | request renewal (bulk) · upload · verify · extend with reason |
| **Not assessed** | "4 checks could not be performed" | `compliance.item` · `assessment_basis = not_assessed` | none — `R-XXI-127` requires it separate from the assessed count | Command Centre | view reason · fix configuration · re-run assessment |
| **Blocking payment** | "9 subjects blocking payment" | `compliance.gate_decision` · `verdict = block AND context_open` | gate matrix severity (Part XXI §XXI.12 row 13) | Gate decisions | view failing item · override with reason (permissioned) · fix the item |
| **Runs awaiting approval** | "2 payroll runs awaiting approval" | `payroll.run` · `state = pending_approval` in scope | SLA hours before the tile escalates severity | Payroll runs | open preview exceptions · approve · return |
| **Queue health** | "oldest pending job 14 min" | `metric: queue.oldest_pending_seconds` | `warn_seconds` / `critical_seconds` — configuration (spine S18) | Operations screen | view failed jobs · retry · restart worker guidance |

**R-B-079** Every threshold in the table above MUST be a `thresholds[]` row on the
widget with an SPX `value_expr`, resolvable per scope. **No threshold is a literal
in code, in the client, or in a query.**
*Test:* a static check asserts no numeric literal in a widget renderer; a
configuration test changes `horizon_days` from 30 to 60 and asserts the number and
the drill list both change.

**R-B-080** The three fields this section adds to Part XXI's
`cfg_dashboard_widget` are recorded as extensions **owned by Part XXI**:
`data_source_kind_code`, `presentation_code`, `aggregate_code`. §B.10 records this
as a cross-part reconciliation item rather than a second table.

### B.2.10 Explainability *(spine S15)*

**R-B-081** Every widget number MUST expose "How was this number produced?":

```
Widget          Documents expiring this month
Number          12          as at 03-Sep-2026 11:38 IST (cached 4 min, TTL 15 min)
Source          dataset document.instance v2.1
Filter          valid_to BETWEEN 2026-09-03 AND 2026-09-30
                horizon_days = 30   ← Studio → Dashboards → HR Operations
Scope applied   department IN (Collections, Field Ops)  ← your role "HR Manager"
Of which        12 visible · 12 you can action
Drill           Command Centre → documents, grouped by type (12 rows expected)
```

### B.2.11 Configuration surface

| # | What a tenant administrator can change without code | Where | Scoped | Effective-dated | Approval |
|---:|---|---|---|---|---|
| 1 | Create a dashboard; set its audience expression | Studio → S11 → Dashboards | yes | yes | no |
| 2 | Add, remove, reorder widgets; set layout and breakpoints | same | yes | yes | no |
| 3 | Bind a widget to a report definition or dataset + filter | same | yes | yes | no |
| 4 | Choose the presentation (count, pie, bar, trend, table, KPI…) | same | yes | yes | no |
| 5 | Set thresholds, severities and target values | same | yes | yes | no |
| 6 | Set the drill target and the actions offered | same | yes | yes | no |
| 7 | Write the empty-state text (per locale) | same | yes | yes | no |
| 8 | Set refresh policy, interval and cache TTL | same | yes | yes | no |
| 9 | Set `max_rows` within the dataset's ceiling | same | yes | yes | no |
| 10 | Set which role sees which dashboard, and the default landing dashboard per role | same | yes | yes | no |
| 11 | Personalise one's own layout | the dashboard itself | user | n/a | no |

**Not tenant-changeable:** the requirement that every widget has a drill target
and an action; the viewer-scope rule; the cache-key composition; the budget
ceilings; the platform dashboard's separation.

*Section 127 worked case:* **"a tile showing agents with 3+ late arrivals over 10
minutes this payroll month, so the manager can action them"** — dataset
`attendance.day`; the count comes from the same `agg.count(event, window,
predicate)` primitive the rule engine uses (spine S5.5), exposed as a declared
measure; threshold `3` and `10 minutes` are widget threshold rows; drill to the
attendance list filtered to those employees; actions: raise regularisation,
apply the late policy's penalty for review, notify. **No code.**

### B.2.12 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/dashboards` | `dashboards.view` | Dashboards the caller may see, in audience order |
| GET | `/dashboards/{key}` | `dashboards.view` | Layout + widget definitions, resolved for the caller |
| GET | `/dashboards/{key}/widgets/{wkey}/data` | `dashboards.view` | The number, the as-at instant, the drill target, the actions |
| GET | `/dashboards/{key}/widgets/{wkey}/rows` | `dashboards.view` | The drill list, cursor-paginated — the same query without the aggregate |
| POST | `/dashboards` · PATCH `/dashboards/{key}` | `dashboards.definition.manage` | Draft |
| POST | `/dashboards/{key}/versions/{v}/publish` | `dashboards.definition.manage` | Conformance check runs here (`R-B-062`) |
| PUT | `/me/dashboards/{key}/layout` | `dashboards.personalise` | Personal layout |
| DELETE | `/me/dashboards/{key}/layout` | `dashboards.personalise` | Reset to published |

**R-B-082** `…/widgets/{wkey}/data` and `…/widgets/{wkey}/rows` MUST be generated
from the **same** compiled definition, in the same request path, so `R-XXI-120`
cannot be violated by a later edit to one of two code paths.

### B.2.13 Events

`dashboard.published` · `dashboard.widget.budget_exceeded` ·
`dashboard.widget.errored` (drives the degraded state, `R-XXI-123`) ·
`dashboard.personalised` (audited at low severity) ·
`dashboard.action.invoked` (the action, the row count, the actor — this is the
measurement that tells the Product Owner whether "actions not counts" is working).

**R-B-083** `dashboard.action.invoked` MUST be recorded, and the ratio of widget
views to actions invoked MUST be visible to the Product Owner as a product
metric. *A dashboard that is never acted on has failed its own design principle,
and only telemetry can say so.*

### B.2.14 Edge cases

| # | Situation | Behaviour |
|---:|---|---|
| 1 | The widget's query fails | Degraded state naming the widget; **never zero** (`R-XXI-123`) |
| 2 | The number is genuinely zero | The declared empty state, phrased as completion (`R-XXI-126`) |
| 3 | The viewer's scope is empty | Empty state plus a disclosure that their scope contains no subjects |
| 4 | The bound report definition is retired | The widget renders a configuration-error state naming the definition; publication of the retirement lists the widgets affected |
| 5 | The widget's dataset major version bumps | As `R-B-015` for reports: the dashboard shows a migration task |
| 6 | Cached number, live list differs | The reconciliation notice (`R-B-076`) |
| 7 | A user loses the permission mid-session | Next load omits the widget; the cached value is not served |
| 8 | Two dashboards claim the same audience | Both are offered; the user's default is their preference, or the higher S4 priority; **no silent selection** |
| 9 | The tenant has no dashboard for a role | The "My work" dashboard (Part XXI §XXI.11.4) is the platform default and is always present |
| 10 | A widget's threshold expression fails to evaluate | Publish-time type error; at run time, the degraded state — never an unthresholded raw number styled as normal |
| 11 | A platform user with no tenant loads a dashboard | The platform dashboard only (`R-XXI-125`); **never** the tenant card set with the tenant filter skipped — `H/NEW-DSH-102` explicitly |
| 12 | A chart with more categories than slices allowed | Top N plus "other"; "other" drills to the remainder |

### B.2.15 Tests

| ID | Scenario | Expected |
|---|---|---|
| T-B-025 | Every published widget in every shipped dashboard | Has a drill target and ≥ 1 action; charts included (`R-B-062`) |
| T-B-026 | Widget number vs drill list count, every widget kind, seeded fixture | Equal (`R-XXI-120`) |
| T-B-027 | Two users, different scopes, same dashboard, inside the cache TTL | Each sees their own number; cache key includes the scope hash |
| T-B-028 | A platform user with `tenant_id` unresolved | Receives the platform dashboard or nothing; never installation-wide tenant totals |
| T-B-029 | Widget data source errors | Degraded state; `dashboard.widget.errored` emitted; no zero rendered |
| T-B-030 | `horizon_days` changed 30 → 60 in the Studio | Number and drill list both change; no deployment |
| T-B-031 | Whole-dashboard budget on a 10 000-employee, 5-year tenant | Within the declared statement and time budget |
| T-B-032 | A user personalises, then the role dashboard is republished with a new widget | New widget appears; personal ordering preserved; reset restores published |
| T-B-033 | A user loses a permission that a personalised widget requires | Widget disappears on next load; no cached value served |
| T-B-034 | Keyboard-only and screen-reader traversal of a pie widget | The underlying rows are reachable as a table (`R-B-064`) |

### B.2.16 Data model

No new tables. `cfg_dashboard` and `cfg_dashboard_widget` (Part XXI §XXI.11.2)
gain three columns (`R-B-080`), and this section adds two user-preference tables,
which are **not** configuration artefacts.

#### `user_dashboard_layouts` — A1 (user preference)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | Law 6 |
| `user_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `dashboard_key` | VARCHAR(80) | N | — | The published dashboard being personalised |
| `based_on_config_version_id` | BIGINT UNSIGNED | N | — | Detects "the role dashboard moved on" |
| `is_default_landing` | TINYINT(1) | N | 0 | Which dashboard opens on login |
| `updated_at`, `created_at` | DATETIME | N | — | UTC |

PK `id` · Unique `(tenant_id, user_id, dashboard_key)` · **Tenant scope:** yes ·
**History:** none (preference) · **Soft delete:** no — deleting resets ·
**Audit:** low-severity event only · **PII:** none · **Export:** excluded from
configuration packs (`R-B-074`).

#### `user_dashboard_widget_prefs` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `layout_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `widget_key` | VARCHAR(80) | N | — | |
| `seq` | SMALLINT UNSIGNED | N | — | Personal order |
| `is_hidden` | TINYINT(1) | N | 0 | |
| `is_added` | TINYINT(1) | N | 0 | Added from the permitted catalogue |
| `parameter_defaults_json` | JSON | Y | NULL | e.g. a preferred branch filter |

PK `id` · Unique `(layout_id, widget_key)` · **Tenant scope:** yes · **PII:**
none.

---

## B.3 The Custom Form Builder *(brief Section 57)*

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** SmartPRS 2 has no equivalent: its
request modules are a fixed nine-entry PHP registry (`RQ_MAP`,
`AppController.php:3902-3947`, `K` §5.2 item 29) and its 58 "masters" are a
hard-coded PHP array over 54 tables (`MasterController::def():30-493`,
`H/NEW-MST-101` — *"the single largest configurability gap in the product"*).

### B.3.1 Purpose and the one sentence that governs it

A customer must be able to build their own request types — travel request, asset
request, work-from-home request, uniform request, ID card request, any custom HR
request — without a release.

> **A custom form is not a new module. It is a configured entity plus a
> configured workflow.**

Everything else in this section follows from that sentence. If a capability
cannot be assembled from machinery that already exists, it is not in v3.0.

### B.3.2 Why the blueprint blocked this, and how it is unblocked

Part XXXV GAP 2 records the obstruction precisely:

> *"Part X adds a **field** to an existing entity … It cannot create a new entity
> or a new form. Part VI's Configuration Studio edits **declared artefact types**.
> A new artefact type is added at `config_artefact_types` — which Part V
> `R-V-050` explicitly names as the single point at which a new configurable
> concept is added — and that is an Ametecs migration, not a tenant action. So a
> customer who wants a 'Vehicle Damage Report' form with six fields, an approval
> chain and a register must ask Ametecs for a release."*

**R-B-084** The obstruction is removed by shipping **one** artefact type,
`custom_form`, and **one** entity archetype, `custom_form_record`, in the platform
release. Every tenant form is an **instance** of that artefact type, not a new
artefact type. No migration, no `config_artefact_types` row, no release.

```
Ametecs ships, once:
    config_artefact_types += 'custom_form'
    entity archetype       'custom_form_record'   (a row shape, not a table per form)
    permission namespace   'form.*'               (reserved)
    dataset template       'form.<form_key>'      (auto-registered on publish)

A tenant then creates, at runtime, with no DDL and no release:
    cfg_form_definitions row          the form
    entity_definitions row            entity_key = 'form.travel_request'
    field_definitions rows            its fields (Part X)
    field_surface_rules rows          who sees/edits what, per surface
    a workflow binding                (Part IX R-IX-014, B3 entity binding)
    notification subscriptions        (Part XIX)
    document type bindings            attachments
    a report definition               its register (§B.1) — auto-generated
    a dashboard widget                its queue (§B.2) — optional
```

**R-B-085** Creating a form MUST NOT execute DDL, create a table, create a column,
or require a deployment (Law 2). Values are typed rows in `entity_field_values`
(spine S9); the record header is a row in `custom_form_records`.
*Rationale — SmartPRS 2 creates 49 tables and ~60 columns from controllers so that
*"the effective schema of any given install is a function of which screens have
been opened, in what order, since the install"* (`H` §2.3). The form builder is
precisely the feature that would make that pathology unbounded.*

### B.3.3 What composes what

| Capability the form needs | Provided by | Not re-implemented here |
|---|---|---|
| Fields, types, labels, help text, groups, ordering, defaults | Part X `field_definitions` | ✔ |
| Value lists | Part X `value_sets` | ✔ |
| Per-role, per-surface visibility and required-ness, including **conditional** (`required(conditional SPX)`) | Part X `field_surface_rules` | ✔ |
| Validation | Part X `validation_expr` (SPX) + the rule engine for cross-field rules | ✔ |
| Uniqueness | Part X `uniqueness_scope`, database-constrained | ✔ |
| Masking, PII class, retention | Part X | ✔ |
| Approval chain, delegation, escalation, SLA, maker-checker | Part IX workflow engine | ✔ |
| State vocabulary and legal transitions | `status_transitions` (Part IX / Part XXVIII) | ✔ |
| Alerts and reminders | Part XIX notification engine | ✔ |
| Attachments | The document engine + `document_types` | ✔ |
| Numbering (`TRV-2026-00147`) | Part X `code_sequence` | ✔ |
| Register, export, scheduling | §B.1 | ✔ |
| Queue widget | §B.2 | ✔ |
| Screen rendering | §B.4 metadata-driven rendering | ✔ |
| Labels in Hindi | §B.5 message keys | ✔ |

**R-B-086** A custom form MUST NOT introduce a new engine, a new approval path, a
new notification sender, a new storage mechanism or a new screen renderer. Where
one of the above cannot serve, the gap is a platform requirement against the
owning part, recorded in the escape-hatch register (`R-B-104`), not a special case
inside the form builder.

### B.3.4 The form definition artefact

```
cfg_form_definition
  form_key                  'travel_request'
  entity_key                'form.travel_request'        -- derived, immutable
  name_key, description_key, icon_key, category_key
  subject_kind              self | employee | org_unit | asset | none
  requester_eligibility     SPX Boolean — who may raise it
  subject_eligibility       SPX Boolean — about whom it may be raised
  code_sequence_ref         'TRV-{FY}-{#####}'
  sections[]                { section_key, label_key, seq, visibility_expr,
                              is_collapsible, columns (1|2|3) }
  layout_items[]            { seq, section_key, item_kind (field|static_text|
                              divider|computed|attachment_slot|subform),
                              field_key, column_span, visibility_expr }
  attachments[]             { document_type_key, is_required, max_bytes,
                              mime_allow_list, evidence_class }
  workflow_binding          workflow_code (B2/B3 per Part IX R-IX-013)
  state_machine_ref         status_transitions key
  completion_actions[]      typed, from the closed catalogue (§B.3.7)
  notification_subscriptions[]  event_key → audience → template (Part XIX)
  sla_hours, escalation_policy_ref
  register_report_key       auto-generated on publish
  retention_class_code
  is_ess_visible, is_mobile_visible
  -- spine S4 envelope: scope, priority, effective_from/to, status, version
```

**R-B-087** A form definition MUST be a spine S4 artefact — versioned, scoped,
effective-dated, diffed before publish, and approval-gated where declared
`high_risk`. **An in-flight request completes on the form version it was raised
on**, exactly as an in-flight workflow instance does (spine S8).
*Rationale — an employee who submitted a travel request under one field set must
not have their submission silently reinterpreted under another.*

**R-B-088** `entity_key` MUST be derived from `form_key` and MUST be immutable
after first publication. Renaming a form changes its label, never its key.
*Rationale — `E/DEF-LVE-07`: renaming a leave type orphans all history.*

**R-B-089** Publishing a form MUST validate: every referenced field exists and is
published; every `visibility_expr` type-checks as Boolean against the form's
declared symbol scope; the workflow exists and is published; every notification
template exists for every channel in its plan; the code sequence is unique; the
state machine's terminal states are reachable; and no two forms claim the same
`form_key` or `code_sequence` prefix.

**R-B-090** A published form MUST NOT be deleted. It is retired by closing its
effective range; existing records remain readable, reportable and exportable
under the version that created them (Law 3).

### B.3.5 Layout and conditional visibility

**R-B-091** Layout MUST be data: sections, ordering, column spans, collapsibility
and breakpoints. Rendering is §B.4's metadata-driven renderer, so a form needs no
screen code.

**R-B-092** Conditional visibility and conditional requiredness MUST be
**SPX Boolean expressions** evaluated **on the server** on write, and evaluated
in the client only as a convenience for the user.

```
Field                    visibility_expr                       required
travel_mode              always                                always
flight_class             travel_mode = 'AIR'                    when visible
advance_amount           needs_advance = true                   when visible
advance_justification    needs_advance = true AND
                         advance_amount > policy.advance_free_limit
                                                               when visible
international_docs       destination_country <> org.home_country when visible
```

**R-B-093** A field that is hidden by its condition MUST be **rejected if
supplied**, not silently accepted and not silently ignored. A field that is
required by its condition and absent MUST fail validation with the condition
stated in the message.
*Rationale — Law 5 (the UI never enforces anything) plus spine S9's PATCH
semantics; SmartPRS 2's employee save is a full-record replace that nulls every
omitted key, which behind a public API would be catastrophic.*

**R-B-094** A conditional expression MUST be able to read: the form's own fields,
`employee.*`, `org.*`, `service.*`, `policy.*` and the form's parameters — and
nothing else. Reading another record is not available; a form is a form.

### B.3.6 The request lifecycle

```
draft ──submit──▶ pending_approval ──▶ approved ──▶ fulfilled ──▶ closed
  │                    │      │                        │
  │                    │      └─returned_for_edit──────┘
  │                    └─rejected
  └─cancelled (by requester, before a decision)         withdrawn (after, permissioned)
```

**R-B-095** The state vocabulary MUST be a `status_transitions` artefact —
**data**, so a tenant can add "awaiting_budget_confirmation" without a release —
and the workflow engine MUST own every transition. The form does not decide who
may approve; the workflow does.
*Rationale — spine S8: one engine, zero module-specific approval code anywhere in
SmartPRS 3.*

**R-B-096** A decided step MUST be immutable and re-deciding MUST be impossible
(spine S8). *SmartPRS 2 re-fires transfer orders and increment letters on
re-approval.*

**R-B-097** Self-approval MUST be prohibited by default on every generated form,
overridable only by an explicit workflow configuration that is audited.

**R-B-098** Every transition MUST write the audit ledger with actor, timestamp,
IP, decision, comment and the entity snapshot hash (spine S14).

### B.3.7 Completion actions — the closed catalogue

Most custom forms are a record plus an approval, and that is the honest majority
case. Some must *do* something on approval.

**R-B-099** A completion action MUST come from the **closed, typed, versioned
action catalogue** (spine S6). Customer-supplied code is never accepted (spine
S5). The v3.0 catalogue available to a custom form:

| Action | Effect | Owning module |
|---|---|---|
| `NOTIFY(event_key)` | Raise a notification event | XIX |
| `WRITE_AUDIT(reason)` | Explicit ledger note | XXIII |
| `RAISE_COMPLIANCE_ITEM(type, due_expr)` | Create an obligation | XXI |
| `REQUIRE_DOCUMENT(type)` | Demand evidence before closure | X / XXI |
| `CREATE_RECOVERY(type, amount_expr, schedule_ref)` | An advance to be recovered | XVIII |
| `ADD_COMPONENT(code, amount_expr)` | A payroll input, subject to the run's cut-off and approval | XIV / XV |
| `ASSIGN_ASSET(asset_ref, from_date)` | Asset issue | XX (asset register) |
| `CREATE_ATTENDANCE_EXCEPTION(kind, dates)` | E.g. an approved work-from-home day | XI |
| `SET_FIELD(entity, field, value_expr)` | Write a declared field on the subject employment, subject to that field's surface rules and audit | X |
| `START_WORKFLOW(code)` | Chain a second workflow | IX |

**R-B-100** A completion action MUST execute inside the domain service that owns
it, honouring that module's own validation, permissions, period locks and audit.
A form never writes another module's tables.

**PRODUCT OWNER DECISION REQUIRED — D-B-05: how far do completion actions go in
v3.0?**
**Recommendation:** ship the ten above. `ADD_COMPONENT` and `CREATE_RECOVERY` are
the two that carry money and therefore the two that make the feature commercially
valuable (a travel advance, a uniform deduction), and both already exist as rule
actions in spine S6 with their own guards. Everything beyond this list is a
platform request through the escape-hatch register, so demand is measured before
it is built.

### B.3.8 Five worked forms

| Form | Fields (abridged) | Conditional rules | Workflow | Notifications | Completion action | Register |
|---|---|---|---|---|---|---|
| **Travel request** | from/to dates, destination, purpose, mode, class, estimated cost (Money), advance needed?, advance amount, justification, attachments | `class` visible when mode = AIR; `advance_*` visible when advance needed; justification required above `policy.advance_free_limit` | `submit → n_manager(RL 1) → condition(cost > ₹50,000) → finance → finalise` | on submit to manager; on approval to requester + travel desk; SLA reminder at 24 h | `CREATE_RECOVERY(advance, amount, schedule)` when an advance is approved | `form.travel_request` dataset; register report; "pending > 3 days" widget |
| **Asset request** | asset type (value set), quantity, justification, needed by, delivery location | `justification` required when type is in the high-value set | `submit → n_manager(RL 1) → IT asset owner → finalise` | on each step; on issue | `ASSIGN_ASSET` + `REQUIRE_DOCUMENT(acknowledgement)` | Asset issue register; "awaiting issue" queue |
| **Work-from-home request** | dates from/to, reason, contact number, half/full day, manager comment | `contact number` required when duration > 1 day | `submit → n_manager(RL 1) → finalise`; auto-approve when `days <= policy.wfh_auto_days` | on decision; digest to HR weekly | `CREATE_ATTENDANCE_EXCEPTION(wfh, dates)` — so attendance treats the day correctly and payroll follows | WFH register; "WFH today" attendance widget |
| **Uniform request** | uniform item (value set), size, quantity, replacement or new, chargeable? | `chargeable` defaults from `policy.uniform_free_sets` and the employee's issue history | `submit → admin store → finalise` | on issue | `CREATE_RECOVERY(uniform, amount, schedule)` when chargeable | Issue register with cost recovery status |
| **ID card request** | reason (new/lost/damaged/renewal), photo attachment, delivery branch | `police report` attachment required when reason = lost | `submit → HR → finalise` | on ready for collection | `NOTIFY` + `WRITE_AUDIT` (a lost card is a security event) | ID card issue register |

**R-B-101** These five MUST ship as **example form definitions** in the demo
tenant, buildable from the administrator manual (§B.6.4), and MUST be used as the
acceptance fixtures for §B.3's tests. They are examples, not product features: a
tenant may delete all five.

### B.3.9 Reporting and export from a custom form

**R-B-102** Publishing a form MUST auto-register a dataset `form.<form_key>` with:

| Kind | Columns |
|---|---|
| Dimensions | `request_code`, `state`, `requester`, `subject_employee`, org assignments **as at submission**, form version, workflow instance, every form field whose type is a dimension |
| Dates | `submitted_on`, `decided_on`, `closed_on`, plus every `Date` field |
| Measures | `count`, `sla_breach_count`, `days_to_decision` (avg/max), and `SUM`/`AVG` over every `Money` and `Number` field |
| Scope anchor | `subject_employee` where `subject_kind` is employee-shaped; otherwise `requester` |

**R-B-103** Field-level permission and masking on the form MUST carry into the
dataset, so a manager who cannot see the `advance_justification` field on the
screen cannot see it in the register or the export either.

**R-B-104** A default register report definition MUST be generated with the form
and MUST be editable like any other definition. The tenant gets a working register
on day one without authoring one.

### B.3.10 Permissions — and one honest conflict

Publishing a form needs permissions for it. Part XXII `R-XXII-010` states that
`verb.resource` codes are **platform-owned and closed**. A tenant-created form
implies tenant-created permission codes. That is a genuine, unresolved conflict
between two parts and it is stated rather than smoothed over.

**PRODUCT OWNER DECISION REQUIRED — D-B-06: how are custom-form permissions
expressed?**

| Option | Mechanism | For | Against |
|---|---|---|---|
| **A. Reserved generative namespace (recommended)** | Publishing `travel_request` generates `form.travel_request.raise` / `.view` / `.approve` / `.manage` into a **reserved `form.*` namespace** that Part XXII declares closed to everything else | Roles read naturally; per-form granularity; the catalogue stays generated rather than hand-edited | Part XXII's "closed catalogue" becomes "closed plus one generative namespace", and `R-XXII-010` needs amending |
| B. Four generic codes + data binding | `form.raise` / `form.view` / `form.approve` / `form.manage`, with *which forms* bound as a data scope | Catalogue stays literally closed | Every role that may raise any form may raise all of them unless a second binding layer is built — which is the generative namespace with extra steps |

**Recommendation: A**, with `R-XXII-010` amended to read *"codes are
platform-owned and closed, except within the reserved `form.*` namespace, whose
codes are generated by form publication and are never hand-authored."* §B.10
records this as a required amendment to Part XXII, not as a resolved matter.

**R-B-105** Whichever option is chosen, a form's permissions MUST be enforced
server-side on every operation (raise, view, list, approve, export) and MUST fail
closed. The absence of a form from a user's navigation is never the control.

### B.3.11 Configuration surface

| # | What a tenant administrator can change without code | Where | Scoped | Effective-dated | Approval |
|---:|---|---|---|---|---|
| 1 | Create a form: key, name, icon, category, subject kind | Studio → S3 → Forms | yes | yes | on publish |
| 2 | Add/remove/reorder fields and sections; set layout | same (fields via Part X) | yes | yes | on publish |
| 3 | Set conditional visibility and conditional requiredness | same | yes | yes | on publish |
| 4 | Set validation expressions and uniqueness | Part X | yes | yes | on publish |
| 5 | Choose or author the approval workflow | Studio → S9 | yes | yes | yes |
| 6 | Set the state vocabulary and legal transitions | Studio → S3 | yes | yes | yes |
| 7 | Set notifications: events, audiences, channels, templates, reminders | Studio → S14 | yes | yes | no |
| 8 | Set attachments: types, requiredness, size and MIME limits | same | yes | yes | no |
| 9 | Set the request numbering pattern | Studio → S3 → Code sequences | yes | yes | no |
| 10 | Set the completion actions | Studio → S3 → Forms | yes | yes | **yes** (money-moving actions) |
| 11 | Set SLA and escalation | Studio → S9 | yes | yes | no |
| 12 | Set retention class and PII classes per field | Studio → Data governance | yes | yes | yes |
| 13 | Edit the auto-generated register report | Studio → S11 | yes | yes | no |
| 14 | Publish to ESS and to mobile | Studio → S3 → Forms | yes | yes | no |
| 15 | Translate every label and message | Studio → Localisation (§B.5) | tenant | no | no |

**Not tenant-changeable:** the action catalogue; that a workflow owns transitions;
that hidden fields are rejected on write; that values are rows and never columns.

*Section 127 worked case:* **"a Vehicle Damage Report form with six fields, an
approval chain and a register"** — the exact example Part XXXV GAP 2 says a
customer *"must ask Ametecs for a release"* to obtain. Under §B.3 it is: create
the form, add six field definitions, bind a two-step workflow, accept the
generated register. **One afternoon in the Studio, no release.**

### B.3.12 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/forms` | `form.*.view` (resolved) | Forms the caller may raise or view |
| GET | `/forms/{form_key}/definition` | `form.<key>.view` | Resolved fields for the caller's surface — the client renders from this |
| POST | `/forms/{form_key}/requests` | `form.<key>.raise` | Idempotency-keyed; server re-evaluates conditional rules |
| GET | `/forms/{form_key}/requests` | `form.<key>.view` | Cursor-paginated, scoped |
| GET | `/forms/{form_key}/requests/{uuid}` | `form.<key>.view` | Includes workflow state and history |
| PATCH | `/forms/{form_key}/requests/{uuid}` | `form.<key>.raise` | Draft or `returned_for_edit` only; PATCH semantics |
| POST | `/forms/{form_key}/requests/{uuid}/submit` | `form.<key>.raise` | |
| POST | `/forms/{form_key}/requests/{uuid}/cancel` | `form.<key>.raise` | Before a decision |
| POST | `/forms/{form_key}/requests/{uuid}/attachments` | `form.<key>.raise` | Document engine |
| POST | `/forms` · `/forms/{key}/versions/{v}/publish` | `forms.definition.manage` / `.publish` | Studio |

Approval actions are **not** here: they are workflow-task endpoints (Part IX),
because approving a travel request and approving a payroll run are the same
operation on the same engine.

### B.3.13 Events

`form_definition.published` / `.retired` (→ dataset registration, navigation
refresh, permission catalogue regeneration, docs generation) ·
`form_request.created` · `.submitted` · `.decided` · `.returned` · `.cancelled` ·
`.fulfilled` · `.closed` · `form_request.sla_breached` ·
`form_request.completion_action_failed` (→ operations alert; the request stays
approved and the failure is visible, never silently swallowed).

### B.3.14 Edge cases

| # | Situation | Behaviour |
|---:|---|---|
| 1 | The form is edited while requests are in flight | In-flight requests complete on their own version (`R-B-087`); the new version applies to new requests only |
| 2 | A field is removed from the form | Historic values remain and remain reportable, marked as belonging to a prior version |
| 3 | The requester leaves the organisation mid-approval | The request continues to a decision (it may carry money); the notification audience falls back to the declared owner; the record's subject is unchanged |
| 4 | The approver leaves or is deactivated | Part IX's delegate/backup/escalation applies; never an orphaned task |
| 5 | A completion action fails (a period is locked, the payroll cut-off has passed) | The request is `approved` and `fulfilment_failed` with the reason; an operations alert; a retry action. The approval is not reversed by a downstream failure |
| 6 | Two forms declare the same code sequence prefix | Publish blocked, both named |
| 7 | A conditional expression references a retired field | Publish blocked with the field named |
| 8 | A form is retired with open requests | Retirement closes the effective range; open requests continue; no new requests are accepted; the register still runs |
| 9 | An attachment exceeds the type's size or MIME limit | Rejected with the limit stated; the request is retained as draft |
| 10 | A user raises a form for another employee without the permission | `403`; ESS resolves the subject from the session, never the request body (spine S13) |
| 11 | A form field is marked PII after data exists | Masking applies immediately on read everywhere, including existing register exports |
| 12 | A form's workflow is republished mid-flight | The instance completes on the workflow version it started on (spine S8) |

### B.3.15 Tests

| ID | Scenario | Expected |
|---|---|---|
| T-B-035 | Build the Vehicle Damage Report end-to-end through the API/Studio | Form, fields, workflow, notifications, register — no migration, no DDL, no release (`R-B-085`) |
| T-B-036 | Submit with a conditionally hidden field populated | `422`, field named (`R-B-093`) |
| T-B-037 | Submit missing a conditionally required field | `422` with the condition stated |
| T-B-038 | Raise a request, then republish the form with a new required field | The in-flight request completes on its own version |
| T-B-039 | An employee raises a request for a colleague through ESS | `403`; subject resolved from the session |
| T-B-040 | Approve a travel request with an advance | `CREATE_RECOVERY` executes inside Part XVIII with its own validation and audit |
| T-B-041 | Completion action fails on a locked period | Request `approved` + `fulfilment_failed`; alert raised; approval intact |
| T-B-042 | Cross-tenant: two tenants each with a `travel_request` form | Zero cross-tenant rows in list, detail, register and export |
| T-B-043 | Field marked `pii_class = sensitive` after 500 records exist | Masked in screen, register, export and API for roles without the permission |
| T-B-044 | Retire the form with 12 open requests | Open requests complete; no new requests; register still runs |
| T-B-045 | Two approvers decide the same step concurrently | One decision recorded; the second receives a conflict, never a second decision (spine S8) |
| T-B-046 | The generated register report | Runs, honours scope, honours masking, exports by streaming |

### B.3.16 Data model

Three new tables. Fields, values, value sets, surface rules, workflows, documents
and status transitions are existing tables used unchanged.

#### `cfg_form_definitions` — cfg payload

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `config_version_id` | BIGINT UNSIGNED | N | — | FK CASCADE; spine S4 envelope |
| `uuid` | BINARY(16) | N | — | |
| `form_key` | VARCHAR(60) | N | — | Immutable |
| `entity_key` | VARCHAR(80) | N | — | `form.<form_key>`, immutable (`R-B-088`) |
| `name_key`, `description_key` | VARCHAR(120) | N | — | Message keys |
| `icon_key`, `category_key` | VARCHAR(60) | Y | NULL | |
| `subject_kind_code` | VARCHAR(20) | N | `self` | Ref table |
| `requester_eligibility_expr` | TEXT | Y | NULL | SPX Boolean |
| `subject_eligibility_expr` | TEXT | Y | NULL | SPX Boolean |
| `code_sequence_ref` | VARCHAR(60) | N | — | Part X |
| `workflow_code` | VARCHAR(60) | N | — | Part IX |
| `state_machine_ref` | VARCHAR(60) | N | — | `status_transitions` |
| `completion_actions_json` | JSON | Y | NULL | From the closed catalogue only, validated at publish |
| `sla_hours` | INT UNSIGNED | Y | NULL | |
| `escalation_policy_ref` | VARCHAR(60) | Y | NULL | |
| `register_report_key` | VARCHAR(80) | Y | NULL | Auto-generated |
| `retention_class_code` | VARCHAR(40) | N | — | |
| `is_ess_visible`, `is_mobile_visible` | TINYINT(1) | N | 1/1 | |
| `created_by_user_id`, `created_at`, `updated_at` | | N | — | |

PK `id` · Unique `(tenant_id, form_key, config_version_id)`, `(tenant_id, uuid)`,
`(tenant_id, entity_key, config_version_id)` · Index `(tenant_id, category_key)` ·
**Tenant scope:** yes · **History:** S4 versions · **Soft delete:** no ·
**Audit:** full, field-level · **PII:** none (the definition; its records are
classified per field).

#### `cfg_form_layout_items` — cfg payload child

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `form_definition_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `section_key` | VARCHAR(60) | N | — | Sections are layout items of kind `section` |
| `parent_section_key` | VARCHAR(60) | Y | NULL | |
| `seq` | SMALLINT UNSIGNED | N | — | |
| `item_kind_code` | VARCHAR(20) | N | — | `section`/`field`/`static_text`/`divider`/`computed`/`attachment_slot` |
| `field_key` | VARCHAR(60) | Y | NULL | FK (logical) → `field_definitions` |
| `label_key` | VARCHAR(120) | Y | NULL | For sections and static text |
| `column_span` | TINYINT UNSIGNED | N | 1 | 1–3 |
| `visibility_expr` | TEXT | Y | NULL | SPX Boolean (`R-B-092`) |
| `visibility_ast` | JSON | Y | NULL | Compiled at publish |
| `document_type_key` | VARCHAR(60) | Y | NULL | For `attachment_slot` |
| `is_required_attachment` | TINYINT(1) | N | 0 | |

PK `id` · Unique `(form_definition_id, section_key, seq)` · Index
`(form_definition_id, field_key)` · **Tenant scope:** yes · **Audit:** with
parent.

#### `custom_form_records` — A1

*Purpose:* the request header. Field values live in `entity_field_values` (spine
S9) — one populated typed column per row, indexed — so a new field is never a new
column (Law 2).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | Law 6 |
| `uuid` | BINARY(16) | N | — | Public id — the only id in a URL (spine S2) |
| `entity_key` | VARCHAR(80) | N | — | `form.<form_key>` |
| `form_config_version_id` | BIGINT UNSIGNED | N | — | The version this record was raised on (`R-B-087`) |
| `request_code` | VARCHAR(40) | N | — | From the code sequence |
| `state_code` | VARCHAR(40) | N | `draft` | From `status_transitions` — no database ENUM |
| `requester_user_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `subject_employment_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL when `subject_kind = none` |
| `subject_org_unit_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `org_snapshot_json` | JSON | N | — | The requester's and subject's org/classification assignment **as at submission** — what the register groups by |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK SET NULL |
| `submitted_at`, `decided_at`, `closed_at` | DATETIME | Y | NULL | UTC |
| `submitted_on`, `decided_on`, `closed_on` | DATE | Y | NULL | Business dates (§B.5.8) |
| `business_timezone` | VARCHAR(64) | N | — | The zone those dates were resolved in |
| `sla_due_at` | DATETIME | Y | NULL | UTC |
| `sla_breached_at` | DATETIME | Y | NULL | |
| `fulfilment_state_code` | VARCHAR(30) | Y | NULL | `pending`/`done`/`failed` (edge case 5) |
| `fulfilment_failure_reason` | VARCHAR(255) | Y | NULL | |
| `retention_class_code` | VARCHAR(40) | N | — | Inherited from the definition |
| `created_at`, `updated_at` | DATETIME | N | — | |

PK `id` · Unique `(tenant_id, request_code)`, `(tenant_id, uuid)` · Index
`(tenant_id, entity_key, state_code)`, `(tenant_id, requester_user_id)`,
`(tenant_id, subject_employment_id)`, `(tenant_id, submitted_on)`,
`(workflow_instance_id)` · **Tenant scope:** yes · **History:** state changes in
the audit ledger; values effective-dated where the field declares it · **Soft
delete:** no — retired by state, purged by retention class · **Audit:** full,
every transition · **PII:** varies by field; classified per `field_definitions`,
masked on read, and the record inherits the highest class among its populated
fields for retention purposes.

---

## B.4 User interface architecture *(brief Section 99)*

### B.4.1 Purpose

This section decides what replaces SmartPRS 2's front end: how many there are,
what renders a screen, what happens when the declarative model is not enough, who
decides what a user may see, how escaping works, and what "accessible" means as a
test rather than as an intention.

Spine S2 settles only the framework (`D-02`: React + TypeScript, chosen for
hiring depth in India). Everything else is here.

### B.4.2 What SmartPRS 2 does today, and why it must change

> *"The entire SmartPRS 2 front end is one PHP file."* — `K` §0.3

`AppController::show()` (`AppController.php:63-12533`) reads a static HTML
prototype off disk, splices a **12,060-line JavaScript heredoc**, a 213-line CSS
heredoc and a ~90-key JSON config into it as strings, and returns the result.

| # | Evidence | Consequence |
|---:|---|---|
| 1 | Two front ends; the second is dead — `layouts/app.blade.php` and 17 Blade views calling **ten route names that do not exist**, plus 353 lines of CSS that styles only them (`K` §6.1) | Anyone editing "the layout" edits the wrong one |
| 2 | The live one is a **12,533-line PHP heredoc**; because `$boot` interpolates, the JavaScript may contain no bare `$`, no ES6 template literals, and every regex backslash must be doubled (`K` §0.3) | The language the product is written in is constrained by the string-quoting rules of the language that emits it |
| 3 | **Access control is 100 % presentational** — every decision is `element.style.display = 'none'` (`K` §7.1) | *"Open DevTools, run `go('users')`."* An Employee-role login reaches Payroll, Statutory, Users, Roles, Tenants and Plans |
| 4 | **Four independent fail-open paths**: an unknown Spatie role maps to **Admin** (`:93`, `NEW-FE-014`); a missing permission matrix means **allow** (`:758-763`, `NEW-FE-015`); Super Admin and Admin **bypass the matrix entirely** (`:769`, `NEW-FE-016`); and 33 boot steps run inside `safe(fn)` which swallows every exception (`:530`, `K` §6.5) so *"a silent exception in `applyPerms` or `wireEditionLockdown` disables access control for that session"* | Four ways to end up with full access by accident |
| 5 | **146 screen ids, of which 26 have no permission mapping at all** (`PERM_NAV`, `:655-672`, `K` §6.17) — including `statutory-config`, `sys-updates`, `coa`, `vouchers`, `settlement`, `recovery-reports` | Even a tenant that has fully configured its permission matrix has 26 ungated screens |
| 6 | **142 `innerHTML =` assignments** in the boot script and 13 more in the prototype; **19 escape helpers with four different escape sets, none escaping `'`**; the prototype contains **zero** escape functions (`K` §7.3, §6.19) | A worked stored-XSS path: an employee sets their own name via ESS to an `<img onerror>` payload; an admin opens the directory; the script runs with the admin's session and the CSRF token from `window.__SMARTPRS.csrf` |
| 7 | **90 of the 114 declarative screens are re-registered as `type:'custom'`** (`:3185-3312`, `:2112-3183`), *"which means the declarative `cols`/`fields` metadata for those screens is discarded"* and replaced by a **58-branch `if/else` chain** (`K` §1.3, §6.15) | The one metadata-driven mechanism in the product was built and then overwritten |
| 8 | **No History API** — `pushState` appears zero times; the URL never changes; *"deep links, bookmarks and open-in-new-tab do not work for 145 of the 146 screens"* and Back exits the application (`K` §6.12) | |
| 9 | **1.2 MB per navigation, uncacheable** — `no-store, no-cache, must-revalidate, max-age=0` (`:12527-12531`, `K` §6.13) | The whole product is re-downloaded on every entry, on metered mobile connections |
| 10 | Statutory and money maths **in browser JavaScript**, in several cases with no server equivalent: PF (`basic = gross × 0.5`), ESI, TDS with an **87A cliff that costs ₹62,400 on a one-rupee increase**, GST 9/9/18 literals, commission TDS (`K` §4.1-4.8) | The client is where Law 1 is most comprehensively broken; `spRates()` silently substitutes 17 hard-coded literals when the server fetch fails, *"and the user is not told"* |
| 11 | **The entire client state — roles and their permission matrices included — is one JSON blob** written by `POST /app/state/save` with no role check, no size limit and no schema validation (`K` §2.1, §7.2) | A privilege escalation and a one-request destruction of ~60 collections with no history and no undo |
| 12 | Five CDN dependencies with **no SRI and no fallback**; two different Font Awesome versions (`K` §6.14) | An on-prem install behind a firewall loses maps, Excel export and payments with no degradation path |
| 13 | Screen counts disagree across five sources — 114, 146, 107, "113", 134 (`K` §6.16) | Nobody knows how many screens the product has |

**R-B-106** Every design decision in §B.4 exists because of a numbered finding
above. There is no ornamental requirement in this section.

### B.4.3 One front end

**R-B-107** There MUST be exactly **one** front end: a React + TypeScript
single-page application (spine S2 `D-02`) consuming **`/api/v3` exclusively**.
The server renders one shell document (a title, a root element, a nonce, and
fingerprinted asset references) and nothing else.
*Test:* a CI check asserts that no Blade view outside the shell and the public
token pages emits application markup, and that no application HTML is produced
from a PHP string anywhere.

**R-B-108** The SPA MUST use **no private endpoint**. Every call it makes is a
documented, versioned, OpenAPI-described `/api/v3` operation available to a third
party with the same authorisation (Part XXIV §XXIV.2).
*Rationale — this is the property that keeps the public API honest; spine S2
states it, and this requirement makes it testable.*

**R-B-109** The SmartPRS 2 second front end MUST NOT be carried across in any
form — no Blade layout, no orphaned views, no second stylesheet. Deleting them is
a migration deliverable, not a tidy-up.

**R-B-110** **No business logic in the client.** No statutory rate, tax slab,
proration, wage-base rule, eligibility test, rounding decision or money
calculation may execute in the browser. The client renders values and traces the
server produced.
*Test:* a lint rule fails the build on arithmetic over a value typed `Money` in
client code, and on any numeric literal in a `src/domain-ish` path; a code review
rule forbids a client-side default for a server-supplied statutory value.
*Rationale — finding 10. `spRates()` is Law 1's worst violation in the codebase
and it is in the browser, where nobody was looking.*

**R-B-111** There MUST be **no whole-application client state blob and no
endpoint that accepts one**. Configuration lives in configuration tables and is
read through scoped, permissioned endpoints. `POST /app/state/save` has no
successor.
*Rationale — finding 11: one unauthenticated-beyond-login POST replaces a
tenant's roles, permission matrices, leave types, holidays, templates and ~60
other collections, with last-write-wins across users and no history.*

### B.4.4 The metadata-driven rendering model

```
             field_definitions + field_surface_rules        (Part X)
             value_sets · validation_expr · masking
                              │
   ui_screens row  ──────────►│──────► generated screen
   (kind, entity, permission) │        list · detail · form · wizard
                              │
             cfg_report_definitions  ─► generated report screen   (§B.1)
             cfg_dashboard           ─► generated dashboard        (§B.2)
             cfg_form_definitions    ─► generated request screen   (§B.3)
                              │
                              └──────► registered_component        (§B.4.5)
                                       only where declared, only reviewed
```

**R-B-112** A screen MUST be a **row** in `ui_screens`, not a code branch. The
registry is the product's single screen inventory, and the counts disagreement of
finding 13 is structurally impossible because there is one place to count.

**R-B-113** `ui_screens.required_permission_key` MUST be `NOT NULL`. A screen
without a permission cannot exist.
*Test:* the column's constraint plus a CI sweep asserting every screen key
referenced by the client exists as a row with a permission that exists in Part
XXII's catalogue.
*Rationale — finding 5: 26 screens with no mapping, `PERM_NAV` covering 120 of
146.*

**R-B-114** Screens of kind `entity_list`, `entity_detail`, `entity_form` and
`wizard` MUST be **generated from field definitions and surface rules**. Adding a
field to an entity changes the screen with no front-end work, in the same
resolution cycle as it changes the report column (`R-B-013`).
*Rationale — finding 7: SmartPRS 2 built exactly this and then overwrote 90 of
114 screens, discarding their metadata.*

**R-B-115** The client MUST render from `GET /entities/{key}/fields?surface=…`
(Part X §X.15 API), which returns **the fields resolved for the caller**. A field
the caller may not see is **absent from the payload**, not hidden by the client.
*Rationale — Law 5: hiding a control is a convenience, never a control; and
finding 6's over-sharing bootstrap returned `ctc`, `pan`, bank account and IFSC
for every employee in scope.*

**R-B-116** Screen layout (sections, ordering, column spans, which columns a list
shows by default, default sorts and filters) MUST be configuration, per role and
per scope, editable in the Studio.

### B.4.5 The registered-component escape hatch

Genuinely bespoke screens exist — the payroll run cockpit, the rule builder's
condition tree, the attendance calendar grid, the punch-map view. The declarative
model should not pretend otherwise, and it must not become a permanent bypass.

**R-B-117** Where the declarative model cannot express a screen, the screen's
`ui_screens.kind` is `registered_component` and it points at a row in
`ui_components`: a **named, versioned, code-reviewed React component in the
repository**. It is never an inline string, never generated markup, never a
branch in a dispatch chain.
*Rationale — finding 7's 58-branch `if/else` chain inside a heredoc is what an
unreviewed escape hatch becomes.*

**R-B-118** **Every use of the escape hatch MUST record the capability the
declarative model lacked.** A `ui_components` row is not accepted without:

| Field | Meaning |
|---|---|
| `declarative_gap_reason` | Prose: what the metadata model could not express |
| `missing_capability_code` | A classified reason from a growing, declared vocabulary — e.g. `bulk_grid_edit`, `spatial_view`, `drag_reorder`, `realtime_stream`, `nested_expression_tree`, `multi_pane_compare` |
| `capability_request_ref` | The platform backlog item raised against the owning part |
| `first_used_in_version` | When it appeared |
| `retired_when_capability_shipped` | Set when the declarative model absorbs it and the component is deleted |

**R-B-119** The escape-hatch register MUST be **reviewed on a declared cadence
(recommended: every release)** by the lead architect, who reads the classified
reasons in descending frequency and converts the top ones into declarative
capabilities. The count of registered components, and the count retired, are
tracked release over release.
*Rationale — this is the difference between an escape hatch and a second product.
Without the register the declarative model is bypassed forever and nobody can
prove it; with it, the bypasses are the roadmap for the model.*

**R-B-120** A registered component MUST obey every platform rule the generated
screens obey: it renders from the same resolved-field payload, it never fetches a
private endpoint, it never computes a business value, it never enforces access,
and it declares its own empty, loading and error states.

**R-B-121** A registered component MUST NOT be added to reproduce a layout
preference. "The customer wants three columns" is configuration
(`R-B-116`), not a component. The register's classified reason makes this
auditable.

### B.4.6 Navigation and permission — server-provided, never client-decided

**R-B-122** Navigation MUST be computed **server-side** and returned by
`GET /api/v3/ui/navigation`, resolved from: the caller's permissions × data scope
× module entitlement × edition × licence state × the `ui_screens` registry. The
client renders exactly what it receives and filters nothing.
*Rationale — findings 3, 4 and 5. SmartPRS 2's navigation is decided by literal
role arrays in prototype HTML, by `PERM_NAV`, by `Edition::hiddenNavIds()`, by a
dead plan gate and by eleven ad-hoc DOM manipulations selected by CSS selector
(`K` §5.2 items 1–4).*

**R-B-123** A screen absent from the navigation response MUST also be refused by
its API endpoints. **Defence in depth, both server-side.** The absence of a menu
item is never the control.
*Test:* for every `ui_screens` row, a generated test asserts that a user without
its permission receives `403`/`404` from every endpoint the screen calls — not
merely a missing menu entry.

**R-B-124** The client MUST **fail closed** on every access-shaped uncertainty:

| Situation | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| Unrecognised role | maps to **Admin** (`:93`) | **no navigation**, an explicit error state, a support code |
| Permission payload missing or null | **allow** (`:758-763`) | **deny**; the app renders an error, not an empty-but-permissive shell |
| Privileged role | **bypasses the matrix** (`:769`) | no bypass exists; there is no client-side matrix to bypass |
| A boot step throws | swallowed by `safe()` (`:530`) | **a hard, visible failure with a correlation id**; there is no silent degraded boot |

**R-B-125** There MUST be **no client-side permission matrix**. `spCan()` has no
successor. Action affordances are driven by capability flags the server returns
**with the data** (per row where row-level), and every action is re-authorised on
execution.

**R-B-126** Edition, licence and plan gating MUST be applied server-side in the
same navigation computation, not as a second cosmetic pass.
*Rationale — `K/NEW-FE-020`: `applyPlanGate()` returns immediately;
*"the SaaS plan product promise is not enforced in the UI"* — and it was never
enforced anywhere else either.*

### B.4.7 Routing, state and caching

**R-B-127** Every screen MUST have a **real, deep-linkable URL** using the
History API, so bookmarks, Back, Forward and open-in-new-tab work.
*Rationale — finding 8: this works for 1 of 146 screens today.*

**R-B-128** A deep link to a screen the user may not see MUST resolve to the
standard "not available" state after a server check — never a client-side
redirect that leaks the screen's existence, and never a blank page.

**R-B-129** Server state MUST be held in a request cache with **explicit
invalidation keyed to domain events and to mutation responses**. There is no
long-lived client-owned copy of business data.

**R-B-130** Concurrent edits MUST be detected: a mutation carries the version it
read (`If-Match` or an explicit version field), and a conflict is surfaced to the
user with both values. **Last-write-wins is prohibited.**
*Rationale — finding 11: two admins in two tabs, whoever saves last silently
discards the other's work, including work on unrelated screens.*

**R-B-131** Assets MUST be fingerprinted, immutable and cacheable, with
route-level code splitting; the shell document is small and revalidated. A
declared **initial-bundle budget** and a time-to-interactive budget on a named
reference device and connection are asserted in CI.
*Rationale — finding 9: 1.2 MB, uncacheable, per navigation.*

**R-B-132** **No CDN.** All scripts, styles, fonts and icons are self-hosted from
the application's own origin, so an on-prem installation behind a firewall
behaves identically to SaaS (spine S18 parity). Where an external asset is
unavoidable, it carries SRI and a local fallback.
*Rationale — finding 12.*

### B.4.8 Escaping and XSS defence as a framework property

**R-B-133** Output escaping MUST be a property of the framework, not a discipline.
React escapes by default; `dangerouslySetInnerHTML` is **banned by lint** with a
single, reviewed allow-list entry: one shared rich-text renderer that sanitises
through one allow-list DOM policy.
*Test:* a lint rule; a CI grep; and an XSS corpus test that injects the payloads
from `K` §7.3's worked path into every free-text field and asserts inert
rendering in list, detail, export preview and PDF.
*Rationale — finding 6: 155 `innerHTML` assignments and 19 mutually incompatible
escape helpers, **none escaping `'`**.*

**R-B-134** A **Content-Security-Policy** with per-request nonces MUST be served:
no `unsafe-inline`, no `unsafe-eval`, `object-src 'none'`, `base-uri 'self'`,
`frame-ancestors 'none'`, and a `connect-src` limited to the application origin.
Trusted Types are enabled where the browser supports them.

**R-B-135** Sanitisation MUST be an **output-time** property. Input is stored as
the user typed it (subject to validation), because destroying input at write time
is lossy and was itself a defect — `H/BR-SEC-401` strips HTML on master writes
*"and also destroys legitimate rich text"*.

**R-B-136** The CSRF token MUST NOT be readable from a global JavaScript object
that any injected script can lift; session handling uses `SameSite` cookies with
a per-request token exchanged in a header.
*Rationale — `K` §7.4: `window.__SMARTPRS.csrf` converts every XSS into a
state-changing compromise.*

**R-B-137** File uploads MUST be validated server-side by content type as well as
extension, stored outside the web root, and served only through authorised,
signed, expiring URLs (spine S13). Rendering an uploaded document never executes
it.

### B.4.9 Global search, notifications, tasks and approvals

**R-B-138** Global search MUST be a **server-side, permission- and scope-checked**
endpoint returning typed results with the actions the caller may take. It MUST NOT
return a colleague's contact details to a self-service user.
*Rationale — `H/NEW-DSH-105`: search has no role guard and no self-scope, so an
employee can enumerate every colleague.*

**R-B-139** There MUST be **one unified inbox**, combining workflow tasks (Part
IX), notifications (Part XIX), compliance obligations (Part XXI) and custom-form
requests (§B.3), each item carrying its subject, its age, its SLA state and its
permitted actions. Bulk actions are idempotent and individually audited.

**R-B-140** Notification delivery to the client MUST use a configured transport
with a **configurable** interval or a push channel — never a literal poll
interval.
*Rationale — `K/BR-NOT-700`: `setInterval(rmBell, 60000)`, a literal.*

**R-B-141** The approvals deep-link target MUST be resolved from the item, not
hard-coded. *`K/NEW-FE-025`: the bell always navigates to `approvals-inbox`.*

### B.4.10 Responsive and mobile — stated factually

**R-B-142** SmartPRS 3's client is **one responsive web application**. Breakpoints
are design tokens; the declared minimum supported viewport is stated in the design
system; every screen is usable at that width or explicitly declares itself
desktop-only in `ui_screens.min_viewport_code`, which the navigation response
carries so a phone does not offer a screen it cannot render.

**R-B-143** The following surfaces MUST be first-class on a phone: punch/attendance
self-service, leave apply and balance, approvals, payslip view and download,
documents, compliance obligations, custom-form raise, and the dashboard. Anything
else is a declared desktop screen, not an accident.

**R-B-144** Offline behaviour MUST be stated rather than assumed: the application
is **online-first**; where offline capture is offered (a punch on a field agent's
phone), captured events are queued with idempotency keys, carry their capture
instant and zone, and are reconciled server-side under Part XI's punch model. No
business decision is made offline.

**PRODUCT OWNER DECISION REQUIRED — D-B-07: PWA or a native mobile application in
v3.0?**
**Recommendation: a responsive PWA**, installable, with push where the browser
supports it. A native app is a second front end with a second release train, a
store review cycle and a second access-control surface — which is precisely the
architecture this section exists to end. Revisit when a capability genuinely
requires native (background geofencing, offline biometric capture); make it a
thin client over the same `/api/v3`, never a second product.

### B.4.11 Accessibility — a named standard, tested

**R-B-145** SmartPRS 3 MUST conform to **WCAG 2.2 Level AA** on every
tenant-facing screen. The standard and the level are stated here so that
conformance is a test rather than an opinion.
*Rationale — Part XXXV GAP 4: *"Accessibility is not merely unspecified, it is
unmentioned. For a product sold to BPOs and to bank-facing collection agencies,
accessibility is frequently a procurement requirement, and it is the one quality
that cannot be retrofitted cheaply."**

**R-B-146** Automated accessibility checks (axe or equivalent) MUST run in CI over
every route in the screen registry and **fail the build** on any violation at
severity `serious` or `critical` against the declared rule set. The rule set is
versioned; suppressions require a named reviewer, an expiry date and a reason.

**R-B-147** The following MUST be covered by explicit tests, because automation
cannot see them:

| Requirement | Test |
|---|---|
| Keyboard-only completion of the ten highest-traffic journeys (login, punch, apply leave, approve, run payroll, view payslip, raise a form, run a report, open a dashboard drill-through, upload a document) | Scripted keyboard traversal, no pointer events |
| Visible focus on every interactive element, in both themes | Automated + visual |
| Screen-reader announcement of table semantics, form errors, live regions and modal focus traps | Manual smoke on a named screen-reader/browser pair per release |
| Colour contrast ≥ 4.5:1 for text, ≥ 3:1 for UI components and chart marks, in light and dark | Token-level automated check |
| Information never conveyed by colour alone (charts, statuses, validation) | Review checklist + `R-B-063` |
| Text resize to 200 % and reflow at 320 CSS px without loss of function | Automated viewport test |
| Every form control has a programmatic label; every error is associated with its control | Automated |

**R-B-148** An accessibility conformance statement MUST be published per release,
listing the standard, the level, known exceptions with dates, and the assistive
technologies tested. *A procurement questionnaire asks for exactly this document.*

**PRODUCT OWNER DECISION REQUIRED — D-B-08: is WCAG 2.2 AA a GA commitment or a
post-GA commitment?**
**Recommendation: a GA commitment**, because the cost is in the component
inventory — build the twenty components accessibly once and every generated screen
inherits it — and because retrofitting after 146 screens exist is the expensive
path SmartPRS 2 demonstrates. If the Product Owner defers, `R-XXXV-026` requires
the **level and the date to be stated**; silence is not an available answer.

### B.4.12 Design system, states and the component inventory

**R-B-149** A **design system** MUST exist as code and data: design tokens
(colour, type scale, spacing, radius, elevation, motion) defined once, with light
and dark themes, and a component inventory that every screen composes from. A new
visual pattern is added to the inventory, not to a screen.
*Rationale — `K` §5.2 item 27: the entire visual theme is a 213-line CSS heredoc
inside a PHP controller.*

**R-B-150** Every list, detail, widget and form MUST declare three states, and
they are reviewed like any other content:

| State | Rule |
|---|---|
| **Empty** | Reads as completion or as a next action, never a blank region (`R-XXI-126`) |
| **Loading** | A skeleton of the eventual shape; never a spinner over a blank page for a list whose shape is known |
| **Error** | Names what failed, offers a retry where retrying is safe, and shows a **correlation id** the user can quote to support (Part XXIV) — never a raw exception (`H/BR-RPT-014`) |

**R-B-151** A failed data fetch MUST NEVER render as zero, empty or "0 records".
*This is `R-XXI-123` generalised from dashboards to the whole interface, and it is
the single most consequential UI rule in the product: on a compliance screen, a
zero produced by a failed query and a zero produced by full compliance look
identical.*

**R-B-152** Destructive and money-moving actions MUST require explicit
confirmation naming the effect and the scope ("this will regenerate 412 payslips
for August 2026"), and MUST be idempotent under a double submission.

### B.4.13 Explainability in the interface *(spine S15)*

**R-B-153** Wherever a derived number is displayed — an attendance status, a leave
balance, a payslip line, a statutory deduction, a compliance score, a widget count
— the interface MUST offer **"How was this calculated?"** in a consistent,
discoverable control that renders the engine's trace unmodified. The client never
recomputes, summarises or paraphrases a trace.

### B.4.14 Configuration surface

| # | What a tenant administrator can change without code | Where |
|---:|---|---|
| 1 | Which columns a list shows, their order and default sort, per role | Studio → S3 → Screens |
| 2 | Which fields appear on a detail/edit screen, in which sections, in which order, per role and surface | Studio → S3 (Part X surface rules) |
| 3 | Which screens appear in navigation, their grouping and order, within permission | Studio → S3 → Navigation |
| 4 | The default landing screen per role | same |
| 5 | Branding: logo, colours within the accessible palette constraints, product name, favicon, login page copy | Studio → S10 |
| 6 | Empty-state and help text per screen (§B.6.6) | Studio → S10 → Help |
| 7 | Locale, date/number formats, timezone (§B.5) | Studio → Localisation |
| 8 | Notification transport interval and quiet hours | Studio → S14 |
| 9 | Which screens are offered on mobile, within the declared support | Studio → S3 → Screens |

**Not tenant-changeable:** the `ui_screens` registry's existence and its
permission requirement; the escape-hatch register; the CSP; the escaping policy;
the accessibility rule set; the fail-closed navigation contract; brand colours
that would breach the contrast requirement (the picker refuses them and says why).

### B.4.15 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/ui/navigation` | authenticated | The resolved tree, with edition/licence/entitlement applied |
| GET | `/ui/screens/{screen_key}` | the screen's own permission | Screen metadata: kind, entity, layout, columns, actions |
| GET | `/ui/design-tokens` | authenticated | Tokens + tenant branding, so branding is data |
| GET | `/ui/search?q=` | `search.global` | Scoped, typed results with permitted actions |
| GET | `/ui/inbox` | authenticated | The unified inbox (`R-B-139`) |
| GET | `/ui/help/{screen_key}` | authenticated | Screen help, edition-filtered, tenant-overridable (§B.6.6) |
| GET | `/entities/{key}/fields?surface=` | Part X | The rendering contract (`R-B-115`) |

### B.4.16 Events

`ui.screen.registered` / `.retired` (release-time, drives the inventory check) ·
`ui.escape_hatch.registered` (drives `R-B-119`'s review) ·
`ui.access_denied` (screen key, actor, reason — a security signal, and the
measurement that would have exposed SmartPRS 2's 26 ungated screens) ·
`ui.client_error` (correlation id, screen, release — because a swallowed client
exception is how SmartPRS 2 loses access control silently).

**R-B-154** Unhandled client exceptions MUST be reported to the server with the
correlation id and surfaced on the operations dashboard (spine S18). **There is no
`safe(fn)`.**

### B.4.17 Migration implication

| SmartPRS 2 asset | Disposition |
|---|---|
| `AppController.php` (12,533 lines) | **Deleted.** Its screen list, field lists and validation intent are harvested into `ui_screens` seed rows and Part X field definitions; its code is not ported |
| `resources/prototype/app.html` | Deleted |
| `layouts/app.blade.php` + 17 views + `smartprs.css` + `smartprs-theme.css` | Deleted (`K` §6.1) |
| `ScreenHelpController.php` (2,206 lines, 120 screen entries, 119 "why", 73 "mistakes") | **Content preserved, mechanism deleted** — migrated into `help_content` (§B.6.6). `H` §Q9 is explicit: *"The mechanism does not [belong]; the content emphatically does"* |
| Client-side statutory maths (`spRates`, `tdsTax`, `statutoryScreen`) | Deleted. Its *values* are migrated into rule-pack candidates for verification by the compliance owner (Part XVII), **never** carried as code |
| `settings.app_state` blob | Decomposed into real configuration tables by the Part XXIX migration; nothing round-trips |
| The 19 escape helpers | Deleted |
| Screen ids | Mapped one-to-one into `ui_screens` with an owning module and a permission; the 26 unmapped screens are the first ones reviewed |

### B.4.18 Edge cases

| # | Situation | Behaviour |
|---:|---|---|
| 1 | The navigation call fails at boot | Hard error state with a retry and a correlation id. **Not** an empty shell that renders everything |
| 2 | A user's permissions change mid-session | The next navigation or data call reflects it; a screen that becomes forbidden renders "not available" on its next fetch; there is no cached authority |
| 3 | A deep link to a retired screen | "No longer available", with a pointer to its successor where declared |
| 4 | A field is retired while a form is open | Submit fails validation naming the field; the draft is preserved |
| 5 | A registered component throws | The screen's error state, `ui.client_error` reported; the rest of the application is unaffected |
| 6 | An asset fails to load (a stale fingerprint after deploy) | The shell detects a version mismatch and prompts a reload; it does not run a half-updated bundle |
| 7 | The browser is unsupported | A stated minimum-version page, not a broken screen |
| 8 | A tenant brand colour fails contrast | The branding picker refuses it, states the ratio, and offers the nearest passing colour |
| 9 | A screen's permission is retired from Part XXII's catalogue | The build fails (`R-B-113`), before release |
| 10 | Two roles give a user conflicting navigation | The union of what each permits; permissions are additive, scopes are resolved per Part XXII |

### B.4.19 Tests

| ID | Scenario | Expected |
|---|---|---|
| T-B-047 | Every `ui_screens` row | Has a non-null permission that exists in Part XXII's catalogue |
| T-B-048 | For every screen, a user without its permission | `403`/`404` from **every endpoint the screen calls**, not merely an absent menu item (`R-B-123`) |
| T-B-049 | An unrecognised role | No navigation; explicit error; **not** admin (`NEW-FE-014` inverted) |
| T-B-050 | Permission payload absent | Deny; **not** allow (`NEW-FE-015` inverted) |
| T-B-051 | An exception thrown in a boot step | Visible hard failure; **no** silently degraded boot (`K` §6.5 inverted) |
| T-B-052 | XSS corpus injected into every free-text field | Inert in list, detail, export preview, PDF and notification preview |
| T-B-053 | CSP headers on the shell | No `unsafe-inline`, no `unsafe-eval`; nonce present; violation report endpoint live |
| T-B-054 | Deep link, refresh, Back, Forward, new tab, on 20 sampled screens | All work (`R-B-127`) |
| T-B-055 | Two tabs edit the same record | The second receives a conflict with both values; no silent overwrite (`R-B-130`) |
| T-B-056 | axe over every route | Zero serious/critical violations |
| T-B-057 | Keyboard-only completion of the ten journeys | All complete |
| T-B-058 | Contrast tokens, light and dark | Meet 4.5:1 / 3:1 |
| T-B-059 | Initial bundle and TTI on the reference device | Within budget |
| T-B-060 | Every network call the SPA makes | Is a documented `/api/v3` operation (`R-B-108`) |
| T-B-061 | A data fetch forced to fail on a compliance list | Error state, **not** "0 records" (`R-B-151`) |
| T-B-062 | Grep the client bundle for statutory literals (PF cap, ESI threshold, slab rates) | Zero occurrences (`R-B-110`) |

### B.4.20 Data model

#### `ui_screens` — PM (platform metadata, release-seeded; layout overrides are tenant configuration)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `screen_key` | VARCHAR(80) | N | — | Stable; the routing key |
| `module_key` | VARCHAR(40) | N | — | Owning module — every screen has an owner |
| `kind_code` | VARCHAR(30) | N | — | `entity_list`/`entity_detail`/`entity_form`/`wizard`/`report`/`dashboard`/`inbox`/`registered_component` |
| `entity_key` | VARCHAR(80) | Y | NULL | For entity kinds |
| `report_key` / `dashboard_key` | VARCHAR(80) | Y | NULL | For those kinds |
| `component_id` | BIGINT UNSIGNED | Y | NULL | FK → `ui_components`; **required** when kind = `registered_component` |
| `required_permission_key` | VARCHAR(80) | **N** | — | `R-B-113` — the constraint that makes finding 5 impossible |
| `required_feature_keys_json` | JSON | Y | NULL | Entitlement |
| `min_viewport_code` | VARCHAR(20) | N | `phone` | `phone`/`tablet`/`desktop` |
| `route_pattern` | VARCHAR(160) | N | — | Deep-linkable (`R-B-127`) |
| `title_key`, `help_key` | VARCHAR(120) | N | — | Message and help keys |
| `nav_group_key`, `nav_seq` | VARCHAR(60)/SMALLINT | Y | NULL | Defaults; tenant may reorder |
| `is_active` | TINYINT(1) | N | 1 | |
| `introduced_in_version`, `retired_in_version` | VARCHAR(20) | Y | NULL | |

PK `id` · Unique `(screen_key)` · Index `(module_key)`, `(kind_code)`,
`(required_permission_key)` · **Tenant scope:** none (platform); per-tenant
navigation and column overrides live in the Studio's screen-configuration
artefact · **History:** release-versioned · **Soft delete:** `is_active` ·
**Audit:** release manifest · **PII:** none.

#### `ui_components` — PM (the escape-hatch register)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `component_key` | VARCHAR(80) | N | — | Matches a reviewed component in the repository |
| `module_key` | VARCHAR(40) | N | — | |
| `version` | VARCHAR(20) | N | — | |
| `declarative_gap_reason` | TEXT | **N** | — | `R-B-118` — prose |
| `missing_capability_code` | VARCHAR(60) | **N** | — | Classified reason from the declared vocabulary |
| `capability_request_ref` | VARCHAR(60) | **N** | — | The backlog item against the owning part |
| `reviewed_by` | VARCHAR(120) | N | — | Named architect |
| `first_used_in_version` | VARCHAR(20) | N | — | |
| `retired_when_capability_shipped` | VARCHAR(20) | Y | NULL | Set when the model absorbs it |
| `created_at`, `updated_at` | DATETIME | N | — | |

PK `id` · Unique `(component_key)` · Index `(missing_capability_code)` — the
review reads this · **Tenant scope:** none · **Audit:** release manifest ·
**PII:** none.

**R-B-155** The three `NOT NULL` columns above are the whole mechanism. A
component that cannot state why the declarative model failed does not get
registered, and therefore does not get built.

---

## B.5 Localisation and timezone *(brief Sections 94 and 95)*

### B.5.1 Purpose, and what SmartPRS 2 does today

Part XXXV GAP 3 records that localisation appears three times in thirty-four
parts and *"not one of them specifies anything"*, and that **Part XIX identifies
English-only literals as a SmartPRS 2 defect and then specifies a template engine
that does not fix it.**

The as-is position:

| Evidence | Consequence |
|---|---|
| *"Language / localisation — **No**. English literals throughout"* (`E`:958) | There is no message catalogue, no locale concept and no translated surface |
| Default drive instructions are an English literal in a controller: *"Please carry an updated resume, original photo ID proof and a pen. Formal dress."* (`HiringDriveController.php:82`, `:183`, `H/BR-REC-025`) — *"English-only, India-walk-in-specific, not tenant-editable"* | Product copy is source code |
| Mobile normalisation takes the **last 10 digits** and treats anything shorter as invalid (`RecruitMessagingController.php:78-91`, `H/BR-REC-029`) — *"hard-wires India-only 10-digit numbers; a country code is silently discarded"* | The single-country assumption is not only in the timezone |
| Employee date parsing accepts a fixed format set in browser JavaScript (`AppController.php:1655-1666`, `K/NEW-FE-023`) — *"dd/mm vs mm/dd is a real locale decision"* | Ambiguous dates are resolved by a literal |
| `config/app.php:68` = `env('APP_TIMEZONE', 'UTC')`, one global for the whole installation (`L`:462); `config:cache` then **freezes** it (`L/DEF-L-24`) | *"One `APP_TIMEZONE` for the whole install; no per-tenant, per-company or per-device"* (`B` item 55) |
| `AbsenceService::runForDate():92-94` — with `APP_TIMEZONE` missing (UTC) the sweep *"returns yesterday between 18:30 and 24:00 IST, so the sweep evaluates the wrong day and can mark a working employee absent"* (`B`:1566) | A timezone default marks present employees absent |

**Two conclusions.** First, localisation is not a translation project; it is an
architectural property that must be present from v3.0 even if only English ships,
because retrofitting a locale key onto published, effective-dated, versioned
templates is a migration of every template a customer owns (`R-XXXV-025`).
Second, a single global `APP_TIMEZONE` is a **single-country assumption**, and it
has already produced a money-and-attendance defect in a single-country product.

### B.5.2 No user-facing string in business logic

**R-B-156** No user-facing string may appear as a literal in application code —
not in a domain service, not in a controller, not in a job, not in a client
component, not in a validation message, not in an email body. Every such string is
a **message key** resolved from the catalogue.
*Test:* a static-analysis rule fails the build on a string literal reaching a
rendered surface from `Domain\*`, `Http\*` or the client's presentation layer,
outside a declared allow-list (log messages, machine-readable error `code`s,
configuration keys).
*Rationale — Law 1's own logic applied to text: if a customer could reasonably
want it different, it is data. `H/BR-REC-025` is Law 1 broken with a sentence
instead of a number.*

**R-B-157** The catalogue MUST cover, without exception: UI labels, help text and
empty states; validation and error messages; value-set item labels; report column
headings and report names; dashboard widget titles and empty states; notification
and template bodies (Part XIX); document, letter and payslip labels; custom-form
labels (§B.3); PDF and export headings; and the names of configuration artefacts
where a tenant sees them.

**R-B-158** A message MUST be identified by a **stable key**, never by its English
text. Changing the English source text does not orphan translations; it marks them
`needs_review`.

### B.5.3 The catalogue and its workflow

```
message_keys          platform: key, source_text (en-IN), context/description,
                      placeholder contract (name → SPX type), max length per
                      channel, screenshot reference, module owner, introduced_in
        │
        ├── message_translations       platform: (key, locale) → text, state,
        │                              translator, reviewer, released_in
        │
        └── tenant_message_overrides   tenant: (key, locale) → text
                                       "Establishment" → "Unit"
```

Workflow, per release:

```
1  Extraction         CI extracts every message key referenced in code, in
                      seeds and in configuration payload metadata
2  Reconciliation     keys present in code but absent from the catalogue FAIL
                      the build; keys in the catalogue referenced by nothing are
                      reported as candidates for retirement
3  Source review      a native reviewer approves changed English source text
                      (product voice, and it is the translation source)
4  Translation        per locale, machine-assisted then human-reviewed;
                      state: machine → human → reviewed → approved
5  Validation         placeholder arity and types match the contract; length
                      fits every channel the key is used on; no unescaped markup
6  Release            approved translations ship; unapproved fall back
7  Metrics            untranslated-key count per locale is a release metric
```

**R-B-159** Placeholders MUST be **named and typed** (`{employee_name}: Text`,
`{amount}: Money`, `{due_on}: Date`), and a translation MAY reorder them freely
but MAY NOT drop one, invent one, or change its type. Validation is automatic.
*Rationale — a positional-argument catalogue produces a payslip with the amount
where the date should be, in a language the reviewer does not read.*

**R-B-160** Pluralisation, gender and number agreement MUST use ICU
MessageFormat-style plural and select forms. Hindi's plural rules are not
English's, and string concatenation cannot express either.

**R-B-161** A missing translation MUST fall back down a declared chain and MUST
be **counted and reportable**, never silently rendered as the key:
`user locale → tenant locale → language without region (hi) → en-IN`.
A key with no entry anywhere renders the source text and raises
`localisation.key_missing` at low severity.

**R-B-162** A tenant override MUST be scoped to the tenant, MUST never leak across
tenants, and MUST survive a platform text change (it is an override, not a fork of
the catalogue).
*Rationale — `H/BR-REC-040`: SmartPRS 2's knowledge base is written with
`tenant_id = NULL` and every write path queries by id with no tenant filter, so
*"an admin of tenant A editing an article changes it for every tenant on the
installation — including the four Code of Conduct articles that agents legally
acknowledge."* Tenant-editable content without tenant scoping is that defect.*

### B.5.4 Locales, scripts, formatting and money in words

**R-B-163** A **locale** MUST be a data row, not a code branch. Adding
Telugu is a content release: a `locales` row, a translation set, a font, and a
completion threshold — no application release.

**R-B-164** The v3.0 locale set and its path:

| Locale | Status | Notes |
|---|---|---|
| `en-IN` | **Source and fallback** | Indian English; the language every key is authored in |
| `hi-IN` | **First translation** | Devanagari |
| Regional (`te-IN`, `ta-IN`, `bn-IN`, `mr-IN`, `kn-IN`, `ml-IN`, `gu-IN`, `pa-IN`, `or-IN`, `as-IN`) | **Path, not commitment** | Each is a content release once the machinery exists; each requires its font and its statutory-document review |

**PRODUCT OWNER DECISION REQUIRED — D-B-09: which languages are committed at GA?**
**Recommendation: `en-IN` at GA with the complete localisation machinery in place
and proven by a second locale in staging; `hi-IN` in the first content release
after GA.** Shipping the machinery without a second locale is how a product
discovers at tender time that its layout, its PDF fonts and its date formats were
never actually exercised. Shipping ten locales at GA is a translation project
competing with the platform build. A regional language should be committed only
against a named customer or tender, and the commercial consequence of *not*
committing (regional-language payslips are a routine tender requirement) is
recorded here.

**R-B-165** Every locale MUST declare its **script and its embeddable font**, and
PDF generation MUST embed the font for the rendered locale. A statutory document
that renders as boxes is worse than one in English, so a missing font is a **run
failure** (`report.font_missing`, §B.1 edge case 15), never a silent fallback.

**R-B-166** Number, currency and date formatting MUST come from a **format
profile** resolved per locale, never from a formatter written inline:

| Property | `en-IN` default |
|---|---|
| Digit grouping | **Indian**: `₹1,50,000.00`, `₹1,25,00,000.00` — 3, then 2, then 2 |
| Currency symbol and position | `₹` prefix, non-breaking space rules declared |
| Negative money | Parentheses or minus, declared per profile, consistent across screen, export and PDF |
| Decimal places | Per component's declared rounding (Law 7); the formatter never rounds |
| Date | `dd-MMM-yyyy` (`03-Sep-2026`) — unambiguous by construction |
| Date input | Locale-aware parsing that **rejects ambiguity** rather than guessing (`K/NEW-FE-023` inverted) |
| Time | 24-hour, per the Shifts convention already required by the product |
| Week start | Configuration (Part XII), not a locale assumption |
| Financial year | Configuration (Part VI S2), never derived from the locale |

**R-B-167** Formatting MUST happen at the **edge only**. A `Money` value travels
as integer minor units plus a currency through every layer, including the API,
and is formatted once for display or for a document (Law 7). A formatted string
is never an input to a calculation, an export column type, or a comparison.

**R-B-168** **Amount in words** MUST use the Indian scale (thousand, lakh, crore)
per locale, be produced by one shared service, and be covered by exact-value tests.
It appears on payslips, cheques, letters, advances and settlement statements, and
a wrong one is a legal defect.

```
₹1,50,000.00      → "Rupees One Lakh Fifty Thousand Only"
₹1,50,000.50      → "Rupees One Lakh Fifty Thousand and Fifty Paise Only"
₹1,00,00,000.00   → "Rupees One Crore Only"
₹0.00             → "Rupees Zero Only"
-₹1,234.00        → "Rupees One Thousand Two Hundred Thirty Four Only (Debit)"
```

**R-B-169** Sorting and searching MUST be **locale-collated**, and search MUST
match across scripts where a transliteration mapping is declared (a name entered
in Devanagari found by its Latin spelling). Where no mapping is declared, the
behaviour is stated rather than approximated.

**R-B-170** Layout MUST tolerate text expansion of at least 40 % over the English
source without truncation or overlap, asserted by a pseudo-localisation build in
CI. *Hindi labels are routinely longer than their English source; a fixed-width
button is a defect discovered at the worst possible moment.*

**R-B-171** RTL is **not** required for the Indian language set and is therefore
**not claimed**. The design system MUST use logical CSS properties (`inline-start`
rather than `left`) so that RTL remains reachable, and the absence of an RTL
commitment is stated rather than implied.

### B.5.5 Locale resolution

**R-B-172** Locale MUST resolve in this order, and the resolved locale MUST be
recorded on anything it produces:

```
1  explicit user preference          (a person reads in their language)
2  employment/tenant locale policy   (cfg_locale_policies, scoped per S3.1)
3  company or location policy
4  Accept-Language                   (API callers only, per Part XXIV:862 —
                                      it localises a message, never a code)
5  tenant default
6  en-IN
```

**R-B-173** A **notification** MUST render in the **recipient's** locale, never
the actor's. A **document about a person** — payslip, letter, statement — MUST
render in the **subject's** locale, unless the document's template declares a
fixed locale because a statute requires one.
*Rationale — an approval email in the approver's Hindi about a request raised in
English is correct; a payslip in the payroll operator's language is not.*

**R-B-174** Part XIX's template versions MUST carry a **locale dimension** from
v3.0, so a template family has one version per locale per channel rather than a
separate artefact per language. `R-XXXV-025` requires this even if only English
ships. §B.10 records it as a required extension to Part XIX's `template_channel_body`
model, owned by Part XIX.

**R-B-175** Publication of a template or a form MUST fail when a locale declared
`required` by the tenant's policy has no approved body for a channel in the plan.
*Rationale — Part XIX `R-XIX-024` already fails publication when a channel body is
missing; locale is the same class of completeness check.*

### B.5.6 Timezone resolution

**R-B-176** `APP_TIMEZONE` MUST NOT govern any business behaviour. It may set the
PHP process default for logs; nothing in a domain path may read it.
*Test:* a static check forbids `config('app.timezone')` and `date_default_timezone_get()`
inside `Domain\*` and inside any job that resolves a business date.

**R-B-177** The **business timezone** MUST resolve, per subject and per operation,
in this order, each level being an IANA zone stored as data:

```
device / capture point (Part XI records the zone applied per punch)
  → location
    → establishment
      → company
        → legal entity
          → tenant
            → platform default (declared at install; never assumed)
```

**R-B-178** `org_units` MUST carry a nullable `timezone` (IANA) at every level
that can differ, and the resolver MUST walk upward to the first non-null value.
§B.10 records this as an addition to Part V / Part XXVIII's `org_units`, owned by
Part V.

**R-B-179** A tenant MUST be able to operate **multiple zones simultaneously** —
an establishment in Kathmandu and one in Hyderabad — with no application change
and no separate installation. *This is the concrete test of "single-country
assumption removed": if the model cannot express it, the assumption survives.*

**R-B-180** The zone actually applied MUST be **recorded on the record**, not
inferred at read time (Part XI `R-XI-062` already requires this for punches; §B.5
generalises it to every business date the product derives).

### B.5.7 Scheduled work fires at each tenant's local hour

**R-B-181** Every scheduled activity whose meaning is local — the absence sweep,
the compliance sweep, alert ladders, digests, scheduled reports (§B.1.6.1),
accruals, period rollovers, expiry notifications, backups where the customer
declares a local window — MUST fire at the **local hour of its declared scope**,
computed from that scope's IANA zone. The scheduler stores a materialised
`next_run_at_utc` per binding and recomputes it on change and on a zone-database
update.
*Rationale — `B`:1566: with the global default at UTC, `AbsenceService::runForDate()`
evaluates **yesterday** between 18:30 and 24:00 IST and can mark a working
employee absent. A local job fired on server time is not an inconvenience; it is a
wrong business decision.*

**R-B-182** DST and zone-database changes MUST be handled explicitly, and the two
pathological local times MUST have declared behaviour, because the zone is data
and a customer may operate outside India:

| Case | Behaviour |
|---|---|
| A local time that does not exist (spring forward) | Fire at the **first valid instant after** the nominal time; record the shift on the run |
| A local time that occurs twice (fall back) | Fire **once**, on the **first** occurrence; the second is suppressed by an idempotency key on `(binding, local business date)` |
| The IANA database is updated | Every materialised `next_run_at_utc` is recomputed; differences are logged and visible on the operations dashboard |
| A tenant changes its zone | Future firings recompute; **past business dates are never re-derived** (Law 3) |

**R-B-183** Quiet hours, contact windows and send windows (Part XIX; and the RBI
lawful-contact window that `H/NEW-CFG-102` records as *"present in defaults and
absent from save, so it can never be changed"*) MUST resolve in the **recipient's**
zone, and a message suppressed by a window MUST be recorded as suppressed with its
reason, never dropped.

**R-B-184** The operations dashboard MUST show, per tenant, the resolved business
zone and the next firing local time of every scheduled activity. *An operator
cannot debug a timezone they cannot see.*

### B.5.8 The storage rule

**R-B-185** Every temporal value MUST be stored in exactly one of three declared
shapes, and never in a fourth:

| Shape | Columns | Used for |
|---|---|---|
| **Instant** | `<name>_at DATETIME` (UTC) **+** `<name>_on DATE` (business date) **+** `business_timezone VARCHAR(64)` | Anything that happened at a moment: a punch, a submission, a decision, a delivery |
| **Business date** | `<name>_on DATE` | Anything that is a date by nature: an effective date, a due date, a period boundary, a joining date |
| **Local wall time** | `<name>_time TIME` **+** the zone that gives it meaning | A shift start, a send hour, a contact window bound |

**R-B-186** A date MUST NEVER be stored as `VARCHAR`.
*Rationale — spine S16, evidenced: SmartPRS 2 turns `dra_expiry` and
`pcc_deadline` into `VARCHAR` on installs where a runtime helper wins the race.*

**R-B-187** A business date MUST be **derived once, at write time, in the resolved
zone, and stored** — never recomputed at read time from the UTC instant using
whatever zone the reader happens to be in. Two readers in two zones must see the
same business date for the same event.
*Rationale — this is the property that makes an attendance day, a payroll period
boundary and a compliance due date agree with each other.*

### B.5.9 Cross-midnight determinism *(cross-reference Part XI)*

Part XI and spine S10 already own the mechanism: the attendance day is derived by
a **configurable rule**, not by the punch's calendar date, so a 22:00–06:00 shift
is one day and not two; and all late/early/worked arithmetic uses **absolute
instants anchored to shift start**, never minutes-since-midnight. That is not
restated here.

What §B.5 adds is the **consistency obligation across every surface that displays
or aggregates a business date**:

**R-B-188** "Today", "yesterday", "this month", "this payroll period" and every
named window MUST resolve through **one** `BusinessCalendar` service, in the
resolved business zone, identically for: the attendance engine, a report's date
range (`R-B-023`), a dashboard widget (§B.2.9's "absent yesterday" tile), a
scheduled report, a notification's digest window, a compliance due-date
calculation and a payroll period boundary.

**R-B-189** A cross-surface determinism test MUST exist and MUST run in CI:

> Seed a tenant in `Asia/Kolkata` with a 22:00–06:00 night shift and a server
> running in UTC. For a punch pair spanning midnight, assert that the attendance
> engine, the `attendance.day` report, the "absent yesterday" dashboard widget,
> a scheduled digest and the payroll period settlement **all attribute the shift
> to the same single business date**, and that the count each surface reports is
> identical.

*Rationale — `M/DUP-3` records four independent "Present" derivations in SmartPRS 2
(`AttendanceReportController`, `PayrollGenController::presentDays`,
`AbsenceService::presentEmpCodes`, `TodayController::me`) with **no** agreement
test between them. This requirement is the agreement test, extended to the
surfaces this appendix introduces.*

**R-B-190** Where a report or widget spans a period boundary in a multi-zone
tenant, the boundary MUST be resolved **per subject**, not per viewer, and the
output MUST state which zone was used. A Hyderabad manager reading a Kathmandu
branch's "yesterday" sees Kathmandu's yesterday, labelled.

### B.5.10 What is deliberately not localised

**R-B-191** The following MUST remain in their canonical form, and the reason is
stated so nobody "fixes" them later:

| Not localised | Why |
|---|---|
| Statutory form field names and filed file content | They belong to the authority, not to the product (Part XVII `R-XVII-138`) |
| API error `code` values | Machine-readable; Part XXIV:862 localises the `message` and never the `code` |
| Audit ledger stored values | Evidence must be byte-stable for hash chaining (spine S14) |
| Configuration keys, artefact keys, permission codes, event keys, screen keys | Identifiers, not text |
| Log messages | Operator-facing, single language, greppable |
| Currency codes and IANA zone identifiers | Standards |

### B.5.11 Configuration surface

| # | What a tenant administrator can change without code | Where | Scoped |
|---:|---|---|---|
| 1 | Default locale, and the required-locale set for templates | Studio → Localisation | tenant / company |
| 2 | Per-user locale preference | User profile | user |
| 3 | Override any message key's text | Studio → Localisation → Overrides | tenant |
| 4 | Number, currency and date format profile per locale | Studio → Localisation | tenant / company |
| 5 | Amount-in-words style (with/without paise wording) | same | tenant |
| 6 | Business timezone at tenant, company, establishment, location | Studio → S1 Organisation | per level |
| 7 | Local send hour, quiet hours and contact windows | Studio → S14 | scoped |
| 8 | The local hour of each scheduled activity | Studio → the owning section | scoped |
| 9 | Week start, financial year, holiday calendars | Studio → S2 (Part XII) | scoped |
| 10 | Which locale a template family publishes in | Studio → S10 | scoped |

**Not tenant-changeable:** the storage rule; the resolution order; the
`BusinessCalendar` service; the fallback chain's shape; the prohibition on
`APP_TIMEZONE` in domain paths; which items §B.5.10 exempts.

*Section 127 worked case:* **"the absence sweep must run at 20:00 local time for
our Kathmandu branch and 20:00 IST for Hyderabad"** — set `timezone` on each
establishment; set the sweep's local hour once. Two zones, one tenant, one
installation, no code. Under SmartPRS 2 this is impossible: `APP_TIMEZONE` is one
value for the whole installation and `config:cache` freezes it.

### B.5.12 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/locales` | authenticated | Available locales, completion %, script, font availability |
| GET | `/i18n/bundle?locale=&surface=` | authenticated | The resolved catalogue for the client, cache-fingerprinted |
| GET/PUT | `/me/preferences/locale` | self | User preference |
| GET/PUT | `/config/locale-policy` | `config.manage` | Tenant/company policy |
| GET/POST | `/i18n/overrides` | `config.manage` | Tenant overrides |
| GET | `/i18n/coverage` | `config.manage` | Untranslated keys by surface — the metric that drives §B.5.3 step 7 |

### B.5.13 Events

`locale.published` · `translation.approved` · `localisation.key_missing`
(low severity, aggregated — the signal that a release shipped an unextracted
string) · `tenant.timezone_changed` (→ recompute every materialised
`next_run_at_utc`) · `schedule.recomputed_for_zone_update`.

### B.5.14 Edge cases

| # | Situation | Behaviour |
|---:|---|---|
| 1 | A translation is missing at render | Fallback chain; counted; never the raw key on a customer-facing surface |
| 2 | A translation drops a placeholder | Rejected at validation, never published |
| 3 | A translated label overflows its control | Caught by the pseudo-localisation build, not by a customer |
| 4 | A PDF locale has no embedded font | Run fails naming the script (`R-B-165`); no boxes |
| 5 | A user's preferred locale is retired | Falls back and the preference is cleared with a notice |
| 6 | A tenant changes its zone mid-month | Future derivations use the new zone; existing business dates are immutable; the change is audited and the operations dashboard shows both |
| 7 | A DST-invalid local schedule hour | First valid instant after; recorded (`R-B-182`) |
| 8 | A DST-ambiguous local schedule hour | Fires once, first occurrence, idempotency-keyed |
| 9 | An employee transfers between establishments in different zones mid-period | Business dates before the effective date use the old zone, after it the new; the assignment is effective-dated (spine S3) and the payroll snapshot records both |
| 10 | A statutory document must be filed in English while the payslip is in Hindi | The template declares a fixed locale (`R-B-173`); both are correct simultaneously |
| 11 | A tenant override contains a placeholder the key does not define | Rejected on save with the contract shown |
| 12 | Two users in different zones read the same audit entry | Both see the same stored instant, each rendered in their own zone, with the zone labelled |

### B.5.15 Tests

| ID | Scenario | Expected |
|---|---|---|
| T-B-063 | Static analysis over `Domain\*`, `Http\*` and the client | Zero user-facing string literals outside the allow-list (`R-B-156`) |
| T-B-064 | Extraction vs catalogue | Every referenced key exists; build fails otherwise |
| T-B-065 | `₹1,50,000` / `₹1,25,00,000` formatting in `en-IN` | Indian grouping, on screen, in CSV, in XLSX and in PDF |
| T-B-066 | Amount in words, the five cases in §B.5.4 | Exact string match |
| T-B-067 | A `Money` value through API → screen → export → PDF | Integer minor units throughout; formatted once at the edge (`R-B-167`) |
| T-B-068 | Pseudo-localised build at +40 % length | No truncation or overlap on any registered screen |
| T-B-069 | A notification to a Hindi-preferring recipient raised by an English-preferring actor | Rendered in the recipient's locale (`R-B-173`) |
| T-B-070 | A template published with a required locale missing a channel body | Publication refused (`R-B-175`) |
| T-B-071 | A tenant with establishments in `Asia/Kolkata` and `Asia/Kathmandu` | The sweep fires at 20:00 local in each; two firings, correct business dates (`R-B-179`, `R-B-181`) |
| T-B-072 | Server in UTC, tenant in IST, absence sweep at 20:00 local | Evaluates **today**, not yesterday (`B`:1566 inverted) |
| T-B-073 | A DST-invalid and a DST-ambiguous scheduled hour in a non-Indian zone | First-valid-after; fires exactly once |
| T-B-074 | The cross-midnight determinism scenario in `R-B-189` | All five surfaces attribute the shift to one business date and report identical counts |
| T-B-075 | A business date read by users in two zones | Identical (`R-B-187`) |
| T-B-076 | Grep for `config('app.timezone')` in domain paths | Zero occurrences (`R-B-176`) |

### B.5.16 Data model

#### `locales` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | SMALLINT UNSIGNED | N | auto | PK |
| `locale_code` | VARCHAR(20) | N | — | BCP 47: `en-IN`, `hi-IN` |
| `language_code` | VARCHAR(8) | N | — | Fallback rung (`hi`) |
| `display_name_key` | VARCHAR(120) | N | — | Named in its own language and in English |
| `script_code` | VARCHAR(20) | N | — | `Latn`, `Deva`, `Telu` |
| `direction_code` | VARCHAR(3) | N | `ltr` | `R-B-171` |
| `pdf_font_family` | VARCHAR(80) | N | — | Embeddable font (`R-B-165`) |
| `format_profile_key` | VARCHAR(60) | N | — | Number/currency/date profile |
| `plural_rule_code` | VARCHAR(20) | N | — | ICU rule set |
| `collation` | VARCHAR(60) | N | — | Sorting (`R-B-169`) |
| `is_source` | TINYINT(1) | N | 0 | Exactly one row is the source locale |
| `is_offered` | TINYINT(1) | N | 0 | Below the completion threshold, not offered |
| `completion_threshold_pct` | TINYINT UNSIGNED | N | 95 | |

PK `id` · Unique `(locale_code)` · **Tenant scope:** none · **Audit:** release
manifest.

#### `message_keys` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `message_key` | VARCHAR(160) | N | — | `report.column.gross_earnings` |
| `module_key` | VARCHAR(40) | N | — | Owner |
| `source_text` | TEXT | N | — | `en-IN` |
| `context_note` | VARCHAR(500) | N | — | **Required** — a translator cannot translate a bare word |
| `placeholders_json` | JSON | Y | NULL | name → SPX type (`R-B-159`) |
| `max_length_json` | JSON | Y | NULL | Per channel: `sms`, `push_title`, `column_header` |
| `screenshot_ref` | VARCHAR(255) | Y | NULL | Where it appears |
| `surface_tags_json` | JSON | Y | NULL | `ui`, `report`, `notification`, `document` |
| `introduced_in_version`, `retired_in_version` | VARCHAR(20) | Y | NULL | |

PK `id` · Unique `(message_key)` · Index `(module_key)` · **Tenant scope:** none
· **PII:** none.

#### `message_translations` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `message_key_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `locale_code` | VARCHAR(20) | N | — | FK → `locales` |
| `text` | TEXT | N | — | |
| `state_code` | VARCHAR(20) | N | `machine` | `machine`/`human`/`reviewed`/`approved`/`needs_review` |
| `source_text_hash` | CHAR(64) | N | — | Set `needs_review` when the source changes (`R-B-158`) |
| `translated_by`, `reviewed_by` | VARCHAR(120) | Y | NULL | |
| `released_in_version` | VARCHAR(20) | Y | NULL | |
| `updated_at` | DATETIME | N | — | |

PK `id` · Unique `(message_key_id, locale_code)` · Index `(locale_code, state_code)`
— drives the coverage metric · **Tenant scope:** none · **Audit:** release
manifest.

#### `tenant_message_overrides` — A1

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | **N** | — | Law 6 — the defect `H/BR-REC-040` records is exactly a missing tenant filter here |
| `message_key` | VARCHAR(160) | N | — | Logical FK |
| `locale_code` | VARCHAR(20) | N | — | |
| `text` | TEXT | N | — | Placeholder contract validated on save |
| `scope_org_unit_id` | BIGINT UNSIGNED | Y | NULL | Optional narrowing (a company that calls it "Unit") |
| `created_by_user_id`, `created_at`, `updated_at` | | N | — | |

PK `id` · Unique `(tenant_id, message_key, locale_code, scope_org_unit_id)` ·
Index `(tenant_id, locale_code)` · **Tenant scope:** yes · **History:** audited ·
**Soft delete:** no · **Audit:** full · **PII:** none.

#### `cfg_locale_policies` — cfg payload

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `config_version_id` | BIGINT UNSIGNED | N | — | S4 envelope: scope, effective range |
| `default_locale_code` | VARCHAR(20) | N | `en-IN` | |
| `required_locales_json` | JSON | Y | NULL | Templates must publish in these (`R-B-175`) |
| `allow_user_preference` | TINYINT(1) | N | 1 | |
| `format_profile_key_override` | VARCHAR(60) | Y | NULL | |
| `amount_in_words_style_code` | VARCHAR(30) | N | `indian_default` | |
| `default_timezone` | VARCHAR(64) | N | — | IANA; the tenant rung of `R-B-177` |

PK `id` · Unique `(tenant_id, config_version_id)` · **Tenant scope:** yes ·
**History:** S4 versions · **Audit:** full.

*(`org_units.timezone` is an addition to an existing table, owned by Part V —
`R-B-178`, recorded in §B.10.)*

---

## B.6 The documentation programme *(brief Sections 117, 118 and 119)*

### B.6.1 Purpose, and why this is not a phase at the end

Part XXXV GAP 5 records that `documentation set`, `user documentation` and
`administrator guide` return **zero occurrences** across thirty-four parts, and
that Part XXX §XXX.10's definition of done lists *"documented"* as one of eleven
acceptance properties a named reviewer signs — *"and nothing in the corpus defines
what documented means, who writes it, in what form, or what gates a release."*

It also names the commercial stake, which is worth repeating because it is the
whole argument:

> *"The product thesis is that a tenant administrator changes behaviour through
> configuration rather than code. That administrator must operate a Configuration
> Studio with fourteen sections, eleven artefact kinds, an expression language
> with its own type system, a twenty-one-dimension scope model with weighted
> precedence, and a six-state draft/publish lifecycle. **A configuration-first
> product with no configuration manual is a code-first product with extra steps**
> — the customer will call Ametecs, and D-VI-04's 'Ametecs-managed by default'
> will become permanent by accident rather than by choice."*

**R-B-192** Documentation MUST be part of the **definition of done** for every
deliverable, and MUST gate the release (§B.6.7). It is not a phase, and it is not
a task that follows the code.

**R-B-193** Part XXX §XXX.10's *"documented"* checkbox MUST name the specific
artefacts it is signing for, drawn from the inventory in §B.6.3, §B.6.4 and
§B.6.5. A checkbox that names nothing signs for nothing.

### B.6.2 What SmartPRS 2 has, and what survives

SmartPRS 2's documentation is not absent — it is misplaced.

| Asset | State | Disposition |
|---|---|---|
| `ScreenHelpController.php` — 2,206 lines, **120 screen entries, 119 "why it matters", 73 "common mistakes"**, in PHP array literals with one dispatcher and no data access (`H` §1.18, §Q9) | Product content held as source code; no table, no editor, no tenant scoping, no edition awareness; re-materialised on every request with no cache; a live key collision silently kills one entry (`NEW-HLP-101`…`104`) | **Content preserved, mechanism deleted** (§B.6.6) |
| The `refs/` documentation set — `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md`, `BUSINESS_RULES_CATALOGUE.md`, `SECURITY_AND_TECHNICAL_DEBT_REPORT.md` | Extensive and, per the analyses, **frequently wrong about the code** — the fourteen as-is analyses exist precisely because the reference documents could not be trusted without source verification | Historical input to migration only. **Not a template for SmartPRS 3's documentation**, because hand-written prose about code drifts from it |
| Industry content pack — 12 training programmes, 25 subjects, 17 FAQs, 16 KB articles including the four Code of Conduct articles agents legally acknowledge (`H` §1.19) | Product content installed as customer-editable rows, idempotent by natural key; but `kb_topics` is written `tenant_id = NULL` and de-duplicated **across the whole installation** (`NEW-SEED-101`) | Content preserved; the cross-tenant defect is not (§B.6.6) |

`H` §Q9's verdict on the screen help is the governing one, and it is the reason
§B.6.6 exists:

> *"In several places this copy is the clearest existing statement of intended
> behaviour in the entire codebase — the Roles & Permissions entry documents the
> deliberate fail-open permission posture, the Shifts entry documents the 24-hour
> time requirement, and the Commissions mistakes list documents the exact
> confusion that causes ₹42 000 payouts instead of ₹840."*

**R-B-194** The SmartPRS 2 help content MUST be migrated, not rewritten from
scratch, because it encodes operational knowledge the rebuild would otherwise
lose. It MUST be reviewed against SmartPRS 3's actual behaviour before
publication, because some of it documents defects as if they were features.

### B.6.3 Live technical documentation *(Section 117)*

Three audiences, three deliverable sets. This is the first.

**R-B-195** The technical documentation set MUST be **built from the repository on
every release** and published as one versioned site, with the SmartPRS version
prominent, and with prior versions retained (an on-prem customer three releases
behind needs their own documentation).

**R-B-196** Every document below MUST be classified `generated` or `written`, and
**generated MUST be maximised**, because generated documentation cannot go stale
and written documentation always does.

| # | Document | Source | Class |
|---:|---|---|---|
| 1 | **API reference** | OpenAPI, emitted from the route and schema definitions (Part XXIV §XXIV.3) | **Generated** |
| 2 | **ERD and table catalogue** | Migrations + the table registry (Part XXVIII) | **Generated** |
| 3 | **SPX language reference: symbol catalogue** — types, operators, functions with signatures, namespaces, and **per-evaluation-context availability** | The SPX symbol-table registry (Part VII, spine S5) | **Generated** |
| 4 | **Event catalogue** — every domain event, its payload schema, its emitters and its subscribers | The event registry (spine + every module) | **Generated** |
| 5 | **Permission catalogue** — every `verb.resource`, its data-scope semantics, the routes bound to it | Part XXII's catalogue + the route-policy sweep | **Generated** |
| 6 | **Configuration schema** — every artefact type, its payload fields, types, defaults, validation and scope dimensions | Part VI's artefact-type registry | **Generated** |
| 7 | **Rule action catalogue** — every typed action, its parameters, its owning module | Spine S6 registry | **Generated** |
| 8 | **Report dataset catalogue** — every dataset, grain, column, type, permission (§B.1) | `report_datasets` | **Generated** |
| 9 | **Dashboard widget-kind and presentation catalogue** (§B.2) | Part XXI's registry + §B.2.5 | **Generated** |
| 10 | **Error-code catalogue** | The error envelope's code registry (Part XXIV) | **Generated** |
| 11 | **Notification event × channel × template matrix** | Part XIX registries | **Generated** |
| 12 | **Screen inventory** — every screen, its kind, its module, its permission (§B.4) | `ui_screens` | **Generated** |
| 13 | **Escape-hatch register** — every registered component and the capability it names as missing (`R-B-118`) | `ui_components` | **Generated** |
| 14 | **Statutory rule-pack format** — the pack schema, the signing and verification procedure, the `report_mapping` grammar, the `source_reference` obligation | The pack JSON schema + Part XVII | **Generated schema + written narrative** |
| 15 | **System architecture** — modules, boundaries, the dependency rule, the engines and their contracts | Written, from Part IV and the spine | Written |
| 16 | **Domain model narrative** — the aggregates, their lifecycles and their invariants | Written, from Part V | Written |
| 17 | **Security model** — permissions vs scope, fail-closed, encryption, masking, MFA, session, audit chain | Written, from Part XXII and spine S13/S14 | Written |
| 18 | **Tenancy model** — Law 6 in practice, the isolation tests, what a platform role can and cannot do | Written, from Part XXII/XXV | Written |
| 19 | **Workflow engine reference** — node types, participant kinds, binding resolution, SLA and escalation semantics | Written + generated node/participant catalogues | Mixed |
| 20 | **Deployment** — topology, prerequisites, scaling, queue and scheduler operation, health checks | Written, from Part XXXI | Written |
| 21 | **On-prem installer guide** | Written, from Part XXVI | Written |
| 22 | **Upgrade guide** — signature verification, backup, migrate, rollback | Written, from Part XXVII / spine S18 | Written |
| 23 | **Backup and restore runbook** — including the scheduled restore test and where its last success date is visible | Written, from spine S18 (`L/DEF-L-27` demands it and no part specified it as a document) | Written |
| 24 | **Biometric Adapter SDK** — the `DeviceAdapter` contract, the canonical punch, identity strategies, time policies, the connector-recipe grammar | Written + generated interface reference | Mixed |
| 25 | **Testing** — the test taxonomy, the generated isolation tests, the budgets, the fixtures, how to add a regression case | Written, from Part XXX | Written |
| 26 | **Operator runbook** — alerts, their meaning, their first response; queue, scheduler, webhook, device-sync and backup failure modes | Written, from Part XXXI + spine S18 | Written |

**R-B-197** A **written** page MUST NOT duplicate a generated catalogue. Where a
written page needs a list that a generated catalogue holds, it **transcludes** it.
A hand-maintained copy of a machine-known list is a defect, not documentation.
*Test:* a drift check fails the build when a written page names a symbol — an
endpoint, an event, a permission, a function, a dataset, a table — that the
generated catalogues do not contain.

**R-B-198** Generated documentation MUST fail the build when a documentable
element lacks its description: a new endpoint without a summary, an event without
a payload description, a permission without a purpose, an SPX function without a
signature and an example, a dataset column without a label key.
*Rationale — this is how generated documentation stays useful rather than merely
current.*

### B.6.4 Customer administrator manuals *(Section 118)*

**R-B-199** The administrator manual set MUST cover, at minimum, the following —
one manual or one chapter each, each written **against the Configuration Studio's
actual screens** (Part VI §VI.6.2's sections S1–S16), naming the section, the
artefact and the control the reader is looking at:

| # | Manual / chapter | Studio section |
|---:|---|---|
| 1 | Getting started: your tenant, your first company, your first user | S1, S13 |
| 2 | Organisation setup: the tree, level types, classification dimensions, establishments, registrations | S1 |
| 3 | Calendar, financial year, pay cycles, weekly-off patterns, holiday calendars | S2 |
| 4 | People data model: entities, custom fields, value sets, surface rules, document types, code sequences | S3 |
| 5 | Employees: onboarding, import, bulk edit, transfers, exits | S3 |
| 6 | Attendance: shifts, rosters, attendance-day rules, late/early/absence rules, devices | S4, S12 |
| 7 | Leave: types, entitlement, accrual, carry-forward, encashment, leave rules | S5 |
| 8 | Compensation: components, structures, formulas, rounding, proration, recovery order | S6 |
| 9 | Statutory: applicability, company policy, rule packs, acceptance, exceptions | S7 |
| 10 | **Rules and formulas for administrators** — SPX for people who are not programmers: the visual builder, the expression view, the simulator, the conflict detector, the impact preview | S8 |
| 11 | Workflows and approvals: nodes, participants, delegation, escalation, maker-checker | S9 |
| 12 | Templates and documents: payslips, letters, ID cards, branding, layouts | S10 |
| 13 | **Reports and dashboards** (§B.1, §B.2): datasets, building a report, scheduling it, building a dashboard | S11 |
| 14 | **Custom forms** (§B.3): building a request type end to end | S3 |
| 15 | Security: roles, permissions, data scopes, MFA, sessions, password policy | S13 |
| 16 | Notifications: events, audiences, channels, templates, quiet hours, suppression | S14 |
| 17 | Integrations and devices: connector recipes, webhooks, API credentials and scopes | S12 |
| 18 | Import and export: templates, mapping, validation, correction, bulk operations | S3 |
| 19 | **Change management**: draft/publish, diff, approval, scheduled changes, publication history, rollback, packs | S16 |
| 20 | Localisation: locales, overrides, formats, timezones (§B.5) | Localisation |
| 21 | Platform and entitlement: modules, features, licence, retention classes | S15 |
| 22 | On-prem operations: installation, updates, backup, restore, health | (on-prem edition only) |
| 23 | Troubleshooting: the twenty things that go wrong and what to do | cross-cutting |

**R-B-200** Each manual MUST be **validated by an implementation consultant
configuring a reference tenant from the manual alone**, with no access to the
authors and no access to the source. The validation records every point at which
the consultant had to ask a question; **each question is a documentation defect**
with an owner and a fix, and the manual is not signed off until the run completes
clean within its declared time budget.
*Rationale — this is the only test that distinguishes a manual that describes the
software from a manual a customer can work from. It is also the test of the
product thesis itself: if a competent consultant cannot configure the reference
tenant from the manual, the product is not configuration-first regardless of what
the architecture says.*

**R-B-201** The reference tenant produced by that validation run MUST be retained
as a **regression fixture** — the same tenant the performance budgets, the
cross-tenant isolation tests and the demo environment use. Documentation
validation therefore produces engineering value, not only a document.

**R-B-202** Screenshots MUST be **regenerated per release from the seeded demo
tenant by an automated capture run**, never hand-pasted, and a screenshot whose
screen key no longer exists fails the build.
*Rationale — stale screenshots are the fastest way for a manual to lose a reader's
trust, and hand-maintained ones are always stale by the second release.*

**R-B-203** The Section 127 conformance cases (Part VI §VI.16) MUST appear in the
manuals as **worked examples with their exact Studio paths** — cross-referenced,
not duplicated. *"3 late arrivals over 10 minutes = half day" is the sentence the
product is sold on; the manual must show a reader how to do it in the Studio, step
by step.*

**R-B-204** Each manual MUST declare the **permissions** its procedures require,
so a reader knows immediately whether the screen they cannot find is missing or
merely not theirs.

**R-B-205** Manuals MUST be **edition- and entitlement-aware**: an on-prem L1
customer must not be given procedures for SaaS-only screens.
*Rationale — `H/NEW-HLP-101` records exactly this defect in the in-product help:
*"an on-prem L1 client is served help for SaaS-only screens they cannot reach."**

### B.6.5 Developer extension guides *(Section 119)*

**R-B-206** There MUST be one guide per extension point, each with a **worked
example that lives in the repository, compiles, and passes its contract tests in
CI**. A guide whose example does not build is a guide that has already drifted.

| # | Guide | Extension point | Worked example |
|---:|---|---|---|
| 1 | **Adding a platform module** | Namespace, public contract, dependency rule, migrations, permissions, events, datasets, screens, tests | A minimal "Asset Register" module: one aggregate, one workflow binding, one dataset, one screen, one event, its isolation tests |
| 2 | **Adding a biometric adapter** | `DeviceAdapter` (spine S10.1) — capabilities, connect, test, fetch, parse, identity strategy, time policy, health | A pull adapter for a fictional device with a vendor status-byte map and a non-obvious direction token, plus the same integration expressed as a **connector recipe** with no PHP at all |
| 3 | **Adding a statutory rule type** | Pack schema, SPX calculation and eligibility, thresholds, slabs, forms, `report_mapping`, due dates, `source_reference`, signing, verification, activation | A fictional state levy: slab table, eligibility expression, monthly obligation, a return mapping, and a diff/impact preview |
| 4 | **Adding a notification channel** | Channel adapter, capability declaration, constraints as platform data, escaping profile, delivery ledger, failure semantics (Part XIX `R-XIX-024`) | A generic SMS-provider channel with a length constraint and a provider template contract |
| 5 | **Adding a report dataset** | `ReportDataset` (Part VI `R-VI-142`, §B.1.4) — grain, columns, joins, measures, and **the scope predicate as a composable expression** | A dataset over the example module, with its grain assertion and its cross-scope isolation test |
| 6 | **Adding a workflow action** | `ActionHandler` — a typed entry in a module's closed action catalogue (spine S6), its parameters, its transaction boundary, its audit and its trace | An `ASSIGN_ASSET` action used by §B.3's asset-request form |

**R-B-207** Every guide MUST state what the extension point **may not do**: no DDL
at runtime, no bypass of the scope predicate, no direct write to another module's
tables, no customer-supplied code, no silent catch in a domain path, no business
literal.

**R-B-208** Each guide MUST include its **contract-test template**, so an
extension is testable by the same harness Ametecs uses. *A partner adapter without
its contract test is the D-VI-03 partner-SDK decision made badly in advance.*

### B.6.6 In-product help — the content store

**R-B-209** Screen help MUST be a **versioned, screen-keyed content store**:
vendor-authored, edition-filtered, entitlement-filtered, tenant-overridable,
cached, and linked to the corresponding manual section. It MUST NOT be a PHP array
in a controller.

**R-B-210** Help content MUST preserve SmartPRS 2's three-part shape, because the
shape is the valuable part: **what the screen is for and how to use it** ·
**why it matters** (the business argument) · **common mistakes, their consequence
and the correct action**. `H` §1.18 records 120 / 119 / 73 entries respectively;
the target is full coverage of the `ui_screens` registry.

**R-B-211** Help content MUST be **tenant-scoped when overridden**, and a vendor
update MUST NOT overwrite a tenant's override, nor a tenant's edit affect any
other tenant.
*Rationale — `H/BR-REC-040` and `NEW-SEED-101`: SmartPRS 2's knowledge base is
installation-wide and cross-tenant writable, including the Code of Conduct
articles agents legally acknowledge.*

**R-B-212** Every `ui_screens` row MUST have a `help_key`, and a screen shipped
without help content fails the release gate (§B.6.7).

**PRODUCT OWNER DECISION REQUIRED — D-B-10: may a tenant edit product help text?**
**Recommendation: yes, as an override, per tenant, with the vendor original always
visible and one-click restorable.** Customers genuinely need to add their own
policy ("in our company, travel over ₹50,000 also needs the CFO"), and refusing
sends that content to a shared drive where nobody reads it. The override model,
not the fork model, is what keeps the vendor's updates flowing.

### B.6.7 The release gate

**R-B-213** A release MUST NOT be publishable when any of the following is true:

| Gate | Condition that blocks |
|---|---|
| G1 | The generated documentation set fails to build |
| G2 | Any new or changed endpoint, event, permission, SPX symbol, dataset, dataset column, artefact type or error code lacks its description (`R-B-198`) |
| G3 | Any `ui_screens` row lacks a `help_key`, or its help content is absent (`R-B-212`) |
| G4 | A written page references a symbol the generated catalogues do not contain (`R-B-197`) |
| G5 | A Studio screen changed in this release and its manual section's `last_verified_version` is older than the change |
| G6 | A developer guide's worked example does not build or its contract tests fail (`R-B-206`) |
| G7 | The consultant validation run for a **materially changed** manual has not been re-run (`R-B-200`) |
| G8 | A screenshot references a screen key that no longer exists (`R-B-202`) |
| G9 | An accessibility- or behaviour-affecting change is undocumented in the release notes |

**R-B-214** Release notes MUST be a deliverable in their own right, in three
registers — what changed for an **administrator**, what changed for a **developer**
integrating with the API, and what an **operator** must do (a migration, a
re-index, a configuration review) — and a breaking API change appears in all three.

**R-B-215** Every manual section MUST carry `last_verified_version` and
`owner_role`. Documentation without an owner rots by default.

### B.6.8 Ownership

**R-B-216** Each document MUST have a named owner role, and the mapping MUST be
declared:

| Deliverable class | Author | Reviewer | Signs off |
|---|---|---|---|
| Generated technical docs | The code (CI) | Module owner (descriptions) | Lead architect |
| Written technical docs | Module owner | Lead architect | Lead architect |
| Administrator manuals | Technical writer, with the module owner | Implementation consultant (by doing it, `R-B-200`) | Product Owner |
| Developer guides | Module owner | An engineer who has not seen the module | Lead architect |
| In-product help | Technical writer | Product Owner (voice) | Product Owner |
| Release notes | Release manager | Product Owner | Product Owner |
| Operator runbook | Operations owner | Lead architect | Operations owner |

**PRODUCT OWNER DECISION REQUIRED — D-B-11: who staffs the documentation
programme, and is the gate blocking at GA?**

| Option | For | Against |
|---|---|---|
| **A. One technical writer + module-owner authorship + consultant validation (recommended)** | The writer owns voice, structure and the manuals; engineers write only what they alone know; the consultant validation catches what both miss | A headcount, from the start of Phase 1 rather than near the end |
| B. Module owners write everything | No headcount | Engineers writing manuals produce reference material, not procedures; §B.6.4's twenty-three chapters will not exist |
| C. Documentation after GA | Ships sooner | This is precisely what `R-XXXV-027` and `H` §Q9 warn against; and D-VI-04's "Ametecs-managed by default" becomes permanent |

**Recommendation: A, with the gate blocking from the first customer-facing
release** — warning-only during internal milestones so the gate is tuned before it
is load-bearing. The single highest-value item in the whole programme is chapter
10 (SPX for administrators) and chapter 19 (change management), because those two
are the difference between a configuration-first product and a support ticket.

### B.6.9 Data model

#### `documentation_index` — PM

*Purpose:* the manifest of the documentation set for a release, so the product can
link a screen to its manual section, the installer can ship the right edition's
docs, and the release gate has something to assert against.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `doc_key` | VARCHAR(80) | N | — | `admin.reports_and_dashboards` |
| `audience_code` | VARCHAR(20) | N | — | `technical`/`administrator`/`developer`/`operator` |
| `title_key` | VARCHAR(120) | N | — | Localisable |
| `class_code` | VARCHAR(20) | N | — | `generated`/`written`/`mixed` (`R-B-196`) |
| `source_ref` | VARCHAR(255) | N | — | Repository path or generator id |
| `owner_role_key` | VARCHAR(60) | N | — | `R-B-216` |
| `edition_scope_json` | JSON | Y | NULL | Which editions it applies to (`R-B-205`) |
| `required_feature_keys_json` | JSON | Y | NULL | Entitlement filtering |
| `studio_section_key` | VARCHAR(20) | Y | NULL | S1–S16 (`R-B-199`) |
| `url_path` | VARCHAR(255) | N | — | In the published site |
| `product_version` | VARCHAR(20) | N | — | Retained per release |
| `last_verified_version` | VARCHAR(20) | Y | NULL | Gate G5 (`R-B-215`) |
| `consultant_validation_at` | DATETIME | Y | NULL | Gate G7 (`R-B-200`) |

PK `id` · Unique `(doc_key, product_version)` · Index `(audience_code)`,
`(studio_section_key)` · **Tenant scope:** none · **Audit:** release manifest ·
**PII:** none.

#### `help_content` — PM

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `help_key` | VARCHAR(80) | N | — | Referenced by `ui_screens.help_key` |
| `locale_code` | VARCHAR(20) | N | — | |
| `what_it_is_md` | MEDIUMTEXT | N | — | Purpose and how to use it |
| `why_it_matters_md` | MEDIUMTEXT | Y | NULL | The business argument (`R-B-210`) |
| `common_mistakes_json` | JSON | Y | NULL | `[{mistake, consequence, correct_action}]` |
| `related_doc_key` | VARCHAR(80) | Y | NULL | FK (logical) → `documentation_index` |
| `edition_scope_json` | JSON | Y | NULL | `R-B-205` |
| `required_feature_keys_json` | JSON | Y | NULL | |
| `product_version` | VARCHAR(20) | N | — | |

PK `id` · Unique `(help_key, locale_code, product_version)` · **Tenant scope:**
none (vendor content) · **Audit:** release manifest.

#### `tenant_help_overrides` — A1

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | **N** | — | Law 6 — `H/BR-REC-040` is this column missing |
| `help_key` | VARCHAR(80) | N | — | |
| `locale_code` | VARCHAR(20) | N | — | |
| `override_mode_code` | VARCHAR(20) | N | `append` | `append`/`replace` — append keeps vendor updates flowing |
| `body_md` | MEDIUMTEXT | N | — | Sanitised on render (`R-B-133`) |
| `created_by_user_id`, `created_at`, `updated_at` | | N | — | |

PK `id` · Unique `(tenant_id, help_key, locale_code)` · **Tenant scope:** yes ·
**History:** audited · **Soft delete:** no · **Audit:** full · **PII:** none.

### B.6.10 Tests

| ID | Scenario | Expected |
|---|---|---|
| T-B-077 | Add an endpoint without a summary | Build fails (G2) |
| T-B-078 | Add an SPX function without a signature or example | Build fails (G2) |
| T-B-079 | Add a screen without a `help_key` or without help content | Build fails (G3) |
| T-B-080 | A written page cites a removed permission code | Drift check fails (G4) |
| T-B-081 | Change a Studio screen without re-verifying its manual section | Build fails (G5) |
| T-B-082 | Each of the six developer guides' worked examples | Builds and passes its contract tests (G6) |
| T-B-083 | Screenshot capture run | Every screenshot's screen key exists; images regenerate deterministically |
| T-B-084 | Consultant validation of the reference tenant, from the manuals alone | Completes within the time budget; every question raised is filed as a documentation defect |
| T-B-085 | The reference tenant produced by that run | Is the seeded fixture the performance and isolation suites use (`R-B-201`) |
| T-B-086 | Tenant A overrides a help entry | Tenant B is unaffected; the vendor original is restorable (`R-B-211`) |
| T-B-087 | An on-prem L1 edition build | Contains no manual section and no help entry for a SaaS-only screen (`R-B-205`) |
| T-B-088 | Generated catalogue counts | Match the registries they are generated from (datasets, events, permissions, screens, components) |

### B.6.11 Migration implication

| Item | Disposition |
|---|---|
| `ScreenHelpController.php`'s 801 array entries | Extracted programmatically into `help_content` seed rows, keyed to `ui_screens`, then **reviewed against SmartPRS 3 behaviour** — some entries document SmartPRS 2 defects as intended behaviour (`R-B-194`) |
| The duplicate `notifications` key that silently kills the richer entry (`H/NEW-HLP-103`) | Resolved during extraction; the unique constraint on `(help_key, locale_code, product_version)` makes a recurrence impossible |
| The 4-entry alias table duplicated at `:35` and `:45` | Not carried; aliases become `ui_screens` rows or nothing |
| The generic fallback that ignores its own `$key` (`NEW-HLP-104`) | Not carried; a missing entry fails the release gate instead of rendering a generic panel |
| The `refs/` documentation set | Historical input only; SmartPRS 3's technical documentation is generated where it can be |
| The industry content pack (training, FAQs, KB, Code of Conduct) | Carried as tenant-scoped seeded content, **with `tenant_id` populated** — `NEW-SEED-101`'s installation-wide de-duplication is not carried |

---

## B.7 Consolidated requirements index

**216 numbered requirements, `R-B-001` … `R-B-216`, contiguous, no duplicates.**
**88 named regression scenarios, `T-B-001` … `T-B-088`.**

| Range | Section | Subject |
|---|---|---|
| `R-B-001` – `R-B-002` | B.0 | Standard of the appendix; no parallel mechanisms |
| `R-B-003` – `R-B-004` | B.1.3 | The three-layer model; a definition cannot express an undeclared query |
| `R-B-005` – `R-B-011` | B.1.4 | Datasets as platform metadata; **the scope predicate**; grain and fan-out; column permission and masking; budgets; no per-row work; the `ReportDataset` escape hatch |
| `R-B-012` – `R-B-015` | B.1.4.1–2 | The dataset catalogue; a Part X field becomes a report column automatically; PII carried into every output; retirement never breaks silently |
| `R-B-016` – `R-B-019` | B.1.5 | The definition as an S4 artefact; computed columns as SPX; error cells; no fallback report |
| `R-B-020` – `R-B-026` | B.1.5.1–2 | Date-range semantics; unresolvable ranges are errors; range caps; one calendar; typed parameters; parameters never widen scope; locked filters |
| `R-B-027` – `R-B-035` | B.1.6 | Saved, shared, published; scheduling; delivery only through Part XIX; tenant-local firing; render identity; signed links; fail-closed recipients; fire logging |
| `R-B-036` – `R-B-040` | B.1.7 | **Streaming, never in-memory**; asynchronous generation; run telemetry; cancellation; cache keyed on scope |
| `R-B-041` – `R-B-043` | B.1.8 | CSV injection and BOM; no format bypasses permission; artefact hashing |
| `R-B-044` – `R-B-045` | B.1.9 | **A report runs as the requester, never the author**; the permission codes |
| `R-B-046` – `R-B-047` | B.1.10 | The `ReportTrace`; a report never becomes a second explanation |
| `R-B-048` – `R-B-051` | B.1.11 | Statutory registers as system definitions; stored values only; provenance; the audit-trail export writes through `Audit::record()` |
| `R-B-052` – `R-B-054` | B.1.12 | Successor for every SmartPRS 2 report; the three unnamed datasets; intent carried, code not |
| `R-B-055` – `R-B-056` | B.1.14 | The UI uses the public API; no raw exception text |
| `R-B-057` | B.2.2 | One dashboard model, owned by Part XXI |
| `R-B-058` – `R-B-061` | B.2.4 | Three data-source kinds and nothing else; inherited scope and budgets; aggregates; owned metrics |
| `R-B-062` – `R-B-064` | B.2.5 | **Every presentation declares a drill target and an action, charts included**; not colour alone; charts reachable as tables |
| `R-B-065` – `R-B-070` | B.2.6 | Scope hash in the cache key; refresh policies; staleness shown; TTL ceiling; over-budget behaviour; whole-dashboard budget |
| `R-B-071` – `R-B-074` | B.2.7 | Personal dashboards: additive, resettable, never scope-widening, excluded from packs |
| `R-B-075` – `R-B-078` | B.2.8 | The drill-through contract; count equals list end to end; server-side action authorisation; visible-versus-actionable |
| `R-B-079` – `R-B-080` | B.2.9 | Every threshold is configuration; the three fields added to Part XXI's tables |
| `R-B-081` | B.2.10 | "How was this number produced?" |
| `R-B-082` – `R-B-083` | B.2.12–13 | One compiled definition serves number and rows; action telemetry |
| `R-B-084` – `R-B-086` | B.3.2–3 | One shipped artefact type unblocks GAP 2; no DDL; no new engine |
| `R-B-087` – `R-B-090` | B.3.4 | The form as an S4 artefact; immutable entity key; publish validation; retire, never delete |
| `R-B-091` – `R-B-094` | B.3.5 | Layout as data; conditional visibility re-evaluated server-side; hidden-but-supplied is rejected; bounded symbol scope |
| `R-B-095` – `R-B-098` | B.3.6 | States as data; the workflow owns transitions; decided steps immutable; no self-approval; full audit |
| `R-B-099` – `R-B-100` | B.3.7 | The closed completion-action catalogue; actions execute inside their owning module |
| `R-B-101` | B.3.8 | The five worked forms as fixtures |
| `R-B-102` – `R-B-104` | B.3.9 | Auto-registered dataset; masking carried; a generated register on day one |
| `R-B-105` | B.3.10 | Form permissions enforced server-side whichever option is chosen |
| `R-B-106` – `R-B-111` | B.4.2–3 | One front end; public API only; the dead front end deleted; **no business logic in the client**; no state blob |
| `R-B-112` – `R-B-116` | B.4.4 | Screens as rows; **`required_permission_key NOT NULL`**; generated entity screens; resolved-field rendering; layout as configuration |
| `R-B-117` – `R-B-121` | B.4.5 | The registered-component escape hatch; **every use records the missing capability**; the review that turns bypasses into features |
| `R-B-122` – `R-B-126` | B.4.6 | Server-provided navigation; defence in depth; **the four fail-open paths inverted**; no client matrix; server-side edition gating |
| `R-B-127` – `R-B-132` | B.4.7 | Real URLs; safe deep links; explicit cache invalidation; no last-write-wins; asset budgets; no CDN |
| `R-B-133` – `R-B-137` | B.4.8 | Escaping as a framework property; CSP with nonces; output-time sanitisation; CSRF token not global; upload handling |
| `R-B-138` – `R-B-141` | B.4.9 | Scoped global search; one unified inbox; configurable transport; resolved deep links |
| `R-B-142` – `R-B-144` | B.4.10 | Responsive as fact; the mobile-first surface list; stated offline behaviour |
| `R-B-145` – `R-B-148` | B.4.11 | **WCAG 2.2 AA**, CI-enforced, with the tests automation cannot do, and a published conformance statement |
| `R-B-149` – `R-B-152` | B.4.12 | Design system as tokens; three declared states; **a failed fetch is never zero**; confirmation on destructive actions |
| `R-B-153` – `R-B-155` | B.4.13–20 | "How was this calculated?" everywhere; no swallowed client errors; the three `NOT NULL` columns that are the escape-hatch mechanism |
| `R-B-156` – `R-B-162` | B.5.2–3 | No user-facing literal; full catalogue coverage; stable keys; typed placeholders; ICU plurals; counted fallback; tenant-scoped overrides |
| `R-B-163` – `R-B-171` | B.5.4 | Locales as data; the language path; fonts per script; Indian digit grouping; formatting at the edge only; amount in words; collation; 40 % expansion; RTL not claimed |
| `R-B-172` – `R-B-175` | B.5.5 | Resolution order; recipient's and subject's locale; **templates carry a locale dimension from v3.0**; publication completeness |
| `R-B-176` – `R-B-180` | B.5.6 | `APP_TIMEZONE` abolished for business use; the resolution ladder; `org_units.timezone`; multi-zone tenants; the zone recorded |
| `R-B-181` – `R-B-184` | B.5.7 | Local-hour firing; DST and zone-update behaviour; recipient-zone windows; operator visibility |
| `R-B-185` – `R-B-187` | B.5.8 | The three storage shapes; never `VARCHAR`; business date derived once at write |
| `R-B-188` – `R-B-190` | B.5.9 | One `BusinessCalendar`; **the cross-midnight determinism test**; per-subject boundaries |
| `R-B-191` | B.5.10 | What is deliberately not localised |
| `R-B-192` – `R-B-194` | B.6.1–2 | Documentation in the definition of done; the checkbox names artefacts; SmartPRS 2 help content migrated and reviewed |
| `R-B-195` – `R-B-198` | B.6.3 | Built per release; **generated maximised**; no written duplicate of a generated catalogue; missing descriptions fail the build |
| `R-B-199` – `R-B-205` | B.6.4 | The twenty-three manual chapters against real Studio screens; **consultant validation from the manual alone**; the reference tenant as a fixture; regenerated screenshots; Section 127 worked examples; declared permissions; edition awareness |
| `R-B-206` – `R-B-208` | B.6.5 | Six developer guides with building, tested worked examples; stated prohibitions; contract-test templates |
| `R-B-209` – `R-B-212` | B.6.6 | The help content store; the three-part shape; tenant-scoped overrides; every screen has help |
| `R-B-213` – `R-B-216` | B.6.7–8 | The nine release gates; three-register release notes; verification metadata; named owners |

### B.7.1 Traceability to the audit finding

| Part XXXV requirement | Closed by |
|---|---|
| `R-XXXV-012` — reporting and dashboard-authoring architecture to the Section 123/125 standards, covering the report definition artefact, dataset registration, **scope enforcement on every dataset**, scheduling, delivery, export, permissions, the dashboard widget model, and the five `H/DEFECT-RPT-*` defects | §B.1 (`R-B-003`…`R-B-056`), §B.2 (`R-B-057`…`R-B-083`); the five defects at `R-B-049`, `R-B-051`, §B.1.12 |
| `R-XXXV-024` — a custom form builder specified, or Section 57 explicitly deferred | §B.3 (`R-B-084`…`R-B-105`) — **specified, not deferred** |
| `R-XXXV-025` — a localisation model, and templates carrying a locale dimension from v3.0 regardless | §B.5 (`R-B-156`…`R-B-191`); the locale dimension at `R-B-174` |
| `R-XXXV-026` — design system, navigation model, screen inventory keyed to module parts, empty/loading/error policy, and **a declared accessibility standard with its conformance level** | §B.4 (`R-B-106`…`R-B-155`); accessibility at `R-B-145`…`R-B-148` and `D-B-08` |
| `R-XXXV-027` — the documentation deliverable set: inventory, owner, format, release gate; and §XXX.10's checkbox naming its artefact | §B.6 (`R-B-192`…`R-B-216`); the checkbox at `R-B-193` |
| `R-XXXV-011` — each gap closed, or explicitly deferred with its consequence | Five of twelve rows closed here; GAP 6 (overtime) and GAP 7 (rule register) remain with their assigned owners |

**Phase 8 is now costable.** §B.1 and §B.2 give it a dataset catalogue of
twenty-four entries, eleven new tables, an API surface, a widget model already
half-built by Part XXI, and a migration list of fourteen SmartPRS 2 reports.

---

## B.8 Tables this appendix introduces

**Twenty-six new tables. Three existing tables extended.** Every one is specified
to the Section 123 standard in its section.

| # | Table | Section | Archetype | Tenant | History | Audit | Highest PII |
|---:|---|---|---|---|---|---|---|
| 1 | `report_datasets` | B.1.18 | PM | none | release | manifest | none |
| 2 | `report_dataset_columns` | B.1.18 | PM | none | release | manifest | classifies only |
| 3 | `report_dataset_joins` | B.1.18 | PM | none | release | manifest | none |
| 4 | `cfg_report_definitions` | B.1.18 | cfg payload | yes | S4 | full | internal |
| 5 | `cfg_report_definition_columns` | B.1.18 | cfg child | yes | S4 | with parent | none |
| 6 | `cfg_report_definition_filters` | B.1.18 | cfg child | yes | S4 | with parent | internal |
| 7 | `cfg_report_definition_parameters` | B.1.18 | cfg child | yes | S4 | with parent | none |
| 8 | `report_schedules` | B.1.18 | A1 | yes | audited | full | none |
| 9 | `report_schedule_recipients` | B.1.18 | A3 | yes | audited | full | internal |
| 10 | `report_runs` | B.1.18 | A4 | yes | is history | full | internal |
| 11 | `report_run_artefacts` | B.1.18 | A3/A4 | yes | is history | full | **the artefact is the PII** |
| 12 | `user_dashboard_layouts` | B.2.16 | A1 (preference) | yes | none | event only | none |
| 13 | `user_dashboard_widget_prefs` | B.2.16 | A3 | yes | none | event only | none |
| 14 | `cfg_form_definitions` | B.3.16 | cfg payload | yes | S4 | full | none |
| 15 | `cfg_form_layout_items` | B.3.16 | cfg child | yes | S4 | with parent | none |
| 16 | `custom_form_records` | B.3.16 | A1 | yes | audit + effective-dated values | full | **per field** |
| 17 | `ui_screens` | B.4.20 | PM | none | release | manifest | none |
| 18 | `ui_components` | B.4.20 | PM | none | release | manifest | none |
| 19 | `locales` | B.5.16 | PM | none | release | manifest | none |
| 20 | `message_keys` | B.5.16 | PM | none | release | manifest | none |
| 21 | `message_translations` | B.5.16 | PM | none | release | manifest | none |
| 22 | `tenant_message_overrides` | B.5.16 | A1 | **yes** | audited | full | none |
| 23 | `cfg_locale_policies` | B.5.16 | cfg payload | yes | S4 | full | none |
| 24 | `documentation_index` | B.6.9 | PM | none | release | manifest | none |
| 25 | `help_content` | B.6.9 | PM | none | release | manifest | none |
| 26 | `tenant_help_overrides` | B.6.9 | A1 | **yes** | audited | full | none |

*Sixteen carry `tenant_id NOT NULL`; ten are release-shipped platform metadata
(see the note below). §B.10 item 4 records that Part XXVIII's catalogue must be
updated with all twenty-six.*

**Existing tables extended (owned elsewhere):**

| Table | Owner | Addition | Requirement |
|---|---|---|---|
| `cfg_dashboard_widget` | Part XXI | `data_source_kind_code`, `presentation_code`, `aggregate_code` | `R-B-080` |
| Part XIX's `template_channel_body` family | Part XIX | a **locale** dimension | `R-B-174` |
| `org_units` | Part V | `timezone VARCHAR(64) NULL` (IANA) | `R-B-178` |

**Platform-metadata tables without `tenant_id`.** Ten tables here
(`report_datasets`, `report_dataset_columns`, `report_dataset_joins`,
`ui_screens`, `ui_components`, `locales`, `message_keys`,
`message_translations`, `documentation_index`, `help_content`) carry no
`tenant_id`. This is **not** a Law 6 exception: Law 6 governs **business tables**,
and these are release-shipped platform metadata, on the precedent of spine S9's
`entity_definitions`. Every table holding tenant data or tenant configuration in
this appendix carries `tenant_id NOT NULL` and participates in every unique index.
The two tenant-editable content stores — `tenant_message_overrides` and
`tenant_help_overrides` — carry it precisely because SmartPRS 2's equivalent
(`kb_topics`, written `tenant_id = NULL` and cross-tenant writable) is the defect
they exist to avoid.

---

## B.9 Open items — PRODUCT OWNER DECISION REQUIRED

Eleven decisions, each with a recommendation and its trade-offs. All are new and
are **not** in Part XXXIV's consolidated register; adding them is a reconciliation
item (§B.10 item 1).

| ID | Decision | Recommendation | Cost of getting it wrong |
|---|---|---|---|
| **D-B-01** | Under whose scope is a **scheduled report** rendered — per recipient, or one render under a declared reporting identity? (§B.1.6.1) | **Per recipient**, batched by identical scope hash; the reporting identity available per schedule, permissioned and audited | Option B without the permission gate turns every scheduled email into a standing data-leak channel |
| **D-B-02** | Are **export artefacts retained**, and for how long? (§B.1.8) | Retain, encrypted, retention class `report_artefact`, default 30 days, per-definition override; statutory artefacts inherit their obligation's retention | Not retaining makes `R-B-043` ("what exactly did we send the auditor?") unanswerable; retaining forever is the widest PII store in the product |
| **D-B-03** | **Who may author** report definitions, and who may publish? (§B.1.9) | `reporting.definition.manage` to admins and a grantable `report_author` role; `reporting.definition.publish` to tenant admin only; datasets stay with Ametecs | Too narrow and every report is an Ametecs ticket — the failure the feature exists to end; too wide and an unchecked shared report becomes a data-distribution channel |
| **D-B-04** | Is **dashboard personalisation** in v3.0? (§B.2.7) | Yes — reorder, hide, add from the permitted catalogue. **No** personal widget authoring | Withholding it pushes customers back to asking for cards; allowing authoring makes query budget a free-for-all |
| **D-B-05** | How far do **custom-form completion actions** go in v3.0? (§B.3.7) | The ten in `R-B-099`, including `ADD_COMPONENT` and `CREATE_RECOVERY` | Fewer and the feature is a form that files paper; more and the closed action catalogue stops being closed |
| **D-B-06** | How are **custom-form permissions** expressed, given Part XXII's closed catalogue? (§B.3.10) | Reserved generative `form.*` namespace; **`R-XXII-010` must be amended** to say so | Option B without a second binding layer means anyone who may raise one form may raise all of them |
| **D-B-07** | **PWA or native mobile app** in v3.0? (§B.4.10) | Responsive PWA, installable, push where supported | A native app is a second front end with a second release train and a second access-control surface — the exact architecture §B.4 exists to end |
| **D-B-08** | Is **WCAG 2.2 AA** a GA commitment or post-GA? (§B.4.11) | **GA.** If deferred, `R-XXXV-026` requires the level and the date to be stated — silence is not available | Retrofitting accessibility across 146 screens is the expensive path SmartPRS 2 demonstrates; and it is frequently a procurement requirement for bank-facing customers |
| **D-B-09** | Which **languages** are committed at GA? (§B.5.4) | `en-IN` at GA with the full machinery, proven by a second locale in staging; `hi-IN` in the first content release; a regional language only against a named customer or tender | Shipping machinery never exercised by a second locale means discovering at tender time that fonts, layout and formats were never tested. Committing ten locales at GA is a translation project competing with the build |
| **D-B-10** | May a **tenant edit product help text**? (§B.6.6) | Yes, as an override with the vendor original visible and restorable; append mode by default | Refusing sends customer-specific policy to a shared drive nobody reads; a fork model stops vendor updates reaching customers |
| **D-B-11** | Who **staffs the documentation programme**, and does the gate block at GA? (§B.6.8) | One technical writer + module-owner authorship + consultant validation; gate warning-only internally, **blocking from the first customer-facing release** | *"A configuration-first product with no configuration manual is a code-first product with extra steps"* — and D-VI-04's "Ametecs-managed by default" becomes permanent by accident |

**Sequencing note.** `D-B-08` and `D-B-09` must be answered **before front-end
implementation begins**, not during it: an accessibility standard and a second
locale both change the component inventory, and both are cheap at component-build
time and expensive afterwards. `D-B-06` must be answered before the form builder
is built, because it is an amendment to another part. The rest can be answered at
Phase 8 planning.

---

## B.10 Cross-part reconciliation items

These are stated, not resolved, in the manner of Appendix A §A.3. Each needs an
owner, not another paragraph.

| # | Item | Owning part | What is needed |
|---:|---|---|---|
| 1 | **Eleven new decision IDs** (`D-B-01` … `D-B-11`) are not in Part XXXIV's consolidated register | XXXIV | Add them and apply Part XXXIV's tiering method |
| 2 | **Fourteen permission codes**, of which **eleven are new** (`R-B-045`, §B.3.10) | XXII | Add to the §XXII.2.2 catalogue. Until then, §B.1/§B.2/§B.3's endpoints fail `R-XXIV-023`'s build check — which is correct behaviour and a visible prompt, exactly as Appendix A F-18 left two Part XXIV scopes |
| 3 | **`R-XXII-010` must be amended** if `D-B-06` option A is accepted: codes are platform-owned and closed *except* within the reserved generative `form.*` namespace | XXII | An amendment, not a waiver |
| 4 | **Twenty-six new tables** must be added to Part XXVIII's table catalogue, which Appendix A §A.6 already records as materially incomplete | XXVIII | Register them; reconcile the count |
| 5 | **`cfg_dashboard_widget` gains three columns** (`R-B-080`) | XXI | Accept the extension into §XXI.11.2's model; §B.2 must not hold a second definition |
| 6 | **Part XIX's template model must carry a locale dimension** (`R-B-174`), which `R-XXXV-025` requires from v3.0 whether or not a second language ships | XIX | Extend `template_channel_body`'s key; the migration cost later is every template a customer owns |
| 7 | **`org_units.timezone`** (`R-B-178`) | V, XXVIII | Add the column and the resolver walk |
| 8 | **Part X's thirteen surfaces** (`R-X-029`) are declared complete; §B.3's custom forms introduce a fourteenth consumer shape (a request form) that is served by the existing `edit`/`ess`/`mobile`/`api` surfaces rather than a new one | X | Confirm that reading, or add `form` as a fourteenth surface. §B.3 assumes the former |
| 9 | **Part XXX §XXX.10's "documented" checkbox** must name its artefacts (`R-B-193`) | XXX | A one-line edit with a cross-reference to §B.6.3–§B.6.5 |
| 10 | **Part XXXII Phase 8** should be re-scoped against §B.1's dataset catalogue and §B.8's table count, now that it is costable | XXXII | Re-estimate |
| 11 | **Part XXXV §XXXV.1's Section 76, 77, 57, 94, 95, 99, 117, 118 and 119 rows** still read "Appendix B commissioned; **not delivered**" | XXXV | Update to delivered, citing this appendix's requirement ranges |
| 12 | **The three unnamed SmartPRS 2 report datasets** (`R-B-053`) | XXIX (migration) | Enumerate from `ReportController.php:22-40` during migration design |

---

## B.11 What this appendix does not claim

It does not claim the bridge is now fully specified. Specifically:

1. **It does not specify a visual design.** §B.4 specifies the architecture, the
   registry, the escape hatch, the states and the accessibility standard. The
   design system's actual tokens, its component inventory and its interaction
   patterns are a design deliverable, and `R-B-149` names it without producing it.

2. **It does not enumerate 146 screens.** `R-B-112` makes `ui_screens` the single
   inventory and `R-B-113` makes a permission mandatory; populating it is each
   module part's responsibility under the Section 125 *screens* point, which
   §XXXV.8.4 records as one of the two systematically thin points. This appendix
   removes the reason it was thin; it does not do the work.

3. **It does not resolve `D-B-06`.** A tenant-created form implies tenant-created
   permission codes, and Part XXII's catalogue is closed. That is a real conflict
   between two parts, stated as one.

4. **It has not verified a single statutory register layout.** §B.1.11 specifies
   the mechanism; the columns of Form B, Form D and Form E are rule-pack content
   whose correctness is Part XVII's compliance owner's, under `D-03`.

5. **It assumes Part XXI's widget model is sound.** §B.2 builds on
   `R-XXI-119` … `R-XXI-127` without re-deriving them. If Part XXI's model is
   revised, §B.2 follows it rather than the reverse.

6. **The documentation programme is a commitment, not a plan.** §B.6 specifies
   what must exist, who owns it and what gates the release. The schedule and the
   headcount are `D-B-11`.

The appendix's own honest summary: **five traceability gaps are closed with 216
testable requirements, 88 named regression scenarios, 26 tables and 11 decisions —
and the reason all of this was missing is that thirty-four authors each reasonably
assumed the bridge belonged to somebody else.**

---

*End of Appendix B.*
