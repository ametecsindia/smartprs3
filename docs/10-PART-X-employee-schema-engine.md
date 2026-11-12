# PART X — EMPLOYEE SCHEMA ENGINE

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Covers: governing brief Sections 10 (dynamic employee schema), 11 (one employee
data model for every surface), 12 (self-onboarding), 13 (bulk import/export),
14 (documents).
Conforms to: SPINE.md — S1 (seven laws), S3/S3.1 (organisation and scope), S4
(effective dating, draft/publish), S5 (SPX), S6 (rule engine), S8 (workflow),
**S9 (the Entity Schema Engine — this part is its full specification)**, S13
(security), S14 (audit ledger), S15 (explainability), S16 (data conventions),
S17 (API).
Depends on: Part V (domain model — Person/Employment/Assignment), Part VI
(configuration lifecycle), Part VII (rule engine and SPX), Part XXVIII
(physical schema).
Evidence base: `/home/claude/blueprint/analysis/D-employee-master.md` in full.

---

## X.0 Purpose

This part decides **how an employee attribute comes to exist in SmartPRS 3**, and
it decides it once for every consumer of that attribute.

Three commitments follow, and everything else in this part is their consequence.
First, an employee field is a **row of dated, scoped, versioned metadata** — never
a PHP constant, never a column created by a controller, never a hand-maintained
list repeated per screen. Second, there is **exactly one write path** into an
employee record, `EntityWriteService`, with PATCH semantics, and every surface —
directory, onboarding portal, import, ESS, mobile, API, recruitment conversion —
calls it. Third, the answer to *"what was this employee's branch on 15 May?"* is a
single service call, because the attributes that move over time are stored as
effective-dated rows rather than as mutable columns.

The acceptance test for this part is the brief's own worked example: **adding a
`linkedin_profile` field must be a configuration action that appears correctly on
thirteen surfaces with zero code edits, zero DDL and zero deployment.** Section
X.4.5 executes that test. Section X.11 shows why the SmartPRS 2 parity failures
that motivate it become structurally impossible rather than merely fixed.

---

## X.1 What SmartPRS 2 does today, and why it must change

Every claim in this section is carried from `D-employee-master.md`, with the
`file.php:line` citation preserved. This is not a defect dump: each row is the
justification for a specific design decision later in this part, and the last
column names it.

### X.1.1 The field registry is a PHP constant, identical for every tenant

`App\Services\EmployeeFieldRules::FIELDS` (`EmployeeFieldRules.php:80-233`)
declares exactly **56** employee fields — 20 Personal (`ep`), 14 Job & Company
(`ej`), 9 Statutory & Salary (`es`), 8 Documents & compliance (`ed`), 5 Bank
(`eb`); 22 carry `hr`=true, 26 carry a portal key, 8 are duplicate-blocked, 16
carry a format validator — plus one import-only `DEFAULT PASSWORD` column
(`:239-243`).

It is a `const` array. There is **no custom-field table, no EAV table, no JSON
attribute bag, no per-tenant field metadata, no field-definition UI, and no
tenant-scoped variation of the registry whatsoever** (`D-…` Q2). The nearest
thing to dynamism is `AppDataController::ensureEmployeeColumns()`
(`AppDataController.php:46-75`), which executes `ALTER TABLE` at request time on
every `/app/data` load (`:252`), every export
(`EmployeeExportController.php:85`) and every import preview
(`EmployeeImportService.php:203`) — adding a **developer-declared** list of 34
columns, every one of them `string(255) NULL` regardless of semantics. That is a
deployment workaround, not a customer capability, and it is the direct cause of
SCHEMA-01: on an install where the parity migration has not run, `dra_expiry`,
`pcc_expiry` and `pcc_deadline` are `VARCHAR(255)` rather than `DATE`, so the
same column has two different types depending on install history.

> **Design consequence.** Law 2 forbids runtime DDL absolutely (spine S1). Field
> definitions become dated metadata rows (X.2); values live in typed rows or
> migration-owned columns (X.2.2, Part XXVIII §7); promotion to a real column is a
> release activity, never a request-time `ALTER`.

### X.1.2 One new field costs six code edits minimum, thirteen to sixteen for parity

The registry's own docblock claims the procedure is three steps
(`EmployeeFieldRules.php:30-35`). The measured cost (`D-…` Q2):

| # | File and location | What must be edited | What breaks without it |
|---|---|---|---|
| 1 | `EmployeeFieldRules.php:80-233` | one `FIELDS` row | field does not exist |
| 2 | a migration (or `PARITY_COLUMNS`) | add the column | no column |
| 3 | `AppDataController.php:52-64` `ensureEmployeeColumns()` | add the column name | value silently dropped by `array_intersect_key` on installs where the migration has not run |
| 4 | `AppDataController.php:2846-2941` `storeEmployee()` `$payload` | `'col' => $e['formKey'] ?? null` | the form shows the input and **never saves it** |
| 5 | `AppDataController.php:301-427` `bootstrap()` | `'formKey' => $col(...)` | the value never returns to the form, so the **next save writes NULL over it** |
| 6 | `EmployeeImportService.php:483-497` `payload()` | add the column name | the wizard maps, validates, previews — then drops it |
| 7–18 | `AppDataController.php:698-720`; `SelfOnboardingController.php:827-834`, `:526-530`, `:703-789`; `portal.blade.php`; `hr-console.blade.php:109-121` **and** `:137`; `AppController.php:1645-1723`; `portal.blade.php:292-298`; `EssController.php:70-101`, `:274-284`; `RecruitmentController.php:626-637`; `ComplianceController::scan()` | full parity | the field is absent, read-only, unvalidated or unalerted on the remaining surfaces |

**Minimum 6 sites across 4 files plus a migration; full parity 13–16 sites across
8 files.** Every one is a PHP or Blade source edit requiring a redeploy — and for
an on-premise estate, a redeploy per customer.

### X.1.3 Four surfaces derive from the registry; nine do not

The registry docblock states that "there is no longer a way to add [a field] to
only some of [the five surfaces]" (`EmployeeFieldRules.php:26-27`). The analysis
records this as *"the single most misleading claim in the codebase"*
(`D-…` §8 row 13).

| Surface | Derives from the registry? | Evidence |
|---|---|---|
| Employee form panels | yes | `EmployeeFieldRules::formPanels()` |
| Import sample template | yes | `employeeTemplate()` |
| Export column map | yes | `EmployeeExportController::exportMap()` |
| Import header aliases | yes | `EmployeeFieldRules:291-328` |
| `storeEmployee()` write mapping | **no** — hand-coded literal | `AppDataController.php:2846-2941` |
| `bootstrap()` read-back projection | **no** | `:301-427` |
| Import wizard write mapping | **no** | `EmployeeImportService.php:483-497` |
| Legacy CSV importer | **no** | `AppDataController.php:690-720` |
| Onboarding portal fields | **no** — hard-coded HTML | `portal.blade.php` |
| Onboarding HR console | **no** — three hand-maintained copies | `hr-console.blade.php:109-121`, `:137`, `SelfOnboardingController.php:526-530` |
| Onboarding injection mapping | **no** | `SelfOnboardingController.php:703-789`, `:827-834` |
| ESS read and write lists | **no** | `EssController.php:70-101`, `:263-292` |
| Browser-side validation | **no** — a second copy of 16 regexes | `AppController.php:1645-1723` |
| Portal browser validation | **no** — a **third** copy | `portal.blade.php:292-298` |

`EmployeeFieldRules::hrFields()` exists and has **no callers** (DEF-23);
`portalMap()` and `formToColumn()` are used for validation only while the actual
write mappings are hand-coded copies (DEF-24). Drift is therefore not a risk, it
is a certainty, and it has already occurred: the HR console posts `pf_uan`, which
is not an `hr` registry field, and the HR value silently overwrites the
candidate's own UAN (`SelfOnboardingController.php:779-781`).

### X.1.4 The employee save is a full-record replace that NULLs omitted fields

`storeEmployee()`'s `$payload` (`AppDataController.php:2846-2941`) is a literal of
~50 fixed keys, each `$e['x'] ?? null`. Any caller that omits a key **NULLs that
column**; `ctc` → `0.0` (`:2851`), `type` → `office` (`:2814`), `salary_type` →
`only_salary` (`:2852`), `status` → `active` (`:2920`), `pcc_deadline` → null
(`:2919`). A partial POST wipes PAN, UAN, bank, address — everything
(NEW-EMP-070, DEF-02, **CRITICAL**).

The same shape recurs in the legacy importer (`:690-698`), where on an *update* a
file lacking a MOBILE column sets `mobile = NULL`. Because `ACCOUNT NUMBER`
normalises to `account_number` and is never looked up, **every re-import of the
application's own export through that button NULLs `bank_acc` for every employee
in the file** and resets `type` to `office` for all of them (NEW-EMP-078,
DEF-01). That importer is still wired to two Directory buttons — the topbar Import
button (`AppController.php:12347`) and `window.importCsv('emp-list')` (`:12420`)
— sitting next to the button that downloads the file it destroys (NEW-EMP-080).

Two further consequences of a full-record replace: `employee_references` are
`DELETE`d and re-inserted on every save (`:3021`, `:3055-3071`), so a save that
omits `refs` wipes the verification history with no audit and no undo (DEF-03);
and `status` is rewritten unconditionally (`:2920`), so an employee marked
`exited` by any other path is silently reset to `active` by the next unrelated
edit — and re-consumes a licensed seat (DEF-04).

> **Design consequence.** X.5: PATCH semantics are mandatory, clearing is an
> explicit operation, and child collections take explicit add/update/remove
> operations. A full-record replace behind a public API would be catastrophic.

### X.1.5 Nine write paths, four write mappings, three sanitisation policies

The reference documentation names five "doors". The source has **nine**
(`D-…` §1.3):

| # | Path | Writes | Validates? | Sanitises? | Seat check? | Audits? |
|---|---|---|---|---|---|---|
| 1 | `AppDataController::storeEmployee()` (`:2718-3089`) | 56 fields + geo | format + duplicate | yes | yes (`:3042`) | **no** |
| 2 | legacy CSV `importEmployees()` (`:591-870`) | ~25, NULLs 6 | partial | yes | yes (`:815`) | no |
| 3 | `EmployeeImportService` wizard (`:399-430`) | 56, set-only | format + duplicate | **no** (`:247` trims only) | **no** | job row only |
| 4 | `SelfOnboardingController::injectOne()` (`:793-906`) | 26 portal + 23 HR | portal only | **no** (`:279-282`) | **no** | no |
| 5 | `RecruitmentController::createEmployeeFromCandidate()` (`:579-645`) | 12, FKs only | no | n/a | yes (`:602`) | no |
| 6 | `EssController::updateProfile()` (`:263-292`) | 10, self-service | **none** | **no** | n/a | no |
| 7 | `BiometricConfigController` (`:630-633`) | `device_user_id`, **steals it from the current holder** | no | n/a | n/a | **no** |
| 8 | `LetterController::uploadPhoto()` (`:349`) | `photo_path`, **creating the column at runtime** | no | n/a | n/a | no |
| 9 | Lifecycle writers — `IncrementController::applyCtc()` (`:342`), `TransferService::apply()` (`:174`), `ProbationService` (`:294`, `:329`), `archiveEmployeeNow` (`:2651`), `setEmployeeStatus` (`:2705`), `bulkDeleteEmployees` (`:889`) | field-level | no | n/a | n/a | partly |

Path 6 is the sharpest illustration: an ESS user may set their `email` — which is
both the ESS login identifier and one of the eight "unique" identifiers — to a
colleague's, because `updateProfile()` calls neither `formatErrors()`, nor
`duplicateErrors()`, nor `stripHtmlDeep()` (NEW-EMP-071, DEF-12). Path 7 NULLs
another employee's `device_user_id` and re-keys their punches with no audit row
and no notification (NEW-EMP-072).

### X.1.6 Four importers silently drop unknown columns, by four different mechanisms

`D-…` Q3, verbatim in substance:

| Mechanism | Where | Behaviour |
|---|---|---|
| (a) unmatched header → `null`, discarded | `EmployeeImportService::suggestMapping():159-176`, filtered `:224-229` | a header the user does not manually assign is never read. **No warning, no count, no report.** |
| (b) mapped columns absent from `employees` dropped after mapping | `payload():603-608` `array_intersect_key($out, array_flip($empCols))` | on installs without the parity migration, `also_works_for`, `team_leader`, `account_holder`, `id_marks`, `nationality` are mapped, validated, previewed as importable — then dropped at write time with no error. `dra_pcc_declared` is dropped this way on **every** install: no migration creates it |
| (c) legacy importer drops 30 of 56 columns of the app's own file | header normaliser `AppDataController.php:623` does not strip parentheticals or `/` | 7 mis-normalised (`DOB (DD-MM-YYYY)`→`dob_(dd_mm_yyyy)`, `NATIONAL ID / SSN`→`national_id_/_ssn`, `ACCOUNT NUMBER`→`account_number`, `EMPLOYEE TYPE`→`employee_type`, `DEFAULT PASSWORD`→`default_password`, …), 23 never implemented, 2 written to a non-existent column |
| (d) onboarding bulk CSV, a fourth vocabulary | `hrBulkUpload():1019-1058`, normaliser `preg_replace('/[^a-z0-9]/','',…)` | reads only the 17 keys it names; everything else in the sheet is discarded silently |

Only the wizard shows which headers matched, and it never lists those that did
not. Separately, the import cannot clear a field at all: `payload()` guards every
column with `! empty($v[$f])` (`:494`), so a blank cell in an update row leaves
the old value and there is no way to erase a wrong PAN by import (NEW-EMP-088).

### X.1.7 Self-onboarding writes a parallel JSON blob that is never purged

`D-…` Q4. For the whole candidate-facing life of the record, every value goes to
`self_onboarding.data` — a single JSON column keyed by section (`personal`,
`contact`, `statutory`, `bank`) and by *portal* field names (`full_name`,
`acc_no`, `aadhaar`, …) that are **not** `employees` columns
(`SelfOnboardingController::save():279-282`). HR's fields go into the same JSON
under `data.hr`, keyed by *importer* names (`:538-540`). The `employees` table is
untouched throughout; a record can sit in `submitted`/`correction`/`verified`
indefinitely with no employee row.

At approval, `injectOne()` (`:793-906`) performs a **one-way** copy —
`array_merge($base, $candExtra, $hrPatch)`, HR winning (`:885`) — and then freezes
the parallel copy: `status='injected'`, `link_disabled_at=now()` (`:893`).
**Nothing purges or redacts `self_onboarding.data`**, which holds PAN, National
ID, UAN, ESIC, bank account and IFSC as plaintext JSON, plus the selfie and
documents on disk (SEC-17). There is no re-sync in either direction. On the
existing-employee branch, `name`, `email`, `mobile` and `whatsapp` are never
updated (`:853-864`) — a candidate can correct their own name and it is not
applied.

Around this sit six security findings: no rate limiting on any of the eight
public endpoints (SEC-04); the one-time code returned in the API response whenever
`config('app.debug')` is true — a condition that is **not** environment-bound
(SEC-05, `:201-203`); the bulk path bypassing the verification gate entirely
(SEC-06); a bulk matcher `emp_code OR pan OR email OR mobile` with `->first()` and
no ordering that can bind a row to the wrong employee and then overwrite their
DOB, gender, PAN, UAN and bank details (DEF-10); the selfie served publicly and
permanently with no gate (SEC-13); and the token travelling in the URL to
`fonts.googleapis.com` on every page load (SEC-21).

### X.1.8 Nothing is effective-dated: the branch question is unanswerable

`D-…` Q5. `employees` carries exactly one current value per attribute, and every
writer issues `UPDATE employees SET …`. `storeEmployee()` writes **no** audit row
at all — unlike `MasterController`, which audits every create/update/delete
(`MasterController.php:792-800`).

Three partial side registers exist, each covering only changes made through its
own workflow: `transfers` (branch/department/company, `TransferService.php:184-193`),
`increments` (CTC/designation, `IncrementController.php:338-347`) and
`probation_events` (stage, `ProbationService.php:357-367`). All three are bypassed
by four of the nine write paths, which write branch, department, CTC and stage
directly with no history row. **Nothing at all** records history for status,
reporting manager, team leader, team, shift, salary type, commission %, PF/ESI
applicability, PT state, bank details, statutory identifiers, DRA/PCC status and
expiry, biometric ID, or name.

The analysis's honest answer to the Product Owner is that **SmartPRS 2 cannot
answer "what was this employee's branch on 15 May?"** — and the same is true of
department, designation, manager, salary and status. A back-dated payroll re-run,
a statutory return for a past period, or a "who reported to whom last quarter"
query is not answerable from this schema. The only point-in-time copy of an
employee row that exists anywhere is `employee_archives.snapshot`, taken once, at
archive time, for archived employees only.

### X.1.9 Employee identity is resolved by name in eighteen places

`D-…` Q6 enumerates all eighteen. The four that cause data corruption rather than
mere confusion:

| Where | Code | Consequence |
|---|---|---|
| `selfScope()` — resolves the logged-in employee for the whole SPA | `AppDataController.php:239-242` `where('email',…)->orWhere('name',…)`; the `employee_id` branch at `:236` is **not tenant-scoped** | a user is served another employee's payslips and TDS (SEC-22); sixteen further copies of this resolver exist |
| Recruitment → Hire idempotency | `RecruitmentController.php:594-596` `where('name', $c->name)` | hiring a second "Ravi Kumar" creates **no** employee and reports the first one's code as the hire (NEW-EMP-077) |
| Transfer-onboarding idempotency | `TransferService.php:91` | a namesake's open onboarding suppresses the transferee's destination checklist |
| `OnboardingController::board()` | `:115-124` | resolves a dead employee link by name **and persists the healed id**, permanently re-pointing the record at a namesake (NEW-EMP-082) |

Underlying all of it, the org hierarchy itself is stored as **denormalised name
strings** — `reporting_manager`, `team_leader`, `team`, `department`,
`designation`, `branch`, `shift` — by the form and both importers
(`AppDataController.php:2867-2874`), while `ProbationService::recipientUserIds()`
(`:262`) reads `reporting_manager_id`, a column those paths never write. **The
manager is therefore never notified.**

### X.1.10 The ten failures, and where this part answers each

| # | SmartPRS 2 failure | Evidence | Answered in |
|---|---|---|---|
| 1 | Field registry is a PHP `const`, identical per tenant | `EmployeeFieldRules.php:80` | X.2 field definition model |
| 2 | One field = 6–16 edits across 8 files | `D-…` Q2 | X.4.5 worked example; X.11 parity matrix |
| 3 | Four surfaces derive, nine hand-maintained | DEF-24 | X.4 surface rules; R-X-040 |
| 4 | Employee save is a full-record replace | DEF-02 | X.5.1 PATCH semantics |
| 5 | Nine write paths, three sanitisation policies | `D-…` §1.3 | X.5 `EntityWriteService` |
| 6 | Four importers, four silent-drop mechanisms | `D-…` Q3 | X.9.3 per-column disposition |
| 7 | Onboarding is a parallel unpurged JSON blob | `D-…` Q4 | X.8.4 staging on the canonical entity |
| 8 | Nothing effective-dated; no field audit | `D-…` Q5 | X.6 |
| 9 | Binary status rewritten on every save; seat-free statuses a private const | DEF-04, BR-EMP-022 | X.7 state machine |
| 10 | Identity by name in 18 places; runtime DDL for 34 columns | `D-…` Q6; SCHEMA-01 | X.2.2, X.5.2 stage 1, Part V §V.7 |

**We are not replicating any of these defects.** Each is named at the design
decision it caused.

---

## X.2 The field definition model

### X.2.1 Every attribute of a field, and where it lives

The brief's Section 10 lists the attributes a field definition must carry.
SmartPRS 3 stores them in three places — the definition, its surface rules, and
the referenced document type — because visibility and requiredness are *per
surface and per role* and therefore cannot be single-valued on the definition.
This table is the authoritative mapping; nothing in the brief's list is dropped.

