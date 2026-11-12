# PART XVI — PAYSLIP ARCHITECTURE

**Covers master-brief Section 38 (payslip design, generation, distribution and
verification).**

Conforms to: spine **S1** (the seven laws), **S3.1/S3.2** (scope and
precedence), **S4** (effective dating, draft/publish), **S5** (SPX — template
conditions), **S12** (statutory bases by flag), **S13** (security), **S14**
(audit), **S15** (explainability), **S16** (data conventions), **S17** (API and
webhooks).

Depends on and does not duplicate: **Part VI** (configuration architecture and
the template artefact), **Part VIII** (formula engine, the calculation trace of
§10), **Part XIV** (payroll — the run, the reconciliation assertion, the lock),
**Part XV** (salary structure — component codes, flags, and the per-component
presentation attributes this part consumes), **Part XXVIII** (`payslips`,
`payslip_lines`, `calculation_traces`).

---

## XVI.0 Purpose

This part decides **what a payslip is made of, how it is designed, and how it
reaches the employee.** Three things are fixed: a payslip is a set of stored,
typed, traced facts — nothing on it is ever recovered by parsing prose or
inferred from a keyword; its layout is a tenant-designed, scoped, versioned
template rather than one hardcoded page; and its distribution, acknowledgement,
reissue and verification are auditable events rather than side effects.

A payslip is the only document in the system that an employee, a bank, a court
and a tax officer may each read. It must be **reproducible and defensible three
years later**. Today it is neither.

---

## XVI.1 What SmartPRS 2 does today, and why it must change

### XVI.1.1 A payslip stores no rate snapshot, so no payslip can be reproduced or defended

`F-statutory-compliance.md` §5.2 item 32, verified: **payslips store no rate
snapshot.** A `payslips` row holds `earnings` and `deductions` as JSON blobs plus
`gross`, `total_ded` and `net` — and nothing about *how* those numbers arose. It
does not record the PF rate, the PF ceiling, the ESI threshold, the PT slab
walked, the TDS slab table, the standard deduction, the rebate limit, the cess
rate, the salary structure, the component definitions, the LOP basis, the working
days, or the policy version in force.

The rates themselves are a single overwritten JSON blob with no history
(`SettingsController::rates():91-102`; `save():198-201` is an `updateOrInsert`).
So a payslip cannot be re-derived even in principle: the inputs that produced it
no longer exist anywhere. Asked "why was this employee's PF ₹1,800 in March?",
the system can show the number and nothing else.

`A-payroll.md` §5.3 Q5 records the same conclusion from the payroll side. This is
the single most consequential defect in this area, because a payslip that cannot
be explained cannot be defended — to the employee, to the EPFO, or to a labour
court.

### XVI.1.2 Paid Days and LOP are recovered by regex from English prose

`AppDataController::payslipViewData():1278-1282`:

```php
preg_match('/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i', $note, $pm);
```

`$note` is `payslips.calc_note` — a free-text, human-readable "how this was
calculated" sentence written by `PayrollGenController::calcNote()`. That sentence
is **only emitted when `factor < 0.9999`** (`calcNote():537-540`). When the
employee had full attendance, the regex finds nothing and the payslip prints
`daysInMonth / 0` — i.e. "31 paid days, 0 LOP" — which happens to be right by
accident. When someone edits the wording of the note, every payslip silently
reverts to "full month" (DEF-P16).

Two of the most-read figures on the document — the ones an employee checks
first — are the output of a regular expression over English.

### XVI.1.3 "Gross" has two conflicting definitions

`NEW-PAY-31`. The printed **"Gross Earnings (A+B)"** on the PDF
(`resources/views/payslip-pdf.blade.php:153`) sums groups A and B and therefore
**excludes** reimbursements. The stored `payslips.gross`
(`computeSlipFromComponents():2329`) is `ctc/12` and therefore **includes** them.

The two numbers differ by the reimbursement total, both are labelled "gross", and
they appear on the same system: one on the employee's document, one in the run
header, the bank file, the wage register and the YTD column. A reconciliation
between the payslip and the register is therefore impossible without knowing
which "gross" each side means.

The statutory bases add a third: `wageGross = gross − reimbursements`
(`:2220`, `:2273`) is what ESI is tested against, while the ESI pre-check at
`compute():1297` uses full gross — so at the ceiling the two disagree and the ESI
period lock may not be consulted when it should be (DEF-P20g).

### XVI.1.4 Earning and deduction grouping comes from keyword matching

`payslipGroupEarnings():1591-1636` classifies every earning into
**A · Fixed / B · Variable / C · Reimbursement** using
`salary_components.category` when it is set, and otherwise a nine-keyword
heuristic (`:1617-1624`):

> `reimburs` → reimbursement;
> `commission | incentive | bonus | overtime | arrear | ex-gratia | ex gratia |
> payout | variable` → variable; everything else → fixed.

`category` is a **runtime-created** column (`MasterController::ensureCols()`), so
on an install where nobody opened the Salary Setup screen it does not exist at
all and the heuristic is the only classifier. A component called "Variable Dearness
Allowance" is classified variable; "Performance Pay" is classified fixed.

The same class of defect runs through the wage register, which maps deduction
keys to Form B columns by substring — `provident`/`pf` → PF, `esi` → ESI,
`professional`/`pt` → PT, `tds` → TDS (`ReportController:172-192`, NEW-PAY-35).

### XVI.1.5 The payslip prints `pf_applicable` / `esi_applicable` flags the engine never reads

`F-statutory-compliance.md` NEW-F-10: two employee-level flags,
`pf_applicable` and `esi_applicable`, exist and are **printed on the payslip**.
Neither is read by the calculation. An employee marked "PF: Not Applicable" has
PF deducted; an employee marked "ESI: Applicable" above the threshold does not.
The document asserts a fact about the employee's statutory position that the
system does not honour.

### XVI.1.6 The remaining structural problems

| # | Finding | Evidence |
|---|---|---|
| 1 | **One hardcoded Blade template.** No per-company template, no field selection beyond a YTD on/off toggle, no language option | `A-payroll.md` §5.2 item 54 |
| 2 | **No stored payslip → a theoretical slip prints anyway.** With no run for the month the PDF falls back to a full-CTC `computeSlip()` — un-prorated, no components, no extras — **with no warning on the document** | `payslipViewData():1237-1264`, BR-PAY-202 |
| 3 | **YTD has no tenant, company or run filter and no de-duplication.** It sums every `payslips` row for the employee from `YYYY-04` by **string comparison** | `payslipYtd():1557-1584`, NEW-PAY-34, SEC-P09 |
| 4 | **The employer-cost memo is a second implementation of `statutory()`.** It re-derives EDLI as `min(basic,15000) × 0.5%` and **back-derives** employer ESI as `esi × erRate / eeRate` when the keys are absent | `payslipEmployerCost():1541-1542`, NEW-PAY-32 |
| 5 | **A fourth gratuity formula lives on the payslip** — `Basic × 4.81%` monthly accrual — disagreeing with the three elsewhere | `:1543`, NEW-PAY-33, DEF-P25 |
| 6 | **`net_words` and `pdf_path` are migrated columns that are never written**; `emailed_at` is not written by the payroll path | `A-payroll.md` §2.3 |
| 7 | **The salary voucher route always 500s** — `Pdf::loadView('voucher-pdf', …)` where `resources/views/voucher-pdf.blade.php` does not exist | `SalaryApprovalController::voucher():548`, DEF-P12 |
| 8 | **The payslip ID format `PRS/<YYYYMM>/<empcode>` is a literal** | `A-payroll.md` §5.2 item 57 |
| 9 | **Self-download policy is four literal modes** — `all \| none \| dept \| emp` — tenant-wide, with HR/Admin bypassing | `SettingsController::payslipSelfAllowed():108-124`, enforced `:1191-1194` |
| 10 | **No record of who viewed a payslip.** There is no view audit of any kind | grep: no read-audit on `payslipPdf()` |
| 11 | **Acknowledgement is a typed name plus a checkbox**, on a line in exactly `disbursed` state, with no reissue concept | `acknowledge():731-792`, BR-PAY-029 |
| 12 | **Identity resolution falls back to matching on employee *name***, so in a tenant with two employees of the same name a user can be bound to the wrong record — and download that person's payslip | `payslipPdf():1183-1186`, SEC-P06 |

### XVI.1.7 What is not replicated

| SmartPRS 2 behaviour | SmartPRS 3 |
|---|---|
| No rate snapshot | **Not replicated.** Every payslip pins its run's input snapshot and every line carries a trace (R-XVI-002) |
| Paid Days and LOP from a regex over prose | **Not replicated.** Typed stored columns; parsing prose is not a code path that exists |
| Two definitions of gross | **Not replicated.** One definition: `SUM(lines where in_gross = 1)` (Part XV R-XV-016) |
| Grouping by keyword | **Not replicated.** `payslip_group_code` on the component definition |
| Printing statutory flags the engine ignores | **Not replicated.** A field may only be printed if it is a stored fact the engine used (R-XVI-006) |
| One hardcoded template | **Not replicated.** Scoped, versioned templates from a visual designer |
| A theoretical slip printed with no warning | **Not replicated.** No payslip exists without a run; there is nothing to fall back to |
| YTD unscoped and un-de-duplicated | **Not replicated.** Scoped, run-approved, de-duplicated |
| Second and third implementations of statutory arithmetic on the document | **Not replicated.** The payslip **reads stored lines**; it computes nothing |
| No view audit | **Not replicated.** Every view of a payslip is audited |

