# SMARTPRS 3 — MASTER REBUILD ARCHITECTURE & IMPLEMENTATION BLUEPRINT

**Ametecs India Private Limited**
**Status: DRAFT FOR PRODUCT OWNER APPROVAL**
**Prepared: September 2026**

---

## Document control

| | |
|---|---|
| Product | SmartPRS 3 |
| Owner | Ametecs India Private Limited |
| Approver | Product Owner |
| Basis | Forensic analysis of the SmartPRS 2 source repository (81,592 lines of PHP, 84 controllers, 422 routes, ~152 live tables) and the eight-document SmartPRS 2 reference dossier (89,977 lines) |
| Governing brief | The SmartPRS 3 Master Prompt, Sections 1–131 |
| Volume | 38 documents · 86,364 lines · 5.9 MB |
| Brief coverage | 124 of 131 sections FULL · 3 PARTIAL · 4 directive-satisfied · **0 gaps** |
| Approval gate | Sections 122 and 131 of the governing brief forbid implementation before this document is approved. **No SmartPRS 3 code has been written.** |

---

## What this document is

The governing brief asked for a forensic understanding of SmartPRS 2 and a
complete architecture for its replacement, before any code. That is what this is.

It was produced in two stages.

**Stage 1 — forensics.** Fourteen independent analyses read the SmartPRS 2 source
line by line and cross-checked every material claim in the existing reference
dossier against it. Where the documentation and the source disagreed, the source
won and the discrepancy was recorded. That work produced **19,468 lines of
evidence**: approximately 950 business rules, 494 defects, 200 security findings
and 1,100 derived requirements, each carrying a `file.php:line` citation.

**Stage 2 — the blueprint.** Thirty-five parts and three appendices, written
against a single binding architectural contract, with every design decision
traceable to the SmartPRS 2 behaviour that made it necessary.

The forensic analyses are included as an appendix folder. They are the evidence
base; where this blueprint asserts something about SmartPRS 2, that is where the
proof lives.

---

## The finding, in one page

SmartPRS 2 is not a codebase with architectural problems. It is a codebase in
which architecture was never the unit of decision. The evidence:

| | |
|---|---|
| Business logic in one file | `AppController.php` — 12,533 lines, 2 PHP methods, the entire user interface in PHP heredocs |
| Schema authority | Split: 49 migrations, ~49 tables created at runtime by controllers; 15 tables get a *different* schema from each authority; column types depend on which screens were opened first |
| Referential integrity | Zero foreign keys on business tables; an admin endpoint exists whose job is sweeping the resulting orphans |
| Tenancy | Enforced by a global scope filtering `users.company_id` — **a column that does not exist**. 1,375 hand-written query sites; 405 unfiltered |
| Authorisation | 62 role-string comparisons, **0** permission checks, 6 controls failing open by design comment, 78 authenticated routes with no server-side check |
| Error handling | **426 of 843 catch blocks (50.5%) destroy the exception entirely**; only 10.2% log or rethrow |
| Money | Float. A float comparison decides whether a loan instalment is deducted |
| Concurrency | 4 transactions, **0 locks**, 25 enumerated race conditions |
| Tests | 132 cases covering 18 of 422 routes (4.3%); 16 of 24 modules have none; **not one rupee of a generated payslip is asserted anywhere** |

And the consequences that are live in production today:

- A page load **fabricates eleven months of payroll** marked `paid`, permanently blocking real payroll for those months
- **Loss-of-pay leave is paid in full** — an ongoing salary overpayment
- **Loans are never recovered**, or are recovered forever
- **Commissions never reach a payslip** on a freshly migrated install
- A 22:00–06:00 shift becomes **two attendance days and two payroll months**
- An unconditional nightly job creates a **known-password administrator**
- The on-premise installer ships a **default credential the README publishes**
- **KYC, PCC and PAN documents sit on a publicly readable disk**
- **Update packages are unsigned** — a compromised release server is remote code execution on every customer's payroll server

None of these is exotic. Each is the kind of defect one cheap exact-value test
catches on the day it is written.

**The conclusion this blueprint supports:** the defects are architectural, not
incidental. Incremental repair does not converge, and SmartPRS 2 has already run
that experiment three times — `statutory_configs`, `PeriodResolver` and
`letters.pdf_path` were each built correctly and then bypassed by the
surrounding architecture.