| Brief §10 attribute | SmartPRS 3 home | Column / mechanism |
|---|---|---|
| Field code | `field_definitions` | `field_key` — stable, immutable, referenced by SPX, templates, import headers and the API |
| Field label | `field_definitions` | `label` (translatable; see R-X-018) |
| Field description | `field_definitions` | `description` — administrator-facing, shown in the Configuration Studio |
| Help text | `field_definitions` | `help_text` — end-user-facing, rendered under the input on every surface |
| Section / group | `field_definitions` | `group_key` → `value_set('employee_field_group')`, tenant-editable, so a tenant may add a tab |
| Display order | `field_definitions` | `display_order` within `group_key` |
| Data type | `field_definitions` | `authoring_type_code` (31 authoring types, X.3) → `data_type_code` (16 storage types) |
| Default value | `field_definitions` | `default_expression` — SPX, evaluated at create time in the entity's context |
| Mandatory | `field_surface_rules` | `required_code` ∈ `never|create|always|conditional` + `required_expression` (SPX) — **per surface, per role** |
| Unique | `field_definitions` | `uniqueness_scope_code` ∈ `none|tenant|legal_entity|global`, **materialised as a real database index** (X.2.7) |
| Validation regex | `field_definitions` | `regex_pattern` + `regex_error_message`; anchored and step-bounded (Part VII §10) |
| Min / max | `field_definitions` | `min_value`, `max_value` (DECIMAL(18,4)), `min_date_expression`, `max_date_expression` (SPX), `min_length`, `max_length` |
| Allowed values | `field_definitions` → `value_sets` | `value_set_id`; items are tenant-editable rows with stable codes and labels |
| Masking | `field_definitions` | `masking_rule_code` ∈ `none|last4|first_last|full|domain_only|custom`; overridable per surface/role in `field_surface_rules.masking_override_code` |
| Encryption | `field_definitions` | `is_encrypted` — application-layer, with a `v_blind_index` for lookup and uniqueness |
| Sensitivity level | `field_definitions` | `pii_class_code` ∈ `none|identifying|sensitive|restricted` |
| Role visibility | `field_surface_rules` | `visibility_code` ∈ `hidden|read|write` per `(surface_code, role_id)` |
| Employee visibility | `field_surface_rules` | the `ess` surface rows |
| Editability by role | `field_surface_rules` | `visibility_code = write` for that role |
| Onboarding visible / mandatory | `field_surface_rules` | the `self_onboarding` surface rows: `visibility_code`, `required_code` |
| Importable | `field_surface_rules` | the `import` surface row |
| Exportable | `field_surface_rules` | the `export` surface row |
| API exposed | `field_surface_rules` | the `api` surface row |
| ESS visible / editable | `field_surface_rules` | the `ess` surface row |
| Approval required on change | `field_surface_rules` | `change_workflow_artefact_key` — the workflow the change is routed to (spine S8), per surface and role |
| Document upload | `field_definitions` | `document_type_id` (FK `cfg_document_types`) — set when the authoring type is `document`, `file`, `photo` or `signature` |
| Allowed file extensions | `cfg_document_types` | `allowed_mime_types_json`, `allowed_extensions_json` — one place, not two lists |
| Max file size | `cfg_document_types` | `max_bytes` |
| Expiry applicable | `cfg_document_types` | `expiry_mode_code` ∈ `none|optional|required|derived` + `expiry_derivation_expression` |
| Expiry reminders | `cfg_document_types` → `cfg_expiry_alert_ladders` | the ladder (X.10.2) |
| Verification required | `field_definitions` / `cfg_document_types` | `verification_mode_code` ∈ `none|optional|required` |
| OTP verification required | `field_definitions` | `otp_verification_code` ∈ `none|onboarding|always` — for `email`, `mobile` and equivalent contact types |
| Historical changes retained | `field_definitions` | `history_mode_code` ∈ `audit_only|dated` (X.6.1) |
| Scope | `field_definitions` | `scope_json` + `priority` — spine S3.1/S3.2, so a field may exist only for one legal entity, state or employee category |
| Effective dates | `field_definitions` | `effective_from`, `effective_to`, `open_marker` (spine S4, archetype A5) |

**R-X-001** Every attribute in the table above is stored as data. No employee
field attribute may be expressed as a PHP literal, a Blade template, a
JavaScript constant, or a database `ENUM`. *Test:* the static-analysis rule of
Law 1 fails the build on any string or numeric literal naming a field, a group, a
value-set code or a validation pattern inside `Domain\People`.

**R-X-002** A field definition is a configuration artefact in the sense of spine
S4: it is versioned, effective-dated, scoped, drafted, diffed, approved where
declared high-risk, and published. Publishing supersedes; it never overwrites.
*Origin:* SmartPRS 2's registry has no version, no date and no scope, so a change
to it is retroactive to all history on all tenants at once.

**R-X-003** A field definition change classed `high_risk` — a type change, a
uniqueness change, a PII-class change, an encryption change, a
`required_code` promotion on any surface, or a `history_mode_code` change —
requires the maker-checker workflow (spine S8) and a diff preview naming the
affected employee count before publication.

### X.2.2 Storage kinds — where the value actually sits

Three storage kinds, exactly as Part XXVIII §7 fixes them:

| `storage_kind_code` | Value lives in | Used for | Created by |
|---|---|---|---|
| `column` | a real, migration-owned column on `persons`, `employments`, `employment_assignments` or a dated child table | core identity and hot-query attributes: `employee_code`, `joined_on`, `lifecycle_state_code`, the twelve assignment org dimensions, the classification codes | Ametecs, by migration |
| `attribute` | one typed row in `entity_field_values` | **every customer-defined field, and every system field that is not query-hot** | a tenant administrator, at runtime, **with no DDL** |
| `generated` | a generated column populated from the `entity_field_values` row, plus an index | a field measured to be query-hot | Ametecs, by migration — a **promotion**, never a runtime `ALTER` |

**R-X-004** No application request path, queued job, console command, installer
step or seeder may execute DDL. Custom fields never create columns. *Test:* the
production database user holds no `ALTER`, `CREATE` or `DROP` privilege; a CI grep
fails the build on `Schema::create`, `Schema::table`, `ALTER TABLE` or
`CREATE TABLE` outside `database/migrations`. *Origin:* 34 `employees` columns, 6
tables and several master tables are created at request time in SmartPRS 2, always
untyped (SCHEMA-01, `D-…` §2.1).

**R-X-005** Exactly one `v_*` column is populated per `entity_field_values` row,
determined by `data_type_code` and enforced by a `CHECK` constraint. Changing a
field's type is a new dated version plus a migration-run conversion, never an
in-place reinterpretation.

**R-X-006** The storage kind is invisible above the repository. Every surface
reads through the field definition, so promoting or demoting a field changes no
caller. *Test:* an integration test promotes a field mid-suite and asserts every
surface's output is byte-identical.

### X.2.3 The field key contract

**R-X-007** `field_key` is `^[a-z][a-z0-9_]{1,58}$`, unique per
`(tenant_id, entity_definition_id)` across all versions, and **immutable**. It is
the join between the definition and: the SPX symbol `employee.custom('key')`
(Part VII §5.7), the template token `{{employee.key}}`, the import header alias
set, the export column identity, the API property name, and the report column
reference.

**R-X-008** Renaming a field's **label** is a metadata change with no downstream
effect. Renaming its **key** is not supported; the operation offered instead is
"retire and replace", which closes the old definition's effective range, creates
the new key, and records a `superseded_by_field_definition_id` link so that
historical values, audit rows, exports and letters continue to resolve.
*Origin:* SmartPRS 2 stores the org hierarchy as names, so renaming a master row
orphans every employee pointing at it (`D-…` Q6 #16).

**R-X-009** A system field (`is_system = 1`) may be re-labelled, re-ordered,
re-grouped, hidden, made mandatory, given help text, given additional validation
and given surface rules by a tenant. It may **not** be deleted, have its
`field_key` changed, have its `data_type_code` changed, or have a
platform-declared invariant relaxed (for example, `employee_code` may not be made
non-unique). The platform declares which invariants are locked in
`field_definitions.locked_attributes_json`.

### X.2.4 Validation, defined once

**R-X-010** A field carries at most one of each validation mechanism, and all of
them are declarative: `regex_pattern`, `min_length`/`max_length`,
`min_value`/`max_value`, `min_date_expression`/`max_date_expression` (SPX),
`value_set_id`, and a general `validation_expression` (SPX Boolean) for anything
the others cannot express.

**R-X-011** The client-side check is **generated** from the same definition and
served with the field metadata; it is a convenience only, and the server repeats
every check. *Test:* a test harness posts a payload that passes no client
validation and asserts identical rejection. *Origin:* SmartPRS 2 maintains the
same 16 format rules in three hand-written copies —
`EmployeeFieldRules::formatError`, `AppController::empFieldError` and
`portal.blade.php`'s `SO_RULES` — which have already drifted (`D-…` Q2 sites 14
and 15).

**R-X-012** Validation is **locale-parameterised**. The mobile, postal-code,
government-identifier and bank-identifier validators take a country code resolved
from the field's own `country_source_expression` (default:
`employee.country_code`, falling back to the tenant's default country). *Origin:*
`BR-EMP-007` hard-codes a 10-digit Indian mobile starting 6–9
(`EmployeeFieldRules.php:477-489`) while the product ships a NATIONALITY field and
an "SSN" label — a non-Indian number can never be stored.

**R-X-013** A blank value is **not** automatically valid. SmartPRS 2's sixteen
format rules all short-circuit on blank (`BR-EMP-006`), which is why "PAN is
mandatory" is unenforceable there. In SmartPRS 3, presence is decided by
`required_code` on the resolved surface rule, and format is decided by the
validators; the two are independent.

**R-X-014** Validation reports **every** failing field and every failing rule per
row, never only the first. *Origin:* `formatErrors()` short-circuits, so a row
with three bad values takes three round-trips (`D-…` req. 29).

### X.2.5 Uniqueness — declarative, index-backed, fail-closed

**R-X-015** Uniqueness is declared per field as a scope
(`none | tenant | legal_entity | global`) and is **materialised as a real database
unique index** at the moment the declaration is published:

- `storage_kind = column` → a unique index on the owning table, including
  `tenant_id` (Law 6), plus `open_marker` where the column lives on a dated table.
- `storage_kind = attribute` → `UNIQUE (tenant_id, field_definition_id,
  v_blind_index)` on `entity_field_values`, provisioned by the same release
  mechanism as a promotion.
- Encrypted fields are made unique on the blind index, never on ciphertext.

**R-X-016** Publication of a uniqueness declaration is **blocked** when existing
data violates it, and the block message lists the conflicting rows. *Origin:*
SmartPRS 2's parity migration deliberately created the five identifier indexes
**non-unique** (`2026_08_28_000100:120-124`), so duplicates are guaranteed to
exist today; a naïve migration would fail at 3am instead of at configuration time.

**R-X-017** The uniqueness pre-check is a convenience for a friendly message; the
**database constraint is the guarantee**, and a constraint violation is caught and
rendered as a field-level error. The pre-check may never be the only control, and
a failure inside it **denies the write**. *Origin:* `duplicateErrors()` swallows
every `Throwable` and returns no error, with the comment *"a failed lookup must
never block a save outright"* (`EmployeeFieldRules.php:606-608`, DEF-37) — so a
database blip silently disables the only uniqueness guarantee on eight
identifiers, none of which has a backing unique index (DEF-38).

**R-X-018** No field key, column name or table name supplied by configuration is
ever interpolated into SQL. Identifiers are resolved through a compiled map from
`field_definitions` to physical columns, validated against the live schema at boot.
*Origin:* SEC-20 —
`whereRaw('LOWER(TRIM('.$col.')) = ?', …)` (`EmployeeFieldRules.php:588-590`) is
informational today **only because** `uniqueFields()` is a constant, and this part
makes it data-driven. The analysis flags this as *blocking for the rebuild*.

**R-X-019** Uniqueness comparison semantics are declared per field:
`exact | case_insensitive | normalised(expression)`. The normalised form is
**stored** (in `v_blind_index` or a generated column) so the index serves the
query. *Origin:* SmartPRS 2 compares `LOWER(TRIM(col))` against plain indexes that
cannot serve it, running eight queries per row per import (DEF-38).

**R-X-020** Soft-deleted, archived and exited employees participate in uniqueness
by declaration, not by accident. The default is **included**. *Origin:*
SmartPRS 2 excludes archived rows (`EmployeeFieldRules.php:596-598`), so a
duplicate PAN can be created against an archived person; re-importing that person
un-archives them (BR-IMP-006), producing two live employees sharing a PAN with no
error (`D-…` §8 row 11).

### X.2.6 PII, encryption, masking and retention

| `pii_class_code` | Meaning | Default storage | Default masking | Default export |
|---|---|---|---|---|
| `none` | operational data | plain | none | exportable |
| `identifying` | names it a person: name, contact, address, employee code | plain, blind-indexed where searched | none | exportable, access-logged in bulk |
| `sensitive` | PAN, Aadhaar, UAN, ESIC, bank account, salary, biometric template id, date of birth | **encrypted at the application layer**, blind-indexed | `last4` | requires `employee.export.sensitive`, always audited |
| `restricted` | health, disciplinary, background-verification detail | encrypted; row-level access list | `full` | not exportable without an explicit per-export approval |

**R-X-021** The PII class is declared on the field, not inferred from its name,
and it propagates automatically to: storage encryption, the masking applied on
every surface, the audit ledger's decision to store a hash rather than a value
(`audit_field_changes.old_value_hash`), export eligibility, the import error
report's redaction (X.9.3), and the retention class.

**R-X-022** A field's audit row stores a **masked display form plus a hash** when
`pii_class ∈ {sensitive, restricted}`, so the audit proves the change without
re-exposing the datum. *Origin:* SmartPRS 2's import error report embeds PII by
construction — `PAN "ABCDE1234F" is already used by EMP100 (Ravi).`
(`EmployeeFieldRules.php:603-604`) — and the endpoint that serves it has no role
check at all (SEC-02).

**R-X-023** Every field carries a `retention_class_code`, defaulting to the
entity's. Archival, anonymisation and purge are scheduled, audited operations
governed by that class, and crypto-shredding of the field key is an acceptable
purge for encrypted fields. *Origin:* PO-14 — SmartPRS 2 has no retention policy
at all; archive snapshots, `self_onboarding.data`, staged import files, documents
and import error reports all retain PAN, national ID, UAN, bank accounts and
plaintext passwords indefinitely.

---

## X.3 The data type catalogue

### X.3.1 Authoring types versus storage types

A tenant administrator chooses from **31 authoring types**. The engine stores them
in **16 storage types**. The separation matters: `dropdown`, `radio` and
`single-select` are one storage type with three widgets, so a tenant may change a
field's presentation without a data migration, and the import, export, API and
SPX behaviour of all three is identical by construction.

| Storage type | `v_*` column (attribute kind) | Column type (column kind) |
|---|---|---|
| `text` | `v_text VARCHAR(1024)` | `VARCHAR(n)` |
| `long_text` | `v_text` (≤1024) or `v_json` for longer | `TEXT` |
| `number` | `v_number BIGINT` | `BIGINT` / `INT` |
| `decimal` | `v_decimal DECIMAL(18,4)` | `DECIMAL(p,s)` |
| `money` | `v_decimal DECIMAL(18,4)` + `currency_code` | `DECIMAL(18,4)` + `currency_code` |
| `date` | `v_date DATE` | `DATE` |
| `datetime` | `v_datetime DATETIME(3)` (UTC) + business date | `DATETIME(3)` |
| `time` | `v_text` as `HH:MM` (24h) | `TIME` |
| `bool` | `v_bool TINYINT(1)` | `TINYINT(1)` |
| `code` | `v_code VARCHAR(60)` | `VARCHAR(40)` |
| `code_list` | `v_json` (ordered array of codes) | child table |
| `reference` | `v_number` (internal id) + `reference_entity_key` | `BIGINT UNSIGNED` FK |
| `json` | `v_json` | `JSON` |
| `encrypted_text` | `v_text` (ciphertext) + `v_blind_index` + `v_last4` | `VARBINARY` + `BINARY(32)` |
| `file_ref` | `v_number` → `employee_documents.id` | `BIGINT UNSIGNED` FK |
| `address_ref` | `v_number` → `person_addresses.id` | `BIGINT UNSIGNED` FK |

**R-X-024** The storage-type set is closed and platform-owned. A new authoring
type is added by mapping it onto an existing storage type plus a widget, a
validator and an import/export codec. Adding an authoring type is a platform
release; adding a **field** of any existing type is a tenant configuration action.

### X.3.2 The catalogue

Columns: **Store** = storage type · **Validation** = what the engine enforces
before persistence · **Index** = the index that serves filter and sort ·
**Render** = how each surface presents it (D=directory list, E=edit form,
O=onboarding, ESS, API) · **Import/export** = the canonical text representation
in a spreadsheet cell.