---

## XVI.2 The payslip data contract

### XVI.2.1 The principle

**R-XVI-001** **A payslip renders stored facts. It computes nothing.** Every
figure on a payslip is a column on `payslips`, a row in `payslip_lines`, or a
value in a stored aggregate that itself derives only from those. A template may
select, order, group, subtotal, format, caption, mask and hide. It may not
calculate, derive, infer or parse.

**R-XVI-002** Every payslip pins:

- `payroll_run_id` and the run's `snapshot_digest` — through which the statutory
  pack version, every structure, component, formula and rule version, the
  proration policy, the day-length policies, the compensation row and the org
  assignment are all reachable (Part XIV §XIV.4.3);
- `employee_compensation_id` — the dated CTC and structure that applied;
- `employment_assignment_id` — the org attribution **as at the period**;
- `period_settlement_id` — the frozen attendance settlement;
- `payslip_template_config_version_id` — the template that rendered it;
- per line, `component_config_version_id` and `trace_id`.

*This is the direct answer to §XVI.1.1: a payslip that pins its inputs can be
re-derived, and a payslip whose every line carries a trace can be explained
without re-deriving anything.*

**R-XVI-003** A payslip carries no free-text field from which any figure is
recovered. `legacy_calc_note` exists only to display a migrated SmartPRS 2 note
verbatim as an archival artefact, is never parsed, and is never written for a
SmartPRS 3 run.

### XVI.2.2 The stored fact set

**R-XVI-004** Beyond the columns Part XXVIII declares, a payslip stores these as
**typed columns**, because every one of them is printed and none of them may be
derived at render time:

| Fact | Type | Why stored |
|---|---|---|
| `payable_days`, `lop_days` | DECIMAL(9,4) | *The two figures currently recovered by regex* |
| `scheduled_payable_days`, `present_days`, `paid_leave_days`, `unpaid_leave_days`, `weekly_off_days`, `holiday_days` | DECIMAL(9,4) | The day account the employee checks first |
| `ot_hours`, `night_shift_count`, `comp_off_earned`, `comp_off_availed` | DECIMAL(9,4) | |
| `proration_factor_numerator`, `proration_factor_denominator` | BIGINT | The **exact rational**, not a rounded decimal, so the factor is reproducible |
| `proration_basis_code`, `day_length_policy_code` | VARCHAR(40) | Which basis valued the absence |
| `gross_earnings`, `total_deductions`, `net_pay`, `employer_cost`, `total_reimbursement`, `ctc_actual` | DECIMAL(18,4) | One definition each (R-XV-016) |
| `net_pay_in_words` | VARCHAR(400) | **Generated at issue and stored**, in the payslip's language. *`payslips.net_words` is migrated and never written* |
| `payslip_number` | VARCHAR(60) | From `code_sequence_counters`, format configurable |
| `period_start_on`, `period_end_on`, `pay_on` | DATE | Copied from the period; never re-derived |
| `currency_code` | CHAR(3) | |
| `statutory_applicability_json` | JSON | The **determinations the engine actually used** — PF covered, ESI covered/locked, PT state, LWF applicability — each with the rule and pack version that produced it |

**R-XVI-005** `proration_factor_numerator/denominator` are stored as the exact
integers, never as a rounded decimal. *`PayrollGenController:1453` stores
`round($factor, 4)` for display while the unrounded float multiplies the CTC — so
the stored factor cannot reproduce the stored amount.*

**R-XVI-006** A template field may only be bound to a **stored fact that the
engine used**. The field catalogue is generated from the payslip schema and the
line schema; a field that no engine stage writes cannot be placed on a template.
*This is the structural prohibition on §XVI.1.5 — printing `pf_applicable` when
nothing reads it becomes impossible, because the printed value is
`statutory_applicability_json.pf.determination`, which is by construction the
determination the engine applied.*

### XVI.2.3 Every figure carries its trace

**R-XVI-007** Every `payslip_lines` row has a non-null `trace_id` (Part XIV
`RC-13`). Every derived payslip **header** figure — gross, deductions, net,
employer cost, payable days, LOP days, CTC actual, YTD, each statutory block —
likewise has a stored trace.

**R-XVI-008** The trace is reachable from the rendered document: in ESS and
mobile, every figure is a "How was this calculated?" affordance; in the PDF, the
optional verification QR (§XVI.9) resolves to the same trace view for an
authorised viewer.

**R-XVI-009** Traces are **stored, not regenerated** (Part XIV R-XIV-073).
Regenerating a trace from current configuration would reproduce exactly the
defect this part exists to remove.

---

## XVI.3 The Payslip Template Designer

### XVI.3.1 What a template is

**R-XVI-010** A payslip template is a spine S4 configuration artefact,
`artefact_type = 'payslip_template'`, versioned, scoped, effective-dated,
draft/publish, with a diff and an approval before publication.