---

## What SmartPRS 3 is

> A configurable HR, Payroll, Attendance, Workforce and Compliance Operating
> System for Indian businesses. One product core, one codebase, one release.
> Every customer's policy expressed as configuration, every number it produces
> explained on demand, and every statutory change delivered as signed content
> rather than a software upgrade.

The thesis is falsifiable, and Part VI §VI.16 tests it: for each of the twelve
customisation examples the governing brief gives, the blueprint shows the exact
artefact a consultant edits, in which screen, and what the stored configuration
looks like. **Twelve requests. Zero deployments.**

The whole architecture rests on **seven laws** (Spine §S1), each of which
corresponds to a confirmed systemic failure above, and each of which has a
mechanical CI gate rather than a policy statement:

1. No business literal in code
2. No schema change at runtime
3. Nothing overwritten — everything versioned and effective-dated
4. Every number explains itself
5. Authorisation is server-side, declarative, and fails closed
6. Tenancy is structural, not clerical
7. Money is exact

---

## How to read this

**If you have thirty minutes** — read this index, then Part II (the case for the
rebuild), then Part XXXIV §2 (the 26 decisions that block Phase 1).

**If you are approving the programme** — add Part III (vision), Part XXXII
(phasing) and Part XXXIII (risks, including the risks of *not* doing this).

**If you are the lead architect** — read `SPINE.md` first and completely. It is
the binding contract; every part conforms to it, and it wins any conflict. Then
Parts IV–IX, which are the platform. Then Appendix A, which records where the
parts diverge from each other and what was fixed.

**If you are implementing a module** — the spine, then Part IV, then your
module's part, then Part XXX for its tests.

**If you are the compliance owner** — Part XVII, and nothing else is urgent. Note
that no legal or statutory value is asserted as correct anywhere in this
document, deliberately; Part XVII §XVII.1 explains why and what you must verify.

---

## The parts

### Foundation

| # | Part | Lines |
|---|---|---|
| — | **`SPINE.md`** — the binding architectural contract | 480 |
| I | SmartPRS 2 understanding | 1,045 |
| II | Problems being solved | 1,273 |
| III | Product vision | 313 |
| IV | System architecture | 356 |
| V | Domain model | 1,351 |

### The platform engines

| # | Part | Lines |
|---|---|---|
| VI | Configuration architecture | 3,798 |
| VII | The universal rule engine | 4,057 |
| VIII | Formula engine | 3,422 |
| IX | Workflow engine | 3,601 |
| X | Employee schema engine | 2,593 |

### The domain

| # | Part | Lines |
|---|---|---|
| XI | Attendance and biometric architecture | 2,873 |
| XII | Work week, weekly off and holiday | 1,576 |
| XIII | Leave architecture | 2,067 |
| XIV | Payroll architecture | 1,739 |
| XV | Salary structure architecture | 1,358 |
| XVI | Payslip architecture | 1,217 |
| XVII | India statutory architecture | 4,100 |
| XVIII | Loans, advances, commission, incentives | 3,681 |
| XIX | Notifications, templates and automation | 2,255 |
| XX | Recruitment and employee lifecycle | 3,114 |
| XXI | Compliance command centre and the collections vertical | 3,199 |

### Platform services

| # | Part | Lines |
|---|---|---|
| XXII | Roles, security, data scope and tenancy | 4,116 |
| XXIII | Audit architecture | 1,957 |
| XXIV | API and integration architecture | 3,052 |
| XXVIII | Database architecture and ERD | 3,076 |

### Delivery

| # | Part | Lines |
|---|---|---|
| XXV | SaaS architecture | 2,039 |
| XXVI | On-premise architecture | 1,387 |
| XXVII | Licensing, editions and updates | 2,541 |
| XXXI | Deployment, observability and operations | 2,329 |

### Programme

| # | Part | Lines |
|---|---|---|
| XXIX | Migration from SmartPRS 2 | 4,210 |
| XXX | Test strategy and the golden test matrix | 2,111 |
| XXXII | Implementation phases and roadmap | 345 |
| XXXIII | Risk register | 180 |
| XXXIV | **Decisions required from the Product Owner** | 1,710 |
| XXXV | Requirement traceability matrix | 1,655 |

### Appendices