| # | Authoring type | Store | Validation | Index | Render (D / E / O / ESS / API) | Import/export representation |
|---|---|---|---|---|---|---|
| 1 | **Text** | `text` | `min_length`, `max_length` (≤255 default), `regex_pattern`, HTML-stripped | `(tenant, field, v_text(64))` prefix | text cell / single-line input / same / same / JSON string | the value verbatim; leading and trailing whitespace trimmed |
| 2 | **Long text** | `long_text` | `max_length` (default 4000), HTML-stripped, no markup retained | not indexed; full-text optional | truncated to 80 chars with expander / textarea / textarea / read or write / JSON string | verbatim; embedded newlines quoted per RFC 4180 |
| 3 | **Number** (integer) | `number` | integral, `min_value`, `max_value` | `(tenant, field, v_number)` | right-aligned / stepper / stepper / same / JSON number | plain digits, no separators; a cell formatted as text is accepted |
| 4 | **Decimal** | `decimal` | scale ≤ declared `decimal_scale`, `min_value`, `max_value`; rejects a value needing more scale rather than rounding silently | `(tenant, field, v_decimal)` | right-aligned, declared scale / numeric input / same / same / JSON string (never a float) | `1234.5600` — point decimal separator always, regardless of display locale |
| 5 | **Currency** | `money` | integer minor units in memory (Law 7); `currency_code` from the tenant or the legal entity; `min_value`, `max_value` | `(tenant, field, v_decimal)` | Indian grouping, ₹1,50,000.00 / numeric input with currency chip / same / masked unless `salary.view` / `{ "amount": "150000.0000", "currency": "INR" }` | `150000.0000` plus a separate `<field>_CURRENCY` column when the tenant is multi-currency |
| 6 | **Date** | `date` | real calendar date; `min_date_expression`, `max_date_expression` (SPX, e.g. `<= TODAY()` for DOB) | `(tenant, field, v_date)` | tenant display format / native date picker / same / same / ISO `YYYY-MM-DD` | **`DD-MM-YYYY` in the file, always, and the header says so.** Excel serial numbers and ISO are accepted on import; export emits exactly what the header declares |
| 7 | **Date-time** | `datetime` | instant stored UTC with the business timezone and business date alongside | `(tenant, field, v_datetime)` | tenant timezone + zone label / picker / same / same / ISO 8601 with offset | `DD-MM-YYYY HH:MM` plus a `<field>_TZ` column when the tenant spans zones |
| 8 | **Time** | `time` | `HH:MM` 24-hour; a time-of-day, never an instant | `(tenant, field, v_text(8))` | `HH:MM` / time picker / same / same / `"HH:MM"` | `HH:MM` |
| 9 | **Email** | `text` (or `encrypted_text` when classed sensitive) | RFC-shaped, single address, lower-cased for the blind index, domain allow/deny list optional; `otp_verification_code` may require proof of ownership | `(tenant, field, v_blind_index)` | mailto link / input / input + verify button / same / JSON string | verbatim |
| 10 | **Mobile** | `text` + `v_blind_index` | country-parameterised (R-X-012): parsed to E.164 for the blind index; **the entered form is preserved for display**; `otp_verification_code` applies | `(tenant, field, v_blind_index)` | formatted per country / input with country selector / input + OTP / same / `{ "e164": "+919876543210", "as_entered": "+91 98765-43210" }` | as entered; export additionally offers an E.164 column in a profile |
| 11 | **URL** | `text` | scheme in `{http,https}` only, host shape valid, ≤2048 chars, **no credentials in the URL**, optional host allow-list | prefix index | link with `rel="noopener noreferrer"` / input / input / same / JSON string | verbatim |
| 12 | **Boolean** | `bool` | true/false only; tri-state is a `code` type with an explicit third value, never a nullable boolean | `(tenant, field, v_bool)` | tick or dash / switch / switch / same / JSON boolean | `Yes`/`No` on export; `Yes/No/Y/N/True/False/1/0/TRUE/FALSE` accepted on import, case-insensitive |
| 13 | **Dropdown** | `code` | value must exist in the value set, be active, and be effective on the record's date | `(tenant, field, v_code)` | label / `<select>` / `<select>` / same / `{ "code": "MARRIED", "label": "Married" }` | the **label** in the cell, with the code accepted too; the template ships a data-validation dropdown |
| 14 | **Multi-select** | `code_list` | every member valid; `min_selected`, `max_selected`; order preserved | `(tenant, field, v_json)` + generated bridge for filtering | chips / multi-select / multi-select / same / array of code objects | pipe-separated labels: `Hindi\|Telugu\|English` |
| 15 | **Radio** | `code` | identical to dropdown; widget only | as dropdown | inline options | as dropdown |
| 16 | **Checkbox (single)** | `bool` | identical to boolean; widget only | as boolean | as boolean |
| 17 | **Checkbox group** | `code_list` | identical to multi-select; widget only | as multi-select | as multi-select |
| 18 | **Address** | `address_ref` | composite: line1, line2, landmark, city, district, state (code), postal code, country; per-country required-subfield rules; postal code validated by country | `(tenant, postal_code)`, `(tenant, state_code)` on `person_addresses` | one-line composed / grouped sub-inputs / same, with "same as present" copy / same / nested JSON object | **one column per sub-field**, named `<FIELD> — LINE 1`, `<FIELD> — CITY`, …; round-trips exactly |
| 19 | **Photo** | `file_ref` | image MIME sniffed from content, **not from the extension**; re-encoded server-side to strip EXIF; min/max bytes; max pixel dimensions; face-presence check optional | `(tenant, employment_id, document_type)` | thumbnail / upload with crop / capture-or-upload / view, upload if permitted / signed URL | export emits the **signed URL** or, in an archive-format export, the file inside the ZIP at `photos/<employee_code>.<ext>`; import accepts a relative path inside an uploaded ZIP |
| 20 | **File** | `file_ref` | MIME sniffed; extension **and** MIME must both be allowed; size cap; virus scan verdict must be `clean` before the row is visible | as photo | filename + size / drop zone / drop zone / view own only | as photo |
| 21 | **Document** | `file_ref` | everything under File, plus the document type's rules: issue date, expiry (X.10), verification state, versioning | `(tenant, expires_on)` | badge with expiry state / typed uploader / typed uploader with checklist / view own | as photo, plus `<FIELD> — ISSUED ON`, `<FIELD> — EXPIRES ON`, `<FIELD> — VERIFIED` columns |
| 22 | **Signature** | `file_ref` | captured as vector strokes plus a rendered PNG; bound to the signer's authenticated identity, the timestamp, the IP and the document hash; immutable once captured | `(tenant, employment_id)` | "signed on DD-MM-YYYY" / read-only / capture pad / read-only | never exported as an image in a data export; a `<FIELD> — SIGNED ON` column and a verification reference only |
| 23 | **Employee lookup** | `reference` → `employment` | must be a live employment in scope; **self-reference blocked by declaration**; cycle detection where the field is declared hierarchical | `(tenant, field, v_number)` | name + code chip / type-ahead by **code and name**, storing the id / same / read-only | **`employee_code` in the cell, never a name.** The template's dropdown lists `CODE — Name`; the export emits `CODE`; an unresolvable code is a **row error**, never a silent skip |
| 24 | **Manager lookup** | `reference` → `employment` | as employee lookup, plus: not self, not a subordinate (cycle guard), must hold a manager-capable designation where declared | as employee lookup | as employee lookup | as employee lookup |
| 25 | **Organisation lookup** | `reference` → `org_unit` | the unit must be of the declared `org_unit_type`, active, and within the actor's data scope | `(tenant, field, v_number)` | path chip (`Acme › Hyderabad › Collections`) / cascading picker / same / read-only | the unit's **stable code**; the template dropdown shows the full path; ambiguity by name is impossible because the code is the key |
| 26 | **Country** | `code` | ISO 3166-1 alpha-2, from the platform value set | `(tenant, field, v_code)` | label + flag / searchable select | ISO code accepted; label exported |
| 27 | **State / province** | `code` | must belong to the selected country; drives PT applicability (spine S12) | `(tenant, field, v_code)` | label / dependent select | code accepted, label exported |
| 28 | **District** | `code` | must belong to the selected state | `(tenant, field, v_code)` | label / dependent select | code accepted, label exported |
| 29 | **PIN / postal code** | `text` | country-parameterised pattern (India: `^[1-9][0-9]{5}$`); optional lookup that **suggests** city, district and state and never silently overwrites them | `(tenant, field, v_text(8))` | plain / input with lookup affordance | verbatim |
| 30 | **Bank data** | composite (`encrypted_text` + `text`) | account number encrypted + blind-indexed + `last4`; IFSC pattern country-parameterised and checksum-validated where the country defines one; account holder name; account type from a value set; optional penny-drop verification state; **changes routed to maker-checker by default** | `(tenant, account_blind_index)` | `••••3210 · HDFC0001234` / grouped sub-inputs with re-entry confirmation / same / masked unless `employee.bank.view` | one column per sub-field; the account number exports **masked** unless the export profile carries `employee.export.sensitive`, and that export is audited |
| 31 | **Government identifier** | `encrypted_text` | type-parameterised: pattern, checksum (PAN's structural rule, Aadhaar's Verhoeff check where the tenant enables it, UAN's length), country, issuing authority; uniqueness scope; masking; optional verification workflow | `(tenant, field, v_blind_index)` | masked to declared rule / input with re-entry / input, OTP-gated where declared / masked | masked by default; full value only under `employee.export.sensitive`, audited, and never in an error report (R-X-022) |
| 32 | **Custom reference list** | `code` or `code_list` | a tenant-defined value set with codes, labels, sort order, active flag, effective range and free-form `attributes_json` usable by SPX | `(tenant, field, v_code)` | label / select / select | label in the cell, code accepted, template dropdown generated |

**R-X-025** Every type in this catalogue has: a server validator, a generated
client validator, a canonical import parser, a canonical export formatter, a
template cell specification (including data validation), an API schema fragment,
an SPX type, a mask renderer and a diff renderer. A type without all nine is not
available for selection. *Test:* a table-driven conformance suite instantiates
one field of every type and asserts all nine artefacts exist and round-trip.

**R-X-026** Dates in files are `DD-MM-YYYY` and the header says so. Export emits
exactly the format its header declares. *Origin:* SmartPRS 2 exports
`dra_expiry`, `pcc_deadline` and `pcc_expiry` as `Y-m-d` under a `(DD-MM-YYYY)`
header (DEF-21), and parses dates through **three** different implementations
with different fallbacks (`D-…` §4.2).

**R-X-027** A lookup field stores an **identifier**. No lookup anywhere in
SmartPRS 3 — employee, manager, org unit, shift, schedule, company — is stored,
imported or matched by name. *Origin:* eighteen name-based identity resolutions
(`D-…` Q6), including two idempotency keys where a namesake causes a record simply
not to be created, and one that writes a name match back to the database as an id.

**R-X-028** File-bearing types validate content, not filename. MIME is sniffed
from the bytes; the extension must additionally be allowed; the declared size
bounds are enforced; and the object is quarantined until the scan verdict is
`clean`. *Origin:* SmartPRS 2 performs an **extension check only** with no MIME
sniff, no content check and no image decode anywhere (`D-…` Q7), and accepts any
base64 blob between 500 B and 4 MB as a selfie (SEC-13).

---

## X.4 One model, every surface

### X.4.1 The surfaces, enumerated

Spine S9 fixes the list. Each is a `surface_code`, and the set is closed and
platform-owned; a tenant configures rules **on** surfaces, never new surfaces.

| `surface_code` | What it is | Reads | Writes |
|---|---|---|---|
| `directory` | the employee list, its columns, filters and sorts | ● | — |
| `edit` | the HR employee record screen | ● | ● |
| `self_onboarding` | the candidate-facing journey (X.8) | ● | ● |
| `import` | the bulk import template, mapper and committer (X.9) | ● | ● |
| `export` | bulk export and export profiles (X.9.7) | ● | — |
| `ess` | employee self-service | ● | ● |
| `mss` | manager self-service, for the manager's own reports | ● | ● |
| `mobile` | the mobile client | ● | ● |
| `api` | `/api/v3` | ● | ● |
| `recruitment_conversion` | candidate → employment conversion | ● | ● |
| `template` | letters, offer packs, forms (mail-merge tokens) | ● | — |
| `report` | report builder columns and filters | ● | — |
| `payslip` | payslip header and footer fields | ● | — |

**R-X-029** These thirteen surfaces are the complete set. Any new consumer of
employee data is one of them or is added to this table by a platform release —
never by growing a second field list. *Origin:* SmartPRS 2's nine write paths grew
because there was no such list; three of them (`EssController`,
`BiometricConfigController`, `LetterController`) are not even documented as
employee writers (`D-…` §8 row 5).

### X.4.2 The surface-rule matrix and its resolution

For a given `(field, surface, actor)` the engine resolves one **effective rule**:

```
visibility ∈ {hidden, read, write}
required   ∈ {never, create, always, conditional(SPX)}
masking    ∈ {inherit, none, last4, first_last, full, domain_only, custom}
change_workflow ∈ {none, <workflow_artefact_key>}
```

Resolution order, most specific first:

1. `field_surface_rules` row for `(field, surface, role)` where `role` is one the
   actor holds. If the actor holds several roles with rows, the **most permissive
   visibility** and the **strictest required** apply, and the union of change
   workflows applies (any one requiring approval means approval).
2. `field_surface_rules` row for `(field, surface, role IS NULL)` — all roles.
3. The field's own `default_visibility_code` and `default_required_code`.
4. **`hidden` and `never`.** Absence of a rule never grants access.

**R-X-030** Surface-rule resolution fails closed at every step: an unknown role, a
null scope, an unresolvable field, an SPX evaluation error in
`required_expression`, or any exception denies visibility and, for a write,
denies the write. *Origin:* `ApprovalService::denyUnlessRole()` wraps its role
check in `catch (\Throwable) { return null; }` where `null` means **allowed**,
with the comment *"fail open — availability over strictness on pivot errors"*
(SEC-07) — a single finding that grants access across most of the employee
subsystem.

**R-X-031** `visibility = read` on a write surface means the field is rendered
disabled **and** any supplied value for it is rejected with an explicit error, not
ignored. Silently discarding a submitted value is forbidden anywhere in
SmartPRS 3.

**R-X-032** The **directory** surface additionally carries, per field:
`is_list_column`, `list_order`, `is_filterable`, `is_sortable`,
`is_quick_search`. A field that is `hidden` on `directory` cannot be filtered or
searched on, and the API rejects such a filter rather than ignoring it.

**R-X-033** The **template** and **report** surfaces expose fields as tokens and
columns respectively, resolved through the same rules with the *recipient's*
scope, so a letter cannot leak a field the recipient may not see.

**R-X-034** Configuration validation blocks publication of a contradictory rule
set: a field `required: always` on a surface where it is `hidden`; a field
required on `import` but not `importable`; a field required at onboarding but
hidden from every onboarding step; a field with `change_workflow` set on a surface
where it is read-only. (Part VI §VI.9 owns the validator; these are its
`field_surface_rules` checks.)

### X.4.3 Surface rules for a seeded system field, illustrated

`pan` (government identifier, `pii_class = sensitive`, `uniqueness = tenant`),
as seeded from SmartPRS 2's registry:

| Surface | Role | Visibility | Required | Masking | Change workflow |
|---|---|---|---|---|---|
| `directory` | HR Manager | read | — | `last4` | — |
| `directory` | Team Leader | hidden | — | — | — |
| `edit` | HR Manager | write | `always` | `last4` | — |
| `edit` | HR Executive | write | `always` | `last4` | `EMP-IDENTIFIER-CHANGE` |
| `self_onboarding` | *(candidate)* | write | `create` | none while entering | — |
| `import` | HR Manager | write | `never` | — | — |
| `export` | HR Manager | read | — | `last4` | — |
| `export` | Payroll Manager | read | — | none *(requires `employee.export.sensitive`)* | — |
| `ess` | Employee | read | — | `last4` | — |
| `mss` | Manager | hidden | — | — | — |
| `api` | *(scope `employee.write`)* | write | `always` | `last4` on read | `EMP-IDENTIFIER-CHANGE` |
| `payslip` | — | read | — | `last4` | — |

This single table replaces: the `FIELDS` row's `u`/`v` flags, the browser
validation copy, the portal validation copy, the ESS read list, the ESS write
list, the HR-console key list (three copies), and the four import vocabularies.

### X.4.4 The worked example — adding `linkedin_profile`

The brief's acceptance case. An HR administrator wants a LinkedIn profile URL on
the employee record: visible in the directory, editable by the employee, collected
at onboarding but not mandatory, importable and exportable, exposed on the API,
and absent from the payslip.

**The configuration action, in full:**

```
CREATE FIELD  entity = employee
  field_key            linkedin_profile
  label                "LinkedIn Profile"
  description          "Public LinkedIn profile URL, used in internal directory cards."
  help_text            "Paste the full https://www.linkedin.com/in/… address."
  authoring_type       url          → storage type: text
  group_key            personal
  display_order        215
  regex_pattern        ^https://([a-z]{2,3}\.)?linkedin\.com/in/[A-Za-z0-9_-]{3,100}/?$
  regex_error_message  "Enter a full LinkedIn profile URL."
  max_length           2048
  uniqueness_scope     tenant
  pii_class            identifying
  history_mode         audit_only
  scope                { ALL }        -- every legal entity, every category
  effective_from       01-Apr-2026

SURFACE RULES
  directory  · all roles         · read   · —      · is_list_column = 0, filterable = 0
  edit       · HR Manager        · write  · never
  edit       · HR Executive      · write  · never
  self_onboarding · candidate    · write  · never   -- step "Personal", optional
  import     · HR Manager        · write  · never
  export     · HR Manager        · read
  ess        · Employee          · write  · never
  mss        · Manager           · read
  mobile     · Employee          · write  · never
  api        · scope employee.*  · write  · never
  recruitment_conversion         · write  · never   -- carried from the candidate record
  template   · all               · read
  report     · all               · read
  payslip                        · (no rule → hidden)
```

**What happens on publish, with no code, no DDL and no deployment:**

| Surface | Result |
|---|---|
| Directory | the field appears in the column chooser and on the employee card; not a default column because `is_list_column = 0` |
| Employee edit | a URL input appears in the Personal group at position 215, with the help text and the generated client validator |
| Self-onboarding | the field appears in the journey's "Personal" step for every journey that includes that step; optional; the candidate's entry is validated identically |
| Import template | a new column `LINKEDIN PROFILE` is generated, with a sample value, the validation instruction in the header note, and no dropdown (free text) |
| Import | headers `LINKEDIN PROFILE`, `LINKEDIN_PROFILE`, `LinkedIn Profile`, `LINKEDIN` map to it by the alias algorithm; unknown variants are **reported**, never silently dropped |
| Export | a `LINKEDIN PROFILE` column appears in the default profile and the column chooser |
| ESS | an editable field on the employee's own profile, subject to the same regex and uniqueness |
| MSS | read-only on the manager's team cards |
| Mobile | the same input, from the same metadata |
| API | `linkedin_profile` appears in the OpenAPI schema for `GET/PATCH /api/v3/employees/{uuid}` with `format: uri` and the pattern |
| Recruitment conversion | the candidate's value is carried into the new employment |
| Letters | the token `{{employee.linkedin_profile}}` resolves |
| Reports | available as a column and a filter |
| Payslip | absent — no rule exists, and absence means hidden (R-X-030) |
| SPX | `employee.custom('linkedin_profile')` is available to rules and formulas; an unknown key is a publish-time error |
| Audit | every change writes `audit_field_changes` with old and new values |

**The comparison that justifies this part:**

| | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| Files edited | 8 | 0 |
| Edit sites | 13–16 | 0 |
| Migrations | 1 | 0 |
| Deployments | 1 per install (on-premise: one per customer) | 0 |
| Surfaces that work automatically | 4 of 13 | 13 of 13 |
| Surfaces that silently drop the value | up to 5 | 0 |
| Time to live | a release cycle | minutes |
| Who can do it | a PHP developer | a tenant administrator |

**R-X-035** Adding, re-labelling, re-ordering, re-grouping, hiding, making
mandatory, validating, making unique or retiring an employee field is a
configuration action available to a tenant administrator with the
`entity.field.manage` permission, requiring no code change, no schema change and
no deployment. *Test:* an end-to-end test creates a field through the API,
publishes it, and asserts its correct appearance and behaviour on all thirteen
surfaces, including a full import/export round-trip, without restarting the
application.

### X.4.5 The parity matrix for SmartPRS 3

`D-…` Q1 renders SmartPRS 2's parity as a 56-row matrix with five failure
symbols. The SmartPRS 3 matrix has one row per field and **no failure symbols**,
because the symbols correspond to conditions that cannot arise:

| SmartPRS 2 symbol | Meaning | Why it cannot occur in SmartPRS 3 |
|---|---|---|
| `✗` silently dropped | the surface appears to carry the field but the value never reaches the column | there is one write mapping (X.5), derived from the definition; a value for an unknown or non-writable field is a **rejection**, never a discard (R-X-031, R-X-063) |
| `!` set-only | a blank cell cannot clear an existing value | `blank_means` is declared per field and shown in the preview (X.9.4) |
| `▲` different value set on this surface | value sets are per field, not per surface; every writer validates against the same set (R-X-024) |
| `—` absent from the surface | absence is a **declared** surface rule that the administrator can see and change, not an omission in a hand-maintained list |
| `● name only` | the field stores a name string while another surface stores an id | lookups store ids only (R-X-027) |

The three summary rows of `D-…` Q1 become, in SmartPRS 3:

| Surface | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| LEG (legacy importer) | 26 of 56, 30 dropped, 6 columns actively NULLed | the surface does not exist; the path is removed before migration (X.16) |
| SOH (onboarding HR console) | 22 + a rogue `pf_uan` that overwrites the candidate's UAN | renders from `field_surface_rules(self_onboarding|edit, HR role)`; a rogue key is impossible because the key list is not hand-written |
| ESS | 16 read / 10 write, no validation, no uniqueness, no HTML strip | declared per field; the same `EntityWriteService` pipeline as every other surface |

---

## X.5 `EntityWriteService` — the single write path

### X.5.1 The contract

```
EntityWriteService::write(WriteCommand): WriteResult

WriteCommand
  tenant_id            resolved by the persistence layer, never supplied
  entity_key           'employee'
  entity_uuid          null on create
  surface_code         one of the thirteen — mandatory, never inferred
  actor                user id + roles + data scope, or a system actor
  changes              map<field_key, ChangeValue>   -- ONLY the fields being changed
  child_operations     ordered list of {collection, op ∈ add|update|remove, key, values}
  effective_from       date, for dated fields; defaults to today
  reason_code          required where the transition or field declares it
  reason_text          free text, retained in the audit row
  idempotency_key      required on unsafe API calls
  source_reference     import batch row, onboarding journey, workflow instance, …

ChangeValue  = Set(value) | Clear() | Unchanged
```

**R-X-036 (PATCH semantics).** Only fields present in `changes` are affected. A
field absent from `changes` is untouched. Clearing a field is the **explicit**
`Clear()` operation and is refused when the field is required on the resolving
surface. *Origin:* DEF-02 — the SmartPRS 2 employee save is a full-record replace
that NULLs every omitted key, which "would be catastrophic behind a public API"
(spine S9).

**R-X-037** `EntityWriteService` is the **only** code path that writes an
employment, a person, an assignment, an identifier, a contact, an address, a bank
account or an entity field value. No controller, job, importer, console command,
service or seeder writes those tables directly. *Test:* an architecture test
(Deptrac or equivalent) fails the build on any reference to the People
repositories from outside `Domain\People\Write`, and a CI grep fails on
`DB::table('employments')` outside the repository.

**R-X-038** `WriteResult` always carries a **field-level outcome list**: for every
field in `changes`, one of `applied | unchanged | cleared | rejected(reason) |
routed_for_approval(workflow_instance)`. A caller can therefore always render
exactly what happened, and the import preview (X.9) is the same structure.

### X.5.2 The ordered pipeline

Spine S9 fixes the order. Each stage is specified below with its inputs, its
failure behaviour and the SmartPRS 2 defect it exists to prevent.

| # | Stage | What it does | On failure | Prevents |
|---|---|---|---|---|
| 1 | **Authorise** | resolves the actor's permissions and data scope; checks the operation permission (`employee.create`, `employee.update`, `employee.status.change`); checks that the subject is inside the actor's data scope. For self-service surfaces the subject is resolved **from the session**, never from the request | deny, HTTP 403, audited | SEC-22 (`selfScope()` resolves by name and is not tenant-scoped); SEC-23 (no role middleware on `/app/*`) |
| 2 | **Resolve surface rules** | for each supplied field, resolves visibility and requiredness for `(field, surface, actor roles)`; rejects fields resolving to `hidden` or `read`; collects fields requiring approval | reject those fields with an explicit per-field error; the whole command fails unless the caller passed `partial_ok` (import preview only) | ESS writing 10 fields chosen by a hard-coded list (NEW-EMP-071) |
| 3 | **Coerce types** | parses each supplied value into its storage type using the type's canonical parser and the caller's declared locale/format; normalises money into integer paise; resolves lookup codes to ids | a coercion failure is a field error naming the expected format | three different date parsers with different fallbacks (`D-…` §4.2); `'Contract'` silently becoming Permanent (`D-…` §4.3) |
| 4 | **Validate** | applies every declared validator (X.2.4) plus cross-field `validation_expression`, plus entity-level invariants (`exited_on >= joined_on`, an address's required sub-fields for its country) | collects **all** errors and returns them together (R-X-014) | `formatErrors()` short-circuiting on the first error |
| 5 | **Check uniqueness** | pre-checks declared uniqueness for a friendly message; the database constraint remains the guarantee (R-X-017) | a pre-check exception **denies** | DEF-37 (fails open), DEF-38 (racy check-then-write) |
| 6 | **Sanitise** | strips HTML and control characters from every text-typed field, normalises Unicode to NFC, collapses whitespace, and applies formula-injection neutralisation for export (`=`, `+`, `-`, `@` leading a cell) | — | DEF-11 / SEC-12: three of nine paths skip `stripHtmlDeep()`, and the SPA renders these values via `innerHTML` "with inconsistent escaping" by the codebase's own admission |
| 7 | **Effective-dated assignment handling** | partitions the change set into: current-value fields, dated-attribute fields, and assignment dimensions. For dated fields, closes the predecessor row at `effective_from − 1 day` and inserts the successor **in one transaction** with the employment row locked `FOR UPDATE`; asserts contiguity after the write (Part V §V.6.4) | the transaction aborts; nothing is applied | Q5 — no history at all; and SmartPRS 2 has zero locks in the entire application |
| 8 | **Persist** | writes columns, `entity_field_values` rows and child collections in one transaction, with optimistic concurrency on `row_version` | conflict → HTTP 409 with the current `row_version` and a field-level diff | last-writer-wins across nine uncoordinated write paths |
| 9 | **Field-level audit** | one `audit_events` row plus one `audit_field_changes` row per changed field, carrying old and new values (masked and hashed per PII class), the surface, the actor, the IP, the reason and the correlation id | **the write fails if the audit write fails** — audit is inside the transaction | `storeEmployee()` writes no audit row at all (Q5) |
| 10 | **Emit events** | publishes the domain events of X.14 **after commit**, transactionally outboxed so no event is lost and none is emitted for a rolled-back write | outbox retry with backoff; dead-letter after the configured attempts | side effects that fire on failed writes |

**R-X-039** Approval-routed fields do not reach stage 8. They are written to
`employee_field_change_requests` and applied by the workflow's `finalise` node
through this same service, with `surface_code = 'workflow'` and the original
actor recorded. Bank-account changes, salary changes, statutory-identifier
changes and role changes are routed by default (spine S8).

**R-X-040** Every surface calls `EntityWriteService`. *Test:* a coverage test
asserts that each of the thirteen `surface_code` values appears in an integration
test that writes through the service, and a mutation test asserts that bypassing
any stage causes at least one test to fail.

### X.5.3 The nine SmartPRS 2 write paths collapse into one

| SmartPRS 2 path | Evidence | SmartPRS 3 |
|---|---|---|
| `storeEmployee()` | `AppDataController.php:2718-3089` | `EntityWriteService`, `surface = edit` |
| legacy CSV importer | `:591-870` | **removed** (X.16); no replacement surface |
| import wizard | `EmployeeImportService.php:399-430` | `surface = import`, one call per row inside the batch transaction |
| self-onboarding injection | `SelfOnboardingController.php:793-906` | `surface = self_onboarding`, writing the canonical entity from the first keystroke (X.8.4) |
| onboarding bulk CSV | `:1088-1113` | folded into `surface = import` with an "invite" mode |
| recruitment → hire | `RecruitmentController.php:579-645` | `surface = recruitment_conversion` |
| ESS self-edit | `EssController.php:263-292` | `surface = ess` |
| biometric device id | `BiometricConfigController.php:630-633` | `surface = edit` on the `device_user_id` field, which is `uniqueness_scope = tenant`; **reassignment requires clearing the current holder explicitly, is audited on both records, and notifies both** |
| photo upload | `LetterController.php:349` | `surface = edit` on a `photo` field; the column is not created at runtime |
| lifecycle writers (increment, transfer, probation, archive, status, bulk delete) | `D-…` §1.3 row 9 | each is a dated assignment change or a status transition through this service (X.6, X.7) |

### X.5.4 Child collections

**R-X-041** Child collections — references, addresses, contacts, identifiers, bank
accounts, dependants, nominees, documents — are edited by **explicit
add/update/remove operations keyed on the child's uuid**. Delete-all-then-reinsert
is forbidden. *Origin:* DEF-03 — `employee_references` are deleted on every save
and re-inserted from `$e['refs']`, so a save that omits `refs` wipes the
verification history with no audit and no undo.

**R-X-042** A remove operation on an evidentiary child (an identifier, a bank
account, a document) closes its effective range or sets its lifecycle state; it
never hard-deletes. *Origin:* spine S16; and SmartPRS 2 hard-deletes both the
`documents` row and the file with no recycle bin (`D-…` Q7).

### X.5.5 Concurrency and idempotency

**R-X-043** Every aggregate carries `row_version`. A write supplying a stale
version is refused with 409 and a field-level diff. A write with no version is
refused on the `api` surface and permitted on interactive surfaces only when the
server can prove no intervening change to the fields being written.

**R-X-044** `idempotency_key` is mandatory on every unsafe API call (spine S17).
A repeat with the same key returns the original `WriteResult` without re-applying.
*Origin:* SmartPRS 2's approval steps are re-decidable, re-firing transfer orders
and increment letters (spine S8).

**R-X-045** Employee-code allocation is a configured sequence (prefix, width,
reset period, per-company or per-tenant, gapless or not) allocated from
`code_sequence_counters` under `FOR UPDATE` inside the write transaction.
*Origin:* BR-EMP-003 — three incompatible generators, one with **no collision
check** (`EmployeeImportService.php:375-377`), which rolls back an entire import
on a ~1-in-9000 collision (DEF-08); and `TMP-YYYY-NNNNN` allocated as `MAX+1`
**across all tenants** with no unique index (BR-EMP-030).

---

## X.6 Effective-dated employee history

### X.6.1 What is dated, and what is not

Every field definition declares `history_mode_code`:

| Mode | Meaning | Storage | Query |
|---|---|---|---|
| `audit_only` | one current value; every change is in the audit ledger | column or `entity_field_values` | current value; history via audit |
| `dated` | the value **as at a date** is a business fact | an A5 dated row — on `employment_assignments` for assignment dimensions, on a dedicated dated child table otherwise | `AsAtService` |

**R-X-046** The following are `dated` and may not be configured otherwise, because
payroll, statutory returns and reporting all depend on them being answerable as at
a date:

| Attribute group | Table | Why |
|---|---|---|
| legal entity, company, establishment, state, zone, branch, department, sub-department, team, cost centre, business unit, client process | `employment_assignments` | scope resolution, statutory applicability, cost allocation |
| designation, grade, employee category, employment type, employment stage, pay group, union group, work location, shift/shift group | `employment_assignments` | policy resolution and entitlement |
| reporting manager, functional manager, team leader | `employment_assignments` | approval routing and MSS "as at" |
| employment status | `employment_status_periods` | seat billing, payroll eligibility, statutory suspension |
| compensation: structure, CTC, component overrides | `employee_compensations` (Part C7) | arrears, retro, defensible payslips |
| statutory profile: PF/ESI/PT/LWF applicability and identifiers | `person_identifiers`, statutory profile (Part C8) | returns filed under the value that was in force |
| bank account | `employee_bank_accounts` | a payout line must name the account in force when it was released |
| contacts and addresses | `person_contacts`, `person_addresses` | payslip delivery, PT state, statutory address |

**PRODUCT OWNER DECISION REQUIRED — D-X-01: the dated attribute set.**
This answers `D-…` PO-02, which asks for a definitive list. **Recommendation: the
table above**, which is the analysis's suggested minimum plus grade, pay group,
shift, bank and statutory profile. Trade-off: each dated attribute is a row per
change and a dated editor in the UI rather than a plain input; the cost is
storage and a slightly heavier edit screen. The alternative — dating fewer
attributes — reintroduces exactly the class of unanswerable question that makes
SmartPRS 2's payroll indefensible, and cannot be added later without migrating
every table that names an employee.

**R-X-047** A custom field may be declared `dated` by the administrator. The
engine then stores it in a dated attribute table rather than
`entity_field_values`, and the change is `high_risk` (R-X-003) because it changes
the field's storage.

### X.6.2 The as-at query

```
AsAtService::employee(employment_uuid, as_of_date) : EmployeeSnapshot
AsAtService::field(employment_uuid, field_key, as_of_date) : Value + provenance
```

One indexed range scan per dated table (Part XXVIII C4 index list). The
`EmployeeSnapshot` composes the assignment, the status period, compensation, the
statutory profile and the current-value fields, and it is what the payroll input
snapshot (spine S11) stores by reference.

**Worked example — the question SmartPRS 2 cannot answer.**

> *What was employee `HYD-0412`'s branch on 15 May 2026?*

```
SELECT a.branch_org_unit_id, a.effective_from, a.effective_to, a.reason_code, a.source_code
FROM   employment_assignments a
WHERE  a.tenant_id = :tenant AND a.employment_id = :employment
  AND  a.effective_from <= '2026-05-15'
  AND  (a.effective_to IS NULL OR a.effective_to >= '2026-05-15')
```

Exactly one row, guaranteed by the invariants of Part V §V.6.4. The branch's
**name as it stood on 15 May** comes from `org_unit_name_history`, so a branch
renamed in July does not rewrite May's payslip.

Answer rendered to the user:

```
Branch on 15-May-2026   Hyderabad — Banjara Hills
In force                01-Feb-2026 → 31-Jul-2026
Changed by              Transfer TRF-2026-0087, approved 24-Jan-2026 by A. Rao
Previous                Hyderabad — Secunderabad (01-Apr-2025 → 31-Jan-2026)
Next                    Hyderabad — Gachibowli (from 01-Aug-2026)
```

### X.6.3 Future-dating

**R-X-048** A dated change with `effective_from` in the future is inserted
immediately and becomes in force **by the passage of time**. No scheduled job
"applies" it, and no job may rewrite it. *Origin:* SmartPRS 2 applies approved
transfers through a daily 06:30 command (`ApplyTransfers.php`,
`routes/console.php:190-193`), so a missed scheduler day means the transfer does
not happen — and the same pattern is not available for any other attribute.

**R-X-049** Future-dated rows are visible on the employee record as a "scheduled
changes" panel, are cancellable before their effective date by an authorised
actor with a reason, and are included in impact previews. Cancellation is an
audited operation, not a delete.

**R-X-050** Two future-dated changes to the same attribute are permitted; the
second closes the first at its own `effective_from − 1 day`. Overlapping ranges
are rejected at write time by the contiguity assertion (Part V §V.6.4 #4).

### X.6.4 Back-dating and declarative retro-effect

**R-X-051** A back-dated change is permitted, permissioned separately
(`employee.backdate`), always requires a reason, and **is refused when its
effective date falls inside a locked payroll period unless an authorised reopen
workflow has run** — and that guard fails closed.

**R-X-052** A back-dated change emits `employment.attribute_changed` with
`retro_from`, and the retro-effect is decided by a **configured rule**, not by
code. The rule's actions are drawn from the closed catalogue (spine S6):

```
RULE  RETRO-CTC-INCREASE
  when   event.attribute IN ['ctc','structure_version'] AND event.retro_from < period.start
  then   CREATE_RECOVERY | ADD_COMPONENT(code:'ARREARS', amount_expr: <formula>)
         · NOTIFY(payroll_owner) · FLAG_FOR_REVIEW('retro CTC change')
  except period.is_locked AND NOT context.reopen_approved
```

**PRODUCT OWNER DECISION REQUIRED — D-X-02: the arrears formula's divisor and
proration basis.** SmartPRS 2 hard-codes annual CTC ÷ **12**
(`IncrementController.php:366`) and calendar-day proration of the effective month
(`:369`), with a ₹1 floor (`:377`) and no effect at all for a decrease. The
worked example in `D-…` §4.6 — old ₹6,00,000, new ₹7,20,000, effective
16-Apr-2026, run in September — yields ₹45,000.00. **Recommendation:** carry that
arithmetic forward as the *seeded default formula* so migrated tenants see no
change, and expose the divisor, the proration basis (calendar days | payable days
| working days) and the minimum-arrears floor as formula parameters. **No value in
this paragraph is asserted as legally or contractually correct**; this is payroll
policy and is per customer (`D-…` PO-05).

### X.6.5 Field-level audit

**R-X-053** Every employee attribute change writes an `audit_events` row and one
`audit_field_changes` row per field, carrying: actor user id (never a name),
timestamp, IP, device, surface, entity, field key, old and new values (masked and
hashed per PII class), reason code and text, workflow instance where applicable,
the field definition version id, and the request correlation id. The ledger is
append-only and hash-chained per tenant (spine S14).

**R-X-054** The employee record exposes a **field history view**: for any field,
every change with actor, date, source surface and reason; and for dated fields,
the effective ranges alongside. This is the `Explain(employee, field)` surface of
spine S15.

---

## X.7 The employee state machine

### X.7.1 States are configuration

**R-X-055** Employment status is a `status_domain` (`employment_status`) whose
values are rows in `status_values`, not a PHP enum and not a database `ENUM`. The
platform seeds a starting set; a tenant may add, re-label, re-order and retire
values subject to the platform-declared invariants.

| Seeded code | Label | Semantics (`semantics_json`) |
|---|---|---|
| `pre_hire` | Pre-hire | `consumes_seat: false`, `payroll_eligible: false`, `login_enabled: false` |
| `active` | Active | `consumes_seat: true`, `payroll_eligible: true`, `login_enabled: true` |
| `probation` *(stage, not status)* | — | **not a status**; it is `employment_stage_code` on the assignment (Part V §V.6.5) |
| `suspended` | Suspended | `consumes_seat: true`, `payroll_eligible: configurable`, `login_enabled: false` |
| `on_long_leave` | On long leave | `consumes_seat: configurable`, `payroll_eligible: configurable` |
| `on_notice` | On notice | `consumes_seat: true`, `payroll_eligible: true` |
| `exited` | Exited | `consumes_seat: false`, `payroll_eligible: false` (except F&F), `login_enabled: false` |
| `absconded` | Absconded | `consumes_seat: false`, `payroll_eligible: false` |
| `retired` | Retired | `consumes_seat: false` |
| `archived` | Archived (old data) | `consumes_seat: false`, hidden from operational lists by default |

**R-X-056** Which statuses consume a licensed seat is **configuration**
(`semantics_json.consumes_seat`), read by the licensing service from the status
value row. *Origin:* BR-EMP-022 — `SEAT_FREE_STATUSES` is a `private const` in
`SubscriptionService.php:37`, and four of its five values (`resigned`,
`terminated`, `left`, `absconded`) cannot be produced by any UI at all
(`D-…` PO-16).

**R-X-057** Seat usage is computed from `employment_status_periods` as at today,
and **archived employments are included or excluded by the same declaration**, not
by an accident of which query forgot the filter. *Origin:* DEF-13 — `seatUsage()`
ignores `archived_at` while the product's own error message tells the customer
that archiving frees a seat (`SubscriptionService.php:87-99` vs `:141`); and
DEF-27 — four consumers disagree about archived employees.

**PRODUCT OWNER DECISION REQUIRED — D-X-03: does archiving free a seat?**
Recommendation: **yes**, with `archived.consumes_seat = false`, because that is
what the product already tells customers. Trade-off: it is a revenue reduction
against today's *actual* behaviour, though not against today's *stated* behaviour.
(`D-…` PO-09.)

### X.7.2 Transitions are configuration

Every transition is a row in `status_transitions` (Part XXVIII §XXVIII.4):
`from_code`, `to_code`, `required_permission`, `requires_reason`,
`requires_effective_date`, `guard_expression` (SPX), `workflow_artefact_key`.

**A transition not present in the table is impossible.**

Seeded example:

| From | To | Permission | Reason | Effective date | Guard (SPX) | Workflow |
|---|---|---|---|---|---|---|
| `pre_hire` | `active` | `employee.activate` | no | yes | `employee.joining_date <= TODAY()` | — |
| `active` | `on_notice` | `employee.status.change` | yes | yes | — | — |
| `active` | `suspended` | `employee.suspend` | yes | yes | — | `EMP-SUSPENSION` |
| `on_notice` | `exited` | `employee.exit` | yes | yes | `exit.clearance_complete OR context.override_approved` | `EMP-EXIT` |
| `active` | `exited` | `employee.exit` | yes | yes | — | `EMP-EXIT` |
| `exited` | `active` | `employee.rehire` | yes | yes | `employment.is_rehire_eligible` | `EMP-REHIRE` |
| `*` | `archived` | `employee.archive` | yes | yes | `NOT employment.has_open_payroll_input` | — |
| `archived` | `active` | `employee.restore` | yes | yes | `licence.seats_available > 0` | — |

**R-X-058** A status change is a transition, never a field write. `status` is not
writable through `changes`; it is writable only through
`EntityWriteService::transition()`, which resolves the row above. *Origin:*
DEF-04 — `storeEmployee()` rewrites `status` unconditionally on every save
(`AppDataController.php:2920`), so an exited employee is silently reset to
`active` by an unrelated edit; and DEF-16 — no transition guards exist at all, so
an archived employee can be reactivated and an archived one soft-deleted, producing
rows with both `archived_at` and `deleted_at` set.

**R-X-059** Every creation path passes the seat check, without exception, inside
the write transaction. *Origin:* DEF-06 — the import wizard and self-onboarding
injection bypass the licence entirely, which the analysis classes as direct
revenue leakage.

**R-X-060** Archive, restore, soft-delete and purge are first-class, permissioned,
audited, reversible operations with a configurable grace period, executed by a
**scheduled job with a failure channel**. *Origin:* BR-EMP-023/024 — a hard-coded
3-day grace, archiving that runs opportunistically inside a user's `/app/data`
page load at up to 50 per request, under whichever user happens to open the app,
with the whole sweep in a bare `catch (\Throwable) {}` (DEF-14). And DEF-17 —
restore exists today **only** as a side effect of re-importing the employee, which
also overwrites their record.

**R-X-061** Soft-delete carries a **declared cascade policy per related entity**
(cascade | retain | block), evaluated before the operation and shown to the actor.
*Origin:* nothing cascades in SmartPRS 2, and orphaned rows point at codes no live
employee holds (`D-…` req. 48).

**PRODUCT OWNER DECISION REQUIRED — D-X-04: the exit lifecycle.**
`D-…` PO-01 records that `exited` frees a seat, that a comment says "Real exits
should use Exit & FnF", that an `exits` table and nav screen exist — and that **no
path in the employee subsystem can produce `exited`, while any form save resets it
to `active`.** Recommendation: `exited` is produced only by the `EMP-EXIT`
workflow, which owns notice period, clearance, last working day and the F&F
trigger (Part XXVIII `exit_cases`), and it is protected by the transition table so
no edit can undo it.

---

## X.8 Self-onboarding

### X.8.1 The journey is metadata

**R-X-062** An onboarding journey is a configuration artefact
(`cfg_onboarding_journeys`) with a scope and an effective range, composed of
**steps**, each composed of **field references** and **document requirements**,
each optionally conditional on an SPX expression. Nothing about the journey is in
code. *Origin:* the eight steps, their order, the fields on each, which sections
are mandatory, which document kinds exist and their friendly names are all
hard-coded across `portal.blade.php`, `hr-console.blade.php` and
`SelfOnboardingController` (`D-…` §5(b) items 31–39).

```
cfg_onboarding_journeys
  journey_key, label, scope_json, priority, effective range
  invite_channel_codes[]        email | sms | whatsapp
  link_lifetime_hours           configurable, default 336 (14 days)
  resume_allowed, save_progress_allowed
  completion_expression         SPX Boolean — what "complete" means
  on_submit_workflow_key        the verification workflow
  purge_policy                  see R-X-078

cfg_journey_steps
  step_key, label, description, display_order,
  visible_expression (SPX), required_expression (SPX),
  is_skippable, help_media_ref

cfg_journey_step_fields
  step_id, field_definition_id, display_order,
  required_override_code, visible_expression (SPX),
  otp_required, verification_required
```

**R-X-063** A journey references **field definitions**. It never declares a field
of its own, never uses a portal-specific field name, and never posts a key the
canonical model does not know. *Origin:* Q4 — the portal writes `full_name`,
`acc_no`, `aadhaar` and 23 other names that are **not** `employees` columns, and
`ApprovalService::safeRow()` then silently drops any key that is not a real
column, so a value can be accepted, verified, approved and never stored.

**R-X-064** Conditional fields and steps use SPX over the journey's own context
(`candidate.*`, `journey.*`, `offer.*`, plus values captured so far), so
"ask for ESIC only when the offered gross is below the ESI wage ceiling" or "ask
for a work permit only when nationality ≠ IN" is configuration.

### X.8.2 Staging writes the canonical entity

This is the central design decision of the section.

**R-X-065** A journey creates the canonical `Person` and `Employment` in status
`pre_hire` at invite time, and **every candidate keystroke is written through
`EntityWriteService` with `surface_code = 'self_onboarding'` onto that same
entity.** There is no parallel structure, no JSON payload of portal-named keys,
and therefore nothing to map at approval, nothing to reconcile afterwards, and
nothing to purge except the documents and the verification evidence.

| Concern | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| Where candidate values live | `self_onboarding.data` JSON, portal-named keys (`:279-282`) | the canonical entity, field-keyed |
| Where HR values live | the **same** JSON under `data.hr`, importer-named keys (`:538-540`) | the canonical entity |
| Mapping at approval | `array_merge($base,$candExtra,$hrPatch)`, hand-coded, HR wins (`:885`) | none needed |
| Fields the mapping forgets | silently never stored | cannot occur |
| Existing-employee branch | `name`, `email`, `mobile`, `whatsapp` never updated (`:853-864`) | ordinary PATCH, with approval where declared |
| After injection | payload frozen, never purged, plaintext PAN/UAN/bank (SEC-17) | nothing to purge but documents and OTP evidence, both under retention |
| Re-sync | none, in either direction | not applicable |
| Validation | portal-only, a third copy of the regexes | the one pipeline |

**R-X-066** A `pre_hire` employment does not consume a seat, does not appear in
payroll, attendance, directory or reports, and cannot log in. It becomes visible
and seat-consuming only on the `pre_hire → active` transition (X.7.2).

**R-X-067** Values captured on a `pre_hire` employment are marked
`verification_state = unverified` per field. HR verification is a **per-field and
per-document decision** (verified / rejected with a reason / correction
requested), recorded with the verifier and the timestamp, and driven by the
`on_submit_workflow_key`. *Origin:* `hrVerify()` (`:570-582`) performs **no checks
of any kind** — it is a bare status write — and `hrBulkCommit()` bypasses the gate
that `hrApprove()` enforces (SEC-06, DEF-09).

**R-X-068** The `pre_hire → active` transition is refused while any field or
document whose journey rule declares `verification_required` remains unverified,
and the refusal names each one. There is no bulk path that bypasses this.

### X.8.3 Verification, consent and signature

**R-X-069** OTP verification is a per-field declaration
(`otp_verification_code`), applied to contact-type fields. The policy is
configuration: code length, alphabet, lifetime, maximum attempts **per identifier
and per journey** (not per OTP row), resend cooldown, maximum resends, channel
set, and lockout duration.

**R-X-070** A one-time code is **never** returned in any API response, in any
environment, under any configuration. *Test:* a CI assertion greps the response
serialisers for the OTP field, and an integration test runs with
`APP_DEBUG=true` and asserts its absence. *Origin:* SEC-05 —
`if (app()->environment('local') || config('app.debug')) { $out['dev_code'] = $code; }`
(`SelfOnboardingController.php:201-203`), where the second condition is **not**
environment-bound, and the portal displays it.

**R-X-071** Issuing a new OTP **invalidates all outstanding OTPs** for that
identifier and journey. *Origin:* SEC-04 — `otpVerify()` always reads the latest
row and caps attempts at 5 *per row*, while `otpSend()` is unlimited, so a
token-holder gets unlimited 5-guess batches against a 6-digit space.

**R-X-072** Every public onboarding endpoint is rate-limited by IP, by token and
by identifier, with configurable limits and a lockout, and every limit breach is
logged and alertable. *Origin:* SEC-04 — none of the eight public endpoints in
`routes/web.php:67-74` carries any throttle, and `/save`, `/document` and
`/selfie` are unbounded, so disk and mail spend are unbounded too.

**R-X-073** The invite link carries a **hashed** token (`invite_token_hash`); the
raw token exists only in the delivered message. Link lifetime is configuration
(default 14 days, carried from SmartPRS 2's `addDays(14)`), single-use for
sensitive steps, revocable, and rotated on each verified re-entry. The portal
loads no third-party asset of any kind, so the token cannot leak through
`Referer`. *Origin:* SEC-21 — the portal loads Google Fonts, so the token-bearing
URL is sent to a third party on every page load, and the HR console renders the
raw link as a clickable anchor.

**R-X-074** Consents and declarations are configuration: a versioned text, a
required flag, an SPX applicability condition, and a capture record storing the
consent version, the timestamp, the IP, the user agent and the exact rendered text
hash. A consent text change creates a new version; it never rewrites what someone
agreed to.

**R-X-075** Digital signature capture (authoring type 22) binds the strokes, the
rendered image, the signer's verified identity, the timestamp, the IP and the
**hash of the document signed**. It is immutable once captured, and a change to
the underlying document invalidates the signature and requires re-signing.

**R-X-076** Uploaded documents are stored on a **private** disk under a
non-guessable key, served only through authorised, signed, expiring URLs, always
with `Content-Disposition: attachment` unless the type is explicitly declared
inline-safe. *Origin:* SEC-13 (the selfie is served publicly and permanently with
no gate), SEC-14 (documents streamed inline with a MIME guessed from the
extension), SEC-15 (`employee-docs/<numeric employee id>/<basename>` on the
public disk).

### X.8.4 The flow

```
invite (HR or recruitment)
  → pre_hire Person + Employment created; token hashed; link delivered
  → candidate authenticates by token + OTP on a declared contact
  → steps rendered from cfg_journey_steps × field_surface_rules(self_onboarding)
  → every save → EntityWriteService(surface=self_onboarding)  [canonical entity]
  → documents uploaded against declared document types
  → consents and declarations captured; signature captured
  → submit → completion_expression asserted → on_submit_workflow_key starts
  → HR verifies per field and per document (verified | rejected(reason) | correction)
  → correction requested → link re-enabled for named fields only
  → all required verifications complete
  → transition pre_hire → active   [seat check, code allocation, welcome pack]
  → journey payload retention clock starts
```

**R-X-077** A correction request re-opens the journey for the **named fields
only**; every other field stays read-only. *Origin:* SmartPRS 2's correction
state re-opens the whole portal.

**R-X-078** Once the employment is `active`, the journey's staged artefacts are
governed by a retention policy: verification evidence retained per the
`R-PII-CONSENT` class; OTP records purged after the configured window; the raw
selfie either promoted to the employee photo or purged; and **no plaintext copy of
any identifier survives outside the encrypted canonical fields.** A scheduled
purge job enforces this and its last run is visible on the operations dashboard.
*Origin:* SEC-17 — `self_onboarding.data` is never purged after injection and
holds PAN, National ID, UAN, ESIC, bank account and IFSC as plaintext JSON.

**R-X-079** Bulk onboarding is the import surface with an `invite` mode: it
creates journeys, it does **not** create active employments, and it passes through
the identical verification gate. Where a row is intended to match an existing
employment, the match is by **employee code or an explicitly chosen identifier**,
is shown in the preview, and requires per-row confirmation. *Origin:* DEF-10 — the
matcher `emp_code OR pan OR email OR mobile` with `->first()` and no ordering can
bind a row to the wrong employee and then overwrite their DOB, gender, PAN, UAN
and bank details.

---

## X.9 The bulk import and export engine

### X.9.1 The template is generated from the enabled fields

**R-X-080** The import template is **generated on demand** from the fields
resolving to `visibility = write` on the `import` surface for the requesting
actor's roles, in `group_key` then `display_order` order. It is never a static
file and never a second list.

The generated workbook carries:

| Element | Content |
|---|---|
| Sheet 1 `Employees` | one column per enabled field, header = the field's `label` plus the declared format hint, e.g. `DOB (DD-MM-YYYY)` |
| Header note per column | the field's `help_text`, its requiredness on the import surface, its validation instruction (pattern in words, min/max, allowed length), and whether blank clears |
| Row 2 | a **sample row** of realistic values generated from each field's type and value set |
| Data validation | a dropdown for every `code` and `code_list` field, sourced from the active value-set items; a date validation for date fields; a numeric validation with the declared bounds |
| Lookup columns | employee, manager and org-unit fields present a dropdown of `CODE — Label`; **the stored cell is the code** |
| Sheet 2 `Instructions` | the required columns, the duplicate-handling modes, the blank-means-clear list, the row cap, and the support contact |
| Sheet 3 `Lists` | the value sets used, **and nothing else** |
| Locked cells | the header row is protected; the workbook carries a version stamp of the field-definition versions used |

**R-X-081** The template contains **no employee roster**. *Origin:* SEC-01 — the
SmartPRS 2 template's hidden "Lists" sheet carries
`'@employees' => $names('employees')`, every employee name in the tenant
(`AppDataController.php:962-964`), from an endpoint with **no role check at all**;
one authenticated GET downloads the complete staff roster and org structure.
Where a lookup dropdown is genuinely required, it is generated only for actors
holding `employee.list` and is scoped to their data scope.

**R-X-082** The template download requires `employee.import` and is audited.

### X.9.2 The pipeline

```
upload  → content-sniffed, size-capped, virus-scanned, stored encrypted
parse   → streamed; headers normalised; columns matched to fields
map     → suggested mapping presented; HR may correct any column
validate→ per row, per field: coerce, validate, uniqueness, surface rules
preview → per-column disposition + per-row outcome + a downloadable error sheet
commit  → chunked transactional apply through EntityWriteService
report  → immutable batch record, per-row outcome, per-column disposition
```

**R-X-083** Header matching is deterministic and reported. A header matches a
field when its normalised form equals the field's normalised label, its
`field_key`, or one of its declared aliases. Normalisation strips a trailing
parenthetical hint, lower-cases, replaces every non-alphanumeric run with a single
underscore, and trims. **Two headers matching the same field is a `duplicate`
disposition and blocks the import until resolved** — it is never resolved by
"first match wins". *Origin:* BR-IMP-002 and the four incompatible normalisers of
`D-…` Q3, one of which cannot see its own product's headers.

**R-X-084** Required-to-import columns are **configuration** (`required_code` on
the `import` surface), not a `REQUIRED = ['name']` constant. *Origin:* BR-IMP-003
— only `name` is required by any validator anywhere, while customers routinely
need employee code, DOJ or PAN mandatory.

**R-X-085** Duplicate handling is a per-import choice — `skip | update | create` —
and `create` is refused at preview when it would violate a uniqueness constraint,
with the offending rows named. *Origin:* DEF-07 — choosing `create` in SmartPRS 2
**guarantees** a unique-index violation that rolls the whole import back and shows
the user a raw SQLSTATE message.

**R-X-086** Commit is chunked and transactional: each chunk of N rows (configurable,
default 500) commits atomically, and a chunk failure rolls back that chunk only,
records the reason, and continues or halts per the batch's declared
`on_chunk_failure` policy. The batch record always states exactly which rows were
applied. *Origin:* BR-IMP-005 plus DEF-07 — one transaction for the entire file
means one bad row loses everything; and the ESS-password side effect currently
runs **outside** the transaction (BR-IMP-007).

**R-X-087** No import bypasses `EntityWriteService`, so every row is authorised,
surface-checked, coerced, validated, uniqueness-checked, sanitised, dated,
audited and evented exactly as a screen edit would be — including the seat check.

### X.9.3 Per-column disposition: no silent drops, anywhere

**R-X-088** Every column in the uploaded file receives exactly one **disposition**,
reported in the preview, in the commit report and in the batch record:

| Disposition | Meaning | Blocks commit? |
|---|---|---|
| `accepted` | matched a field the actor may write on the import surface | no |
| `unknown` | no field matched this header | **yes**, unless the actor explicitly acknowledges it as ignored |
| `ignored` | matched, but the actor chose to leave it unmapped | no — but it is listed in the report |
| `duplicate` | two or more headers matched the same field | **yes** |
| `not_permitted` | matched a field the actor may not write on this surface | **yes**, unless acknowledged |
| `read_only` | matched a field that is read-only on the import surface | **yes**, unless acknowledged |
| `rejected` | every value in the column failed validation | no — reported per row |
| `empty` | present with no values in any row | no |

**R-X-089** Every row receives exactly one outcome — `created`, `updated`,
`unchanged`, `skipped(duplicate)`, `rejected(validation)`, `rejected(permission)`,
`rejected(uniqueness)` — and every rejected row carries **every** failing field
with its field label, the row number, the column letter, the rule that failed and
the corrective instruction.

**R-X-090** The error sheet is a downloadable workbook **containing the original
rows plus an appended error column**, so the user corrects and re-uploads the same
file. It carries field names, row numbers and rule descriptions — and **never
identifier values**. *Origin:* SEC-02 — SmartPRS 2's error report streams
`PAN "ABCDE1234F" is already used by EMP100 (Ravi).` from an endpoint that, along
with the import history endpoint, is the only pair in its controller with **no**
permission guard, IDOR-able by incrementing the job id.

**R-X-091** Counts always reconcile and are shown together:
`rows in file = created + updated + unchanged + skipped + rejected`. A mismatch
aborts the batch as a programming error. *Origin:* four importers that discard
unknown columns in silence, none of which counts what it dropped (`D-…` Q3).

**Preview report shape (rendered and downloadable):**

```
File            employees-june.xlsx · 4,318 data rows · 34 columns
Field versions  employee field definitions as at 03-Sep-2026 (v41)

COLUMNS
  accepted        31   EMPLOYEE CODE, NAME, DOB (DD-MM-YYYY), …
  unknown          1   "OLD ERP REF"        → no matching field. Ignore, or create a field.
  duplicate        1   "MOBILE", "MOBILE NO" both match employee.mobile  → resolve to proceed
  not_permitted    1   "CTC"                → your role may not write CTC on import
  ignored          0

ROWS
  create       1,204
  update       2,981
  unchanged       97
  rejected        36   → 36 rows, 51 field errors, error sheet attached
     14  PAN failed format
     11  BRANCH CODE "HYD-99" does not exist
      9  DOJ 31-02-2026 is not a real date
      7  MOBILE duplicates EMP-2214 (tenant-unique)
     10  ESIC required when ESI APPLICABLE = Yes   [conditional rule]

BLANK-MEANS-CLEAR columns in this file: WHATSAPP, EMERGENCY CONTACT NUMBER
  → 42 rows will CLEAR one or both of these. Review before committing.
```

### X.9.4 Blank-means-clear

**R-X-092** Every field declares `blank_means ∈ {ignore, clear}` for the import
surface, defaulting to `ignore`. Fields declared `clear` are listed explicitly in
the preview together with the count of rows that will clear them, and the actor
confirms. A required field can never be declared `clear`. *Origin:* NEW-EMP-088 —
`payload()` guards every column with `! empty($v[$f])`, so a blank cell never
clears anything and there is no way to erase a wrong PAN by import; and
NEW-EMP-078 — the legacy importer does the opposite, NULLing every column absent
from the file.

### X.9.5 Streaming, caps and resumability

**R-X-093** Files are streamed. The parser holds one row at a time plus the
column map; peak memory is independent of file size. *Origin:* the SmartPRS 2
wizard reads the whole file into memory **twice** with no cap (`D-…` §5(b) item 25).

**R-X-094** Configurable per tenant: maximum rows per file, maximum bytes,
maximum concurrent batches, chunk size, and the row-processing timeout. The
defaults are set so an installation on modest PHP settings succeeds; exceeding a
cap is a clear, actionable message at upload, never a timeout mid-commit.

**PRODUCT OWNER DECISION REQUIRED — D-X-05: the import volume target.**
`D-…` PO-10 records that no cap, no streaming and one transaction mean practical
limits are `upload_max_filesize`, `memory_limit` and `max_execution_time`, and
that typical Laragon/XAMPP defaults would fail well before 10,000 rows.
Recommendation: **50,000 rows per file, previewed within 3 minutes and committed
within 15**, with the queue worker mandatory (spine S2). Trade-off: a lower target
allows a simpler synchronous implementation; a higher one requires partitioned
staging.

**R-X-095** Import runs as a queued job with progress, cancellation and
resumability from the last committed chunk. Preview and commit both survive a
worker restart.

**R-X-096** Uploaded files are stored **encrypted at rest**, with a TTL purge job,
and any credential-bearing column (a default ESS password) is blanked in the
stored copy immediately after use. *Origin:* SEC-10 / DEF-36 — staged files at
`storage/app/imports/employees/<uuid>.<ext>` containing plaintext first-time
passwords plus PAN, national IDs, bank accounts and IFSC for the whole workforce
are **never deleted** by anything.

**R-X-097** An import may not set a login credential in plaintext through a
spreadsheet column. Where an ESS account is to be created, the import sets an
invitation, and the employee sets their own password under the tenant's password
policy. *Origin:* BR-IMP-007 — `DEFAULT PASSWORD`, minimum six characters
hard-coded, bcrypt-written outside the transaction, and left in the staged file
forever.

### X.9.6 Export, and provable round-trip symmetry

**R-X-098** Export is generated from the fields resolving to `visibility ∈ {read,
write}` on the `export` surface for the actor's roles, with: column selection,
column order, filters (status, org unit, designation, grade, date ranges,
include-archived, custom-field predicates), a chosen format (XLSX, CSV, JSON), and
**saved export profiles** that are themselves scoped configuration artefacts.

**R-X-099** Every export is audited with the actor, the profile, the filter, the
row count, the column list and whether sensitive fields were included. Exports
containing `sensitive` or `restricted` fields require the corresponding permission
and are additionally recorded in `audit_export_log`. *Origin:* SmartPRS 2 audits
the export as one `activity_logs` row with no column or sensitivity detail
(BR-IMP-010), and its export leaks cross-tenant company and salary-schedule names
because both maps are plucked with no tenant filter (SEC-19 / NEW-EMP-086).

**R-X-100 (round-trip symmetry).** For every field enabled on both the import and
export surfaces, `import(export(E)) ≡ E`. *Test:* a generated regression test —
one case **per field**, plus one whole-population case — exports a fixture
population, re-imports the file into an empty tenant, and asserts field-by-field
equality including nulls, blanks, dates, decimals, codes, multi-selects,
addresses and lookup references. The test is generated from
`field_definitions`, so a new field is covered the moment it is published, and it
runs in CI.

**R-X-101** Where a field is exportable but not importable (a derived value, a
masked identifier, a computed tenure), the export marks the column
`[read-only]` in its header note and the importer reports it as `read_only`
rather than silently ignoring it.

**R-X-102** Export writes dates, decimals and booleans in exactly the
representations X.3.2 declares, and the importer accepts those representations
without configuration. *Origin:* DEF-21 and DEF-01 together — SmartPRS 2's own
export cannot be re-imported through one of its own two Import buttons without
destroying 30 columns and NULLing six more.

---

## X.10 Documents

### X.10.1 The document type master

**R-X-103** A document type is a configuration artefact (`cfg_document_types`)
with a scope and an effective range. It replaces four unrelated hard-coded
vocabularies in SmartPRS 2: `DocumentController::CATEGORIES` (6 values,
`:21-28`), the portal's `DOCS` (7 kinds, `portal.blade.php:230`), the injection's
friendly-name map (7 entries, `SelfOnboardingController.php:935`) and the free
text accepted by the onboarding upload endpoint (`:324`).

```
cfg_document_types
  type_key, label, description, category_code (value set),
  applicability_expression      SPX — which employee population must hold it
  requirement_code              optional | required | required_on_condition
  requirement_expression        SPX
  allowed_mime_types_json, allowed_extensions_json, max_bytes, min_bytes
  max_pages, image_min_pixels                       -- where applicable
  issue_date_mode               none | optional | required
  expiry_mode_code              none | optional | required | derived
  expiry_derivation_expression  SPX, e.g. DATEADD(issued_on, 3, 'years')
  expiry_alert_ladder_id        FK cfg_expiry_alert_ladders
  verification_mode_code        none | optional | required
  verification_workflow_key     FK config artefact
  versioning_mode_code          single | versioned          -- supersession
  retention_class_code
  visibility_default_code       and per-role overrides via field_surface_rules
  is_encrypted, watermark_policy_code, signature_required
  blocks_expression             SPX — what a missing/expired instance gates
```

**R-X-104** Document **applicability** is an SPX expression over the employee's
attributes as at the evaluation date — `org.state = 'TG' AND
employee.category = 'FIELD'` — so "field agents in Telangana must hold a valid
DRA certificate" is configuration. *Origin:* in SmartPRS 2 only DRA and PCC have
dated certificates at all, and their applicability is not expressible.

**R-X-105** Verification is a **workflow**, not a status write: an approver, a
decision, a reason, evidence and an audit row. A verified document cannot be
silently re-verified, and re-uploading supersedes rather than overwrites where the
type is `versioned`. *Origin:* `D-…` Q7 — "there is **no verification workflow**
… no evidence is linked, no approver is recorded, no audit row is written",
and there is **no endpoint anywhere to change a document's status**, while the
tracker always inserts `pending` and onboarding always inserts `approved`.

**R-X-106** Document access is **object-level authorised**: an employee sees only
their own; a manager sees only their reports' and only the types declared visible
to `mss`; and every view of a `sensitive` or `restricted` document is audited.
*Origin:* SEC-03 — `download()` permits role `employee` and filters on
`tenant_id` only, with no check that the document belongs to the caller, so
walking `/app/documents-mgr/{1..N}/download` yields every colleague's ID proof,
PAN card, bank proof and certificates.

**R-X-107** Documents are soft-deleted with a retention class; the object and the
row are removed together only under an audited retention purge, in one transaction
with the storage delete **confirmed**. *Origin:* SmartPRS 2 hard-deletes the row
and unlinks the file with no recycle bin, employee soft-delete does not cascade,
and the unlink is wrapped in an empty catch.

**R-X-108** Watermarking (viewer identity, timestamp, purpose) is a per-type
policy applied at render time for on-screen viewing and for permitted downloads.
Digital signature requirements are declared per type and satisfied by the
signature type of X.3.2 row 22.

**R-X-109** Storage quota is configuration at two levels — per employee and per
tenant — and the number shown to the user is the number enforced. *Origin:*
`D-…` §4.10 — the quota is enforced per employee but the `used_mb` shown in the
UI is the **tenant** total, so "the number shown is not the number enforced".

### X.10.2 The expiry alert ladder

**R-X-110** Expiry alerting is one engine over every dated compliance artefact —
document expiry, identifier validity, certificate expiry, background-verification
next-due, licence and training validity — configured as a **ladder**:

```
cfg_expiry_alert_ladders
  ladder_key, label, scope_json, effective range
cfg_expiry_alert_steps
  offset_days      90 | 60 | 30 | 15 | 7 | 1 | <custom, any integer>
  on_expiry_day    boolean
  overdue_repeat_every_days
  audience_expression   SPX → employee | reporting manager | HR role | named group
  channel_codes[]       in_app | email | sms | whatsapp | webhook
  template_key
  severity_code         info | warning | critical
  escalation_after_days, escalation_audience_expression
```

The seeded default ladder is **90 / 60 / 30 / 15 / 7 / 1 days, then on the expiry
day, then a configurable overdue repeat**; every step, offset, audience and
channel is editable, and a tenant may add steps at any offset.

**R-X-111** Alert evaluation is a scheduled job using **"at or past" semantics
with catch-up**: on each run it emits every step whose threshold has been crossed
and not yet notified, keyed by `(artefact, step, instance)` for idempotency. A
missed scheduler day is caught up on the next run. *Origin:* DEF-28 /
BR-EMP-045 — probation milestones fire on an **exact** integer match of
`days_left` against `[30,15,7,0]`, so a scheduler outage on one day silently loses
that day's milestone for everyone, permanently. The same exact-match defect
appears in the compliance scan's fixed 30-day window.

**R-X-112** **Every** dated compliance artefact feeds this engine. *Origin:*
NEW-EMP-085 — `documents.expiry` is captured on upload and alerted on by nothing,
because `ComplianceController::scan()` never queries the `documents` table; and
DEF-18 — `pcc_deadline` is captured on three surfaces, exported, **SELECTed by the
scan** (`ComplianceController.php:57`) and then never passed to the bucketing
function (`:102-103`), so a missed PCC deadline raises no alert whatsoever.

**R-X-113** Readiness rules are declarative per document type: which verification
states count as compliant, and what a non-compliant state gates. *Origin:*
BR-CMP-004 — DRA requires `verified` while PCC accepts `verified` **or**
`submitted`, asymmetrically, undocumented, and hard-coded at
`ComplianceController.php:572-580`. The money-gate itself (`off`/`warn`/`block`)
is preserved and generalised as a rule (spine S19 #1, Part VII RL-CMP-001).

**R-X-114** Alert windows, buckets, thresholds, digest sizes, recipients and send
times are configuration. *Origin:* the hard-coded 30-day window, 7-day "critical"
threshold, 25-row digest cap and 07:30 send time (`ComplianceController.php:31`,
`:79`, `:206`, `routes/console.php:143-146`).

---

## X.11 The field-parity matrix for SmartPRS 3

`D-…` Q1 is a 56-field × 9-surface matrix in which 30 cells are silent drops, 56
are set-only, 14 carry a different value set, and 7 store a name where another
surface stores an id. This section shows, mechanism by mechanism, why each class
of failure is **structurally impossible** in SmartPRS 3 — not "fixed", but unable
to arise.

| # | SmartPRS 2 failure class | Instances | The mechanism that made it possible | Why it cannot occur | Requirement |
|---|---|---|---|---|---|
| 1 | A field on a surface that never saves it | `dra_pcc_declared` on LEG; 5 fields dropped by `array_intersect_key` on non-migrated installs | the write mapping is a hand-written literal, separate from the registry | there is one write mapping, derived from `field_definitions`; a field either resolves on the surface or is rejected with an error | R-X-031, R-X-037 |
| 2 | A field that saves but never reads back, so the next save NULLs it | any field missing from `bootstrap()` | read-back projection is a second hand-written list | PATCH semantics: a field absent from `changes` is untouched, so a read-back gap cannot destroy data | R-X-036 |
| 3 | A surface accepting values no dropdown offers | `status='onleave'` via import; 13 fields with no value-set check (DEF-25) | value sets exist only in the form's HTML | every writer validates against the field's value set, in stage 4 of one pipeline | R-X-024, R-X-030 |
| 4 | A blank cell that cannot clear a value | all 56 fields on the wizard (`!`) | `! empty()` guards in the importer's payload builder | `blank_means` is a declared, previewed, confirmed per-field behaviour | R-X-092 |
| 5 | Two surfaces storing different shapes of the same fact | names-only (form, importers) vs FK-only (recruitment) vs both (onboarding HR) — DEF-05 | three hand-written mappings | lookups store ids only; there is no name column to disagree with | R-X-027 |
| 6 | A surface with no validation at all | ESS: no format, no uniqueness, no HTML strip (DEF-12) | validation lives at the call site | validation is stages 4–6 of the single service; a surface cannot skip a stage | R-X-037, R-X-040 |
| 7 | An unknown column discarded in silence | four importers, four mechanisms (Q3) | nothing accounts for columns | every column receives a disposition; `unknown` blocks commit until acknowledged | R-X-088 |
| 8 | An HR console key that is not a registry field | `pf_uan` overwriting the candidate's UAN (DEF-23) | the console's key list is hand-written in three places | the console renders from `field_surface_rules`; there is no key list to write | R-X-063 |
| 9 | A mandatory rule enforced only in the browser | DRA/PCC declared on new hire (BR-EMP-017), bypassed by five write paths | the rule is a JavaScript check | `required_code` is resolved server-side in stage 2 for every surface | R-X-030 |
| 10 | A field captured everywhere and read by nothing | `also_works_for`, `pcc_deadline`, `docs_status`, `docs_stored`, `probation_months` | no relationship between a field and its consumers | the Configuration Studio's "where is this used?" view (Part VI §VI.6.5) lists every rule, formula, template, report and surface referencing a field; a field with no consumers is reported | R-X-115 |

**R-X-115** The field editor shows, for every field, its consumers: surfaces,
rules, formulas, templates, reports, exports and integrations. A field may not be
retired while a published artefact references it; the block names the references.

**R-X-116** A generated **parity report** is available per tenant and runs in CI
against the seeded configuration: one row per field, one column per surface, each
cell showing the resolved visibility and requiredness. There is no hand-maintained
parity matrix, because the matrix is derived. *Origin:* the SmartPRS 2 matrix
took a forensic analysis to produce, and its own docblock's claim about parity was
false (`D-…` §8 row 13).

---

## X.12 Configuration surface owned by this part

Everything below is changeable by an appropriately permissioned tenant
administrator, through the Configuration Studio, without code, without DDL and
without a deployment. The right-hand column is the SmartPRS 2 item it replaces.

| # | Configurable | Where | Replaces |
|---|---|---|---|
| 1 | Add / retire / re-label / re-describe / re-help an employee field | `field_definitions` | `EmployeeFieldRules::FIELDS`, a PHP const |
| 2 | Field group (tab), display order, and adding a new group | `field_definitions.group_key` + value set | `ep/ej/es/ed/eb` hard-coded in `formPanels()` |
| 3 | Data type, decimal scale, currency | `field_definitions` | fixed per field in code |
| 4 | Default value (SPX) | `default_expression` | hard-coded defaults `office`, `only_salary`, `active`, `doj = today` |
| 5 | Mandatory, per surface and per role, including conditional (SPX) | `field_surface_rules` | only `name`; DRA/PCC browser-only |
| 6 | Visibility and editability, per surface and per role | `field_surface_rules` | ESS's hard-coded 16 read / 10 write |
| 7 | Which fields are unique, and at what scope | `uniqueness_scope_code` | the 8-field `u => 'block'` flag |
| 8 | Validation: regex, min/max, length, cross-field (SPX), locale | `field_definitions` | 16 regexes in three hand-written copies |
| 9 | Value sets: add, re-label, re-order, retire, date | `value_sets` / `value_set_items` | 14 fixed lists incl. Gender (3), Marital (4), Blood group (8), Category (5), Stage (3), Status (2), PT State (36) |
| 10 | PII class, encryption, masking rule, retention class | `field_definitions` | none exist |
| 11 | Whether a field is effective-dated | `history_mode_code` | nothing is dated |
| 12 | Which fields require an approval workflow to change | `field_surface_rules.change_workflow_artefact_key` | no approval on any field |
| 13 | Employee-code sequence: prefix, width, reset, per-company/per-tenant, gapless | `cfg_code_sequences` | three incompatible generators |
| 14 | Employment status set and its semantics, incl. seat consumption | `status_values.semantics_json` | binary Active/Inactive; `SEAT_FREE_STATUSES` private const |
| 15 | Status transitions: permission, reason, effective date, guard (SPX), workflow | `status_transitions` | no guards of any kind |
| 16 | Archive grace period, batch size, schedule, and whether archiving frees a seat | policy artefact + status semantics | `addDays(3)`, `limit(50)` inside a page load, `limit(2000)` snapshot |
| 17 | Onboarding journeys: steps, order, fields per step, conditions, completion criteria | `cfg_onboarding_journeys` | 8 hard-coded steps, hard-coded HTML fields |
| 18 | Link lifetime, OTP length/lifetime/attempts/resends/channels, rate limits, lockout | journey config + platform policy | `addDays(14)`, 6 digits, 10 minutes, 5 attempts, no throttle |
| 19 | Consent and declaration texts, versions and applicability | consent artefact | none |
| 20 | Onboarding purge policy and retention | journey config | never purged |
| 21 | Import: required columns, aliases, duplicate mode, blank-means-clear, row caps, chunk size | field metadata + import policy | `REQUIRED=['name']`, `! empty()` guards, no caps |
| 22 | Export: columns, order, filters, format, saved profiles | `export_profiles` | no column chooser, no filters |
| 23 | Document types: applicability, formats, size, expiry, verification, versioning, retention, watermark, signature | `cfg_document_types` | 6 categories + 7 kinds, both hard-coded; no verification action exists |
| 24 | Expiry alert ladder: offsets, audiences, channels, templates, escalation, schedule | `cfg_expiry_alert_ladders` | 30/7/25/07:30, all literals |
| 25 | Document quota per employee and per tenant | policy artefact | 100 MB const + one tenant column |
| 26 | Notification audiences for every employee event | notification artefact (Part on notifications) | `admin\|hr_manager\|hr\|super_admin` hard-coded |

### X.12.1 The Section 127 test, applied to this part

The brief's standard is that *"3 late arrivals over 10 minutes = half day"* must
not require code. The equivalent tests for this part, each of which must be
satisfiable by configuration alone:

| # | Request | Satisfied by | Code? |
|---|---|---|---|
| 1 | "Add a LinkedIn Profile field, visible in the directory, editable by employees." | X.4.4 | no |
| 2 | "Make PAN mandatory for permanent employees in Telangana, optional for interns." | field + `required_code: conditional`, `required_expression: employee.stage <> 'INTERN' AND org.state = 'TG'` | no |
| 3 | "Our Dubai entity's employees have no PAN and 9-digit mobile numbers." | field scope + locale-parameterised validators (R-X-012) | no |
| 4 | "Add employment stages: Contract, Trainee, Apprentice, Consultant." | `value_set_items` on `employment_stage` | no — today all four silently become Permanent (`D-…` §4.3) |
| 5 | "Employees may update their address themselves, but it must be approved by HR." | `ess` surface rule `write` + `change_workflow_artefact_key` | no |
| 6 | "Bank changes must be approved by two people." | maker-checker workflow on the bank field group | no |
| 7 | "Warn us 120 and 45 days before a work permit expires, and copy the manager." | a ladder with two custom steps and an audience expression | no |
| 8 | "Import must refuse any file missing DOJ." | `required_code: always` on the `import` surface | no |
| 9 | "A blank Emergency Contact cell should clear the stored value." | `blank_means: clear` on that field | no |
| 10 | "Show me what this employee's department was in March." | `AsAtService` | no |
| 11 | "Exited employees must never be reactivated by an edit." | absent transition + guard | no |
| 12 | "Interns don't consume a licence seat." | `status_values.semantics_json.consumes_seat` — or a stage-based licence rule | no |

---

## X.13 Data model

Conventions per spine S16 and Part XXVIII §XXVIII.2. Every table:
`tenant_id BIGINT UNSIGNED NOT NULL` participating in every unique index (Law 6);
`id BIGINT UNSIGNED AUTO_INCREMENT` PK; `uuid BINARY(16)` on aggregate roots;
`created_at`, `updated_at`, `created_by_user_id`, `updated_by_user_id`;
`row_version INT UNSIGNED` on aggregate roots; no database `ENUM` anywhere; all
foreign keys declared with an explicit `ON DELETE`.

**Tables defined elsewhere and used unchanged by this part:** `entity_definitions`,
`entity_field_values`, `value_sets`, `value_set_items`, `status_domains`,
`status_values`, `status_transitions`, `config_artefacts`, `config_versions`,
`persons`, `person_identifiers`, `person_contacts`, `person_addresses`,
`employments`, `employment_assignments`, `employment_status_periods`,
`employment_events`, `employee_bank_accounts`, `employee_documents`,
`onboarding_journeys`, `journey_tasks`, `journey_submissions`, `audit_events`,
`audit_field_changes`, `cfg_code_sequences`, `code_sequence_counters`
(Part XXVIII §XXVIII.5, C1/C2/C3/C4/C10/C14/C15).

### X.13.1 `field_definitions` — the columns this part adds

Part XXVIII specifies this table (C14). This part **extends** it with the
attributes of brief Section 10 that the Part XXVIII column list does not yet
carry. The extension is additive; no existing column changes meaning.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `authoring_type_code` | VARCHAR(40) | N | — | one of the 31 authoring types (X.3.2); maps to `data_type_code` |
| `input_widget_code` | VARCHAR(40) | Y | NULL | `select \| radio \| checkbox \| chips \| stepper \| …` — presentation only |
| `description` | VARCHAR(1000) | Y | NULL | administrator-facing |
| `regex_pattern` | VARCHAR(500) | Y | NULL | anchored, step-bounded |
| `regex_error_message` | VARCHAR(400) | Y | NULL | shown verbatim to the user |
| `min_length`, `max_length` | INT UNSIGNED | Y | NULL | |
| `min_value`, `max_value` | DECIMAL(18,4) | Y | NULL | |
| `min_date_expression`, `max_date_expression` | VARCHAR(500) | Y | NULL | SPX returning `Date` |
| `country_source_expression` | VARCHAR(500) | Y | NULL | SPX; drives locale-parameterised validators |
| `uniqueness_comparison_code` | VARCHAR(40) | N | `exact` | `exact \| case_insensitive \| normalised` |
| `uniqueness_includes_inactive` | TINYINT(1) | N | 1 | R-X-020 |
| `history_mode_code` | VARCHAR(40) | N | `audit_only` | `audit_only \| dated` |
| `dated_storage_table` | VARCHAR(64) | Y | NULL | when `dated` |
| `otp_verification_code` | VARCHAR(40) | N | `none` | `none \| onboarding \| always` |
| `verification_mode_code` | VARCHAR(40) | N | `none` | `none \| optional \| required` |
| `document_type_id` | BIGINT UNSIGNED | Y | NULL | FK `cfg_document_types(id)` RESTRICT; required when the authoring type is file-bearing |
| `reference_scope_expression` | VARCHAR(500) | Y | NULL | SPX restricting a lookup's candidate set |
| `default_visibility_code` | VARCHAR(40) | N | `hidden` | fallback when no surface rule matches (R-X-030) |
| `default_required_code` | VARCHAR(40) | N | `never` | |
| `blank_means_code` | VARCHAR(40) | N | `ignore` | `ignore \| clear` — import semantics (R-X-092) |
| `import_aliases_json` | JSON | Y | NULL | additional accepted headers |
| `export_format_hint` | VARCHAR(60) | Y | NULL | the format the header declares and the writer emits |
| `sample_value` | VARCHAR(200) | Y | NULL | used in the generated template |
| `locked_attributes_json` | JSON | Y | NULL | platform-declared invariants a tenant may not relax (R-X-009) |
| `superseded_by_field_definition_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT — retire-and-replace (R-X-008) |
| `scope_json`, `priority` | JSON / INT | Y / N | NULL / 0 | spine S3.1/S3.2 |

**Keys.** As Part XXVIII, plus IX `(tenant_id, entity_definition_id,
authoring_type_code)`, IX `(tenant_id, document_type_id)`.
**Policy.** Tenant-owned · history A5 · delete: never (retire by closing the
range) · audit `full` · PII `none` (the definition) · retention `R-PERM`.
*Why:* brief Section 10's attribute list, in full, as data.

### X.13.2 `field_surface_rules` — the columns this part adds

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `masking_override_code` | VARCHAR(40) | Y | NULL | overrides `field_definitions.masking_rule_code` for this surface/role |
| `change_workflow_artefact_key` | VARCHAR(80) | Y | NULL | approval on change (R-X-039) |
| `is_list_column` | TINYINT(1) | N | 0 | `directory` only |
| `list_order` | INT UNSIGNED | Y | NULL | `directory` only |
| `is_filterable`, `is_sortable`, `is_quick_search` | TINYINT(1) | N | 0 | `directory`/`report` |
| `required_expression` | VARCHAR(1000) | Y | NULL | SPX, when `required_code = conditional` |
| `blank_means_override_code` | VARCHAR(40) | Y | NULL | `import` only |

**Keys.** UQ `(tenant_id, field_definition_id, surface_code, role_id)` ·
IX `(tenant_id, surface_code, role_id)`, `(tenant_id, surface_code, is_list_column, list_order)`.
**Policy.** Tenant-owned · audit `full` · PII `none` · retention `R-PERM`.

### X.13.3 `employee_field_change_requests` — A1

Approval-routed field changes awaiting a decision (R-X-039).

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid`, `tenant_id` | | N | | |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `surface_code` | VARCHAR(40) | N | — | the surface the request originated on |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `requested_effective_from` | DATE | Y | NULL | for dated fields |
| `reason_code`, `reason_text` | VARCHAR(40) / VARCHAR(1000) | Y | NULL | |
| `workflow_instance_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `lifecycle_state_code` | VARCHAR(40) | N | `pending` | `pending\|approved\|rejected\|withdrawn\|applied\|expired` |
| `applied_audit_event_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — proves what was written |
| `row_version` | INT UNSIGNED | N | 0 | |

Child `employee_field_change_request_items` — A3: `field_definition_id`,
`old_value_masked`, `old_value_hash`, `new_value_ciphertext` (values held
encrypted while pending), `new_value_masked`, `item_state_code`.

**Keys.** PK `id` · UQ `(tenant_id, uuid)` ·
IX `(tenant_id, employment_id, lifecycle_state_code)`,
`(tenant_id, workflow_instance_id)`.
**Policy.** Tenant-owned · history: the workflow's transitions · delete: never ·
audit `full` · PII **per field** · retention `R-STAT-8Y`.
*Why:* SmartPRS 2 has no approval on any employee field, so a bank-account change
is a single unaudited `UPDATE` from any of nine write paths.

### X.13.4 `cfg_onboarding_journeys` — cfg payload

`config_version_id` (FK RESTRICT, UQ), `journey_key`, `label`, `description`,
`invite_channel_codes_json`, `link_lifetime_hours` (default 336),
`link_single_use`, `resume_allowed`, `save_progress_allowed`,
`otp_policy_json` (length, alphabet, lifetime_seconds, max_attempts,
resend_cooldown_seconds, max_resends, lockout_minutes),
`rate_limit_policy_json`, `completion_expression` (SPX),
`on_submit_workflow_artefact_key`, `existing_match_field_definition_id`
(FK RESTRICT — the identifier used to match an existing employment; **never a
name**), `purge_policy_json`, `welcome_template_key`.

**Keys.** UQ `(config_version_id)`, `(tenant_id, journey_key,
config_version_id)` · IX `(tenant_id, journey_key)`.
**Policy.** Immutable once its version is published · audit `full` · PII `none` ·
retention `R-PERM`.

### X.13.5 `cfg_journey_steps` — A3 · `cfg_journey_step_fields` — A3

`cfg_journey_steps`: `cfg_onboarding_journey_id` (FK CASCADE), `step_key`,
`label`, `description`, `display_order`, `visible_expression`,
`required_expression`, `is_skippable`, `help_media_ref`, `consent_artefact_key`,
`signature_required`.
UQ `(tenant_id, cfg_onboarding_journey_id, step_key)`.

`cfg_journey_step_fields`: `cfg_journey_step_id` (FK CASCADE),
`field_definition_id` (FK RESTRICT), `display_order`, `required_override_code`,
`visible_expression`, `otp_required`, `verification_required`,
`document_type_id` (FK RESTRICT, for document steps).
UQ `(tenant_id, cfg_journey_step_id, field_definition_id)` ·
IX `(tenant_id, field_definition_id)` — the "where is this field used?" path.

### X.13.6 `journey_otp_challenges` — A4

`onboarding_journey_id` (FK RESTRICT), `identifier_kind_code`
(`email|mobile|whatsapp`), `identifier_blind_index` BINARY(32),
`code_hash` VARBINARY(255), `issued_at`, `expires_at`, `attempt_count`,
`max_attempts`, `consumed_at`, `invalidated_at`, `invalidated_reason_code`,
`delivery_status_code`, `delivery_reference`, `request_ip`, `request_user_agent_hash`.

**Keys.** PK `(id, issued_on)` · IX `(tenant_id, onboarding_journey_id, issued_at)`,
`(tenant_id, identifier_blind_index, issued_at)`.
**Policy.** Append-only · **the code is stored hashed and never returned in any
response** (R-X-070) · issuing a new challenge sets `invalidated_at` on all
outstanding ones for the same `(journey, identifier)` (R-X-071) · PII `sensitive`
(the identifier) · retention `R-LOG-13M`, purged earlier by the journey's purge
policy · partitioned monthly.

### X.13.7 `journey_consents` — A4

`onboarding_journey_id` (FK RESTRICT), `consent_artefact_key`,
`consent_config_version_id` (FK RESTRICT), `rendered_text_hash` BINARY(32),
`accepted`, `accepted_at`, `actor_kind_code` (`candidate|employee|hr`),
`actor_user_id` (FK RESTRICT, nullable), `request_ip`, `request_user_agent`,
`signature_document_id` (FK `employee_documents(id)` RESTRICT, nullable).

**Keys.** PK `id` · UQ `(tenant_id, onboarding_journey_id, consent_artefact_key,
consent_config_version_id)` · IX `(tenant_id, accepted_at)`.
**Policy.** Append-only · audit `full` · PII `identifying` · retention
`R-STAT-8Y` — a consent record outlives the journey.

### X.13.8 `import_batches` — A1

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid`, `tenant_id` | | N | | |
| `entity_definition_id` | BIGINT UNSIGNED | N | — | FK RESTRICT — employee, candidate, … |
| `mode_code` | VARCHAR(40) | N | `upsert` | `create_only \| update_only \| upsert \| invite` |
| `duplicate_mode_code` | VARCHAR(40) | N | `update` | `skip \| update \| create` |
| `source_filename` | VARCHAR(255) | N | — | |
| `storage_key` | VARCHAR(400) | N | — | **encrypted object**, private disk |
| `content_hash` | BINARY(32) | N | — | |
| `byte_size`, `row_count`, `column_count` | BIGINT UNSIGNED | N | 0 | |
| `field_definition_snapshot_json` | JSON | N | — | the field versions the mapping was built against |
| `mapping_json` | JSON | Y | NULL | header → field_definition_id, as confirmed |
| `chunk_size`, `on_chunk_failure_code` | INT / VARCHAR(40) | N | 500 / `halt` | `halt \| continue` |
| `lifecycle_state_code` | VARCHAR(40) | N | `uploaded` | `uploaded\|parsed\|mapped\|previewed\|committing\|committed\|partially_committed\|failed\|cancelled\|purged` |
| `counts_json` | JSON | Y | NULL | created/updated/unchanged/skipped/rejected — reconciled (R-X-091) |
| `error_sheet_storage_key` | VARCHAR(400) | Y | NULL | |
| `started_at`, `finished_at`, `duration_ms` | | Y | NULL | |
| `purged_at` | DATETIME(3) | Y | NULL | TTL purge (R-X-096) |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |

**Keys.** PK `id` · UQ `(tenant_id, uuid)` ·
IX `(tenant_id, entity_definition_id, lifecycle_state_code, created_at)`,
`(tenant_id, purged_at)`.
**Policy.** Tenant-owned · delete: never; the **file** is purged on TTL and
`purged_at` stamped · audit `full` · PII `sensitive` while the file exists ·
retention: record `R-STAT-8Y`, file `R-IMPORT-TTL` (configurable, default 7 days).
*Why:* SmartPRS 2 keeps the staged file forever with plaintext passwords in it
(SEC-10) and returns raw SQL error text to the browser (SEC-16).

### X.13.9 `import_columns` — A3

`import_batch_id` (FK CASCADE), `column_index`, `column_letter`,
`header_raw`, `header_normalised`, `field_definition_id` (FK RESTRICT, nullable),
`disposition_code` (the eight values of R-X-088), `disposition_detail`,
`acknowledged_by_user_id`, `acknowledged_at`, `non_empty_row_count`.

**Keys.** UQ `(tenant_id, import_batch_id, column_index)` ·
IX `(tenant_id, import_batch_id, disposition_code)`.
**Policy.** Child of the batch · audit inherited · PII `none` (headers only).
*Why:* this table is the mechanism by which "no silent drops" is provable rather
than promised — every column of every file has a durable, queryable verdict.

### X.13.10 `import_rows` — A3 (large; partitioned with the batch)

`import_batch_id` (FK CASCADE), `row_number`, `source_row_hash` BINARY(32),
`matched_employment_id` (FK RESTRICT, nullable), `match_basis_field_definition_id`
(FK RESTRICT, nullable), `outcome_code` (R-X-089), `error_count`,
`applied_audit_event_id` (FK RESTRICT, nullable), `chunk_no`, `committed_at`.

**Keys.** UQ `(tenant_id, import_batch_id, row_number)` ·
IX `(tenant_id, import_batch_id, outcome_code)`,
`(tenant_id, matched_employment_id)`.
**Policy.** Child of the batch · PII `none` (values are not stored here) ·
retention with the batch.

### X.13.11 `import_row_issues` — A3

`import_row_id` (FK CASCADE), `field_definition_id` (FK RESTRICT, nullable),
`column_letter`, `severity_code` (`error|warning|info`), `rule_code`,
`message`, `suggested_correction`.

**Keys.** IX `(tenant_id, import_row_id)`, `(tenant_id, rule_code)`.
**Policy.** `message` and `suggested_correction` **never contain a field value for
a field classed `sensitive` or `restricted`** — they name the field, the rule and
the row (R-X-090). Audit inherited · retention with the batch.

### X.13.12 `export_profiles` — A1 (configuration artefact)

`config_version_id` (FK RESTRICT, nullable — a shared profile is versioned; a
personal one is not), `profile_key`, `label`, `entity_definition_id` (FK
RESTRICT), `owner_user_id` (FK RESTRICT, nullable — null = shared),
`columns_json` (ordered `field_definition_id` list), `filter_json`,
`format_code` (`xlsx|csv|json`), `date_format_code`, `includes_sensitive`,
`include_archived`, `scope_json`.

**Keys.** UQ `(tenant_id, profile_key, owner_user_id)` ·
IX `(tenant_id, entity_definition_id)`.
**Policy.** Tenant-owned · audit `full` · PII `none` (the profile) ·
retention `R-PERM`. A profile with `includes_sensitive = 1` may be created only by
an actor holding `employee.export.sensitive` and is flagged in the Studio.

### X.13.13 `export_jobs` — A4

`export_profile_id` (FK RESTRICT, nullable), `entity_definition_id` (FK RESTRICT),
`requested_by_user_id` (FK RESTRICT), `filter_json`,
`columns_snapshot_json`, `includes_sensitive`, `row_count`, `byte_size`,
`storage_key`, `download_count`, `first_downloaded_at`, `expires_at`,
`purged_at`, `audit_event_id` (FK RESTRICT).

**Keys.** PK `(id, requested_on)` · IX `(tenant_id, requested_by_user_id,
requested_at)`, `(tenant_id, expires_at)`.
**Policy.** Append-only · the generated file is served by signed expiring URL and
purged on TTL · audit `full` · PII `sensitive` while the file exists ·
retention `R-LOG-13M`, file `R-EXPORT-TTL` (default 48 hours) · partitioned monthly.

### X.13.14 `cfg_document_types` — cfg payload

`config_version_id` (FK RESTRICT, UQ), `type_key`, `label`, `description`,
`category_code` (value set), `applicability_expression`, `requirement_code`,
`requirement_expression`, `allowed_mime_types_json`, `allowed_extensions_json`,
`min_bytes`, `max_bytes`, `max_pages`, `image_min_pixels`,
`issue_date_mode_code`, `expiry_mode_code`, `expiry_derivation_expression`,
`expiry_alert_ladder_id` (FK RESTRICT), `verification_mode_code`,
`verification_workflow_artefact_key`, `versioning_mode_code`,
`retention_class_code`, `is_encrypted`, `watermark_policy_code`,
`signature_required`, `blocks_expression`, `display_order`.

**Keys.** UQ `(config_version_id)`, `(tenant_id, type_key, config_version_id)` ·
IX `(tenant_id, type_key)`, `(tenant_id, expiry_alert_ladder_id)`.
**Policy.** Immutable once published · audit `full` · PII `none` ·
retention `R-PERM`.

### X.13.15 `cfg_expiry_alert_ladders` — cfg payload · `cfg_expiry_alert_steps` — A3

Ladder: `config_version_id` (FK RESTRICT, UQ), `ladder_key`, `label`,
`scope_json`, `evaluation_time_local`, `timezone_code`, `catch_up_enabled`
(default 1), `catch_up_window_days`.
Steps: `cfg_expiry_alert_ladder_id` (FK CASCADE), `step_no`, `offset_days`,
`on_expiry_day`, `overdue_repeat_every_days`, `audience_expression`,
`channel_codes_json`, `template_key`, `severity_code`, `escalation_after_days`,
`escalation_audience_expression`.
UQ `(tenant_id, cfg_expiry_alert_ladder_id, step_no)` ·
UQ `(tenant_id, cfg_expiry_alert_ladder_id, offset_days, on_expiry_day)`.

### X.13.16 `expiry_alert_emissions` — A4

The idempotency ledger for R-X-111: `artefact_kind_code`
(`document|identifier|certificate|bgv|training|licence`), `artefact_id`,
`employment_id` (FK RESTRICT), `ladder_id`, `step_no`, `due_on`,
`emitted_at`, `notification_id`, `audience_snapshot_json`.

**Keys.** UQ `(tenant_id, artefact_kind_code, artefact_id, ladder_id, step_no)`
← this is what makes catch-up safe and re-runs idempotent ·
IX `(tenant_id, due_on)`, `(tenant_id, employment_id, emitted_at)`.
**Policy.** Append-only · PII `none` · retention `R-LOG-13M`.

### X.13.17 `entity_field_value_history` — A4 *(only for `history_mode = audit_only` custom fields)*

The audit ledger already carries field-level before/after values
(`audit_field_changes`). This table exists **only** as the denormalised read model
for the field-history panel (R-X-054), rebuildable from the ledger, and is
declared as such: `entity_definition_id`, `entity_row_id`, `field_definition_id`,
`changed_at`, `old_value_masked`, `new_value_masked`, `actor_user_id`,
`surface_code`, `reason_code`, `audit_event_id` (FK RESTRICT).

**Keys.** IX `(tenant_id, entity_definition_id, entity_row_id,
field_definition_id, changed_at)`.
**Policy.** Append-only, derived, rebuildable · PII per field · retention with the
audit event.

---

## X.14 API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed on
unsafe methods, with the standard error envelope (spine S17). Every route carries
an explicit policy binding; a route without one fails the CI sweep (spine S13).

### X.14.1 Metadata

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/entities/{entity_key}/fields` | `entity.field.view` | the resolved field list **for the caller and a given `?surface=`** — this is what every client renders from |
| GET | `/entities/{entity_key}/fields/{field_key}` | `entity.field.view` | includes versions and surface rules |
| POST | `/entities/{entity_key}/fields` | `entity.field.manage` | creates a draft field definition |
| PATCH | `/entities/{entity_key}/fields/{field_key}` | `entity.field.manage` | edits the draft |
| POST | `/entities/{entity_key}/fields/{field_key}/versions/{v}/publish` | `entity.field.publish` | validated, diffed, approval-gated when high-risk |
| GET | `/entities/{entity_key}/fields/{field_key}/usages` | `entity.field.view` | where is this used (R-X-115) |
| DELETE | `/entities/{entity_key}/fields/{field_key}` | `entity.field.manage` | retires by closing the range; refused while referenced |
| GET/POST/PATCH | `/value-sets`, `/value-sets/{key}/items` | `config.value_set.*` | |
| GET | `/entities/{entity_key}/parity-report` | `entity.field.view` | the derived matrix (R-X-116) |

### X.14.2 Employee

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/employees` | `employee.list` + data scope | filter by any `is_filterable` field; `?as_of=` for a historical view |
| POST | `/employees` | `employee.create` | seat-checked; code allocated from the sequence |
| GET | `/employees/{uuid}` | `employee.view` | `?surface=api` field projection; `?as_of=` |
| **PATCH** | `/employees/{uuid}` | `employee.update` | **PATCH semantics (R-X-036)**; `{"fields":{"k":{"set":v}\|{"clear":true}}, "effective_from":…, "reason":…}` |
| POST | `/employees/{uuid}/transitions` | per `status_transitions.required_permission` | status change with reason and effective date |
| GET | `/employees/{uuid}/history` | `employee.history.view` | field history and dated ranges |
| GET | `/employees/{uuid}/as-at/{date}` | `employee.view` | the `EmployeeSnapshot` |
| GET/POST/PATCH/DELETE | `/employees/{uuid}/identifiers` `/contacts` `/addresses` `/bank-accounts` | typed permissions | explicit child operations (R-X-041); bank changes route to maker-checker |
| GET/POST | `/employees/{uuid}/documents` | `employee.document.*` | object-level authorised (R-X-106) |
| GET | `/employees/{uuid}/documents/{doc}/download` | `employee.document.view` + ownership | signed, expiring, attachment |
| POST | `/employees/{uuid}/documents/{doc}/verify` | `employee.document.verify` | workflow-backed |
| GET | `/employees/{uuid}/field-change-requests` | `employee.view` | pending approvals |
| GET | `/me/employee` | authenticated | subject resolved **from the session**, never from the request |
| PATCH | `/me/employee` | authenticated | `surface=ess`; approval-routed per field |

**R-X-117** There is no employee endpoint that accepts an employee identifier in a
self-service context. *Origin:* spine S13; and SmartPRS 2's `selfScope()` resolves
the caller by name with no tenant filter (SEC-22).

### X.14.3 Onboarding, import, export, documents

| Method | Path | Permission |
|---|---|---|
| POST | `/onboarding/journeys` | `onboarding.invite` |
| GET | `/onboarding/journeys/{uuid}` | `onboarding.view` |
| POST | `/onboarding/journeys/{uuid}/resend` | `onboarding.invite` |
| POST | `/onboarding/journeys/{uuid}/verify` | `onboarding.verify` — per field and per document |
| POST | `/onboarding/journeys/{uuid}/request-correction` | `onboarding.verify` |
| POST | `/onboarding/journeys/{uuid}/activate` | `employee.activate` — the `pre_hire → active` transition |
| — | **public portal**: `/public/onboarding/{token}/…` | token + OTP; rate-limited; no permission — see R-X-072 |
| GET | `/imports/template?entity=employee` | `employee.import` |
| POST | `/imports` | `employee.import` — upload |
| POST | `/imports/{uuid}/mapping` | `employee.import` |
| GET | `/imports/{uuid}/preview` | `employee.import` — dispositions and row outcomes |
| POST | `/imports/{uuid}/commit` | `employee.import` — idempotency-keyed |
| GET | `/imports/{uuid}/error-sheet` | `employee.import` **+ batch ownership or `employee.import.view_all`** |
| GET | `/imports` | `employee.import.view` |
| POST | `/exports` | `employee.export` (+ `employee.export.sensitive`) |
| GET | `/exports/{uuid}` | requester or `employee.export.view_all` |
| GET/POST | `/export-profiles` | `employee.export` |
| GET/POST/PATCH | `/document-types` | `config.document_type.*` |
| GET/POST/PATCH | `/expiry-ladders` | `config.alert.*` |

**R-X-118** The import history and error-report endpoints carry explicit
permissions and object-level ownership checks. *Origin:* SEC-02 — these are the
**only two methods in the SmartPRS 2 import controller with no guard at all**, and
they stream PII, IDOR-able by incrementing the job id.

**R-X-119** No API error response contains raw SQL text, table names, column names
or SQLSTATE codes. *Origin:* SEC-16.

---

## X.15 Events

Emitted after commit, through the transactional outbox, with the standard envelope
(tenant, event key, version, occurred_at, correlation id, actor, payload).

| Event | Payload highlights | Consumed by |
|---|---|---|
| `field_definition.published` | field key, version, changed attributes, impacted employee count | cache invalidation, template validator, report builder, client metadata refresh |
| `field_definition.retired` | field key, superseded_by | the same |
| `value_set.item_added` / `.retired` | set key, item code | import template regeneration, client metadata |
| `person.created` | person uuid | search index |
| `employment.created` | employment uuid, code, joined_on, source surface | licensing (seat), attendance enrolment, ESS provisioning, welcome pack, onboarding checklist |
| `employment.field_changed` | field key, old/new (masked), surface, reason | audit read model, search index, integrations, downstream caches |
| `employment.assignment_changed` | dimensions changed, effective_from, `retro_from` when back-dated | payroll retro-effect (R-X-052), attendance shift resolution, approval routing, MSS tree |
| `employment.status_changed` | from, to, effective_from, reason | licensing seat ledger, payroll eligibility, access revocation, statutory suspension |
| `employment.code_changed` | old, new | integrations, biometric device mapping |
| `employment.archived` / `.restored` / `.deleted` | grace period, actor | licensing, search index, document visibility |
| `employee.bank_account_changed` | masked last4, verification state | payout engine, maker-checker |
| `employee.identifier_changed` | identifier type, verification state | statutory returns, compliance |
| `onboarding.invited` / `.resumed` / `.submitted` | journey uuid, step progress | HR queue, reminders |
| `onboarding.verification_decided` | field/document, decision, reason | candidate notification |
| `onboarding.activated` | employment uuid | as `employment.created` |
| `onboarding.expired` / `.purged` | journey uuid | retention reporting |
| `import.uploaded` / `.previewed` / `.committed` / `.failed` | batch uuid, counts, dispositions | operations dashboard, notification to the requester |
| `export.generated` / `.downloaded` / `.purged` | profile, row count, sensitivity | audit export log, DLP alerting |
| `document.uploaded` / `.verified` / `.rejected` / `.superseded` / `.expired` | type key, expires_on | compliance engine, money gate |
| `document.expiry_alert_emitted` | ladder, step, due_on, audience | notification engine |

**Consumed by this part:** `org_unit.renamed` (refresh display names without
touching stored ids), `workflow.finalised` (apply an approved field change),
`payroll_period.locked` / `.reopened` (gate back-dated writes),
`licence.seats_changed` (re-evaluate seat availability),
`rule_pack.activated` (re-evaluate document applicability expressions).

---

## X.16 Explainability

Spine S15's `Explain(entity, field)` for this part answers *"why does this field
look like this, and where did this value come from?"*

**Field-level provenance** (any employee field, any date):

```
Field            Branch
Value on         15-May-2026     Hyderabad — Banjara Hills
Stored as        employment_assignments.branch_org_unit_id = 4471  (an id, not a name)
In force         01-Feb-2026 → 31-Jul-2026
Set by           Transfer TRF-2026-0087 · workflow EMP-TRANSFER v3
                 requested 12-Jan-2026 by S. Nair, approved 24-Jan-2026 by A. Rao
Surface          workflow (finalise node)
Reason           reason_code = transfer · "Branch consolidation — Q4 plan"
Audit            audit_events #8,442,190 · chain seq 4,102,338 · anchored 24-Jan-2026
Previous value   Hyderabad — Secunderabad (01-Apr-2025 → 31-Jan-2026)
Scheduled next   Hyderabad — Gachibowli, from 01-Aug-2026 (future-dated, cancellable)
```

**Definition-level provenance** (why is this field here at all):

```
Field            PAN
Definition       employee.pan · version 6 · effective 01-Apr-2026 →
Type             government_identifier (IN-PAN) · encrypted · masked last4
Unique           tenant scope · index uq_efv_pan_blind
Required         edit: always · self_onboarding: create · import: never · ess: read
Validation       ^[A-Z]{5}[0-9]{4}[A-Z]$   "Enter a valid PAN, e.g. ABCDE1234F."
Scope            ALL
Published by     R. Menon, 28-Mar-2026, approved by the HR Director (high-risk: PII class)
Used by          17 surfaces/artefacts — payroll TDS formula, Form 24Q, the offer
                 letter template, 3 reports, the DRA money gate
Seeded from      SmartPRS 2 EmployeeFieldRules::FIELDS row "pan" (migration, 01-Apr-2026)
```

**Import-level provenance** (why did this row do that):

```
Row 1,842 of employees-june.xlsx (batch IMP-2026-0311)
Matched          EMP-2214 by EMPLOYEE CODE
Outcome          updated · 4 fields applied, 1 rejected, 1 routed for approval
  DEPARTMENT     Collections → Recoveries        applied (dated 01-Jun-2026)
  MOBILE         ••••3210 → ••••7788             routed for approval (EMP-CONTACT-CHANGE)
  PAN            unchanged
  ESIC           rejected — required when ESI APPLICABLE = Yes  [rule ESIC-REQ-01]
  WHATSAPP       cleared — column declared blank-means-clear
Not applied      column "OLD ERP REF" — disposition: unknown (acknowledged as ignored)
```

**R-X-120** Every derived or resolved presentation decision is explainable: why a
field is hidden (which rule matched), why it is mandatory (which expression
evaluated true), why a value was masked (which PII class and masking rule), and
why an import row was rejected (which rule, which column).

---

## X.17 Migration implication

Per the analysis's own migration verdicts (`D-…` §9), restated as an ordered plan.

### X.17.1 Ordering

| Step | Action | Rationale |
|---|---|---|
| 0 | **Remove the legacy one-shot importer's route and both Directory buttons in SmartPRS 2, before anything else** | `D-…` §9 — it destroys 30 of 56 columns and NULLs 6 more, and it is still wired next to the template it destroys. Every day it remains is a day of avoidable data loss in the source system |
| 1 | Audit the four orphan seat-free statuses (`resigned`, `terminated`, `left`, `absconded`) per tenant | `D-…` PO-16 — they are writable only by the wizard's unvalidated `status` column or direct SQL; the mapping cannot be chosen without knowing what exists |
| 2 | Audit duplicate identifiers per tenant | R-X-016 — the five parity indexes are deliberately non-unique, so duplicates are guaranteed; uniqueness cannot be declared until they are resolved |
| 3 | Seed field definitions from `EmployeeFieldRules::FIELDS` | the 56 rows become system field definitions; `h`/`c`/`l`/`tab`/`o`/`a`/`u`/`v` become metadata attributes |
| 4 | Seed value sets from the 14 hard-coded lists | Gender (3), Marital (4), Blood group (8), Category (5), Employee Type (2), Stage (3), Status (2), Also Works For (2), PF (2), ESI (3), Salary Type (3), DRA/PCC Declared (3), Certificate status (3), PT State (36) |
| 5 | Seed surface rules to reproduce today's behaviour exactly | so a migrated tenant sees no behavioural change on day one — then relaxes or tightens by configuration |
| 6 | **Name → id reconciliation** for department, designation, branch, team, reporting manager, team leader, shift, company, salary schedule | the largest single task in this area (`D-…` §9); produces an exception report for HR to resolve before cut-over |
| 7 | Migrate `employees` into `persons` / `employments` / `employment_assignments`, re-typing the 34 runtime-`string` columns | dates as DATE, booleans as BOOLEAN, enumerations as value-set codes |
| 8 | Seed one `employment_assignments` row per employment as at the migration date, then optionally back-fill from `transfers` and `increments`, flagged `migration_baseline` and `partial` | there is no history to migrate (`D-…` §9); a back-fill is an approximation and must be labelled as one |
| 9 | Migrate identifiers, contacts, addresses and bank details into their dated, encrypted tables | plaintext today |
| 10 | Migrate `documents` from its three runtime-created shapes into `employee_documents`; reconcile the 6 categories and 7 kinds into document types; **move every file off the public disk to private storage and re-key** | this is the PII exposure fix and is not deferrable |
| 11 | Migrate in-flight `self_onboarding` records into journeys on `pre_hire` employments; **purge or encrypt `data` for `injected` records** | SEC-17 |
| 12 | Migrate `employee_import_jobs` and `employee_archives` for audit; **delete every staged import file**; encrypt archive snapshots | SEC-10, SEC-17 |

### X.17.2 What does not come across

| Not migrated | Why |
|---|---|
| The legacy one-shot importer, the self-onboarding bulk CSV importer, the master CSV importer | three of four import vocabularies; folded into one metadata-driven surface |
| `EmployeeFieldRules` itself, `hrFields()`, `portalMap()`, `formToColumn()` | replaced by metadata; two of the four are already dead code |
| `ensureEmployeeColumns()`, `ensureEmployeeCols()`, `ensureTables()`, `ensure()`, `ensureCols()`, `ensureAuditCols()` | runtime DDL — the root cause of type drift and silent column drops (Law 2) |
| `dra_pcc_declared` | written by two paths, created by no migration — dead on every install |
| `aadhaar`, `employment_type` (legacy duplicates), `docs_status`, `docs_stored` | dead columns; verify empty, then drop |
| `employee_company` | the multi-company pivot read and written by nothing |
| `EmployeeController`, `app/Models/Employee.php`, `resources/views/employees/*` | unreachable, and `$fillable` names 8 columns that do not exist |
| Browser-side validation copies (`EMP_VALID_IDS`, `empFieldError`, `SO_RULES`) | generated from metadata |
| The three hand-written onboarding key lists | rendered from surface rules |

**PRODUCT OWNER DECISION REQUIRED — D-X-06: `also_works_for` and multi-company
employment.** Captured on four surfaces, read by nothing; `employee_company`
exists and is unused (`D-…` PO-15, NEW-EMP-074/076). Recommendation: **drop the
free-text field and model it properly** as a second `Employment` under the
Person/Employment split (Part V D-V-02), which costs nothing extra now and is a
migration of every employee-referencing table later.

**PRODUCT OWNER DECISION REQUIRED — D-X-07: `dra_declared` / `pcc_declared`.**
Self-declarations captured on three surfaces, exported, read back onto the form,
and read by **no** report or gate; the only enforcement is a browser-side new-hire
check (`D-…` PO-07). Recommendation: **retain as declaration-of-record fields on
the relevant document types** (a `self_declared` verification state), so they
become inputs to the compliance engine rather than decoration.

**PRODUCT OWNER DECISION REQUIRED — D-X-08: `probation_months`.** The column, the
derivation and a unit test all exist; **no UI or import can set it**
(NEW-EMP-073, PO-17). Recommendation: **expose it** as an ordinary employee field
with `history_mode = dated`, since the derivation already reads it and the cost is
now a configuration row rather than a code change.

**PRODUCT OWNER DECISION REQUIRED — D-X-09: `whatsapp` uniqueness.** `mobile` is
duplicate-blocked, `whatsapp` is not, though both use the same validator and
verifying WhatsApp marks the *mobile* verified too (`D-…` PO-03). Recommendation:
**not unique by default** (shared family and shop numbers are common in the field
workforce), exposed as a per-tenant declaration — which is exactly what R-X-015
makes a one-row change.

**PRODUCT OWNER DECISION REQUIRED — D-X-10: ESS editable fields and approval.**
Today: 10 fields, no validation, no uniqueness, no sanitisation, no approval, no
audit (`D-…` PO-12). Recommendation: seed the `ess` surface as **read-only for
everything except contacts, address, emergency contact and personal preferences**,
each routed to an HR approval workflow, and let each tenant widen it from there.

---

## X.18 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | A field definition is published while an import batch is mid-preview | the batch pins `field_definition_snapshot_json` at parse time; commit against a changed definition warns and requires re-preview |
| 2 | A field is retired while a letter template references it | retirement is blocked, naming the template (R-X-115) |
| 3 | A value-set item is retired while employees hold it | permitted; existing values remain valid and render with a "retired" marker; the item cannot be newly selected. Reports and filters still resolve the label |
| 4 | A uniqueness declaration is published against data containing duplicates | publication blocked, conflicting rows listed (R-X-016) |
| 5 | A required field is added retrospectively | existing records are not invalidated; the requirement applies to writes from the effective date. The Studio offers a "records not meeting the new requirement" report |
| 6 | Two concurrent PATCHes to the same employee | optimistic concurrency on `row_version`; the loser gets 409 with a field-level diff |
| 7 | Two concurrent dated changes with the same `effective_from` | serialised by the `FOR UPDATE` lock on the employment; the second closes the first or is rejected by the unique index on `(employment_id, effective_from)` |
| 8 | A future-dated assignment exists and a back-dated one is written | both are inserted; contiguity is asserted across the whole set, and an overlap aborts the transaction |
| 9 | Back-dated change inside a locked payroll period | refused; the message names the period and the reopen workflow (R-X-051) |
| 10 | An employee is archived with a future-dated assignment pending | the pending row is retained and flagged; activation on restore re-evaluates it |
| 11 | An encrypted field's key is unavailable | the read fails loudly with an operator alert; ciphertext is **never** rendered as if it were plaintext (spine S13) |
| 12 | A blind index collides (hash collision) | the database unique index is on the blind index, so the second write is rejected; the pre-check disambiguates by decrypting the candidate row under permission and reports accurately |
| 13 | An import row matches two employees on different identifiers | rejected as `ambiguous_match`, both candidates named; never `->first()` |
| 14 | An import file has 34 columns and 3 are unknown | preview blocks; the actor may acknowledge them as ignored, and the acknowledgement is recorded with their user id |
| 15 | An import commits 4 chunks and the 5th fails | chunks 1–4 stand, the batch is `partially_committed`, the report names exactly which rows applied, and re-running skips them by row hash |
| 16 | An onboarding link is opened after expiry | a clear expiry page; no data is disclosed; HR can re-issue, which rotates the token |
| 17 | A candidate never submits | the journey expires on its lifetime; the `pre_hire` employment is purged or retained per the journey's purge policy; no seat was ever consumed |
| 18 | A candidate's OTP identifier is already verified on another live employee | flagged at verification, not at entry; HR decides whether this is a duplicate person (the merge path, `persons.merged_into_person_id`) |
| 19 | An onboarding document fails the virus scan | quarantined, not stored against the employee; the candidate is asked to re-upload; HR sees the attempt |
| 20 | A document type's expiry rule changes after documents exist | the derived expiry is recomputed for future uploads only; existing documents keep their recorded expiry, and the change is diffed at publish |
| 21 | The expiry alert job misses a day | the next run emits every crossed, un-emitted step (R-X-111); `expiry_alert_emissions` guarantees no duplicate |
| 22 | A field is promoted from `attribute` to `generated` mid-flight | reads and writes are unchanged above the repository; the migration backfills, then flips `storage_kind_code` in the same release |
| 23 | A tenant creates 400 custom fields | permitted; the directory warns when more than the configured number of attribute-stored fields are used simultaneously in one filter (the stated ceiling, Part XXVIII §7.3) |
| 24 | An actor holds two roles with conflicting surface rules | most permissive visibility, strictest requiredness, union of approval workflows (X.4.2) |
| 25 | An SPX `required_expression` throws | the write is denied and an operator alert is raised; it never defaults to "not required" (R-X-030) |
| 26 | A seat check fails mid-import | the row is rejected with `licence_exhausted`, the batch continues per policy, and the report states how many rows were refused for licensing |
| 27 | An employee's code is changed | the change is audited, the old code is retained in `employment_events`, and every downstream system is notified by `employment.code_changed`. No table is keyed on the code string |
| 28 | A person is merged into another | explicit, permissioned, audited; identifiers, documents and employments are re-parented; the merge is reversible only by restore from backup, and the UI says so |

---

## X.19 Tests

Exact-value regression tests. Each names the SmartPRS 2 defect it prevents.

### X.19.1 Metadata and parity

| # | Test | Assertion |
|---|---|---|
| T-01 | Create `linkedin_profile` through the API, publish, and exercise all thirteen surfaces | present and correct on all thirteen; zero code changes; no application restart (R-X-035) |
| T-02 | Field-parity report | for every field, every surface's resolved visibility and requiredness matches the declared rules; no cell is "unknown" (R-X-116) |
| T-03 | Type conformance | for each of the 31 authoring types, all nine artefacts exist and round-trip (R-X-025) |
| T-04 | Surface fail-closed | a field with no rule on a surface is hidden; an SPX error in `required_expression` denies (R-X-030) |
| T-05 | Retire a field referenced by a published template | blocked, template named (R-X-115) |
| T-06 | Publish a uniqueness declaration against duplicate data | blocked, rows listed (R-X-016) |
| T-07 | Identifier injection | a field key containing SQL metacharacters cannot reach a query; the compiled map rejects it at boot (R-X-018) |

### X.19.2 Write semantics

| # | Test | Assertion |
|---|---|---|
| T-08 | PATCH one field on a fully populated employee | every other field is byte-identical afterwards — **the direct DEF-02 regression** |
| T-09 | PATCH with `clear` on a required field | rejected with a field error |
| T-10 | Write through each of the thirteen surfaces | all thirteen produce identical validation, sanitisation, uniqueness and audit outcomes for the same value (R-X-040) |
| T-11 | ESS write of a colleague's email | rejected on uniqueness — the DEF-12 regression |
| T-12 | HTML/script payload through import, onboarding and ESS | stripped identically on all three — the DEF-11 regression |
| T-13 | Child collection: update one reference | the other references are untouched — the DEF-03 regression |
| T-14 | Concurrent PATCH | one succeeds, one 409s; no lost update |
| T-15 | Two blank employee codes created concurrently | two distinct codes; no collision, no rollback — the DEF-08 regression |
| T-16 | Device-id reassignment | the previous holder is cleared explicitly, both records audited, both notified — the NEW-EMP-072 regression |
| T-17 | Audit failure injection | the write rolls back; no employee change is persisted without its audit row |

### X.19.3 History and lifecycle

| # | Test | Assertion |
|---|---|---|
| T-18 | "What was the branch on 15 May?" | exactly one row; the correct branch; the name **as at May**, not today's — the Q5 regression |
| T-19 | Future-dated transfer | in force on its date with no job having run (R-X-048) |
| T-20 | Back-dated change into a locked period | refused; refused again with the guard throwing (fails closed) |
| T-21 | Back-dated CTC increase | the seeded arrears formula reproduces `D-…` §4.6 exactly: old ₹6,00,000, new ₹7,20,000, effective 16-Apr-2026, run September → **₹45,000.00** — value-locked so a formula change is visible |
| T-22 | Exit an employee, then edit an unrelated field | status remains `exited` — the DEF-04 regression |
| T-23 | Reactivate an archived employee without seats | blocked by the transition guard |
| T-24 | Seat count with archived, exited and suspended employees | matches the `consumes_seat` declarations exactly (R-X-056) |
| T-25 | Create an employee through every creation path with seats exhausted | all refused — the DEF-06 regression |

### X.19.4 Import and export

| # | Test | Assertion |
|---|---|---|
| T-26 | **Round trip**, generated one case per field plus a whole population | `import(export(E)) ≡ E`, including nulls, dates, decimals, codes, multi-selects, addresses and lookups (R-X-100) |
| T-27 | File with an unknown column | preview blocks; the column is reported; commit refused until acknowledged — the Q3 regression |
| T-28 | File with two headers mapping to one field | `duplicate`; blocked |
| T-29 | File missing a column, in update mode | the omitted field is untouched — the NEW-EMP-078 regression |
| T-30 | Blank cell on a `blank_means: clear` field | cleared, and the preview counted it |
| T-31 | Blank cell on a `blank_means: ignore` field | unchanged |
| T-32 | Row with three invalid fields | all three reported in one pass (R-X-014) |
| T-33 | Error sheet contents | contains field names, row numbers and rule descriptions; contains **no** PAN, mobile, email or bank value (R-X-090) |
| T-34 | Counts reconciliation | `rows = created + updated + unchanged + skipped + rejected`, always |
| T-35 | 50,000-row file | streams within the memory budget; preview and commit meet the D-X-05 targets |
| T-36 | Worker killed mid-commit | resumes from the last committed chunk; no row applied twice |
| T-37 | Staged file TTL | purged on schedule; `purged_at` stamped; download refused afterwards |
| T-38 | Export template and import template | contain no employee roster — the SEC-01 regression |
| T-39 | Sensitive export without the permission | refused; with it, produced and recorded in `audit_export_log` |

### X.19.5 Onboarding and documents

| # | Test | Assertion |
|---|---|---|
| T-40 | Full journey | every candidate value lands on the canonical entity; no parallel store exists to reconcile — the Q4 regression |
| T-41 | `APP_DEBUG=true` OTP request | no code in any response field — the SEC-05 regression |
| T-42 | OTP resend | all prior codes invalidated; attempts counted per identifier, not per row — the SEC-04 regression |
| T-43 | Brute force on the portal | rate-limited and locked out; the events are logged and alertable |
| T-44 | Bulk onboarding | passes the identical verification gate; a match to an existing employment requires per-row confirmation — the SEC-06 / DEF-10 regression |
| T-45 | Activation with an unverified required document | refused, naming the document |
| T-46 | Post-activation purge | no plaintext identifier survives outside the encrypted canonical fields — the SEC-17 regression |
| T-47 | Selfie and document URLs | require authorisation, expire, and are `attachment` — the SEC-13/14/15 regression |
| T-48 | Document download by a colleague | 403 — the SEC-03 regression |
| T-49 | Expiry ladder with the job skipped for three days | on the next run, every crossed step is emitted exactly once — the DEF-28 regression |
| T-50 | `documents.expiry` and a PCC deadline | both raise alerts — the NEW-EMP-085 / DEF-18 regression |

### X.19.6 Cross-cutting

| # | Test | Assertion |
|---|---|---|
| T-51 | Cross-tenant isolation, generated per endpoint | every employee, import, export, onboarding and document endpoint refuses a foreign tenant's uuid, including for `super_admin` unless platform access is explicitly and audibly assumed — the SEC-09 regression |
| T-52 | Route policy sweep | every route in this part has an explicit policy binding — the SEC-23 regression |
| T-53 | Runtime DDL sweep | no DDL statement is reachable from a request, job or command — the SCHEMA-01 regression |
| T-54 | Name-based identity sweep | no query in this part filters an employee by `name` — the Q6 regression |
| T-55 | Silent-catch sweep | no `catch` in a `Domain\People` path discards an exception affecting a correctness or security decision — the DEF-40 regression |

---

## X.20 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Decision | Recommendation | Trade-off | Origin |
|---|---|---|---|---|
| **D-X-01** | The definitive set of effective-dated employee attributes | the table in X.6.1 (org, classification, managers, status, compensation, statutory, bank, contacts, addresses) | more rows and dated editors; the alternative reintroduces unanswerable history and cannot be retrofitted cheaply | `D-…` PO-02 |
| **D-X-02** | The arrears divisor and proration basis for back-dated compensation changes | seed SmartPRS 2's `/12` and calendar-day basis as the default **formula**, and expose both as parameters. **No value asserted as legally correct** | seeding preserves continuity but perpetuates an unexamined default until each tenant reviews it | `D-…` PO-05 |
| **D-X-03** | Does archiving free a licensed seat? | yes — it is what the product already tells customers | a revenue reduction against today's actual behaviour | `D-…` PO-09 |
| **D-X-04** | The exit lifecycle and whether `exited` is protected | `exited` produced only by the `EMP-EXIT` workflow and protected by the transition table | requires the Exit & F&F module to be specified before employee status migration completes | `D-…` PO-01 |
| **D-X-05** | Import volume and SLA target | 50,000 rows; preview ≤3 min, commit ≤15 min; queue worker mandatory | a lower target permits a simpler synchronous implementation | `D-…` PO-10 |
| **D-X-06** | `also_works_for` / multi-company employment | drop the free-text field; model it as a second Employment | a selector appears on a handful of screens | `D-…` PO-15 |
| **D-X-07** | `dra_declared` / `pcc_declared` | retain as a `self_declared` verification state on the document type | slightly more configuration for tenants that never used them | `D-…` PO-07 |
| **D-X-08** | Per-employee probation length | expose `probation_months` as a dated employee field | one more field on the edit screen | `D-…` PO-17 |
| **D-X-09** | Is `whatsapp` unique? | not unique by default; a per-tenant declaration | tenants relying on it as an identifier must opt in | `D-…` PO-03 |
| **D-X-10** | ESS editable field set and approval policy | contacts, address, emergency contact and preferences only, each approval-routed; widen per tenant | more HR approval traffic initially | `D-…` PO-12 |
| **D-X-11** | Employment stages the business actually needs | seed Permanent, Probation, Internship (today's three) **plus** Contract, Trainee, Apprentice, Consultant, and attach the statutory treatment of each to the rule pack rather than to the stage name | requires payroll's stage-based statutory behaviour to be verified — the analysis explicitly did **not** verify the claim that PF/PT/TDS are skipped for probation and internship | `D-…` PO-06 |
| **D-X-12** | Does a tenant administrator publish field changes directly, or does Ametecs? | tenant admin publishes low-risk changes; `high_risk` changes (type, uniqueness, PII class, encryption, requiredness promotion, history mode) require maker-checker inside the tenant | a strict-approval default slows early configuration; a permissive one risks an unreviewed PII change | R-X-003 |
| **D-X-13** | Retention periods for staged import files, export files, OTP records and onboarding payloads | 7 days / 48 hours / 30 days / purge at activation + 90 days for verification evidence | shorter periods reduce exposure but also reduce forensic capability | `D-…` PO-14 |

---

## X.21 Requirements index

| Range | Subject | Section |
|---|---|---|
| R-X-001 – 003 | Field attributes as data; artefact lifecycle; high-risk gating | X.2.1 |
| R-X-004 – 006 | Storage kinds; no runtime DDL; storage transparency | X.2.2 |
| R-X-007 – 009 | Field key contract; retire-and-replace; system-field invariants | X.2.3 |
| R-X-010 – 014 | Declarative validation; generated client checks; locale; blank; all-errors | X.2.4 |
| R-X-015 – 020 | Uniqueness: declaration, index materialisation, fail-closed, no interpolation, comparison, inactive rows | X.2.5 |
| R-X-021 – 023 | PII class propagation; audit hashing; retention | X.2.6 |
| R-X-024 – 028 | Closed storage-type set; nine artefacts per type; date format; lookups by id; content-based file validation | X.3 |
| R-X-029 – 035 | The thirteen surfaces; rule resolution; fail-closed; read-means-reject; directory attributes; validator checks; the configuration guarantee | X.4 |
| R-X-036 – 045 | `EntityWriteService`: PATCH, sole write path, field outcomes, pipeline, approval routing, universality, child ops, concurrency, idempotency, code sequences | X.5 |
| R-X-046 – 054 | Dated attribute set; custom dated fields; future-dating; back-dating; retro-effect; field audit; history view | X.6 |
| R-X-055 – 061 | Configurable statuses; seat semantics; seat computation; transitions; seat checks; archive/restore as jobs; cascade policy | X.7 |
| R-X-062 – 079 | Onboarding journeys as metadata; canonical staging; verification; OTP policy; rate limiting; token handling; consent; signature; private storage; correction; purge; bulk | X.8 |
| R-X-080 – 102 | Template generation; pipeline; header matching; required columns; duplicate mode; chunking; single write path; dispositions; row outcomes; error sheet; reconciliation; blank-means-clear; streaming; caps; encryption and TTL; no plaintext credentials; export profiles; export audit; round-trip symmetry; read-only columns; format fidelity | X.9 |
| R-X-103 – 114 | Document type master; applicability; verification workflow; object-level access; retention; watermark and signature; quotas; expiry ladder; catch-up semantics; universal coverage; readiness rules; configurable windows | X.10 |
| R-X-115 – 116 | Field usage view; derived parity report | X.11 |
| R-X-117 – 119 | Self-service subject resolution; guarded import endpoints; no raw SQL in errors | X.14 |
| R-X-120 | Explainability of presentation decisions | X.16 |

### X.21.1 Dependencies on other parts

| This part needs | From |
|---|---|
| Person / Employment / Assignment aggregates, `AsAtService`, identity strategy, code sequences | Part V |
| Configuration artefact lifecycle, Studio, validator, diff and impact preview | Part VI |
| SPX, the rule engine, the action catalogue, `employee.custom()` | Part VII |
| The formula engine, for `default_expression` and derived expiry | Part VIII |
| The workflow engine, for verification, field-change approval and status transitions | Part IX |
| Physical tables `persons`, `employments`, `employment_assignments`, `entity_field_values`, `audit_*` | Part XXVIII |
| Permission and data-scope model; encryption, masking and signed URLs | Part on security (spine S13) |
| Notification audiences, templates and channels | Part on notifications |
| Queue, scheduler, job observability and the purge jobs | Part on background operations |
| Payroll's input snapshot and retro-effect consumption | Part on payroll (spine S11) |
| Licensing seat ledger | Part on SaaS licensing |

### X.21.2 What this part deliberately leaves to others

The **content** of a tenant's employee schema. This part specifies the mechanism
and the seeded 56-field compatibility set derived from
`EmployeeFieldRules::FIELDS`. Which fields a given customer enables, which are
mandatory on which surface, which are unique, and which appear in their onboarding
journey is configuration in that customer's tenant — which is precisely the
outcome this architecture exists to produce.