**R-XVI-011** The designer is **visual**: a canvas of sections, drag-to-reorder,
per-field property panels, live preview against a real or synthetic payslip, and
a print/PDF preview at the target page size. An advanced view exposes the
template's declarative source for review and for export/import between tenants
(Part VI's configuration package mechanism).

**R-XVI-012** A template is **declarative data**, not markup, and not code. It
compiles to a renderer-independent document model from which the PDF, the ESS
view, the mobile view and the email body are all produced. **Customer-supplied
HTML, PHP, JavaScript, Blade or Twig is never accepted** (spine S5's absolute
constraint, applied to templates). Rich text in a static block is sanitised
against an allow-list.

**R-XVI-013** A template declares its page setup: size (A4, Letter, A5), margins,
orientation, and whether the design is single-page-per-payslip or continuous.
A payslip that overflows its declared page count is a **publish-time validation
failure** against the tenant's widest sample, and a run-time warning that appends
a continuation page rather than truncating.

### XVI.3.2 The sections

**R-XVI-014** The section catalogue is closed, versioned platform data. Every
section is optional, orderable, and individually scoped by an SPX `visible_when`
condition. A tenant composes; it does not invent a section type.

| Section | Contents |
|---|---|
| `header` | Logo, legal entity name, address, registration identifiers (PF code, ESI code, PT registration, CIN, GSTIN), document title, "computer-generated" statement |
| `employee_identity` | Name, employee code, designation, department, branch, location, grade, cost centre, date of joining, date of confirmation, date of exit — **each field independently selectable** |
| `statutory_identity` | PAN, UAN, ESIC number, PF account number, bank name, account number, IFSC — each with its own masking rule |
| `period_block` | Period label, period start and end, pay date, run type, lot, and — when the run is a supplementary, arrears or off-cycle run — an explicit statement of that fact |
| `attendance_block` | Payable days, LOP days, scheduled days, present days, paid and unpaid leave days, weekly offs, holidays, OT hours, comp-off — **from stored columns** |
| `earnings` | Component lines, grouped, with per-group and grand subtotals |
| `deductions` | Component lines, grouped, with subtotals |
| `net_block` | Net pay in figures and in words, and the payment mode |
| `employer_contribution` | Employer-side lines, clearly marked as not deducted |
| `reimbursement_block` | Reimbursement lines, separately totalled |
| `recovery_block` | Loans and advances: opening balance, instalment, closing balance, instalments remaining |
| `ytd_block` | Year-to-date figures (§XVI.6.1) |
| `ctc_statement` | The CTC build-up (§XVI.6.2) |
| `leave_balance` | Balance by leave type: opening, accrued, availed, encashed, lapsed, closing (§XVI.6.3) |
| `statutory_block` | Per-scheme summary: base, rate, employee share, employer share, scheme number (§XVI.6.4) |
| `tax_block` | Regime, projected annual income, exemptions, deductions, tax payable, deducted to date, this period |
| `arrears_block` | Arrear lines by target period |
| `message_block` | A scoped, dated announcement — one place, not a code change |
| `signature_block` | Authorised signatory, designation, digital signature panel |
| `verification_block` | QR code, verification URL, document hash |
| `footer` | Page numbers, disclaimer, contact, generated-at timestamp, template version |
| `custom_block` | Static text or an entity field (Part X) — the only extension point, and it holds **no computation** |

**R-XVI-015** Every section has `visible_when` (SPX Boolean over the payslip's
stored facts) — so "show the arrears block only when there are arrears" and
"show the exit block only for a final settlement" are configuration, and a blank
section never prints as an empty box.

### XVI.3.3 Per-field and per-component control

**R-XVI-016** For **every field** in every section, and for **every component**,
a template declares:

| Control | Values | Notes |
|---|---|---|
| Visibility | `show` \| `hide` \| `show_if_non_zero` \| `show_if(SPX)` | `show_if_non_zero` is first-class (Part XV R-XV-014) |
| Caption | Any localised string, per language | May differ from the component's `name` |
| Display order | Integer within its group | |
| Group | Any declared payslip group | From the component definition by default, overridable per template |
| Subtotal membership | Which subtotal(s) it rolls into | A line may roll into more than one subtotal (e.g. "Gross" and "Fixed") |
| Detail treatment | `show_detail` \| `include_in_total_but_hide_detail` \| `mask` | |
| Masking | `full` \| `last_n(n)` \| `first_n(n)` \| `redact` | For account numbers, PAN, Aadhaar |
| Numeric format | Decimal places, thousands grouping (**Indian by default**), negative style, zero style | |
| Emphasis | Normal, bold, boxed, rule above/below | Presentation only |

**R-XVI-017** `include_in_total_but_hide_detail` is a first-class treatment: a
component contributes to its subtotal and to gross, and its own line does not
print. The subtotal remains arithmetically correct, and the reconciliation
assertion still holds — because hiding is a rendering decision applied after the
figures are final, never a change to them.

**R-XVI-018** A masked field's value is masked in **every** channel — PDF, ESS,
mobile, email body, API response — and the masking is applied server-side. The
unmasked value is never sent to a client that must not see it.

**R-XVI-019** Grouping comes from `payslip_group_code` on the component
definition (Part XV §XV.2.4), overridable per template. **There is no keyword
classifier anywhere in SmartPRS 3.** *Replacing the nine-keyword heuristic of
§XVI.1.4 and the wage register's substring column mapping.*

**R-XVI-020** Groups themselves are tenant-defined reference data
(`payslip_groups`: code, label, sequence, subtotal caption, whether it enters
gross). A tenant that wants five earning groups rather than three needs no
release.

### XVI.3.4 Publish-time validation

**R-XVI-021** A template version cannot be published unless:

| Gate | Assertion |
|---|---|
| **P1** | Every bound field exists in the payslip fact catalogue (R-XVI-006) |
| **P2** | Every `visible_when` / `show_if` expression parses, type-checks and references only declared symbols |
| **P3** | Every component referenced by an explicit per-component rule exists and is not archived |
| **P4** | Every subtotal is arithmetically well-formed: no component contributes twice to the same subtotal; every subtotal's members exist |
| **P5** | **The printed gross equals `SUM(lines where in_gross = 1)`**, and the printed net equals `gross − total_deductions`, on every sample payslip. *A template that prints a "gross" other than the one definition cannot be published — directly preventing §XVI.1.3* |
| **P6** | Every caption has a value in every language the template declares |
| **P7** | The rendered sample fits the declared page setup |
| **P8** | Scope does not overlap another published template version for the same scope and effective range; an S3.2 tie blocks publication (spine S3.2) |
| **P9** | The mandatory sample set (≥3 payslips spanning the tenant's range, including a zero-net and a maximum-line-count case) renders without error and matches the asserted totals |
| **P10** | Every field carrying `sensitive` PII has an explicit masking declaration or an explicit acknowledgement that it is unmasked |

### XVI.3.5 Template scoping and resolution

**R-XVI-022** A template version is scoped over the full spine S3.1 dimension
set, and specifically supports the dimensions the brief names — **company,
state, branch, employee category and salary structure** — plus every other
dimension, resolved by S3.2 precedence.

**R-XVI-023** `salary_structure` participates in template scope as a first-class
dimension, so a tenant may design one payslip for its Collections structure and
another for Corporate without touching either structure.

**R-XVI-024** Template resolution happens at **issue time**, with `as_of` inside
the payroll period, and the resolved version id is stored on the payslip
(R-XVI-002). A payslip re-rendered later uses **the template it was issued
under**, not today's.

**R-XVI-025** A run type may override the resolution: a `final_settlement` run
resolves a settlement-specific template where one is published, falling back to
the ordinary resolution otherwise.

**R-XVI-026** Zero templates resolving is a blocking run exception
(`missing_payslip_template`), never a fallback to a built-in layout. The platform
ships a seeded default template as an ordinary tenant artefact, which a tenant may
edit or replace; it is not a code path.

### XVI.3.6 Caption pinning

**R-XVI-027** At issue, the payslip stores the **rendered caption** for every
line alongside the component code (`payslip_lines.caption_snapshot`). Re-rendering
a historical payslip uses the pinned captions, so a component renamed in 2027
does not silently relabel a 2026 payslip that an employee has already filed with
their bank. *This is the second half of Part XV **D-XV-06**: renaming is free
precisely because captions are pinned here.*

---

## XVI.4 The rendered document

### XVI.4.1 One document model, four renderers

**R-XVI-028** The compiled template plus the payslip's stored facts produce a
**document model**. Four renderers consume it: PDF (the archival artefact), ESS
web, mobile, and an email/messaging summary. All four render the same figures,
the same masking and the same captions; they differ only in layout affordances
and in which sections are declared visible per channel.

**R-XVI-029** The PDF is generated **once, at issue**, stored as a document with
a content hash, and served from storage thereafter. It is never re-generated on
demand from current configuration. *Ensuring that the document an employee
downloaded in March is byte-identical to the one they download in December —
and that its hash can back the verification of §XVI.9.*

### XVI.4.2 Numbering

**R-XVI-030** `payslip_number` is allocated from `code_sequence_counters`
(Part XXVIII) under a `FOR UPDATE` allocation, with a **configurable format**
built from declared tokens (`{legal_entity}`, `{company}`, `{fin_year}`,
`{period}`, `{run_type}`, `{lot}`, `{employee_code}`, `{seq}`), a configurable
reset period, and a configurable padding. *`PRS/<YYYYMM>/<empcode>` is a literal
today, and the invoice numbering elsewhere in the product uses `count() + 1`,
which is not race-safe (NEW-PAY-44).*

**R-XVI-031** A payslip number is never reused. A superseded payslip keeps its
number; its replacement receives a new one and states which it supersedes.

### XVI.4.3 Gross, once

**R-XVI-032** The document prints exactly one gross:
`gross_earnings = SUM(lines where in_gross = 1)` (Part XV R-XV-016). Group
subtotals are presentation. Where a tenant wants "Gross (A+B)" excluding
reimbursements as a visible figure, that is a **named subtotal with its own
caption**, printed alongside gross, never instead of it — and P5 enforces the
distinction.

**R-XVI-033** Reimbursements print in their own group with their own subtotal and
`in_gross = 0`, so the payslip's arithmetic reads:

```
Gross earnings (in_gross)              ₹48,400.00
  A · Fixed                            ₹48,400.00
Reimbursements (not in gross)           ₹1,600.00
Total deductions                        ₹3,940.00
Net pay                                ₹44,460.00
Total credited to bank                 ₹46,060.00   (net + reimbursements)
```

Each of the five figures is a declared, stored total. No two of them are called
gross.

### XVI.4.4 Zero and negative values

**R-XVI-034** A zero line prints per its `show_if_non_zero` setting. A **negative**
earning line never prints as an earning: a negative result is a run-time
`structure_does_not_reconcile` exception (Part XV V5, Part XIV). *Because a
negative residue currently prints as a negative "Special Allowance"
(DEF-P20c).*

---

## XVI.5 Publication, supersession and reissue

**R-XVI-035** A payslip is `issued` only when its run is **locked** and the
reconciliation assertion passed. A preview run produces payslip rows marked
`is_preview` which are never publishable, never numbered, never rendered to PDF
and never visible in ESS.

**R-XVI-036** Publication timing is configurable per scope: `on_lock`,
`on_disbursement_confirmed`, `on_pay_date`, or `manual_release`. The default is
`on_disbursement_confirmed`, so an employee does not receive a payslip for money
that has not moved.

**R-XVI-037** An issued payslip is **immutable**. A correction is a **new
payslip** on a new run version that supersedes it:
`superseded_at`, `superseded_by_payslip_id` on the old row;
`supersedes_payslip_id`, `supersession_reason_code` and
`supersession_note` on the new one.

**R-XVI-038** A superseded payslip remains retrievable and **remains marked
superseded on its own face** when re-rendered — because the employee may have
filed it with a bank or a landlord and must be able to tell which version they
hold.

**R-XVI-039** Reissue on a corrected run notifies every channel the original was
delivered through, states what changed at the line level, and **resets
acknowledgement** — a prior acknowledgement does not carry to a corrected
document.

**R-XVI-040** A supplementary or arrears payslip **references** the original
period's payslip rather than replacing it, and prints the reference on its face
(Part XIV R-XIV-065).

---

## XVI.6 YTD, CTC statement, leave balance and statutory blocks

### XVI.6.1 Year-to-date

**R-XVI-041** YTD is computed over payslips that are **tenant-scoped,
company-scoped, financial-year-scoped, run-approved, non-superseded and
de-duplicated by `(employment, period, run_version)`**, and it is computed by a
date range on `payroll_periods`, never by a string comparison on a month label.
*`payslipYtd():1557-1584` has no tenant filter, no company filter, no run filter
and no de-duplication, compares `YYYY-MM` strings, and — combined with the
fabricated twelve-month back-fill (Part XIV §XIV.1.1) — sums fabricated figures
into a real payslip's YTD (NEW-PAY-34, SEC-P09).*

**R-XVI-042** YTD is **stored on the payslip at issue** (`payslip_ytd_lines`),
not computed at render time. A payslip's YTD is therefore the YTD as at its
issue, is reproducible, and does not change when a later period is run.

**R-XVI-043** YTD is available per component code, per statutory scheme, and for
gross, deductions, net, taxable income and tax deducted. Where a period was
superseded, the YTD of subsequent payslips reflects the surviving version, and
the change is visible in the payslip's own trace.

### XVI.6.2 CTC statement

**R-XVI-044** The CTC block prints the build-up declared by the structure's
`ctc_definition_code`: every component with `in_ctc = 1`, employee-side and
employer-side, at its actual amount for the period and at its annualised
contracted amount, with the variance. Every figure is a stored line; **nothing is
re-derived on the document**.

**R-XVI-045** Gratuity accrual, where shown, is a component of kind
`information` with `is_employer_cost = 1`, computed by a **published formula
version** and traced like any other. *There is exactly one gratuity computation in
SmartPRS 3. `A-payroll.md` DEF-P25 records four in SmartPRS 2 — a provision
report at `(15/26) × Basic × floor(years)` in whole rupees, an F&F report at 2 dp
with a different year count, and a `Basic × 4.81%` monthly accrual printed on the
payslip — which disagree with each other by 46 paise on a worked example and by a
whole eligibility answer at the five-year boundary.*

**R-XVI-046** The employer-contribution block reads **stored employer-cost
lines** produced by pipeline stage 22. It never back-derives. *`payslipEmployerCost():1541-1542`
re-derives EDLI as `min(basic,15000) × 0.5%` and back-derives employer ESI as
`esi × erRate / eeRate` whenever the slip lacks the keys — a second
implementation of `statutory()` that will drift (NEW-PAY-32).*

### XVI.6.3 Leave balance

**R-XVI-047** The leave block prints, per leave type: opening balance, accrued,
availed, encashed, lapsed, adjusted and closing balance, as at the period end,
**from the leave ledger's stored period snapshot**, with the leave type's
configured display name and unit. Each figure is traceable to its ledger entries.

### XVI.6.4 Statutory blocks

**R-XVI-048** Each statutory block prints, per scheme: the **wage base and its
composition** (which components, by code and amount — R-XV-049), the rate or slab
applied, the employee share, the employer share where applicable, the scheme
number, and the pack version. It prints the applicability determination the
engine used, from `statutory_applicability_json` (R-XVI-004).

**R-XVI-049** Where a period-scoped determination applied — an ESI
contribution-period lock continuing coverage above the ceiling — the block states
it, names the contribution period, and links to the determination record
(Part XIV §XIV.8.6). *An employee whose ESI continues above the threshold
currently receives a payslip that explains nothing, and whose lock was decided by
a substring search over a JSON key.*

---

## XVI.7 Distribution

### XVI.7.1 Channels

**R-XVI-050** A payslip is distributed through declared channels, each
independently enabled by scope and each producing an auditable delivery record:

| Channel | Notes |
|---|---|
| **ESS web** | The primary channel. Server-rendered from the document model; every figure carries its trace affordance |
| **Mobile** | The same document model, mobile layout; offline caching of the employee's own payslips only |
| **Email** | Configurable body template; the PDF as an attachment or as a signed, expiring link |
| **WhatsApp** | Through the messaging connector, using approved message templates; the document as a link, or as a document message where the channel and the tenant's policy permit |
| **Bulk export** | For a payroll operator: a scoped, permissioned, audited archive |
| **Print** | A print pack for tenants who distribute on paper |

**R-XVI-051** Every delivery writes a `payslip_deliveries` row: channel, target
(masked), attempt, provider message id, state
(`queued | sent | delivered | read | bounced | failed | suppressed`), failure
reason, and timestamps. Failures are retried with backoff and land in a
dead-letter queue that is visible on the operations dashboard (spine S18).
*`payslips.emailed_at` is a migrated column the payroll path never writes, so
today "was this payslip sent?" is unanswerable.*

**R-XVI-052** A private payslip PDF is **never** placed on a publicly readable
path. It is served through an authorised, signed, expiring URL (spine S13), and
each retrieval is authorised afresh — an expired link is not a valid credential.

### XVI.7.2 Access policy

**R-XVI-053** Who may see a payslip is answered by permission **and** data scope
(spine S13), evaluated server-side, failing closed. An employee's self-access
resolves the subject **from the session**, never from a request parameter, and
there is no dropdown of other employees on a self-service payslip screen.
*`payslipPdf():1183-1186` resolves the acting user to an employee by
`users.employee_id`, else `employees.email`, else **`employees.name`** — so in a
tenant with two employees of the same name a user can be bound to the wrong
record and download that person's payslip (SEC-P06).*

**R-XVI-054** The self-download policy is a scoped, dated policy artefact —
`allow | deny | allow_after(SPX)` — replacing four literal modes
(`all | none | dept | emp`) applied tenant-wide. Conditions such as "not until the
period is disbursed", "not for employees under notice", "not for a superseded
version" are SPX, not code. A privileged role's ability to bypass is itself a
permission, not an implicit exemption.

### XVI.7.3 Password protection

**R-XVI-055** PDF password protection is configurable per scope, with the
password derived by a **declared, documented rule** built from tokens
(employee code, first N of PAN, date of birth in a declared format, a per-employee
secret) — never hardcoded, never predictable across a whole tenant, and never
transmitted in the same message as the document. The rule in force is stated in
the delivery message; the password itself is not.

**R-XVI-056** Where a tenant selects a per-employee secret, it is stored
encrypted at the application layer, rotatable by the employee, and never
recoverable in plaintext by an administrator.

### XVI.7.4 Acknowledgement

**R-XVI-057** Acknowledgement is configurable: `not_required`,
`view_is_acknowledgement`, `explicit_confirm`, `explicit_confirm_with_otp`, or
`explicit_confirm_with_signature`. Each captures actor, timestamp, IP, user
agent, channel, the **payslip version acknowledged** and its document hash.

**R-XVI-058** Acknowledgement does not require the payslip line to be in any
particular disbursement state; it requires the payslip to be **issued and
current**. *SmartPRS 2 requires the line to be exactly `disbursed`
(`acknowledge():745-748`), so a payslip disbursed through the bank-file path —
which bypasses `applyLineAction()` and sends no notification — can be
acknowledged only by chance.*

**R-XVI-059** An acknowledgement is **void on supersession** (R-XVI-039) and the
void is recorded rather than deleted.

**R-XVI-060** A tenant may configure an acknowledgement SLA with reminders and
escalation, using the workflow engine's timer machinery (Part IX §IX.7) rather
than a bespoke scheduler.

### XVI.7.5 The view audit

**R-XVI-061** **Every access to a payslip is audited** — view, download, print,
export, API read — recording actor, subject employment, payslip version, channel,
IP, user agent, whether the access was self or third-party, and the permission
and data scope that allowed it. Salary is `sensitive` PII (spine S13) and its
access is auditable by construction, not by option.

**R-XVI-062** An employee can see, in ESS, **who has viewed their own payslip**,
by role and date. *A transparency property SmartPRS 2 cannot offer, because it
records nothing.*

**R-XVI-063** Bulk export of payslips is separately permissioned, rate-limited,
bounded, and produces a single audit record naming the scope, the count and the
requester.

---

## XVI.8 Verification and digital signature

**R-XVI-064** *(optional, per scope)* A payslip may carry a **verification
block**: a QR code and a short verification URL resolving to a
`payslip_verifications` record. The record holds the payslip's document hash, its
number, the issuing legal entity, the period, the net amount, the issue date and
the current status (`valid | superseded | cancelled`).

**R-XVI-065** The public verification endpoint is **rate-limited, unauthenticated
and minimal**: it confirms or denies that a document with this hash was issued by
this entity for this period, and shows the status. It **never discloses salary
figures, the employee's name, or any personal data** to an unauthenticated
caller. A bank or landlord verifying a payslip learns that it is genuine, not what
it says.

**R-XVI-066** A verification token is per payslip version, is revoked on
supersession (the verification then reports `superseded` and names the current
version's token), and expires per a configurable window.

**R-XVI-067** *(optional, per scope)* A payslip may be **digitally signed** —
PAdES-compatible PDF signature using a tenant-supplied certificate held in the
platform's key store or in an HSM/eSign provider. Private keys are never exported,
never logged, and never leave the store. The signature panel shows the signatory,
the certificate and the signing time.

**R-XVI-068** Where signing is enabled and signing fails, the payslip is **not
issued unsigned**. The run raises a blocking exception naming the certificate
problem. *A silently unsigned document is worse than a missing one, because it
looks the same.*

**R-XVI-069** Verification and signing are independent: a tenant may enable
either, both or neither.

---

## XVI.9 Localisation and language

**R-XVI-070** A template declares one or more languages. Every caption, section
heading, static block and numeric format has a value per declared language, and
gate P6 refuses publication with any caption missing.

**R-XVI-071** An employee's payslip language resolves as: the employee's declared
language preference → the template's default → the tenant's default. It is
resolved at **issue** and pinned on the payslip, so re-rendering does not silently
change the document's language.

**R-XVI-072** Localisation covers **numbers, dates and amounts in words**, not
only strings:

- Money grouping is **Indian by default** (`₹1,50,000.00`), with Western grouping
  available per template.
- `net_pay_in_words` is generated in the payslip's language, using the locale's
  own scale words (crore/lakh where the locale uses them), and is **stored**
  (R-XVI-004).
- Dates render in the template's declared format.
- A right-to-left language, where one is configured, flips the document model's
  layout direction rather than requiring a separate template.

**R-XVI-073** A bilingual payslip — captions in two languages on the same line —
is a template property, not a separate template.

**R-XVI-074** Fonts covering the declared scripts are embedded in the PDF, and
publish gate P7 renders the sample in every declared language, so a script whose
glyphs overflow the layout is caught before the first employee sees it.

---

## XVI.10 Explainability

**R-XVI-075** Every figure on the payslip resolves through `Explain`. A worked
rendering, for the two figures currently recovered by regex:

```
Paid days                                                            25.00
LOP days                                                              2.00

  Source          period_settlements #90233 (frozen 01-Aug-2026 06:14,
                  attendance generation 2)  — layer 4, spine S10
  Scheduled       27.00 days   (weekly-off pattern "TG 6-day" v3,
                                holiday calendar "TG 2026" v2)
  Present         23.00 days
  Paid leave       2.00 days   (EL 1.00, CL 1.00 — leave type versions pinned)
  Unpaid leave     2.00 days   → LOP
  Day-part        half-day penalty 0.00 days
                  (rule HYD-LATE-003 v4 matched on 0 days this period)
  Basis           payable_days · day-length policy WORKING
                  proration policy "Collections proration" v2
  Factor          25 / 27  (exact; stored as numerator 25, denominator 27)

  Not derived from any text. These are stored columns on the payslip,
  written by pipeline stage 5 and stage 8.
```

**R-XVI-076** The payslip's own header explains itself: which template version
rendered it, which run version produced it, which snapshot digest pinned its
inputs, and — where the payslip supersedes another — what changed, line by line.

**R-XVI-077** A payslip that is part of a **reopened** period states that fact,
with the reopen reason, because a register drawn from that period will show it and
the employee is entitled to the same information (Part XIV R-XIV-020).

---

## XVI.11 Configuration surface

| # | What an administrator changes | Where | Scope | Dated |
|---|---|---|---|---|
| 1 | Create, copy, edit, version and publish a payslip template | Payslip Designer | any S3.1 scope, incl. salary structure | Yes |
| 2 | Which sections appear, in what order, and their `visible_when` | Designer | template version | Yes |
| 3 | Per-field visibility, caption, order, group, subtotal, detail treatment, masking, numeric format, emphasis | Designer | template version | Yes |
| 4 | Per-component visibility including `show_if_non_zero`, caption override, group override | Designer | template version | Yes |
| 5 | Payslip groups: code, label, order, subtotal caption, whether the group enters gross | Payslip Groups | tenant | Yes |
| 6 | Page setup, margins, orientation, logo, colours, fonts | Designer | template version | Yes |
| 7 | Declared languages, per-language captions, number and date formats, amount-in-words locale | Designer | template version | Yes |
| 8 | Payslip number format, reset period and padding | Code Sequences | any scope | Yes |
| 9 | Publication timing (`on_lock` / `on_disbursement_confirmed` / `on_pay_date` / `manual_release`) | Payslip Policy | any scope | Yes |
| 10 | Which channels are enabled, and the body template for each | Payslip Policy + Notification Templates | any scope | Yes |
| 11 | Self-download policy, as an SPX condition | Payslip Policy | any scope | Yes |
| 12 | Password protection on/off and the password derivation rule | Payslip Policy | any scope | Yes |
| 13 | Acknowledgement mode, SLA, reminders and escalation | Payslip Policy + Workflow | any scope | Yes |
| 14 | Verification block on/off, token lifetime, what the public endpoint discloses | Payslip Policy | any scope | Yes |
| 15 | Digital signature on/off, certificate, signatory name and designation | Payslip Policy | legal entity | Yes |
| 16 | The `message_block` announcement text and its date range | Payslip Messages | any scope | Yes |
| 17 | Which YTD figures appear, and whether YTD appears at all | Designer | template version | Yes |
| 18 | Which leave types appear in the leave block and in what order | Designer | template version | Yes |
| 19 | Whether the employer-contribution and CTC blocks appear, and their captions | Designer | template version | Yes |
| 20 | Retention and archival class for payslip documents | Retention Classes | tenant | Yes |

**The Section 127 standard, applied.** *"For Telangana Collections employees
only, show HRA only when non-zero, rename 'Special Allowance' to 'City
Compensatory Allowance' on the payslip, add a Telugu column, and hide the
employer-contribution block"* is: one template version, scoped to
`state=TG + department=COLLECTIONS`, four property changes, one effective date,
one approval — **and no code**. Today it is an edit to
`resources/views/payslip-pdf.blade.php`, deployed to every tenant.

---

## XVI.12 Data model

`payslips`, `payslip_lines` and `calculation_traces` are specified in
**Part XXVIII C7** and referenced here, not restated.

### XVI.12.1 Columns added to `payslips`

`payslip_number` VARCHAR(60) NN · `payslip_template_config_version_id` BIGINT
UNSIGNED NN FK RESTRICT · `language_code` VARCHAR(10) NN ·
`scheduled_payable_days`, `present_days`, `paid_leave_days`,
`unpaid_leave_days`, `weekly_off_days`, `holiday_days`, `ot_hours`,
`comp_off_earned`, `comp_off_availed` DECIMAL(9,4) NN DEFAULT 0 ·
`night_shift_count` SMALLINT UNSIGNED NN DEFAULT 0 ·
`proration_factor_numerator`, `proration_factor_denominator` BIGINT NN ·
`proration_basis_code`, `day_length_policy_code` VARCHAR(40) NN ·
`total_reimbursement`, `ctc_actual` DECIMAL(18,4) NN DEFAULT 0 ·
`statutory_applicability_json` JSON NN ·
`document_hash` BINARY(32) NULL · `is_preview` TINYINT(1) NN DEFAULT 1 ·
`published_at` DATETIME(3) NULL ·
`supersedes_payslip_id` BIGINT UNSIGNED NULL FK self RESTRICT ·
`supersession_reason_code` VARCHAR(40) NULL ·
`supersession_note` VARCHAR(1000) NULL ·
`legacy_calc_note` TEXT NULL *(migration only; never parsed, never written by v3)*.

Additional indexes: `IX(tenant_id, payslip_number)` UNIQUE,
`IX(tenant_id, employment_id, published_at)`,
`IX(tenant_id, document_hash)`.

### XVI.12.2 Columns added to `payslip_lines`

`caption_snapshot` VARCHAR(120) NN (R-XVI-027) ·
`payslip_group_code` VARCHAR(40) NN ·
`display_sequence` SMALLINT UNSIGNED NN ·
`detail_visibility_code` VARCHAR(40) NN DEFAULT `show_detail` ·
`full_amount` DECIMAL(18,4) NULL (the pre-proration amount, so the LOP invariant
`RC-08` is checkable from the lines alone) ·
`lop_amount` DECIMAL(18,4) NULL ·
`unit_code` VARCHAR(20) NULL (`days | hours | count`, for `quantity`) ·
`ytd_amount` DECIMAL(18,4) NULL (the YTD for this component as at issue).

### XVI.12.3 `cfg_payslip_templates` — cfg payload

`template_code`, `name`, `description`, `page_size_code`, `orientation_code`,
`margin_top/right/bottom/left_mm`, `default_language_code`,
`languages_json`, `number_format_code`, `date_format_code`,
`amount_words_locale_code`, `logo_document_id` FK RESTRICT, `theme_json`,
`applies_to_run_type_codes_json`, `max_pages`, `continuation_policy_code`,
`signature_enabled`, `verification_enabled`.
**Keys.** UQ `(config_version_id)`, `(tenant_id, template_code, config_version_id)` ·
IX `(tenant_id, template_code)`.
**Policy.** Tenant-owned · versioned; immutable once published ·
**`ON DELETE RESTRICT` from every payslip** · audit `full` · PII `none` ·
retention `R-PERM`.

### XVI.12.4 `cfg_payslip_template_sections` — A3

`config_version_id` FK RESTRICT, `section_key`, `section_kind_code`,
`sequence_no`, `is_visible`, `visible_when` TEXT (SPX), `heading_json`
(per-language), `layout_json`, `column_count`, `show_subtotals`,
`subtotal_caption_json`.
**Keys.** UQ `(tenant_id, config_version_id, section_key)`,
`(tenant_id, config_version_id, sequence_no)`.

### XVI.12.5 `cfg_payslip_template_fields` — A3

`config_version_id` FK RESTRICT, `section_key`, `field_key` (a payslip fact
path, validated by gate P1), `sequence_no`, `visibility_code`
(`show|hide|show_if_non_zero|show_if`), `visible_when` TEXT,
`caption_json` (per-language), `masking_rule_code`, `numeric_format_code`,
`decimal_places`, `negative_style_code`, `zero_style_code`, `emphasis_code`,
`subtotal_codes_json`.
**Keys.** UQ `(tenant_id, config_version_id, section_key, field_key)`,
`(tenant_id, config_version_id, section_key, sequence_no)`.

### XVI.12.6 `cfg_payslip_template_components` — A3

Per-component overrides for a template.
`config_version_id` FK RESTRICT, `component_code`, `visibility_code`,
`visible_when` TEXT, `caption_json`, `payslip_group_code_override`,
`display_sequence_override`, `detail_visibility_code`, `subtotal_codes_json`.
**Keys.** UQ `(tenant_id, config_version_id, component_code)`.

### XVI.12.7 `payslip_ytd_lines` — A3

**Purpose.** The YTD figures **as at issue**, stored (R-XVI-042).

`payslip_id` FK RESTRICT, `line_kind_code` (`component|statutory|aggregate`),
`ref_code` (component code, scheme code, or `gross|deductions|net|taxable|tax`),
`amount` DECIMAL(18,4), `periods_included` SMALLINT UNSIGNED,
`source_payslip_ids_hash` BINARY(32) (proves which payslips were summed).
**Keys.** UQ `(tenant_id, payslip_id, line_kind_code, ref_code)` ·
IX `(tenant_id, payslip_id)`.
**Policy.** Tenant-owned · immutable with its payslip · audit `event-only` ·
PII `sensitive` · retention `R-PAY-8Y`.

### XVI.12.8 `payslip_deliveries` — A4

`payslip_id` FK RESTRICT, `channel_code`, `target_masked` VARCHAR(200),
`attempt_no`, `state_code`, `provider_message_id`, `failure_code`,
`failure_detail`, `queued_at`, `sent_at`, `delivered_at`, `read_at`,
`document_id` FK RESTRICT NULL, `link_expires_at`, `is_password_protected`.
**Keys.** UQ `(tenant_id, payslip_id, channel_code, attempt_no)` ·
IX `(tenant_id, state_code, queued_at)`, `(tenant_id, payslip_id)`.
**Policy.** Tenant-owned · append-only · audit `event-only` ·
PII `sensitive` (target is stored masked) · retention `R-PAY-8Y`.

### XVI.12.9 `payslip_acknowledgements` — A4

`payslip_id` FK RESTRICT, `acknowledged_by_user_id` FK RESTRICT,
`employment_id` FK RESTRICT, `mode_code`, `acknowledged_at`, `ip_address`,
`user_agent`, `channel_code`, `document_hash` BINARY(32),
`signature_document_id` FK RESTRICT NULL, `otp_verified` TINYINT(1),
`voided_at`, `void_reason_code`.
**Keys.** UQ `(tenant_id, payslip_id, acknowledged_by_user_id)` ·
IX `(tenant_id, employment_id, acknowledged_at)`.
**Policy.** Tenant-owned · append-only; voided, never deleted · audit `full` ·
PII `sensitive` · retention `R-PAY-8Y`.

### XVI.12.10 `payslip_access_log` — A4

`payslip_id` FK RESTRICT, `actor_user_id` FK RESTRICT, `subject_employment_id`
FK RESTRICT, `access_kind_code` (`view|download|print|export|api_read`),
`channel_code`, `is_self` TINYINT(1), `permission_code`, `data_scope_code`,
`ip_address`, `user_agent`, `occurred_at`.
**Keys.** IX `(tenant_id, payslip_id, occurred_at)`,
`(tenant_id, actor_user_id, occurred_at)`,
`(tenant_id, subject_employment_id, occurred_at)`.
**Policy.** Tenant-owned · append-only · **partitioned monthly** ·
audit: this **is** an audit table, and it also writes to the ledger (spine S14)
for third-party access · PII `sensitive` · retention `R-PAY-8Y`.

### XVI.12.11 `payslip_verifications` — A4

`payslip_id` FK RESTRICT, `token` VARCHAR(64), `document_hash` BINARY(32),
`status_code` (`valid|superseded|cancelled`), `issued_at`, `expires_at`,
`revoked_at`, `verify_count`, `last_verified_at`.
**Keys.** UQ `(token)`, `(tenant_id, payslip_id)` ·
IX `(document_hash)`.
**Policy.** Tenant-owned; the token is globally unique because the endpoint is
unauthenticated · append-only with counters · audit `event-only` ·
PII `none` (the record holds no personal data, R-XVI-065) ·
retention `R-PAY-8Y`.

### XVI.12.12 Retention, PII and tenancy summary

Every table above carries `tenant_id NOT NULL`, participates in its unique
indexes, is applied by the persistence layer, and is never hard-deleted.
`payslip_verifications.token` is the sole globally unique key, by design, and
carries no personal data.

---

## XVI.13 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/payslips` | `payslip.view` + data scope | Cursor-paginated; self-scope for an employee resolves from the session |
| `GET` | `/payslips/{uuid}` | `payslip.view` + data scope | Document model as JSON; **writes an access-log row** |
| `GET` | `/payslips/{uuid}/pdf` | `payslip.download` + data scope | Signed, expiring URL; access-logged |
| `GET` | `/payslips/{uuid}/lines` | `payslip.view` + data scope | Typed lines with captions and traces |
| `GET` | `/payslips/{uuid}/explain/{field}` | inherits the payslip's policy | Spine S15 |
| `GET` | `/payslips/{uuid}/ytd` | `payslip.view` + data scope | The stored YTD as at issue |
| `GET` | `/payslips/{uuid}/deliveries` | `payslip.admin` | |
| `POST` | `/payslips/{uuid}/deliveries` | `payslip.distribute` | Re-send on a named channel; idempotency key required |
| `POST` | `/payslips/{uuid}/acknowledge` | self only | Subject from the session; mode per policy |
| `GET` | `/payslips/{uuid}/access-log` | `payslip.audit.view`, and **self** for one's own | R-XVI-062 |
| `POST` | `/payroll/runs/{uuid}/payslips/publish` | `payslip.publish` | Bulk issue for a locked run; idempotent |
| `POST` | `/payroll/runs/{uuid}/payslips/distribute` | `payslip.distribute` | Bounded, per-channel, returns per-item results |
| `GET` | `/payslip-templates` | `payslip.template.view` | |
| `POST` | `/payslip-templates` | `payslip.template.manage` | Draft version |
| `PUT` | `/payslip-templates/{uuid}/versions/{n}` | `payslip.template.manage` | Sections, fields, component rules |
| `POST` | `/payslip-templates/{uuid}/versions/{n}/preview` | `payslip.template.manage` | Renders against a real or synthetic payslip; no persistence |
| `POST` | `/payslip-templates/{uuid}/versions/{n}/validate` | `payslip.template.manage` | Gates P1–P10 |
| `POST` | `/payslip-templates/{uuid}/versions/{n}/publish` | `config.publish` | |
| `GET` | `/payslip-templates/resolve` | `payslip.template.view` | `?employment=&as_of=&run_type=` → winner + precedence trail |
| `GET` | `/payslip-groups` · `POST` | `payslip.template.manage` | Tenant-defined groups |
| `GET` | `/verify/{token}` | **none** — public, rate-limited | Returns validity, entity, period, status. **No salary, no name, no personal data** (R-XVI-065) |

**R-XVI-078** `GET /payslips/{uuid}` and every download route write a
`payslip_access_log` row **before** returning the payload, and a failure to write
the audit row fails the request. Access to sensitive data is not served ahead of
its audit.

**R-XVI-079** No payslip endpoint accepts an employee identifier for a
self-service caller. The subject is the session's employment (spine S13).

---

## XVI.14 Events

| Event | When | Payload |
|---|---|---|
| `payslip.issued` | Publication | Payslip uuid, number, run version, template version, document hash |
| `payslip.superseded` | A correction issues | Old and new uuid, reason, line-level diff summary |
| `payslip.delivery_queued` / `.sent` / `.delivered` / `.read` / `.failed` | Per channel per attempt | Channel, provider id, failure code |
| `payslip.acknowledged` | Acknowledgement captured | Mode, actor, document hash |
| `payslip.acknowledgement_voided` | Supersession | Reason |
| `payslip.accessed_by_third_party` | A non-self view | Actor, role, permission, scope — a **security-relevant** event, forwarded to the audit ledger |
| `payslip.bulk_exported` | Bulk export | Scope, count, requester |
| `payslip.verification_checked` | Public endpoint hit | Token, result, source IP class (no personal data) |
| `payslip.signature_failed` | Signing failure | Certificate problem; blocks issue (R-XVI-068) |
| `payslip_template.published` | Publish | Version, scope, impacted employment count |
| `payslip_template.resolution_changed` | A publish changes the winner | Affected employments |

Consumed:

| Event | Source | Effect |
|---|---|---|
| `payroll.run.locked` | Part XIV | Enables publication per the timing policy |
| `payroll.payout.confirmed` | Part XIV | Triggers publication under `on_disbursement_confirmed` |
| `payroll.run.superseded` | Part XIV | Supersedes the affected payslips and reissues per policy |
| `payroll.period.reopened` | Part XIV | Marks affected payslips as belonging to a reopened period (R-XVI-077) |
| `salary.component.published` | Part XV | A caption or grouping change affects **future** payslips only; issued payslips keep their pinned captions |
| `employment.language_preference_changed` | Employee module | Affects future issues only |

---

## XVI.15 Migration implication

| SmartPRS 2 artefact | Comes across as | Transformation | Does not come across |
|---|---|---|---|
| `payslips` rows | `payslips` + `payslip_lines`, marked `migrated` | The `earnings`/`deductions` JSON is exploded into typed lines via the per-tenant component mapping (Part XV §XV.10). **The largest single unknown in the migration is whether that JSON is structurally stable across all historical revisions** (Part XXVIII D-XXVIII-02); budget a per-revision analysis pass | Nothing is recomputed |
| `payslips.calc_note` | `legacy_calc_note`, displayed verbatim as an archival note on a migrated payslip | **Never parsed.** Where structured Paid Days / LOP cannot be recovered from a migrated settlement, the payslip is marked `figures_incomplete` and the attendance block is suppressed rather than showing a regex result | The regex (§XVI.1.2) |
| `payslips.gross` | `gross_earnings`, with the **stored** value preserved | Where the migrated lines' `in_gross` sum differs from the stored gross — which it will, wherever reimbursements exist — the discrepancy is **recorded on the payslip**, not silently reconciled (Part XIV R-XIV-079) | The second definition of gross |
| `payslips.net_words`, `pdf_path` | Not migrated (never written) | `net_pay_in_words` is generated for migrated payslips at import, in the tenant's default language, and stored | — |
| `payslips.emailed_at` | A synthetic `payslip_deliveries` row of channel `email`, state `sent`, where the column is populated | | — |
| `payslips.ack_*` | `payslip_acknowledgements` | Direct, preserving actor, timestamp and IP | — |
| The Blade template | A seeded `MIGRATED-DEFAULT` template artefact reproducing the current layout | Built once by Ametecs, published per tenant, then editable. **Its printed "Gross (A+B)" becomes a named subtotal**, and the true gross is printed alongside it, so gate P5 passes and the change is visible to the customer during rehearsal | The template as a file |
| `payslip_dl_mode` / `payslip_dl_depts` / `payslip_dl_emps` | A `payslip_self_access` policy artefact | The four modes become SPX conditions | The four literals |
| `payslip_show_ytd` | A template field-visibility setting | | — |
| Fabricated back-fill payslips | **Quarantined**, excluded from YTD and every register, per Part XIV **D-XIV-08** | | |
| `voucher-pdf` | A template of the same section catalogue | The route currently 500s because the view does not exist (DEF-P12) | The broken route |

**R-XVI-080** A migrated payslip is **re-rendered from its stored lines, never
recomputed.** Where its lines are incomplete, the template suppresses the affected
section and the payslip states that it is a migrated record with limited detail.
*It is better for an employee to see "this figure is not available for records
before July 2026" than to see a number the system invented.*

---

## XVI.16 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | No run exists for the period | **No payslip exists.** There is no theoretical-slip fallback. *SmartPRS 2 prints a full-CTC, un-prorated slip with no warning (BR-PAY-202)* |
| 2 | The run is in preview | Rows exist with `is_preview = 1`: unnumbered, unrendered, invisible in ESS |
| 3 | Net is ₹0.00 | A valid payslip; it prints, with zero-value styling per the template |
| 4 | Every earning line is zero (unpaid leave for the whole period) | A valid payslip stating zero payable days; statutory blocks print their zero determinations |
| 5 | A component is renamed after issue | The issued payslip keeps its pinned caption (R-XVI-027) |
| 6 | A template is superseded after issue | Re-rendering uses the template pinned on the payslip (R-XVI-024) |
| 7 | The template's logo document is deleted | `ON DELETE RESTRICT`; deletion refused while any published template references it |
| 8 | The payslip exceeds the declared page count | A continuation page per `continuation_policy_code`; a run-time warning; never truncated |
| 9 | A declared language has a missing caption | Caught by gate P6 at publish; at run time a missing caption falls back to the default language **and raises a warning**, never prints a key |
| 10 | An employee has no email and no mobile | Delivery records state `suppressed` with the reason; ESS remains available; the operator sees the suppression list |
| 11 | Email bounces | Retried with backoff, then dead-lettered and surfaced on the operations dashboard |
| 12 | WhatsApp template is not approved by the provider | Delivery fails with the provider reason; the payslip remains available in ESS; the failure is visible, not silent |
| 13 | A signed link is shared onward | It expires; each retrieval is authorised afresh; the access log shows the actor of every retrieval |
| 14 | Password-protected PDF and the employee has forgotten the derivation | The delivery message states the rule; the password itself is never stored in plaintext or re-sent in the same channel |
| 15 | An employee acknowledges, then the payslip is superseded | The acknowledgement is **voided** (recorded), the new payslip requires a fresh acknowledgement, and the employee is notified |
| 16 | A period is reopened after payslips were issued | Issued payslips remain valid facts; corrected ones supersede; both state the reopen (R-XVI-077) |
| 17 | Two employment windows in one period (a rejoiner) | One payslip, lines traced per window, the period block stating both windows |
| 18 | A supplementary run for a locked period | A supplementary payslip referencing the original, never a replacement (R-XVI-040) |
| 19 | An arrears-only run | A payslip whose lines all carry `arrear_for_period_code`, with the arrears block listing target periods |
| 20 | A final settlement | Resolves the settlement template where published; includes terminal components and recoveries |
| 21 | A manager without salary visibility opens a team payslip | Denied by data scope, fails closed, and the **attempt** is logged |
| 22 | An unauthenticated caller hits `/verify/{token}` for a superseded payslip | `superseded`, with the current version's token; **no salary and no name** disclosed |
| 23 | A verification token is guessed or brute-forced | Rate limiting plus a 64-character token; repeated failures are alerted |
| 24 | Digital signing certificate expires mid-run | Blocking exception; **no unsigned payslip is issued** (R-XVI-068) |
| 25 | The document hash of a stored PDF does not match the record | Serving is refused and an operator alert is raised — the archival integrity check |
| 26 | A confidential component appears on a payslip a payroll operator may not see | Masked per role; the total remains correct (Part XV edge case 28) |
| 27 | A payslip is requested for an employment in another tenant | Not found. Tenancy is applied by the persistence layer, and a cross-tenant isolation test exists for every payslip endpoint |
| 28 | Bulk export of 20,000 payslips | Bounded, queued, permissioned, single audit record, delivered as a signed expiring archive |

---

## XVI.17 Tests

| ID | Asserts |
|---|---|
| `T-XVI-01` | **No prose parsing.** Static analysis: no regular expression is applied to any payslip text field anywhere in `Domain\*`. Paid days and LOP are read from typed columns |
| `T-XVI-02` | **Paid days / LOP correctness.** Full attendance prints the true payable and zero LOP — not `daysInMonth / 0` by accident; partial attendance prints the settlement's exact figures |
| `T-XVI-03` | **One gross.** The printed gross equals `SUM(lines where in_gross = 1)` equals `payslips.gross_earnings` equals the run total contribution equals the wage-register figure, on a payslip containing reimbursements. Gate P5 refuses a template that prints a different "gross" |
| `T-XVI-04` | **Grouping from definition.** A component named "Variable Dearness Allowance" with `payslip_group_code = A_FIXED` prints in the fixed group; a component named "Basic Adjustment" with `B_VARIABLE` prints in variable. The nine keywords have no effect because they do not exist |
| `T-XVI-05` | **No unread flag printed.** A field bound to a value no engine stage writes cannot be placed on a template (gate P1); the statutory applicability printed equals the determination the engine used |
| `T-XVI-06` | **Rate snapshot.** For any issued payslip, the pack version, structure version, every component version, every formula version, the proration policy and the settlement are all reachable, and the payslip can be re-derived to the paise from them alone |
| `T-XVI-07` | **Trace completeness.** Every line and every derived header figure has a stored trace; a payslip with a missing trace fails `RC-13` and the run aborts |
| `T-XVI-08` | **Caption pinning.** Rename a component; re-render a payslip issued before the rename; the old caption appears. Re-render one issued after; the new caption appears |
| `T-XVI-09` | **Template resolution.** Five candidates across company, state, branch, employee category and salary structure resolve by S3.2 weight; the precedence trail is returned; a tie blocks publication |
| `T-XVI-10` | **Template pinning.** Publish a new template; re-render an old payslip; the old template is used |
| `T-XVI-11` | **`show_if_non_zero`.** A zero HRA line is hidden and the subtotal is unchanged; a non-zero one appears |
| `T-XVI-12` | **`include_in_total_but_hide_detail`.** The component contributes to its subtotal and to gross while its line does not print; `RC-01` still holds |
| `T-XVI-13` | **Masking.** A masked account number is masked in PDF, ESS, mobile, email and API; the unmasked value never leaves the server for an unauthorised viewer |
| `T-XVI-14` | **PDF immutability.** The PDF is generated once; a second download returns a byte-identical document with a matching hash |
| `T-XVI-15` | **Supersession.** A corrected run supersedes; the old payslip re-renders marked superseded; the new one names what it supersedes; the acknowledgement is voided and the employee notified |
| `T-XVI-16` | **YTD scoping.** YTD excludes other tenants, other companies, superseded versions, preview runs and quarantined migration rows, and de-duplicates across lots. A second lot in the same period does not double-count |
| `T-XVI-17` | **YTD immutability.** A payslip's YTD does not change when a later period is run |
| `T-XVI-18` | **No second statutory implementation.** The employer-contribution block reads stored lines; injecting a divergent value into the stored line changes the printed value, proving nothing is re-derived |
| `T-XVI-19` | **One gratuity.** The accrual on the payslip, the provision register and the settlement all resolve to the same formula version and produce the same figure for the same employee |
| `T-XVI-20` | **Access audit.** Every view, download, print, export and API read writes an access-log row; a failure to write the row fails the request |
| `T-XVI-21` | **Self-scope.** A self-service payslip request carrying another employee's identifier is refused; the subject resolves from the session; **no name-based identity fallback exists** |
| `T-XVI-22` | **Cross-tenant isolation.** Generated for every payslip, template, delivery, acknowledgement and verification endpoint |
| `T-XVI-23` | **Verification discloses nothing.** `/verify/{token}` returns validity, entity, period and status; a fuzz test asserts no salary figure, name, code or other personal datum appears in any response |
| `T-XVI-24` | **Verification revocation.** Supersession flips the token to `superseded` and names the current token |
| `T-XVI-25` | **Signing.** A failed signature blocks issue; no unsigned payslip is produced when signing is enabled |
| `T-XVI-26` | **Localisation.** A Telugu-and-English bilingual template renders both captions, Indian grouping, and amount-in-words in the payslip's language; the glyphs fit the declared page |
| `T-XVI-27` | **Publish gates.** Each of P1–P10 fails on a purpose-built invalid template and passes on the valid one |
| `T-XVI-28` | **No fallback slip.** Requesting a payslip for a period with no run returns 404, not a computed document |
| `T-XVI-29` | **Delivery lifecycle.** Queued → sent → delivered → read, with a bounce path to dead-letter, all recorded and visible |
| `T-XVI-30` | **Password derivation.** The declared rule produces the expected password for a fixture employee; the password is not present in the delivery message body |
| `T-XVI-31` | **Numbering.** Concurrent issue of 500 payslips allocates 500 distinct sequential numbers under contention; no reuse after supersession |
| `T-XVI-32` | **Migrated payslip.** Renders from stored lines only; the legacy note displays verbatim and is never parsed; an incomplete migrated record suppresses the attendance block and says so |

---

## XVI.18 Open items — PRODUCT OWNER DECISION REQUIRED

**D-XVI-01 · Default publication timing.**
*Recommendation: `on_disbursement_confirmed`, so an employee never receives a
payslip for money that has not moved. Trade-off: customers who pay by cash or by
a bank channel with no confirmation feed will see payslips held indefinitely, so
`on_lock` must remain a per-scope option and the operations dashboard must show
payslips awaiting a confirmation that is not coming.*

**D-XVI-02 · Is the public verification endpoint enabled by default?**
*Recommendation: off by default, enabled per legal entity. It is a genuine
differentiator — a bank can verify a payslip without the employee — but it is an
unauthenticated endpoint keyed on a token that appears on a printed document, and
a customer must opt in knowing that. When enabled, R-XVI-065's disclosure limit
is not configurable.*

**D-XVI-03 · Digital signature: platform key store or customer HSM?**
*Recommendation: support both — a platform-managed key store for SaaS tenants who
have no PKI, and an eSign/HSM provider integration for those who do. Private keys
never leave either. Trade-off: platform-managed signing means Ametecs holds a key
that signs a customer's financial document, which some customers will refuse and
some auditors will question; the HSM path removes that but requires the customer
to operate one.*

**D-XVI-04 · Default acknowledgement mode.**
*Recommendation: `explicit_confirm` (a deliberate action, actor, time, IP and
document hash), with `explicit_confirm_with_otp` available where a customer needs
a stronger record. Trade-off: `view_is_acknowledgement` is frictionless and is
weak evidence; OTP is strong and will not be completed by a meaningful fraction
of field staff. `not_required` must remain available because many customers do
not want the obligation at all.*

**D-XVI-05 · WhatsApp delivery: document or link?**
*Recommendation: link only, through a signed expiring URL, with the document
never attached to the message. Trade-off: a link is a worse experience on a poor
connection; an attached salary document sits in a third-party message store
outside the tenant's control, and the access log cannot see who opened it.*

**D-XVI-06 · Password protection default.**
*Recommendation: on for email and messaging channels, off for ESS and mobile
(which are already authenticated). Derivation rule default: employee code plus
date of birth in a declared format — configurable, and stated in the delivery
message. Trade-off: any deterministic derivation from data a colleague may know
is weak; a per-employee secret is stronger and generates support load. Both are
offered; the default is the weaker one because the alternative for most tenants
today is an unprotected attachment.*

**D-XVI-07 · Does an employee see who viewed their payslip?**
R-XVI-062 says yes. *Recommendation: keep it, showing role and date rather than
individual identity by default, with full identity available per tenant policy.
Trade-off: full identity is maximally transparent and can expose a legitimate
investigation; role-and-date is a reasonable middle. Either way the full record
exists in the audit ledger.*

**D-XVI-08 · How many templates should ship seeded?**
*Recommendation: three — a standard monthly payslip, a final-settlement
statement, and a bilingual variant — all as ordinary editable tenant artefacts.
Trade-off: more seeded templates speed onboarding and risk becoming a de facto
fixed set; three covers the cases every customer needs while making it obvious
that a fourth is a configuration task.*

**D-XVI-09 · Retention of the rendered PDF versus re-rendering from lines.**
R-XVI-029 stores the PDF. *Recommendation: store it for the full `R-PAY-8Y`
class, because the hash-backed archival copy is what makes verification and legal
defence possible. Trade-off: storage cost at scale — roughly one document per
employee per period for eight years. Mitigated by an archival tier after the
first 24 months, with the hash and the lines always online so a re-render is
always possible even if the archived original is being retrieved.*

**D-XVI-10 · What does a migrated payslip with incomplete lines show?**
R-XVI-080 suppresses the affected section and says so. *Recommendation: keep it.
Trade-off: employees will notice that pre-migration payslips carry less detail,
and support must be briefed. The alternative — printing a plausible number the
system did not compute — is what SmartPRS 2 does with its regex, and it is the
defect this part exists to remove.*

---

## XVI.19 Compliance with the seven laws

| Law | How this part complies | Test |
|---|---|---|
| **1 — No business literal in code** | Sections, groups, captions, numbering format, self-access policy, password rule, acknowledgement mode, language and layout are all data. No component name, group keyword or document string is a literal in `Domain\*` | `T-XVI-04`, `T-XVI-27`, `T-XVI-31` |
| **2 — No schema change at runtime** | Templates are rows, not columns; a custom block binds an existing entity field (Part X), never a new column | Part XIV `T-XIV-31` applies |
| **3 — Nothing overwritten, everything versioned and dated** | Templates are versioned artefacts; issued payslips are immutable and supersede; captions, template version and language are pinned at issue; acknowledgements are voided, never deleted | `T-XVI-08`, `T-XVI-10`, `T-XVI-15` |
| **4 — Every number explains itself** | Every line and every derived header figure carries a stored trace, reachable from ESS, mobile and (for an authorised viewer) the verification QR | `T-XVI-06`, `T-XVI-07` |
| **5 — Authorisation server-side, declarative, fails closed** | Permission plus data scope on every endpoint; self-scope from the session with no name fallback; masking applied server-side; the access log is written before the payload is served | `T-XVI-13`, `T-XVI-20`, `T-XVI-21` |
| **6 — Tenancy is structural** | `tenant_id` NOT NULL and in every unique index on every table here; the one globally unique key is a verification token carrying no personal data | `T-XVI-22` |
| **7 — Money is exact** | The payslip renders stored integer-derived decimals and computes nothing; the proration factor is stored as an exact rational; subtotals are sums of stored lines and are re-asserted by `RC-01`…`RC-08` | `T-XVI-03`, `T-XVI-12` |

### XVI.19.1 Requirement index

`R-XVI-001`–`009` the data contract, the stored fact set and traces ·
`010`–`013` the template artefact and the designer ·
`014`–`015` sections · `016`–`020` per-field and per-component control ·
`021` publish gates · `022`–`026` scoping and resolution ·
`027` caption pinning · `028`–`034` the rendered document, numbering and the
single gross · `035`–`040` publication, supersession and reissue ·
`041`–`049` YTD, CTC, leave and statutory blocks ·
`050`–`056` channels, access policy and password protection ·
`057`–`060` acknowledgement · `061`–`063` the view audit ·
`064`–`069` verification and signature · `070`–`074` localisation ·
`075`–`077` explainability · `078`–`079` API audit and self-scope ·
`080` migration fidelity.

**80 requirements · 10 new tables + 2 extended · 32 tests · 10 Product Owner
decisions.**