| # | Appendix | Lines |
|---|---|---|
| A | Cross-part reconciliation and consistency register | 547 |
| B | Reporting, dashboards, forms, UI, localisation, documentation | 3,855 |
| C | The overtime engine | 2,266 |
| D | The fourteen SmartPRS 2 forensic analyses (`analysis/`) | 19,468 |

---

## What is asked of the Product Owner

**Part XXXIV is the working document.** It consolidates approximately 330
decisions raised across the blueprint into three tiers, with the question stated
in one sentence a non-engineer can answer, the options and their consequences,
a recommendation, and who must be consulted.

- **Tier 1 — 26 decisions block Phase 1.** These change the data model, the
  commercial model, or carry a legal clock.
- **Tier 2 — 190 decisions gate a specific delivery track.**
- **Tier 3 — 53 set a shipped default and block nothing.**
- **27 questions are not the Product Owner's at all** — they are legal,
  compliance-professional, or the customer's, and Part XXXIV §5 routes them.

The decisions with the shortest fuse, needing an answer regardless of whether
the rebuild proceeds:

| | Why it cannot wait |
|---|---|
| Appoint a **named statutory compliance owner** | No rule pack can be signed without one; Phase 6 is blocked, and it is an appointment, not an engineering task |
| The **documents on a public disk** | Possible disclosure event, legal clock, independent of the rebuild |
| The **LOP leave overpayment** | Accruing now; quantify, then decide recover / write off / disclose |
| The **default on-premise credential** and the **nightly known-password admin job** | Audit the installed base; both are live security exposures |
| The **unsigned update channel** | Remote code execution on customers' payroll servers |
| **Do editions exist in SmartPRS 3**, and what did L1/L2 customers buy? | They were sold a differentiated product that is byte-identical; commercial and possibly contractual |

---

## Honesty notes

Three things a reader should know about how this document was assembled, because
a blueprint that hides its own seams is not trustworthy.

1. **It was written in parallel by many authors against one spine.** That
   produced genuine divergences — table names, ownership overlaps, one real
   contradiction. **Appendix A** records every one: 18 fixed in place, 16
   recorded for a human decision. It also found that three central registers
   (the table catalogue, the permission catalogue, the decision register) declare
   themselves complete and are not, and recommends generating them rather than
   maintaining them by hand. **That recommendation is now implemented.**
   `tools/generate-registers.py` parses all forty documents and writes four
   machine-generated registers into **`docs/registers/`** —
   [`REGISTER-TABLES.md`](registers/REGISTER-TABLES.md),
   [`REGISTER-PERMISSIONS.md`](registers/REGISTER-PERMISSIONS.md),
   [`REGISTER-DECISIONS.md`](registers/REGISTER-DECISIONS.md) and
   [`REGISTER-REQUIREMENTS.md`](registers/REGISTER-REQUIREMENTS.md). They are the
   **index**; the owning part is always the **specification**. Appendix A §A.7
   records what the generator found and what was corrected: **540 tables** (not
   162), **545 permission codes** of which **395 are uncatalogued** (not ~110),
   **360 decisions** (not 328) and **3,565 requirements**. Do not hand-edit a
   register; change the part and re-run the script.
2. **The traceability matrix was rebuilt three times**, and Part XXXV §XXXV.13
   records all three generations including a conclusion from the first that was
   wrong and is corrected in place. The lesson it draws — a citation index is not
   a coverage index — is kept in the document rather than quietly removed.
3. **Every migration figure is provisional.** No production schema dump has ever
   been taken from any SmartPRS 2 install, and ~49 tables exist only because
   application code created them. Part XXIX makes this a hard gate rather than a
   caveat, and specifies the discovery programme that must run before any
   migration estimate is credible.

**On legal values:** no rate, ceiling, slab, threshold or due date is asserted
anywhere in this document as legally correct. Every such value appears either as
"the value SmartPRS 2's code currently uses", with its source line, or as a
`<from rule pack>` placeholder to be populated and verified by Ametecs'
compliance owner against the primary statutory source.

---

## The recommendation

Approve the architecture, answer the twenty-six Tier 1 decisions, and start
Phase 1 — the platform foundation and, critically, the enforcement toolchain
that keeps the seven laws mechanically true.

Separately and immediately, fund the remediation of the SmartPRS 2 defects that
carry a security or legal clock. They should not wait for a multi-quarter
rebuild, and they are not competing for the same people.

---

*End of index.*
