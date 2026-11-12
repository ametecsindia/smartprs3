# PART XXI — COMPLIANCE COMMAND CENTRE AND THE COLLECTIONS VERTICAL

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Covers: brief Sections **78** (the Compliance Command Centre), **79** (the
collections and recovery vertical), **80** (module and feature entitlement),
**81** (the module pack concept), and **77** (dashboards), to the **123** (data
model) and **125** (module specification) standards.
Conforms to: `SPINE.md` — S1 (all seven laws), S3 (org model and scope), S4
(effective dating, draft/publish, versioned artefacts), S5 (**SPX**), S6 (rule
engine and the `Compliance` action catalogue), S8 (workflow), S9 (entity schema
engine), S12 (rule packs and the legal-values discipline), S13 (security), S14
(audit ledger), S15 (explainability), S16 (data conventions), S17 (API), S18
(deployment parity and signed distribution), **S19 #1 and #3 (the two things
this part exists to preserve)**.
Extends, and does not fork:
- **Part VI §VI.14** — feature and module entitlement. The mechanism
  (`feature_catalogue`, `entitlement_grants`, `tenant_feature_states`, the
  five identical enforcement points) is specified there and is **not restated**.
  §XXI.9 adds the one thing VI.14 assumes and does not define: the **module
  registry** itself.
- **Part VI §VI.11–VI.12** — configuration packages and Customer Policy Packs.
  §XXI.10 specifies the **module pack** as a Policy Pack with four additional
  powers, reusing the signing, slot, conflict and fork machinery unchanged.
- **Part VII §12 (RL-CMP-001…003)** — the generalised credential gate rules and
  the `credential.*` / `compliance.*` SPX namespaces.
- **Part XVIII §XVIII.9** — the compliance gate on money movement, its policy
  shape (`cfg_gate_policies`), its gate points and `compliance_gate_decisions`.
  §XXI.6 specifies the **item-type × payment-type matrix** that policy resolves
  from, and does not redefine the gate.
- **Part XVII §XVII.11** — the statutory obligation calendar
  (`statutory_obligations`, `statutory_filings`). §XXI.2 **projects** that
  register into the Command Centre; it does not duplicate or re-own it.
- **Part XXVIII §C11** — the six compliance tables are registered there in
  outline; §XXI.13 specifies them to Section 123 grade and adds the remainder.
- **Part V §C11 / §C4** — the ComplianceItem, GateDecision and PolicyDocument
  aggregates, and the Person/Employment aggregates that §XXI.8.2 extends with an
  engagement kind.
Evidence base: `analysis/F-statutory-compliance.md` (§1.9–1.11, §2, §3
BR-CMP-*, §4.14–4.15, §5.2, §6 DEFECT-F-08…F-21, F-38…F-44, §7 SEC-F-01…F-11,
§8, §9, §10 items 34–44); `analysis/N-product-business-modules.md` (§1.5.4,
§1.7, §1.8 in full, §1.10, §5.2, §6 D-04…D-13, §7 S-04…S-07, §9, §10, §11);
`analysis/H-recruitment-hrops-reports.md` (§1.17, §1.19, §3.11 NEW-DSH-101…105,
§9 dashboards verdict) for Section 77.

---

> ## ⛔ TWO ABSOLUTE RULES GOVERNING THIS PART
>
> **RULE 1 — NO ASSERTION WITHOUT EVIDENCE.** No compliance status, finding,
> count or verdict produced by SmartPRS 3 may be a literal, a default, a
> placeholder or an inference from the absence of data. Every assertion is
> derived from a stored, dated, attributable record, or it is reported as **"not
> assessed"**. This rule exists because SmartPRS 2 prints *"Disciplinary
> actions: 0 · Compliant"* from the literal string `'0'` with state `'ok'` and
> no data source at all (`ComplianceController.php:629`, `F-…` DEFECT-F-09), and
> reports six quarters of TDS returns as `Filed` from `$status = $i === 0 ?
> 'Pending' : 'Filed'` (`:1800`, DEFECT-F-07) — on documents a customer files
> with, or presents to, a regulator.
>
> **RULE 2 — NO STATUTORY VALUE IS ASSERTED HERE.** Every due date, retention
> period, validity period, certification hour count, notice period and window in
> this part is either **(a)** recorded as *"the value SmartPRS 2's code
> currently uses: `<value>` (`file.php:line`) — NOT VERIFIED AS LEGALLY
> CORRECT"*, or **(b)** written as `<from rule pack: CODE>` or `<tenant
> configuration>`. The IIBF 50/100-hour DRA training tracks, the RBI recovery
> agent directions, PCC validity and BGV re-verification cadences are all
> **content**, supplied by a signed rule pack or by tenant configuration, and
> verified by the named compliance owner (spine D-03) before publication. This
> part specifies the holes; it does not fill them.

---

## XXI.0 Purpose

This part decides five things.

1. **That compliance is one register, not thirteen screens.** Every obligation
   the platform tracks — a statutory filing due on the 15th, a recovery agent's
   DRA certificate expiring in 40 days, a code-of-conduct version awaiting
   acknowledgement, a biometric device's calibration certificate, an
   establishment's shops-and-establishments renewal — is **one row shape** in one
   register, with one state model, one owner, one evidence trail and one
   escalation ladder. SmartPRS 2 has six compliance registries, five of which
   are rows in a 460-line configuration array in one controller
   (`MasterController::def()`, `N-…` §1.8.2), each with its own screen, its own
   idea of validity, and no owner, no due date and no escalation at all.

2. **That there is exactly one definition of "is this valid?".** One service,
   `compliance_status(subject, item_type, as_of)`, consumed by the dashboard,
   the scorecard, the audit pack, the money gate, the alert digest and every
   report. SmartPRS 2 has **five divergent implementations of "is this agent's
   DRA valid?"** (`F-…` DEFECT-F-08), and the consequence is not theoretical:
   *"the same agent can be blocked from an incentive, reported COMPLIANT on the
   RBI audit PDF, and scored 0/25 on the scorecard — simultaneously."*

3. **How the collections and recovery vertical is packaged.** The as-is analysis
   establishes that of thirteen claimed industry capabilities, **six are
   genuinely vertical and seven are generic HR wearing an industry label**
   (`N-…` §1.8.2). This part draws that boundary precisely, puts the seven in
   the core where they sell to security agencies, staffing, logistics and
   facilities management unchanged, and puts the six in a **module pack** — with
   off-roll agents modelled properly as a first-class worker type rather than a
   parallel string-typed table.

4. **How a module is entitled and how a vertical is shipped.** A single module
   registry that the permission model, the entitlement gate, the licence, the
   price list and the navigation all read from — resolving the six mutually
   inconsistent module vocabularies the analysis found (`N-…` §1.7.1) — plus the
   **module pack**: a signed, versioned, installable bundle of configuration
   artefacts that contains **no PHP**.

5. **What a dashboard is for.** Not counts. **Actions.** A number with no action
   attached is a report, and reports have their own place. Every widget on every
   SmartPRS 3 dashboard resolves to a work queue a named role can clear.

It does **not** decide: the statutory calculation engine or the statutory filing
calendar (Part XVII), the money-movement gate mechanism (Part XVIII §XVIII.9),
the rule engine or SPX (Parts VII and VIII), the configuration lifecycle,
packages or entitlement enforcement (Part VI), the document store (Part X), the
workflow engine (Part IX), or report definitions in general (the reporting part).
Where this part needs one of those, it names the requirement it is conforming to
and moves on.

---

## XXI.1 What SmartPRS 2 does today, and why it must change

This is the justification for every design decision below, not a defect dump.
Each finding names the section that answers it. Citations are carried through
from `F-statutory-compliance.md` (F-) and `N-product-business-modules.md` (N-).

### XXI.1.1 Five divergent implementations of "is the DRA valid?"

This is the single most consequential finding in the compliance area, and it is
the reason §XXI.5 exists.

| # | Implementation | Rule it applies | Legacy fallback | Citation |
|---|---|---|---|---|
| 1 | `ComplianceController::draValid()` | the **latest** `dra_certs` row | **never** falls back to `employees.dra_*` if any row exists | `:339-369` |
| 2 | `ComplianceController::scoreFor()` | **any** verified, unexpired row | ignores legacy entirely | `:293-299` |
| 3 | `ComplianceController::agentAuditData()` | the **latest** row | **does** fall back to legacy on failure | `:563-576` |
| 4 | `MasterController::draGateWarning()` | **any** verified, unexpired row | no legacy | `:899-912` |
| 5 | `OffrollAgentController` | whether a **free-text field is blank** | n/a — there is no certificate, no status and no expiry | `:253` |

`F-…` DEFECT-F-08 states the outcome exactly: *"The same agent can be blocked
from an incentive, reported COMPLIANT on the RBI audit PDF, and scored 0/25 on
the scorecard — simultaneously."* DEFECT-F-12 adds that the audit report prints
two independent verdicts side by side using different rules over the same facts,
so *"a report reading `COMPLIANT · 35%` is reachable"*.

The asymmetry is not confined to DRA. The same file accepts
`pcc_status = 'submitted'` as compliant while requiring DRA to be `verified`
(`:579-586` vs `:293-313`), *"asymmetrically and undocumented"*
(`CONSOLIDATED-REQUIREMENTS` #46).

**Answered by:** §XXI.5 — one `compliance_status()` service, one
`valid_state_codes` declaration per item type, and a CI sweep that fails the
build on a second implementation.

### XXI.1.2 The RBI audit pack asserts facts it never checked

The per-agent *"RBI Recovery-Agent Compliance File"* is, correctly, identified by
the analysis as *"the single most defensible piece of vertical IP in the
product"* and *"the artefact the agency hands the bank"* (`N-…` §1.8.1). It is
also, as generated today, a document that makes assertions the system has not
verified.

| Finding | Evidence | Why it matters |
|---|---|---|
| **"Disciplinary actions: 0 · Compliant" is a literal.** The item prints the string `'0'` with state `'ok'` and has no data source of any kind | `ComplianceController.php:629`; `N-…` V-05 | A document presented as an RBI compliance file asserts a fact it never checked (`F-…` DEFECT-F-09, SEC-F-05) |
| **The "tamper-evident hash" is not tamper-evident.** `substr(sha256($ref.'\|'.$e->id.'\|'.today), 0, 16)` — deterministic from three public values, keyed with no secret, chained to nothing, and covering **none of the findings**. Two reports with different contents on the same day produce the same hash | `:644`; `N-…` V-04; `DISC-04` | *"A bank cannot verify a presented PDF, and Ametecs cannot prove what a given report said."* **No table stores an issued report** |
| **The footer claims a "hash-chained audit log"** the report never writes to or reads; generating an audit report is **not logged at all** | `ComplianceController` contains no `AuditTrail`/`Audit::` call | A false assurance printed on a regulatory document (`F-…` DEFECT-F-10) |
| **The Code-of-Conduct check degenerates.** Both `when()` clauses in the user lookup are conditional. For an employee with no linked user and no email — *"which is most field staff"* — the `user_id` filter is dropped and the query becomes *"has anybody in this tenant ever acknowledged?"*, true as soon as one person has. Worse, where `users.employee_id` does not exist **and** the employee has no email, the lookup has **no `where` clause at all** and returns the first user id in the table | `:598-611`; `N-…` V-06 | *"Every employee without an email is reported 'Acknowledged' on the RBI audit file."* (`F-…` DEFECT-F-11, **CRITICAL**) |
| **A revoked authorisation reads as compliant.** Validity tests `valid_to` only; `agent_authorizations.status` (`active｜expired｜revoked`) is never read | `:619`; `F-…` DEFECT-F-13 | A bank-revoked agent passes the bank's own compliance file |
| **A fully compliant agent tops out at 85.** The NDA leg is worth 15 and requires `letters.letter_type = 'nda'` with `status = 'signed'`; `letters-nda` has no backend definition, so no NDA can be created through the UI | `:312-318`; `N-…` V-08 | The score is not on a 0–100 scale for anyone |
| **A missing table awards points.** If `complaints` does not exist, +20 is awarded anyway; if `dra_certs` does not exist, 0 is awarded | `:325-327` (BR-CMP-003b) vs `:293-299` | Absence of evidence is treated in two opposite directions in one function |
| **The bulk variant misbrands.** `$first = $agents[0]` supplies brand and company for every agent in the document | `:426-433`; DEFECT-F-32 | Mixed-company bulk runs produce misbranded regulatory documents |
| **The authenticated sample renders real tenant branding over wholly fabricated findings**, including a hard-coded `score => 92` | `:483-538`; SEC-F-11 | Indistinguishable from a genuine report except for a banner |
| **The complaint window is a fixed trailing 90 days** while the code comment claims *"current financial quarter approx"* | `:623`; `N-…` V-07 | The measure does not mean what the code says it means |
| **Every threshold in the checklist is a literal** — 12–13 items, 6 groups, references minimum 2, bulk cap 150 | `:556-629`, `:592-594`, `:414` | *"different banks and future RBI directions will differ"* (`N-…` req. 32) |

**Answered by:** §XXI.7 — evidence-derived findings only, `not_assessed` as a
first-class outcome, a persisted issued-pack register, and an integrity hash
computed **over the findings**.

### XXI.1.3 Five of six compliance registries are rows in a configuration array

*"Five of the six registries that carry the industry story — `dra-certs`,
`agent-auth`, `bgv`, `complaints`, `escalations`, `code-of-conduct` — are rows in
a 460-line config array in one controller and self-creating flat tables with
string columns. There is almost no vertical logic; there is vertical naming over
generic CRUD."* (`N-…` §1.8.2.)

The mechanism is `MasterController::def()` (57 registry types) plus
`ensureCols()` (`:496-577`), which creates **every column `nullable()` with no
index**. The consequences are confirmed in source (`F-…` §2.2):

- **Nothing is indexed.** `dra_certs.employee_id`, `dra_certs.expiry`,
  `bgv.next_due`, `code_of_conduct_ack.user_id` and `agent_earnings.agent_id` are
  unindexed on any runtime-built install (DEFECT-F-38). The nightly scan
  additionally runs `SELECT DISTINCT tenant_id FROM employees` with no index
  guarantee, every morning.
- **No foreign keys, no NOT NULL, no defaults, no uniqueness** — *"a `dra_certs`
  row can exist with a null `employee_id` and a null `status`."*
- **`migrate:fresh` produces a different schema from an upgraded install.** The
  `bgv` migration has no `next_due` column and the scan is guarded by
  `Schema::hasColumn('bgv','next_due')`, so **BGV re-verification alerts silently
  never fire until somebody opens the BGV master screen once** (DEFECT-F-14).
- **`code_of_conduct_ack` has two incompatible definitions** — the migration
  keys on `employee_id NOT NULL`, the runtime path keys on `user_id` — reconciled
  by a live, MySQL-only `ALTER TABLE … MODIFY employee_id … NULL` inside a
  swallowed try/catch (DEFECT-F-15; `N-…` DISC-07).

**Answered by:** §XXI.3 (one typed item-type model replacing the registry
array), §XXI.13 (real tables with real constraints and indexes), and spine Law 2
(no DDL outside migrations).

### XXI.1.4 Off-roll agents cannot be scanned, because they were never modelled

`offroll_agents.dra` and `.pcc` are declared `$t->string(...)` — free text, **no
expiry column** (`OffrollAgentController.php:44-45`; `N-…` V-02). The analysis is
explicit about what this changes: *"Changes the fix from 'extend the scan' to
'model the data first'."* (`N-…` DISC-08.)

Around that omission sits a complete parallel person model — its own KYC
document slots, bank and UPI details, four payout shapes, an earnings ledger with
approve/reject, and a token-secured public live-earnings page — which is
simultaneously *"the strongest vertical asset in the product"* (`N-…` §1.8.1)
and outside **every** compliance mechanism: `scan()`, `scoreFor()`, `draValid()`
and `agentAuditData()` all key on `employees`, and `ComplianceController.php`
contains **no reference to `offroll_agents` anywhere in the file** (`N-…` V-01).

For an Indian recovery agency this is the population the RBI directions most
directly target. It is also untaxed (`agent_earnings` has no tax column, no 194H
treatment — `F-…` DEFECT-F-44), unbilled (*"an agency can run 500 off-roll agents
on a ₹1,000/month 25-seat Starter plan"* — `N-…` §1.5.5), stored on the
**public** disk (`->store('offroll-docs','public')`, SEC-F-02/S-04), and exposed
through a 48-character bearer token in a URL that never expires and never rotates
(SEC-F-08/S-05).

**Answered by:** §XXI.8.2 — off-roll agents become an **engagement kind** on the
core person model, inheriting the entity schema engine, the document store, the
compliance register, the gate and the money-movement machinery by construction.

### XXI.1.5 A migration destroyed data on the strength of a documentation claim

`2026_08_27_000100_widen_employee_dra_pcc_status.php` widened
`dra_status`/`pcc_status` from `ENUM` to `VARCHAR(20)` and **rolled the stored
value `'overdue'` back to `'pending'`**, justifying the deletion on the grounds
that *"Compliance Alerts already derives overdue from PCC Deadline / PCC
Expiry"* (`:26-29`, `:60-66`).

**It does not.** `employees.pcc_deadline` is selected by `scan()` at `:57` and
**has no reader anywhere** (`F-…` DEFECT-F-16, NEW-F-14). The catalogue records
the same claim as `BR-CMP-702` *"IMPLEMENTED"* and it is not
(`F-…` §8 rows 6–7), and the associated `BR-CMP-703` — *"missing PCC by its
deadline causes an automatic ₹1,000 deduction"* — describes a rule that **does
not exist in code at all**.

The lesson this part takes from it is structural, not procedural: a derived
compliance state must be **computed by one named service that the whole product
demonstrably calls**, so that "is it derived?" is answerable by a test rather
than by a comment in a migration.

**Answered by:** §XXI.5 (one service, CI-enforced), §XXI.17 (the migration of
`'overdue'` rows, which must be **recovered from audit history where it survives
and reported as unrecoverable where it does not**).

### XXI.1.6 The compliance PII endpoint is unguarded, and evidence sits on the public disk

| Finding | Evidence |
|---|---|
| `ComplianceController::alerts()` has **no role guard**. It returns names, codes, companies, **email addresses**, certification types and expiry dates for every employee in the tenant, to **any authenticated user**. `canManage` is returned as a UI hint only. `?days=` has **no upper bound** (`?days=100000` returns every record with any date). The catch block returns **HTTP 200 with the raw exception message** | `:152-168`; `F-…` SEC-F-01, DEFECT-F-18 |
| KYC and compliance documents — PAN, address proof, **PCC certificates**, DRA certificates, signed agreements — are written with `->store('offroll-docs','public')` under `'visibility' => 'public'`, with `public_path('storage')` symlinked. The auth-guarded `serveDoc()` route is bypassed by the direct URL | `OffrollAgentController.php:450`; `config/filesystems.php:39-43`, `:73`; `F-…` SEC-F-02; `N-…` S-04 |
| Money gates **fail open by design**: `draValid()` returns `['ok' => true, 'note' => 'DRA check unavailable']` on any exception, and each of the three gate call sites is wrapped in a swallowing try/catch | `ComplianceController.php:363-366`; `RequestController.php:1391-1393`; `OffrollAgentController.php:259-261`; SEC-F-04 |
| The DRA gate fires at `RequestController.php:1371`, **before** the "you are not the approver" check at `:1397`, so a non-approver can probe any employee's DRA status and expiry date from the 422 error text | DEFECT-F-31, SEC-F-09 |
| Code-of-Conduct text is `kb_topics WHERE category = 'Code of Conduct'` with **no tenant filter** — one tenant's policy text is every tenant's legal undertaking | `CodeOfConductController.php:101-108`; SEC-F-06 |
| The acknowledgement record is one row per user, **overwritten** on re-acknowledgement: no version of the text, no history, no IP, no user agent, no signature | `:150-176`; SEC-F-07, BR-CMP-016 |

**Answered by:** §XXI.7.1 and §XXI.7.3 (private evidence, signed expiring URLs,
retention classes), §XXI.6 (a gate that never fails open — cross-referencing
R-XVIII-146), §XXI.8.6 (versioned, tenant-owned, per-version acknowledgement with
full history), §XXI.14 (every endpoint policy-bound, every window bounded).

### XXI.1.7 What must be preserved — this is not a rewrite of a bad idea

Spine S19 names two items in this area that the rebuild must not lose, and the
analysis is emphatic about both.

| Preserve | Why | Where it goes |
|---|---|---|
| **The compliance-to-money gate** — `off`/`warn`/`block`, per tenant, at three money points | *"The most sophisticated vertical rule in the product"* and *"the only implemented example of the thing SmartPRS 3 is being built to generalise. Treat it as the reference case for the rule engine."* (`N-…` §1.8.1, §1.10 #10) | Generalised: Part XVIII §XVIII.9, Part VII RL-CMP-001; the item-type × payment-type matrix here in §XXI.6 |
| **The RBI agent audit pack** | *"The product's clearest differentiator and the reason a bank-facing agency buys it."* (`N-…` §1.10 #8) | Rebuilt with every D-06 defect fixed: §XXI.7 |
| **The nightly scan / bucket / digest pattern** | *"The scan/bucket/digest pattern is right; the item types, thresholds, sources and windows must become configuration."* (`F-…` §9) | §XXI.3.8 (ladders as configuration) + §XXI.2.7 |
| **The tri-state gate setting itself** | A per-tenant `off｜warn｜block` severity is exactly the right shape and is kept verbatim | `cfg_gate_policies.severity_code` |
| **The off-roll live-earnings page** | *"A genuinely humane piece of product design for a low-trust, low-literacy workforce."* (`N-…` §1.10 #9) | §XXI.8.2, with an expiring, rotatable, revocable token |
| **The availability bias** | *"A licence check must never take a client's HR system down."* The bias is correct; the silence is not | §XXI.6: gates fail **closed**; *alerts and digests* fail soft but **loudly**, with a counter and an operator alert |

### XXI.1.8 Findings → answering section

| Finding | Severity | Answered in |
|---|---|---|
| DEFECT-F-08 five DRA implementations | CRITICAL | §XXI.5 |
| DEFECT-F-11 CoC check degenerates | CRITICAL | §XXI.5.3, §XXI.8.6 |
| DEFECT-F-09 / SEC-F-05 fabricated findings | HIGH | §XXI.7.5 |
| DEFECT-F-10 / V-04 fake tamper-evidence | HIGH | §XXI.7.6 |
| DEFECT-F-12 two contradictory verdicts | HIGH | §XXI.5, §XXI.7.4 |
| DEFECT-F-13 revoked authorisation passes | HIGH | §XXI.8.3 |
| DEFECT-F-14 BGV alerts inactive on fresh installs | HIGH | §XXI.13 (real migration), §XXI.19 T-XXI-31 |
| DEFECT-F-15 / DISC-07 two CoC schemas | HIGH | §XXI.8.6, §XXI.13 |
| DEFECT-F-16 `'overdue'` data destroyed | HIGH | §XXI.1.5, §XXI.17 |
| DEFECT-F-18 / SEC-F-01 unguarded PII endpoint | HIGH | §XXI.14 |
| SEC-F-02 / S-04 evidence on the public disk | HIGH | §XXI.7.1, §XXI.7.3 |
| SEC-F-04 gates fail open | MEDIUM | §XXI.6 |
| DEFECT-F-19 scan covers resigned staff; three phantom columns | MEDIUM | §XXI.3.2 (applicability as SPX) |
| DEFECT-F-20 early return kills two alert sources | MEDIUM | §XXI.2.3 |
| DEFECT-F-21 Carbon-version-dependent bucketing | MEDIUM | §XXI.3.8 |
| DEFECT-F-38 registers unindexed | MEDIUM | §XXI.13 |
| DEFECT-F-39 no digest throttle or dedup | LOW | §XXI.2.7 |
| DEFECT-F-40 `ScanCompliance` has no error handling | MEDIUM | §XXI.2.7, §XXI.15 |
| DEFECT-F-44 off-roll earnings untaxed | HIGH | §XXI.8.2 (cross-ref Part XVIII) |
| V-01…V-10 vertical defects | mixed | §XXI.8 throughout |
| N- D-04 runtime DDL | CRITICAL | §XXI.13, spine Law 2 |
| N- D-08/D-09 dead edition and plan gating | HIGH | §XXI.9 |
| NEW-DSH-101…105 dashboards | MEDIUM | §XXI.11 |

**We are not replicating any of these defects.** Each is named at the design
decision it caused.

---

## XXI.2 The Compliance Command Centre *(brief Section 78)*

### XXI.2.1 What it is

**R-XXI-001** SmartPRS 3 MUST provide a **Compliance Command Centre**: a single
operational register in which every compliance obligation the platform tracks —
statutory, people-level, entity-level or device-level — appears as **one row with
one shape**, filterable, assignable, escalatable and exportable, regardless of
which module raised it.
*Test:* a PF ECR filing due on the 15th, an agent's DRA certificate expiring in
40 days, an unacknowledged code-of-conduct version, an establishment's S&E
registration renewal and a biometric device's calibration certificate all appear
in one list, sorted by due date, with the same columns.
*Rationale — SmartPRS 2 has six registries with no due date, no owner and no
escalation between them, and "no compliance calendar at all" (`F-…`
DEFECT-F-42, §5.2 item 23).*

The Command Centre is a **read model over typed registers**, not a thirteenth
register. Each obligation is written by exactly one owner, and the Command Centre
projects them. This is deliberate: spine S16 forbids a table serving two
features, and Part XVII already owns statutory filings with a payload the people
register cannot carry (challan references, BSR codes, revision numbers, covered
run versions).

```
        WRITE OWNERS                                READ MODEL
  ┌──────────────────────────────┐
  │ statutory_obligations   (XVII)│──┐
  │  + statutory_filings          │  │
  ├──────────────────────────────┤  │      ┌──────────────────────────────┐
  │ compliance_items        (XXI) │──┼─────►│  compliance_register         │
  │  + compliance_evidence        │  │      │  one row shape · one state   │
  ├──────────────────────────────┤  │      │  vocabulary · one owner       │
  │ policy_acknowledgements (XXI) │──┤      │  model · one escalation      │
  ├──────────────────────────────┤  │      │  ladder                       │
  │ employee_documents      (X)   │──┘      └──────────────────────────────┘
  │  (expiry-bearing only)        │                     │
  └──────────────────────────────┘          ┌──────────┴───────────┐
                                            ▼                      ▼
                                    dashboards (§XXI.11)   audit pack (§XXI.7)
                                    alerts · gate · score · reports · API
```

**R-XXI-002** Every projected source MUST declare, in a registered
**projection adapter**, how its rows map onto the Command Centre row shape:
subject, item type, due date, owner, state, risk, evidence and escalation. Adding
a source is a platform release; the adapter is a declaration, not a query written
into the Command Centre.
*Test:* a projection-conformance test asserts that every registered adapter
produces a complete row for a sample of its own rows, with no null in a
non-nullable projected column.

**R-XXI-003** The Command Centre MUST be **queryable "as at" a date**
(spine Law 3): *"what was outstanding on 31-Mar-2027?"* is answerable, because
the audit question a bank asks is always retrospective.
*Test:* the register at a past date excludes items raised after it and shows the
state each item held on that date.

### XXI.2.2 The row — the eight facts, plus two

The brief specifies seven facts; the analysis forces two more (`risk` is
required by the RBI pack's verdict logic, and `evidence` must distinguish
*present* from *verified*). Every Command Centre row carries all ten.

| Fact | Meaning | Source |
|---|---|---|
| **Compliance item** | *What* obligation — the item type and the specific instance (`DRA-CERT`, certificate `IIBF/2024/114552`) | `cfg_compliance_item_types` + the instance row |
| **Applicable entity** | *Who or what* it attaches to: tenant, legal entity, establishment, branch, employment, engagement (on-roll or off-roll), vendor, device, asset, client portfolio | `subject_kind_code` + `subject_id` (never a name) |
| **Due date** | *By when*, derived by the type's due-date rule; `NULL` only where the type declares `periodicity = event_driven` and no trigger has fired | `due_on` |
| **Owner** | *Who must act* — a resolved person, not a role label. An unassigned obligation is itself an exception | `owner_user_id` + `owner_role_key` |
| **Status** | One of the ten states in §XXI.2.4. Never free text | `lifecycle_state_code` |
| **Risk** | The consequence weight if unmet: `low｜medium｜high｜critical`, from the type, overridable per instance with a reason | `risk_level_code` |
| **Evidence** | `none｜attached｜verified｜rejected｜waived` — **"attached" is not "verified"** | derived from `compliance_evidence` |
| **Escalation** | Current rung of the type's ladder, when it fires next, and to whom | `escalation_rung`, `next_escalation_at` |
| **Gate exposure** | Which money and operational gates this item currently blocks or warns | derived from `cfg_gate_policies` (§XXI.6) |
| **Assessment basis** | `evidence｜derived｜not_assessed` — how the status was arrived at | `assessment_basis_code` |

**R-XXI-004** `assessment_basis_code` MUST be present on every row and MUST be
rendered wherever the status is rendered. A status of `not_assessed` is
**never** collapsed into `compliant` for display, for scoring, for the gate or
for a report.
*Rationale — this single column is what makes `F-…` DEFECT-F-09 and DEFECT-F-11
structurally unreachable: a check that could not run cannot be silently reported
as a pass.*

### XXI.2.3 Two sources of obligations, one register

The distinction matters commercially as well as technically, and the brief asks
for it to be explained clearly.

| | **Rule-pack-derived** | **Tenant- or pack-defined** |
|---|---|---|
| **Origin** | Arrives inside a signed statutory rule pack published by Ametecs (spine S12, Part XVII §XVII.4) | Created as tenant configuration in the Configuration Studio, or installed by a module pack (§XXI.10) |
| **Examples** | PF ECR, ESI contribution, PT payment per state, TDS deposit, quarterly 24Q/26Q, LWF remittance, annual returns, state registers and returns, minimum-wage compliance, bonus and gratuity obligations | DRA certification, PCC, BGV, code-of-conduct acknowledgement, POSH constitution and annual return, training completion, document expiry, agent authorisation, licence and certification expiry, insurance, vendor agreement expiry |
| **Who may edit the definition** | **Nobody in the tenant.** Pack content is signed; a tenant may only bind it (which establishment, which registration) and may enhance never reduce (spine S12) | Tenant administrator or consultant, through the ordinary artefact lifecycle (Part VI §VI.3) |
| **Due-date rule** | Declared by the pack as a `due_rule` expression; recomputed until the obligation is `prepared` (R-XVII-127) | Declared by the item type as an SPX `Date` expression over the period, the issue date or the trigger event |
| **Materialisation** | Generated per establishment × head × period by R-XVII-126 | Generated per subject × period by §XXI.3.4 |
| **Write owner** | `statutory_obligations` (Part XVII) | `compliance_items` (§XXI.13) |
| **Updates on pack upgrade** | Future undue obligations follow the new rule; past obligations keep the rule that applied then, recorded on the row | Not applicable — tenant configuration is versioned by S4 |
| **May a tenant add one?** | No. A new statutory obligation is pack content, verified by the named compliance owner (spine D-03) | **Yes — this is the point.** §XXI.3.11 adds a firearms licence with no code and no release |

**R-XXI-005** A tenant MUST NOT be able to create, edit or delete a
rule-pack-derived obligation *definition*; it MUST be able to bind it, assign its
owner, attach evidence, record its filing and waive an instance with a reason and
an authorising officer.
*Test:* an attempt to edit a pack-derived obligation type through the tenant API
returns `403 pack_owned_artefact`.

**R-XXI-006** A tenant-defined obligation MUST NEVER be able to reduce a
statutory obligation, reclassify it as not applicable, or suppress its
appearance in the register. Suppression is a **waiver**, which is a recorded,
authorised act (R-XVII-130).
*Rationale — spine S12's layering rule, and `F-…` SEC-F-05, where the product
tells a customer their returns are filed when nothing was filed.*

**R-XXI-007** The register MUST be complete even when one source fails. A source
whose adapter errors contributes a **visible degraded marker** —
*"Statutory obligations could not be loaded"* — and never an empty section.
*Rationale — `F-…` DEFECT-F-20: an early return in `scan()` at `:47-49` kills the
`dra_certs` and `bgv` passes entirely when two `employees` columns are absent, so
an install using only the structured register gets **no alerts at all**, silently.*

### XXI.2.4 The state model

Ten states. The vocabulary is a reference table (spine S16 — no database `ENUM`),
but the **set** is a platform constant: an item type selects the subset it uses
and may relabel a state for its own screens; it may not invent an eleventh
state, because the gate, the score, the escalation ladder and the audit pack all
reason over this vocabulary.

| State | Meaning | Entered by |
|---|---|---|
| `not_applicable` | The type's applicability expression evaluates false for this subject on this date. **A computed state with a recorded reason, never a blank row** | the applicability evaluator |
| `upcoming` | Materialised, due date in the future, outside the type's `attention_window` | materialisation |
| `due` | Inside the attention window, or the due date has arrived and the obligation is unmet | the daily sweep |
| `pending` | Action has begun: a renewal task exists, a submission is drafted, an application is lodged with the issuing body | the owner, or workflow start |
| `submitted` | The subject or the owner has supplied evidence; **not yet verified by anyone** | the owner or the subject |
| `paid` | A payment obligation has been discharged with a recorded reference | recorded payment |
| `filed` | A return or a form has been lodged with the authority, with a recorded reference | recorded filing act |
| `acknowledged` | The authority or counterparty has acknowledged, with an acknowledgement reference or receipt document | recorded acknowledgement |
| `verified` | A person holding the verification permission has checked the evidence and accepted it | the verification workflow |
| `overdue` | Past due, and not in a satisfying state | time-driven, by the sweep |

Two additional **terminal markers** are carried as separate flags rather than as
states, because they can apply to several of the above and collapsing them into
the vocabulary would make the gate ambiguous:

| Marker | Meaning |
|---|---|
| `waived` | The obligation was excused for this instance, with a reason, an authorising officer and an expiry for the waiver. **A waived item is reported as waived, never as verified** |
| `superseded` | A renewal has produced a successor instance; the old instance is retained, never deleted (spine Law 3) |

**R-XXI-008** `verified`, `filed`, `acknowledged` and `paid` MUST be reachable
**only** by a recorded human act or an authenticated integration response, each
carrying its evidence. There is no computed, inferred or default satisfying
state.
*Test:* no code path sets any of these four states without an actor id and an
evidence reference. *Rationale — `F-…` DEFECT-F-07: SmartPRS 2 emits six
quarters as `Filed` from a ternary on a loop index.*

**R-XXI-009** `overdue` MUST be **derived by the sweep from the due date and the
current state**, and MUST NOT be a stored value a migration can silently reset.
The derivation MUST be exercised by a regression test that fails if it is
removed.
*Rationale — `F-…` DEFECT-F-16 exactly: a migration destroyed the stored
`'overdue'` value on the written justification that the derivation existed, and
it did not.*

#### Transitions and who may perform each

```
                       ┌───────────────┐
                       │ not_applicable│◄──── applicability evaluator (system)
                       └───────────────┘
                              ▲ (re-evaluated on any assignment change)
                              │
   materialise ──► upcoming ──► due ──► pending ──► submitted ──► verified
                       │         │         │            │            │
                       │         │         │            └──► rejected┘ (back to pending)
                       │         └─────────┴────────────► paid ─► filed ─► acknowledged
                       │
                       └──► (time) ──► overdue ──► (any satisfying state) ──► closed
                                          │
                                          └──► waived (permission + reason + authoriser)
```

| Transition | Who may perform it | Requires |
|---|---|---|
| → `not_applicable` | system only | the applicability expression, its version, and the evaluated reason recorded on the row |
| materialise → `upcoming` | system only | the type version and the due-date derivation trace |
| `upcoming` → `due` | system only (daily sweep) | — |
| `due` → `pending` | owner, or the subject where the type allows self-service | optional comment |
| `pending` → `submitted` | subject (self-service upload) or owner | evidence, where the type requires it |
| `submitted` → `verified` | a user holding `compliance.item.verify` **and** in scope of the subject | evidence present; **self-verification prohibited** where the subject is the actor |
| `submitted` → `rejected` → `pending` | as above | mandatory rejection reason, notified to the subject and the owner |
| → `paid` | a user holding `compliance.item.record_payment` | payment reference, date, amount, evidence document |
| → `filed` | a user holding `compliance.item.record_filing` | filing reference or acknowledgement, and (Part XVII) the reconciliation assertion having passed |
| → `acknowledged` | as filing, or an authenticated integration callback | acknowledgement number or receipt document |
| any → `waived` | a user holding `compliance.item.waive`, through the type's waiver workflow where one is declared | reason (mandatory), authorising officer, waiver expiry |
| any → `superseded` | system, on renewal | the successor instance id |
| reopen a closed item | a user holding `compliance.item.reopen` | mandatory reason; writes a new audit row; **never mutates the closed instance** |

**R-XXI-010** A decided verification step MUST be **immutable**. Re-deciding is
impossible; a correction is a new decision on a reopened item, and both are
retained (spine S8).
*Rationale — SmartPRS 2's acknowledgement rows are overwritten in place, and its
approval steps are re-firable (SEC-F-07; spine S8).*

**R-XXI-011** Self-verification MUST be prohibited by default, per type
configurable to `allow` only with an explicit, audited setting, and never
allowed where the type declares `evidence_class = regulatory`.
*Test:* an agent uploading their own PCC cannot move it to `verified`.

### XXI.2.5 `not_applicable` is computed, never blank

**R-XXI-012** For every (subject, item type) pair in scope, the register MUST
hold either an instance or an explicit `not_applicable` determination carrying
the applicability expression, its version, and the evaluated reason. Absence of a
row is **never** a compliant state.
*Test:* a newly hired telecaller shows `DRA-CERT · not_applicable · "role not in
field-agent set"`, not a gap; a newly hired field agent shows `DRA-CERT · due`.
*Rationale — `F-…` BR-CMP-003b awards 20 points when the `complaints` table does
not exist and 0 when `dra_certs` does not; the fix is to make absence
impossible rather than to choose which direction to guess in.*

### XXI.2.6 The operational views

The Command Centre presents one register through saved views. Views are
configuration (Part VI), not screens.

| View | Default audience | Default filter |
|---|---|---|
| **My obligations** | every user | `owner = me AND state IN (due, pending, submitted, overdue)` |
| **My team's obligations** | manager, MSS | subject in my data scope |
| **Expiring** | HR, compliance officer | `due_on BETWEEN today AND today + <window>` |
| **Overdue** | HR, compliance officer, admin | `state = overdue` ordered by risk then age |
| **Awaiting verification** | compliance officer | `state = submitted` |
| **Not assessed** | compliance officer | `assessment_basis = not_assessed` — **the queue that must be empty before an audit pack is issued** |
| **Blocking money** | payroll, finance | items currently producing a `block` verdict at any gate point |
| **Unassigned** | admin | `owner_user_id IS NULL` |
| **Waived** | auditor, admin | `waived = true`, with reason and authoriser |
| **By client / portfolio** | collections pack | grouped by `client_portfolio_id` |

**R-XXI-013** Every view MUST be scoped by the viewer's `data_scope` (spine S13)
on the server. A view is a filter over an already-scoped query, never a scope in
itself.
*Rationale — `F-…` SEC-F-01: `alerts()` returns the whole tenant's field force,
with email addresses, to any authenticated user, and the role is returned as a UI
hint.*

**R-XXI-014** Every date-window filter MUST have a configured **upper and lower
bound**. An unbounded window is rejected at the API boundary.
*Rationale — `?days=` has no upper bound (`:77-79`), and the window has no lower
bound either, so *"years-dead certificates"* are reported forever
(`CONSOLIDATED-REQUIREMENTS` L-12, `F-…` BR-CMP-005).*

### XXI.2.7 The sweep, the alert ladder and the digest

**R-XXI-015** A single scheduled **compliance sweep** MUST, per tenant, per day
(cadence configurable per tenant, with the send hour and timezone configurable —
`CONSOLIDATED-REQUIREMENTS` L-16): re-evaluate applicability for changed
subjects, materialise newly-due periodic obligations, advance `upcoming → due →
overdue`, fire the alert and escalation ladders, and emit its own health metrics.
*Test:* a tenant whose sweep has not completed in 26 hours raises an operations
alert visible on the operations dashboard (spine S18).

**R-XXI-016** The sweep MUST be **per-tenant fault-isolated**: a failure on
tenant 3 of 20 is recorded, alerted and does not prevent tenants 4–20 from being
processed, and a run that processed fewer subjects than it attempted MUST NOT
report success.
*Rationale — `CONSOLIDATED-REQUIREMENTS` L-7, L-8; `F-…` DEFECT-F-40:
`ScanCompliance` is three lines with no try/catch and returns `SUCCESS`
regardless.*

**R-XXI-017** The alert ladder MUST be configuration per item type: an ordered
list of day offsets, bucket boundaries, per-rung audience, per-rung channel and
per-rung template, with **both** an upper and a lower window bound.
Ladder evaluation is *"first run at or past the threshold, deduped on the ledger
key"*, never exact day equality, so a missed run catches up instead of losing the
reminder permanently.
*Rationale — `F-…` BR-CMP-005 (`WINDOW_DAYS = 30`, buckets `< 0` / `≤ 7` /
`≤ 30`, all literals); `CONSOLIDATED-REQUIREMENTS` L-9, L-10.*

**R-XXI-018** Day arithmetic in bucketing MUST be **signed and explicit**, with
the comparison written against an absolute instant, and MUST be covered by a
regression test asserting the sign.
*Rationale — `F-…` DEFECT-F-21: `$due->diffInDays($today) <= 30` omits the
`absolute` argument; under Carbon 3 a re-verification due two years out yields
−730 and is flagged `warn`, so **every future BGV shows "Attention" on the RBI
audit file, on some installs only**.*

**R-XXI-019** The digest MUST carry a configurable item cap **with a declared
overflow behaviour** (link to the full view, or split into several messages), a
configurable audience expressed as roles, named users or a distribution list, and
a dedupe window so an unchanged digest is not re-sent every morning until
somebody renews the certificate.
*Rationale — `F-…` BR-CMP-008 (07:30, top 25, three literal labels),
DEFECT-F-39 (no throttle, no dedup), DEFECT-F-41 (the subject line always says
"(DRA/PCC)" even when every row is a BGV re-verification).*

**R-XXI-020** Audience resolution MUST fail **closed** and MUST raise an
operator alert when it resolves to nobody.
*Rationale — `F-…` BR-CMP-009 fails closed correctly and **silently**: the
tenant simply never receives a digest and nobody knows.*

### XXI.2.8 Escalation

**R-XXI-021** Every item type MUST declare an **escalation ladder**: ordered
rungs, each with a trigger (days after due, or days in a state), a target
(the owner's manager at level N, a named role, a named user, or a group), an
action (`notify｜reassign｜raise_case｜raise_risk｜gate_tighten`), and an SLA.
Escalation is a scheduled job, not a person's memory (spine S8).
*Test:* an item 15 days overdue with a `critical` risk has notified the owner,
the owner's manager and the compliance officer, and has raised the gate severity
from `warn` to `block`, all without a human action, and each rung is on the audit
ledger.

**R-XXI-022** `gate_tighten` MUST be expressible as an escalation action, so
that *"warn for 30 days, then block"* is configuration rather than two policies.
*Rationale — SmartPRS 2's gate is a single per-tenant severity with no time
dimension; the graduated behaviour is exactly what a collections customer asks
for first (Part VII RL-CMP-002).*

---

## XXI.3 The compliance item type model

This is the mechanism that replaces `MasterController::def()`'s registry rows and
`ComplianceController`'s closed literal set of item types. It is a configuration
artefact under the ordinary S4 lifecycle: draft → testing → pending approval →
published → superseded, effective-dated, scoped, versioned, diffable and
approvable.

### XXI.3.1 The declaration

```
compliance_item_type
  item_type_code            stable code, never a name          e.g. DRA-CERT
  name, description, help_text
  origin                    core | rule_pack | module_pack | tenant
  obligation_kind           credential | acknowledgement | verification
                          | recurring_task | filing | training | registration
  subject_kind_codes[]      tenant | legal_entity | establishment | branch
                          | engagement | person | vendor | client_portfolio
                          | device | asset
  applies_expression        SPX Boolean                        §XXI.3.2
  applies_reason_template   text, rendered into not_applicable rows
  periodicity               one_off | on_issue | monthly | quarterly
                          | half_yearly | annual | financial_year
                          | rolling(n, unit) | event_driven
  period_anchor             calendar | financial_year | service_year
                          | issue_date | joining_date
  due_rule                  SPX Date expression                §XXI.3.4
  attention_window_expr     SPX Days — when it becomes `due`
  validity_source           issue_plus_period | explicit_expiry | perpetual
                          | periodic_occurrence
  validity_expr             SPX Duration or Date
  renewal_lead_expr         SPX Days — when the renewal task is raised
  owner_assignment          §XXI.3.5
  evidence_requirement      §XXI.3.6
  verification_workflow_key ref → cfg_workflows                §XXI.3.7
  alert_ladder              §XXI.2.7
  escalation_ladder         §XXI.2.8
  valid_state_codes[]       ** THE single definition of "valid" **  §XXI.5
  missing_record_is_valid   false — fixed; see R-XXI-041
  risk_level_code           low | medium | high | critical
  score_weight              Number
  score_partial_credit      map: state → fraction (default: valid=1, all else=0)
  gate_bindings[]           §XXI.6 — item type × payment/action type × severity
  retention_class_code      ref → cfg_retention_classes
  pii_class                 none | personal | sensitive
  self_service_allowed      bool — may the subject upload and submit?
  self_verification_allowed bool — default false (R-XXI-011)
  issuing_body_master_key   optional ref to a master list (IIBF, a police
                            commissionerate, a bank)
  reference_format_regex    anchored, timeout-bounded (spine S5.2 MATCHES)
  custom_fields             entity schema engine (spine S9) — the type may
                            declare its own fields (e.g. DRA `track`)
```

**R-XXI-023** The item type set MUST be **data**. DRA, PCC, BGV, code of
conduct, agent authorisation, POSH, training and any customer-invented type are
instances of this one model.
*Test:* a tenant administrator adds a new compliance item type with expiry
tracking, an alert ladder, an evidence requirement and a money gate, and it
appears in the register, the score, the gate and the audit pack **without a
deployment**. *Rationale — `F-…` req. 34, BR-CMP-006: the item type list is a
closed set of PHP literals; `N-…` §5.2 item 38.*

**R-XXI-024** `item_type_code` is the identity. **No behaviour anywhere in
SmartPRS 3 may be derived from a compliance item's name, label or display text.**
*Test:* the Law 1 static-analysis rule fails the build on a string comparison
against a compliance item name; renaming "DRA Certificate" to "IIBF Recovery
Agent Certificate" changes no behaviour.
*Rationale — the ESI contribution-period lock is a `stripos($k,'esi')` substring
match on a **display label**, so renaming a deduction silently loses the lock
(`F-…` §4.4); the same class of defect must not be re-created in compliance.*

### XXI.3.2 Applicability, as SPX

**R-XXI-025** Applicability MUST be an SPX Boolean over the spine S5.3
namespaces, evaluated **as at a date**, and MUST be re-evaluated whenever any
input to it changes (an assignment change, a stage change, an engagement change,
a transfer).
*Test:* moving an employee from Telecalling to Field Recovery on 12-Aug
materialises `DRA-CERT` and `PCC` from 12-Aug, and the prior period correctly
shows `not_applicable`.

Worked examples, all expressible with no code:

```sql
-- DRA certification: field-facing collections staff, on-roll or off-roll
org.business_unit = 'COLLECTIONS'
AND employee.category IN ('FIELD_AGENT', 'TELECALLER_FIELD')
AND engagement.kind IN ('on_roll', 'off_roll')
AND employee.stage <> 'PRE_HIRE'

-- Police clearance: everyone visiting a borrower's premises, in listed states
employee.category = 'FIELD_AGENT'
AND org.state IN policy.value('pcc_required_states')

-- POSH internal committee: establishments above the configured headcount
subject.kind = 'establishment'
AND agg.count('active_engagement', 'as_of', subject.establishment)
      >= <from rule pack: POSH-IC-HEADCOUNT-THRESHOLD>

-- Firearms licence: only where the client contract requires armed escort
engagement.client_portfolio.requires_armed_escort = TRUE
```

**R-XXI-026** The evaluation MUST use structured identifiers — codes and ids —
and MUST NOT use substring matching over free text.
*Rationale — `F-…` BR-STAT-701c: state and location matching is a loose
substring both ways, so "Andhra" matches "Andhra Pradesh" **and** any state whose
name contains it; DEFECT-F-19: the compliance scan's docblock claims
`is_field_agent`, `agent_code` and `portfolio` columns that **exist nowhere in
the schema**, so the promised field-agent scoping does not exist and every alert
row carries empty strings.*

**R-XXI-027** Applicability MUST be able to exclude by employment status. An
exited, resigned, absconded or inactive subject MUST NOT generate live
obligations unless the type explicitly declares a post-exit obligation (records
retention, for instance).
*Rationale — DEFECT-F-19: `scan()` has no status filter, so the daily digest
alerts on resigned and inactive staff.*

### XXI.3.3 Subject kinds

**R-XXI-028** An obligation MUST attach to its subject **by id**, with the
subject kind declared. There is no name join, no code join and no email join
anywhere in the compliance model.
*Rationale — `CONSOLIDATED-REQUIREMENTS` H-68: SmartPRS 2's hire, onboarding and
offer acceptance all key on **name**; `F-…` DEFECT-F-11's degenerate CoC lookup
is the same class of defect.*

| Subject kind | Typical obligations |
|---|---|
| `tenant` | data-protection registration, insurance |
| `legal_entity` | statutory registrations, annual returns, board filings |
| `establishment` | S&E registration, PF/ESI registration, POSH committee, registers and returns, fire and safety certificates |
| `branch` | local licences, lease and municipal permissions |
| `engagement` (on-roll **and** off-roll) | DRA, PCC, BGV, code of conduct, training, medical fitness, driving licence, agent authorisation |
| `person` | obligations that survive the engagement (records retention, post-exit undertakings) |
| `vendor` | agreement expiry, GST registration validity, insurance |
| `client_portfolio` | bank empanelment, service agreement, per-client compliance annexures |
| `device` | biometric device calibration and certification |
| `asset` | vehicle insurance, PUC, fitness certificate |

### XXI.3.4 Periodicity and due-date derivation

**R-XXI-029** The due date MUST be derived by an SPX `Date` expression declared
on the type (or, for pack-derived obligations, by the pack's `due_rule` under
R-XVII-127), and the derivation MUST be recorded on the instance so the row can
explain its own due date.
*Test:* "How was this due date calculated?" on any register row shows the rule,
its version, the substituted inputs and the arithmetic.

| Periodicity | Due-date shape | Example expression |
|---|---|---|
| `on_issue` | issue date + validity | `DATEADD(item.issued_on, type.validity_months, 'month')` |
| `monthly` | day-of-month, clamped | `CLAMP_DAY_TO_MONTH(policy.value('day'), period.month)` |
| `quarterly` | quarter end + offset | `DATEADD(EOMONTH(period.end), 30, 'day')` |
| `annual` / `financial_year` | anniversary or FY offset | `DATEADD(period.fin_year_start, 1, 'year')` |
| `rolling(n, unit)` | last satisfying event + n | `DATEADD(item.last_verified_on, 24, 'month')` |
| `event_driven` | on a domain event | raised by a rule engine `RAISE_ITEM` action (spine S6) |

**R-XXI-030** Where the due date falls on a non-working day, the type MUST
declare the adjustment (`none｜previous_working_day｜next_working_day`) resolved
against the applicable holiday calendar. It MUST NOT be assumed.

**R-XXI-031** A periodic obligation MUST be materialised **ahead of its due
date** by the type's `materialise_lead_expr`, so that the register shows what is
coming, not only what is late.
*Rationale — the brief's dashboard example "PF due in 4 days" is only possible if
the obligation exists before it is due.*

### XXI.3.5 Owner assignment

**R-XXI-032** Every obligation MUST resolve an owner. The type declares the
resolution strategy, evaluated in order, and the **first** strategy that resolves
a live user wins:

```
owner_assignment := ordered list of
    fixed_user(uuid)
  | role_in_scope(role_key, scope_dimension)      e.g. HR Manager at the branch
  | reporting_manager_at_level(n)
  | subject_self                                   (the agent owns their own PCC)
  | org_unit_attribute(attribute_key)              e.g. the establishment's
                                                   declared compliance officer
  | group(group_key)
```

**R-XXI-033** An obligation that resolves **no** owner MUST be created with
`owner_user_id = NULL`, MUST appear on the **Unassigned** view, MUST raise an
exception on the compliance dashboard, and MUST NOT be silently suppressed.
*Rationale — `CONSOLIDATED-REQUIREMENTS` H-22: SmartPRS 2's approval routing
produces "an approver with a NULL id and a string label"; R-XVII-128 makes the
same demand for statutory obligations.*

### XXI.3.6 Evidence requirements

**R-XXI-034** The type MUST declare its evidence requirement:

| Field | Meaning |
|---|---|
| `evidence_required` | `none｜optional｜required_to_submit｜required_to_verify` |
| `evidence_document_type_codes[]` | from the document-type catalogue (Part X) — never free text |
| `min_documents`, `max_documents` | |
| `accepted_mime_types[]`, `max_bytes` | validated on **magic bytes**, not on the declared extension |
| `evidence_class` | `internal｜contractual｜regulatory` — `regulatory` forces private storage, verification, and the strictest retention |
| `expiry_from_evidence` | whether the item's validity is read from a field captured on the evidence (issue date, expiry printed on the certificate) |
| `redaction_policy_key` | what may be shown in an exported pack (§XXI.7.4) |

**R-XXI-035** Evidence MUST be stored on a **private** disk and served only
through an authorised, signed, expiring URL. No compliance evidence may exist on
a publicly readable path, in any deployment shape.
*Test:* a deployment test asserts that no configured disk used by compliance
evidence has public visibility, and that a direct storage URL for an evidence
document returns 404/403.
*Rationale — `F-…` SEC-F-02, `N-…` S-04: PAN cards, address proofs, **PCC
certificates**, DRA certificates and signed agreements are written to the public
disk and served by the web server with no authentication.*

### XXI.3.7 Verification workflow

**R-XXI-036** Verification MUST run on the platform workflow engine (spine S8,
Part IX). There is no compliance-specific approval code.
The type names a workflow key; the workflow supplies the approver resolution,
SLA, reminders, escalation, delegate, backup approver, required comment and
required attachment.

**R-XXI-037** A verification decision MUST record actor, timestamp, IP, device,
decision, comment and the **hash of the evidence set that was verified**, so a
later substitution of the document is detectable.
*Test:* replacing an evidence document after verification moves the item to
`submitted` with `evidence_changed_after_verification = true` and notifies the
verifier.

**R-XXI-038** Where the type declares `verification_expiry_expr`, a verification
itself expires (a BGV re-verification cadence, for instance), and expiry MUST
raise a renewal rather than silently invalidating.
*Rationale — `F-…` BR-CMP-007 computes `next_due = verified_on +
revalidate_months` correctly, and then DEFECT-F-14 means the column may not exist.*

### XXI.3.8 Alert ladder, escalation ladder, risk

Specified in §XXI.2.7 and §XXI.2.8. The type owns the declarations; the sweep
executes them. Three properties are worth stating at the type level:

**R-XXI-039** Bucket boundaries, window bounds, rung offsets, audiences,
channels and templates are **all** configuration on the type. Nothing in this
ladder is a literal in code.
*Rationale — `F-…` §5.2 items 36–37: the buckets (`< 0`, `≤ 7`, `≤ 30`), the
30-day window, the 07:30 send time, the top-25 truncation and the recipient roles
are all literals.*

**R-XXI-040** `risk_level_code` MUST be settable per instance with a mandatory
reason, and the override MUST be visible and audited. Risk drives ordering,
escalation speed and the audit pack's verdict, so an ungoverned override is a
control bypass.

### XXI.3.9 Scoring

**R-XXI-041** A **missing record MUST NEVER score as compliant**, for any item
type, in any direction. `missing_record_is_valid` is fixed at `false` and is not
configurable.
*Test:* deleting every row of an item type's register drops the score for that
type to zero and marks the assessment basis `not_assessed`; it never raises it.
*Rationale — `F-…` BR-CMP-003b: a missing `complaints` table awards +20; a
missing `dra_certs` table awards 0. Absence of evidence must be treated one way,
declared, everywhere (`N-…` req. 29).*

**R-XXI-042** The compliance score MUST be a **configured weighted rule set over
item types**, not a fixed formula. Weights, the states that earn partial credit,
the cap and the scale are configuration; the score is scoped and effective-dated
like any other artefact, so a bank-specific weighting for one portfolio is a
scope, not a fork.
*Test:* a tenant reweights DRA from 25 to 40 and PCC from 20 to 10 and the
scorecard, the gate threshold and the audit pack all change together.
*Rationale — `F-…` BR-CMP-003: `min(100, 25·dra + 20·pcc + 20·bgv + 15·nda +
20·noComplaints)` with all five weights and all five conditions as literals;
`N-…` req. 10; and V-08, where the NDA leg is unreachable so the maximum
achievable score is **85**.*

**R-XXI-043** The score MUST be **computed and stored** on item change and on
the daily sweep, not recomputed per row per screen.
*Test:* the scorecard for 10,000 employees issues a bounded number of queries
independent of headcount.
*Rationale — `CONSOLIDATED-REQUIREMENTS` H-44: *"the compliance scorecard must
read a pre-computed score, not issue five queries per employee"*; `F-…`
DEFECT-F-33: `scoreFor($employeeId, $tid)` accepts a tenant id and never uses it,
trusting its caller for isolation.*

**R-XXI-044** A stored score MUST carry the **scoring rule version** and the
per-item contributions that produced it, so an old score is explainable.

### XXI.3.10 The money gates a type controls

**R-XXI-045** An item type MUST declare its **gate bindings** — a matrix of
(gate point or payment type) × (required state) × (severity) × (grace) — which
the gate policy of §XXI.6 resolves. A type with no bindings gates nothing.
*Cross-reference: Part XVIII §XVIII.9 owns the evaluation; this declaration is
its input.*

### XXI.3.11 Worked — adding "Firearms Licence" with no code

The Section 127 standard for this part. A security-services customer, on the
core platform with no vertical pack, needs armed-escort staff to hold a valid
firearms licence, blocked from field deployment and warned on payroll if lapsed.

```
Configuration Studio → Compliance → Item types → New

  Code                    FIREARMS-LICENCE
  Name                    Firearms Licence
  Obligation kind         credential
  Subject kinds           engagement
  Applies when            employee.category = 'ARMED_ESCORT'
  Periodicity             on_issue
  Validity source         explicit_expiry   (printed on the licence)
  Attention window        90 days
  Renewal lead            120 days
  Owner                   role_in_scope('HR_MANAGER', branch)
                          → fallback subject_self
  Evidence                required_to_verify · type LICENCE_SCAN ·
                          class regulatory · pdf/jpeg ≤ 10 MB
  Custom fields           licence_number (text, unique per tenant),
                          issuing_authority (value set),
                          weapon_class (value set), district (value set)
  Verification workflow   WF-COMPLIANCE-VERIFY-2STEP
  Valid states            [verified]
  Alert ladder            120 / 60 / 30 / 7 / 0 / +7 / +30 days
                          audience: subject, owner, branch manager
                          channels: in-app + email; +7 adds WhatsApp
  Escalation              +7 → notify branch manager
                          +15 → gate_tighten warn → block
                          +30 → raise_case(HR_DISCIPLINARY)
  Risk                    critical
  Score weight            30
  Gate bindings           work_allocation           → block, grace 0
                          payroll_run_approval      → warn,  grace 30
                          commission_claim_approval → warn,  grace 30

  [ Simulate on 20 employees ]   [ Save draft ]   [ Submit for approval ]
```

No PHP, no migration, no release. The item appears in the register, the score,
the dashboards, the digest, the gate and the audit pack because every one of
those reads the type registry rather than a literal list.

### XXI.3.12 Worked — the seeded DRA type reproduces SmartPRS 2 exactly

Migration safety requires that the collections pack's seeded `DRA-CERT` type
reproduce today's *intended* behaviour precisely, so that a migrated tenant sees
no change on day one except the removal of the divergences.

```
  Code                DRA-CERT
  Origin              module_pack: collections-compliance
  Subject kinds       engagement (on_roll AND off_roll)   ← the change from SmartPRS 2
  Applies when        employee.category IN policy.value('dra_applicable_categories')
  Validity source     explicit_expiry (dra_certs.expiry), fallback issue + validity
  Valid states        [verified]                          ← matching draValid(), not scoreFor()
  Custom fields       cert_no, institute (value set: IIBF, …),
                      track (value set: <from module pack: DRA-TRACKS>)
  Alert ladder        30 / 7 / 0 buckets                  ← today's WINDOW_DAYS = 30,
                                                            now bounded below as well
  Gate bindings       commission_claim_approval → severity from the migrated
                      tenant's own `dra_gate` value, verbatim
                      incentive_award            → same
                      worker_earning_approval    → same
  Score weight        25                                  ← today's literal, now data
```

**R-XXI-046** The `track` field (the IIBF 50/100-hour distinction) MUST be a
value from a pack-supplied value set and MUST be **validatable** against the
training register where the type declares a cross-check.
*Rationale — `F-…` NEW-F-16: `dra_certs.track` is stored, never validated and
never cross-checked against `training_records`.*
*Legal-values note: the track vocabulary and any hour counts are **pack content**
(`<from module pack: DRA-TRACKS>`), not asserted here.*

---

## XXI.4 Coverage — what the register contains

### XXI.4.1 Rule-pack-derived statutory obligations

These arrive **with the pack**. Ametecs authors them; the named compliance owner
(spine D-03) verifies each value against its primary source; the pack is signed;
the customer activates it; the obligations materialise. A change to a due date
ships as content, with no application release (spine S12, R-XVII-126).

| Head | Obligation shapes carried by the pack |
|---|---|
| **PF** | monthly ECR generation, contribution remittance, annual returns, exit/withdrawal filings |
| **ESI** | contribution period determination, monthly remittance, half-yearly returns, registers |
| **PT** | payment per state, per periodicity (monthly, half-yearly or annual as the state's rule declares), returns, enrolment renewal |
| **LWF** | remittance on the state's own periodicity, employer share, returns |
| **TDS** | monthly deposit, quarterly 24Q and 26Q, Form 16 issuance, corrections |
| **Bonus** | computation window, payment window, annual return |
| **Gratuity** | payment obligation on exit, notice and register obligations |
| **Minimum wages** | periodic comparison and the register that evidences it |
| **State registers and returns** | the register set and the annual and half-yearly returns each state's rules declare |
| **Registrations** | S&E registration renewal, PF/ESI code maintenance, PT enrolment, contract-labour licence |

> **Legal-values discipline.** Not one due date, periodicity or threshold above
> is asserted here. Each is `<from rule pack: OBLIGATION-CODE.due_rule>`, carries
> `source_reference`, `verified_by` and `verified_on`, and cannot be signed
> without them (R-XVII-039, spine S12).

**R-XXI-047** Rule-pack obligations MUST be projected into the Command Centre by
the registered adapter over `statutory_obligations`, mapping Part XVII's state
vocabulary onto §XXI.2.4's, and MUST NOT be copied into `compliance_items`.
*Test:* recording a filing in the Command Centre writes to `statutory_filings`,
not to a second store, and the two views agree by construction.

| Part XVII state | Command Centre state |
|---|---|
| `due` (before the attention window) | `upcoming` |
| `due` (inside it) | `due` |
| `prepared` | `pending` |
| `prepared_failed` | `pending` + risk raised + exception |
| `filed` | `filed` |
| `acknowledged` | `acknowledged` |
| `revised` | `pending` (with the revision number on the row) |
| `overdue` | `overdue` |
| `waived` | current state + `waived` marker |

### XXI.4.2 People-level obligations

These are **configuration**: seeded by a module pack, or created by the tenant.
The core platform ships the generic types; the vertical pack ships the
industry instances (§XXI.10.7).

| Obligation | Type shape | Core or pack |
|---|---|---|
| **DRA certification** | credential · explicit expiry · regulatory evidence · gate-bearing | **pack** (the instance); core supplies the credential model |
| **PCC (police clearance)** | credential · explicit expiry or issue+validity · regulatory evidence | **pack** (the instance); core model |
| **BGV** | verification · component checks · re-verification cadence | **core** |
| **Code of conduct** | acknowledgement · versioned policy · re-ack on version bump | **core** |
| **POSH** | committee constitution (establishment), annual return (establishment), training (engagement) | **core** |
| **Training and certification** | training · completion evidence · validity · cross-check to the training register | **core** |
| **Document expiry** | credential · driven from `employee_documents.expiry` | **core** |
| **Agent authorisation** | registration · subject engagement × client portfolio · revocable | **pack** |
| **Licence and certification expiry** | credential · customer-defined | **core** (the model), tenant (the instances) |
| **Medical fitness, driving licence, insurance** | credential | **core** model, tenant instances |
| **Vendor agreement expiry** | registration · subject vendor | **core** |
| **Device calibration** | credential · subject device | **core** |

**R-XXI-048** `employee_documents` carrying an expiry MUST feed the Command
Centre through the projection adapter, with the document type's catalogue entry
declaring the alert ladder.
*Rationale — `CONSOLIDATED-REQUIREMENTS` D-45: *"`documents.expiry` and
`pcc_deadline` are captured and alerted on by nothing"*; D-44 requires the
document-type catalogue to carry expiry, renewal cadence and a verification
workflow, which is precisely the item-type declaration reused.*

### XXI.4.3 What the Command Centre does not own

- **The statutory calculation** (Part XVII). The register knows a PF ECR is due;
  it does not compute PF.
- **The filing artefact** (Part XVII §XVII.11). The register links to it.
- **The gate evaluation** (Part XVIII §XVIII.9). The register supplies the item
  states the gate reasons over and displays the resulting exposure.
- **The document store** (Part X). Evidence is a document reference.
- **Approval mechanics** (Part IX). Verification is a workflow instance.
- **General reporting** (the reporting part). The audit pack is a *report
  definition* the pack ships; the Command Centre supplies its inputs.

---

## XXI.5 ONE definition of validity

This section is the direct answer to `F-…` DEFECT-F-08 and is the most important
requirement in this part.

### XXI.5.1 The service

**R-XXI-049** There MUST be exactly **one** implementation of compliance
validity in SmartPRS 3:

```
compliance_status(subject_ref, item_type_code, as_of: Date)
      → ComplianceStatus {
            state              : Code    -- §XXI.2.4 vocabulary
            is_valid           : Boolean -- state ∈ type.valid_state_codes
            assessment_basis   : Code    -- evidence | derived | not_assessed
            item_uuid          : Uuid?   -- the instance that decided it
            valid_from         : Date?
            valid_to           : Date?
            days_to_expiry     : Number? -- signed
            evidence_state     : Code
            waived             : Boolean
            reason             : Text    -- why not_applicable / not_assessed
            type_version_id    : Id      -- the item-type version applied
            trace              : ComplianceTrace   -- spine S15
        }
```

Its bulk form, `compliance_status_many(subjects[], item_types[], as_of)`, is the
only permitted way to evaluate more than one subject, so no consumer can produce
an N+1 query over the register.

**R-XXI-050** Every consumer MUST call it. The consumers are enumerated, and the
list is closed:

| Consumer | What it uses |
|---|---|
| The Command Centre register and its views | `state`, `assessment_basis`, `days_to_expiry` |
| Every dashboard widget (§XXI.11) | counts and the drill-through list |
| The compliance score (§XXI.3.9) | `is_valid` and the partial-credit map |
| The audit pack (§XXI.7) | `state`, `assessment_basis`, `reason`, evidence refs |
| The money gate (Part XVIII §XVIII.9) | `is_valid`, `days_to_expiry`, `waived` |
| Operational gates (work allocation, roster, attendance marking) | `is_valid` |
| The alert ladder and the digest | `days_to_expiry`, `state` |
| Every report and export | the full structure |
| The SPX namespaces `credential.*` and `compliance.*` (Part VII §S5) | resolved through this service, never by a query in a rule |
| The public API `/api/v3/compliance/*` | the full structure |

**R-XXI-051** A CI sweep MUST fail the build on any query against
`compliance_items`, `policy_acknowledgements` or the projected sources that
computes a validity, expiry or compliance verdict outside this service.
*Test:* the sweep is seeded with a deliberate violation and fails.
*Rationale — this is the mechanism, not the intention. SmartPRS 2's five
implementations were each individually reasonable; nothing prevented the second.*

**R-XXI-052** The definition of "valid" for a type is
`cfg_compliance_item_types.valid_state_codes` and nothing else. Changing it
changes the register, the score, the gate, the alerts and the audit pack
**together**, in one publish, with one impact preview.
*Test:* adding `submitted` to `PCC`'s valid states changes all five, and the
diff preview names the affected employee count before publication.
*Rationale — `CONSOLIDATED-REQUIREMENTS` #46 and R-XVIII-142: today DRA requires
`verified` while PCC accepts `submitted`, "asymmetrically and undocumented".*

### XXI.5.2 The absent-record rule

**R-XXI-053** When no instance exists for an applicable (subject, type):

```
applicable    AND no instance                → state = due
                                               assessment_basis = evidence
                                               is_valid = FALSE
not applicable                               → state = not_applicable
                                               assessment_basis = derived
                                               is_valid = n/a (excluded from scoring)
applicability cannot be evaluated            → state = due
                                               assessment_basis = not_assessed
                                               is_valid = FALSE, and an exception is raised
```

There is no fourth case, and **no case returns `is_valid = TRUE`**.
*Rationale — `F-…` DEFECT-F-08 implementation 5 treats a blank free-text field as
valid; BR-CMP-003b awards points for a missing table; `N-…` req. 28 requires that
a check never degrade to a weaker check when data is missing — it returns
"unknown".*

**R-XXI-054** `not_assessed` MUST propagate. A score containing a `not_assessed`
contribution is reported as *"score 72 of a possible 85 assessed; 15 points not
assessed"*, never as 72/100. A gate encountering `not_assessed` follows the
type's `on_not_assessed` setting (`block｜warn`), whose **default is `block`** and
which may never be set to `off` for an `evidence_class = regulatory` type.

### XXI.5.3 The as-of rule

**R-XXI-055** Validity MUST be evaluated **as at the date the decision belongs
to**, never "now": a commission approved for September resolves compliance as at
the collection date or the approval date, per the gate policy's declared
`as_of_basis`; an audit pack for a stated period resolves as at that period's
end.
*Test:* re-issuing an audit pack for March 2027 in July 2027 reproduces the March
verdict exactly.
*Rationale — spine S4's resolution rule, and `F-…` DEFECT-F-25/-F-26, where the
statutory analogue resolves on "today" and silently defers a month.*

### XXI.5.4 No compliance assertion may be a literal

**R-XXI-056** **No compliance assertion anywhere in SmartPRS 3 — on a screen, in
a PDF, in an export, in an API response — may originate from a literal, a
default, a hard-coded pass, or a count that no query produced.** Every assertion
carries its `assessment_basis` and its source item reference.
*Test:* a static-analysis rule fails the build on a literal compliance state,
verdict or finding value inside `Domain\Compliance` and inside any report
definition marked `regulatory`; and a golden test renders the full audit pack for
a fixture tenant and asserts that **every** finding resolves to a stored record
id.
*Rationale — `F-…` DEFECT-F-09 (`'0'`/`'ok'` for disciplinary actions),
DEFECT-F-07 (six quarters "Filed"), SEC-F-11 (`score => 92` on a
customer-branded sample). `N-…` req. 31: "No audit item may be hard-coded to
pass."*

### XXI.5.5 Explainability

Per spine S15, every status explains itself. The `ComplianceTrace` renders as:

```
Subject         R. Krishnan · engagement ENG-2024-00412 (off-roll)
Item type       DRA-CERT  (type v7, published 01-Apr-2026, pack
                collections-compliance 2.1.0)
As at           30-Sep-2026
Applicability   employee.category IN ['FIELD_AGENT','TELECALLER_FIELD']
                  employee.category = 'FIELD_AGENT'                   TRUE
                engagement.kind IN ['on_roll','off_roll']
                  engagement.kind = 'off_roll'                        TRUE
                → APPLICABLE
Instance        CI-2026-000318 · certificate IIBF/2024/114552
                issued 12-Mar-2024 · expiry 11-Mar-2027
Evidence        DOC-88213 (dra_certificate.pdf) · verified 14-Mar-2024
                by A. Rao · sha256 3f9c…a71b
State           verified          Valid states for this type: [verified]
is_valid        TRUE              days_to_expiry  +162
Assessment      evidence
Gate exposure   commission_claim_approval  PASS
                worker_earning_approval    PASS
Score           25.00 of 25.00 (weight v3)
```

Contrast: the same subject where the certificate register is empty.

```
Instance        none
State           due               Assessment  evidence
is_valid        FALSE             reason  "No DRA certificate record exists for
                                           this subject"
Gate exposure   commission_claim_approval  BLOCK  (policy COLLECTIONS-PAYOUT v2)
Score           0.00 of 25.00
```

And where applicability itself could not be evaluated:

```
State           due               Assessment  not_assessed
reason          "Applicability could not be evaluated: employee.category is
                 unresolved for 12-Aug-2026 (no assignment row in force)"
Gate exposure   commission_claim_approval  BLOCK  (on_not_assessed = block)
Exception       CE-2026-00871 raised to the compliance officer
Score           excluded from the assessed total; reported as not assessed
```

---

## XXI.6 The compliance-to-money gate, generalised

Spine S19 #1 names the DRA gate as the first thing the rebuild must not lose.
Part XVIII §XVIII.9 specifies the gate mechanism, its policy table
(`cfg_gate_policies`), its eleven gate points, `compliance_gate_decisions`, and
requirements R-XVIII-141…149 (one definition of valid, missing records never
pass, gate points, decision records, never fail open, durable warnings,
overrides, non-employee workers). **That specification is not repeated here.**

This section owns three things Part XVIII leaves to the compliance side.

### XXI.6.1 The item type × action type matrix

**R-XXI-057** Gate configuration MUST be expressible as a **matrix**: for each
compliance item type, for each payment type or action type, a severity
(`off｜warn｜block`), a grace, an `as_of_basis` and an `on_not_assessed`
behaviour. A tenant fills cells; it does not write policies row by row.

```
                              │ Commission │ Incentive │ Off-roll │ Payroll │ Bank  │ Work
                              │  approval  │   award   │  earning │ approval│ file  │ alloc
──────────────────────────────┼────────────┼───────────┼──────────┼─────────┼───────┼──────
DRA-CERT                      │   block    │   block   │  block   │  warn   │ block │ block
PCC                           │   warn 30d │   warn    │  warn    │  off    │ warn  │ block
BGV                           │   warn     │   off     │  warn    │  off    │ off   │ warn
CODE-OF-CONDUCT-ACK           │   warn     │   warn    │  warn    │  off    │ off   │ warn
AGENT-AUTHORISATION           │   block    │   off     │  block   │  off    │ block │ block
POSH-TRAINING                 │   off      │   off     │  off     │  off    │ off   │ warn
```

Each cell compiles to one `cfg_gate_policies` requirement row (Part XVIII
§XVIII.9.2). The matrix is the editing surface; the policy is the artefact.

**R-XXI-058** The matrix MUST be **scoped and effective-dated** like any
artefact (spine S3.1, S4), so a per-client-portfolio or per-state gating
posture is a scope, not a fork.
*Rationale — `F-…` BR-CMP-002 records the customer want directly: "per-portfolio
/ per-bank gating". Today the gate is a single tenant-wide value.*

**R-XXI-059** Migration MUST seed each tenant's **existing `dra_gate` value
verbatim** into the three cells it governs today (commission approval, incentive
award, off-roll earning), seed every other cell to `off`, and produce an
**exposure report** naming every subject that would newly be blocked or warned if
a cell were tightened.
*Rationale — aligns with D-XVIII-14. Seeding `block` broadly would stop payments
on day one for every subject with a lapsed credential; seeding `off` with a
visible exposure report preserves behaviour while making the gap explicit.*

### XXI.6.2 Gate points beyond money

**R-XXI-060** The gate MUST be bindable to **operational** action types as well
as money events, because the business rule a collections customer actually states
is *"a non-compliant agent must not be sent to a borrower"*, and money is only its
downstream consequence.

| Action gate point | Blocked outcome |
|---|---|
| `work_allocation` | the subject cannot be allocated to a case, a portfolio or a beat |
| `roster_assignment` | the subject cannot be rostered onto a field shift |
| `attendance_marking` | a field punch is accepted but **flagged**, never rejected — attendance evidence is never destroyed by a compliance state (spine S10) |
| `client_portfolio_assignment` | the subject cannot be attached to a client portfolio |
| `device_enrolment` | a device out of calibration cannot be enrolled |
| `letter_issue` | an authorisation letter cannot be generated for a non-compliant agent |
| `field_visit_start` | a mobile app cannot start a visit |

*Rationale — `F-…` req. 39: "The gate points must extend beyond money to
allocation, attendance and field work."*

**R-XXI-061** `attendance_marking` MUST be `flag`-only. No compliance state may
cause a punch to be discarded, because raw attendance is evidence
(spine S10, layer 1 is append-only).

### XXI.6.3 Standing re-evaluation

**R-XXI-062** A gate verdict recorded against a still-open decision (an
unapproved claim, an undisbursed payout, an unreleased bank file) MUST be
**re-evaluated** when a relevant compliance item changes, and the change MUST be
surfaced to the approver.
*Test:* an agent's DRA expires between a claim's submission and its approval; the
approval screen shows `BLOCK` at the moment of approval, not the verdict recorded
at submission.
*Rationale — consumes `compliance.item.expired` (Part XVIII §XVIII.13 already
declares this consumption).*

**R-XXI-063** A gate MUST never fail open; an evaluation error yields
`error_blocked` and an operator alert (R-XVIII-146). The **alerting** side —
digests, dashboards, reminders — MUST fail soft, and MUST record and surface the
failure rather than swallowing it.
*Rationale — the availability bias in `N-…` §1.10 #14 is correct for the HR
system as a whole and wrong for a control: SmartPRS 2 applies fail-open to the
gate itself (SEC-F-04), which is exactly backwards.*

---

## XXI.7 Evidence and the audit pack

### XXI.7.1 Evidence capture

**R-XXI-064** Evidence MUST be captured through the platform document service
(Part X), stored privately (R-XXI-035), validated on **magic bytes**, virus
scanned where configured, and linked to the compliance item with the uploader,
the upload instant, the source surface (ESS, MSS, bulk, API, self-onboarding) and
the content hash.

**R-XXI-065** Evidence upload MUST be available to the **subject**, including a
subject with no login. An off-roll agent with no email and no app MUST be able to
submit a PCC scan through a **single-use, expiring, revocable** token link
delivered by WhatsApp or SMS.
*Test:* a token is single-use, expires at its configured lifetime, is revocable
by HR, is rotatable, and is scoped to one subject and one item.
*Rationale — `F-…` SEC-F-08 and `N-…` S-05: the existing off-roll bearer token is
48 random characters, stored in plaintext, never expires, never rotates, cannot be
revoked, and is looked up with **no tenant scope**. The capability is right; the
token discipline is not.*

**R-XXI-066** Evidence MUST NOT be required for the register row to exist. A
missing evidence document is a **state**, visible and chaseable; it is never a
reason to omit the obligation.

### XXI.7.2 Verification

Specified in §XXI.3.7. Two consequences bear repeating at the pack boundary:

**R-XXI-067** A pack (§XXI.7.4) MUST distinguish, for every finding, between
*evidence attached* and *evidence verified*, and MUST render them as different
outcomes.
*Rationale — `F-…` DEFECT-F-12: the checklist accepts `pcc_status = 'submitted'`
and `bgv.status = 'completed'` as `ok` while the score awards zero for the same
facts, so the customer's own compliance report contradicts itself.*

### XXI.7.3 Retention

**R-XXI-068** Every compliance item type MUST declare a **retention class**
(spine S16). Evidence inherits the item's class unless the type declares a
longer one. Archival, anonymisation and purge are scheduled, audited operations
under that class; nothing in the compliance area is hard-deleted.

**R-XXI-069** A DPDP erasure request against a person MUST be honourable
**without destroying regulatory evidence**: the erasure operation anonymises the
person record and retains the compliance evidence under its statutory retention
with the subject replaced by a pseudonymous reference, and the conflict is
recorded as a retention exception.
*Rationale — `N-…` req. 44–45: hard erasure must be possible and there is no
purge job, no retention timer and no `deleted_after` column today; and a
regulatory evidence set cannot simply be deleted on request.*
**PRODUCT OWNER DECISION REQUIRED — D-XXI-06** (§XXI.20).

### XXI.7.4 The regulatory audit pack

The artefact the agency hands the bank. It is a **report definition shipped by
the module pack**, rendered by the platform reporting engine, over the Command
Centre's own data.

**R-XXI-070** **Every assertion in a generated compliance document MUST be
derived from verified stored evidence, and any check that could not be performed
MUST be reported as "not assessed" — never as compliant.**
*Test:* the golden audit-pack test asserts that each rendered finding carries a
resolvable `compliance_item_uuid` or the literal outcome `not_assessed` with a
reason; a finding with neither fails the test.
*This is the requirement that DEFECT-F-09, DEFECT-F-11, DEFECT-F-13, SEC-F-05 and
V-05 each violate in a different way.*

**R-XXI-071** The pack's **item set, groups, ordering, weights, thresholds,
verdict bands and layout MUST be configuration**, versioned and effective-dated,
so that a different bank's annexure or a future regulatory direction is a new
pack version rather than a release.
*Rationale — `N-…` req. 32; `F-…` BR-CMP-011: 12–13 items, 6 groups, every
threshold, every group name a literal.*

**R-XXI-072** The pack MUST declare, and print, its **assessment window**, its
**as-of date**, the **item-type versions** and the **scoring rule version** used,
and the **evidence set hash** for each finding.

**R-XXI-073** Findings MUST have four possible outcomes, and the verdict logic
MUST treat them distinctly:

| Outcome | Meaning |
|---|---|
| `pass` | derived from a verified record |
| `attention` | derived from a record that is present but not in a valid state, or expiring inside the attention window |
| `fail` | derived from a record that is absent, expired, rejected or revoked |
| `not_assessed` | the check could not be performed — the reason is printed |

**R-XXI-074** The overall verdict MUST be computed by a configured rule over the
outcome counts and the risk weights, and a pack containing **any**
`not_assessed` finding MUST NOT be able to carry an unqualified `COMPLIANT`
verdict. Its verdict is `COMPLIANT — PARTIALLY ASSESSED`, with the unassessed
items listed on the face of the document.

**R-XXI-075** The bulk variant MUST render **each subject under its own legal
entity's branding**, resolved per subject.
*Rationale — `F-…` DEFECT-F-32: `$first = $agents[0]` supplies brand and company
for the whole document, so mixed-company runs produce misbranded regulatory
documents.*

**R-XXI-076** The marketing sample MUST be generated from a **fixture tenant**
by the same renderer, MUST be watermarked `SPECIMEN` on every page, MUST NOT
resolve any real tenant's branding, and MUST NOT be reachable from an
authenticated tenant session.
*Rationale — `F-…` SEC-F-11: `agentAuditSamplePdf` and `publicSampleAuditPdf` are
the same generator; the authenticated one renders the tenant's real branding over
wholly fabricated findings including a hard-coded score.*

### XXI.7.5 "Not assessed" is a first-class outcome

**R-XXI-077** The Command Centre MUST expose a **Not assessed** view
(§XXI.2.6), and issuing an audit pack MUST show the count of unassessed items
before generation, with an explicit acknowledgement step.
*Test:* generating a pack with 3 unassessed items requires an explicit
confirmation and stamps the count on the document.

Worked, showing exactly the four SmartPRS 2 findings that are now impossible:

```
GROUP 4 — CONDUCT AND DISCIPLINE                       as at 30-Sep-2026

  Code of conduct acknowledgement          PASS
     policy CoC v5 · acknowledged 04-Aug-2026 14:22 IST · ACK-2026-4471
     IP 49.37.x.x · agent portal · text hash 9b21…c7
  Disciplinary actions in window           NOT ASSESSED
     Reason: no disciplinary-case register is configured for this tenant.
     Configure item type DISCIPLINARY-CASE to have this assessed.
  Borrower complaints in window            PASS
     window 01-Jul-2026 → 30-Sep-2026 (financial quarter, per pack v2.1)
     0 complaints · register CASE-REG-BORROWER · last row 12-Jun-2026
  Bank authorisation status                FAIL
     authorisation AUTH-4471 REVOKED by HDFC on 02-Sep-2026
     (valid_to 31-Dec-2026 — revocation overrides the end date)

  VERDICT   COMPLIANT — PARTIALLY ASSESSED (1 item not assessed, 1 failure)
```

- *"Disciplinary actions"* is `not_assessed` with a reason, not `'0' · ok`
  (DEFECT-F-09).
- The complaint window names its rule and its dates rather than being a silent
  90 days described in the code as a financial quarter (V-07).
- The revoked authorisation **fails**, because `status` is read (DEFECT-F-13).
- The code-of-conduct finding names the policy version, the acknowledgement id,
  the timestamp, the IP and the text hash, so it cannot degenerate to *"has
  anybody in this tenant acknowledged?"* (DEFECT-F-11).

### XXI.7.6 The issued-pack register and the integrity hash

**R-XXI-078** Every issued pack MUST be **persisted**: subject, generator, issue
instant, as-of date, window, item-type versions, scoring version, every finding
with its outcome and source record, the verdict, the score, the rendered
document, and the recipient where one is recorded.
*Rationale — `N-…` V-04 and req. 30: "No table stores an issued report. A bank
cannot verify a presented PDF, and Ametecs cannot prove what a given report
said."*

**R-XXI-079** The integrity marker MUST be a **keyed hash over the pack's
canonical findings payload**, not over public metadata:

```
findings_canonical = canonical_json({
      pack_definition_version, as_of, window_from, window_to,
      subject_uuid, findings[ { code, outcome, source_record_uuid,
                                evidence_sha256, assessed_at } ],
      verdict, score, scoring_rule_version })

content_sha256 = SHA-256(findings_canonical)
reference      = HMAC-SHA-256(tenant_pack_key, content_sha256)   → printed
```

- The tenant pack key is held in the platform key store, is never in the
  document, and is rotatable with a key id printed alongside the reference.
- The stored row carries `content_sha256`, so **changing any finding changes the
  reference**.
- The issued-pack row is written into the tenant's hash-chained audit ledger
  (spine S14), so the chain covers the issuance.

*Rationale — today's marker is `substr(sha256($ref.'|'.$e->id.'|'.today), 0, 16)`:
unkeyed, deterministic from three public values, identical for two different
reports on the same day, chained to nothing, and covering **none of the
findings** (`F-…` DEFECT-F-10, `N-…` V-04, DISC-04).*

**R-XXI-080** A **verification endpoint** MUST exist so a bank holding a printed
pack can confirm it. It accepts the reference and returns only: issued or not
known, the issue date, the subject's masked identifier, the verdict, and whether
the pack has since been superseded. It returns **no** compliance detail, is rate
limited, and requires no authentication.
*Test:* altering one character of a printed reference returns "not known"; a
genuine reference returns the verdict and nothing else.

**R-XXI-081** Generating a pack MUST itself be **audited** — actor, subject set,
filters, purpose, recipient — because the generation of a regulatory document
about a named person is a privacy-relevant act.
*Rationale — `F-…` DEFECT-F-10: `ComplianceController` contains no audit call at
all and generating the report is not logged.*

**R-XXI-082** A superseded pack MUST remain retrievable and MUST be marked
superseded, naming its successor. A pack is never deleted and never regenerated
in place.

---

## XXI.8 The collections and recovery vertical *(brief Section 79)*

Spine S19 #3 records this as *"genuine domain capital"*. The analysis records
something more precise and more useful: **the vertical is thin**.

> *"Five of the six registries that carry the industry story … are rows in a
> 460-line config array in one controller and self-creating flat tables with
> string columns. There is almost no vertical logic; there is vertical naming
> over generic CRUD, plus three genuinely vertical engines (audit pack, DRA money
> gate, off-roll payouts). If SmartPRS 3's core provides (a) a Credential object
> with expiry and alert policy, (b) a Policy-acknowledgement object with
> versioning, (c) a Case/Complaint register, (d) a Worker abstraction that admits
> non-employees, and (e) a rule engine that can gate a money event on a
> compliance state, then the entire collections module pack reduces to
> configuration plus three engines — and the same core sells to security
> agencies, staffing firms, logistics and facilities management without a
> rewrite."* (`N-…` §1.8.2.)

That paragraph is the commercial argument for this entire part, and §XXI.8.1
implements it.

### XXI.8.1 Core or pack — the thirteen capabilities decided

`N-…` §1.8.1 assesses each claimed industry capability. This table carries that
verdict into a build decision, and adds what each requires.

| # | Capability | Genuinely vertical? | **SmartPRS 3 home** | What must be built |
|---:|---|---|---|---|
| 1 | **DRA certificate register** | Schema no; consuming logic yes | **Core** model (`compliance_item_type`, `obligation_kind = credential`) + **pack** instance | The DRA type declaration, IIBF issuing body, track value set, gate bindings — all data (§XXI.3.12) |
| 2 | **PCC** | **No** — a credential with an expiry, modelled worse than DRA (two columns on `employees`) | **Core** | Nothing beyond the type instance. `employees.pcc_status/pcc_expiry/pcc_deadline` become instances of the core model |
| 3 | **BGV** | **No** — generic pre-employment screening; the six checks are the standard set | **Core** | A `verification` item type with component checks, agency, and a re-verification cadence that **exists on a fresh install** (fixing DEFECT-F-14) |
| 4 | **Employee references (min 2)** | **No** — generic. The *threshold* is the industry bit, and it is hard-coded | **Core**, threshold configurable | `min_references_expr` on the type; the count rule is SPX (`N-…` req. 13) |
| 5 | **Agent authorisation / empanelment** | **Yes** | **Pack** | A real entity keyed to engagement × client portfolio, with revocation, alerting on `valid_to`, and a gate binding — *"it needs building properly, not migrating"* |
| 6 | **Code of conduct** | **No** — a generic policy acknowledgement; only the content is industry | **Core** | Versioned policy, per-version acknowledgement, re-ack on bump, tenant-owned text (§XXI.8.6) |
| 7 | **Off-roll agents** | **Yes**, and the strongest vertical asset — but the underlying need is general | **Core** worker abstraction + **pack** behaviours | Engagement kind `off_roll` on the core person model (§XXI.8.2); payout shapes as pack configuration |
| 8 | **Client-bank mapping** | The need yes, the implementation no (*"there is no bank master"* — it is a free-text `portfolio` string) | **Pack** entities | `clients` and `client_portfolios` as real entities (R-XVIII-091); a data-cleansing migration, not a copy |
| 9 | **Complaint tracking** | Register no; the *consumption* yes (borrower complaints against this agent in a window) | **Core** case register + **pack** case type and window rule | A generic case/complaint register with subject, channel, severity, SLA; the pack adds `BORROWER-COMPLAINT` and the audit window |
| 10 | **Escalations** | **Yes** — a bank-raised issue against the agency is not an HR concept | **Pack** | A case type with client portfolio, severity, priority, action taken, SLA and escalation ladder |
| 11 | **RBI agent audit pack** | **Yes** — *"the single most defensible piece of vertical IP"* | **Pack** report definition | Rebuilt per §XXI.7 with all D-06 defects fixed |
| 12 | **Nightly expiry scan** | Mechanism no; applicability yes | **Core** engine + **pack** ladders | The sweep of §XXI.2.7; the pack ships which types and what windows |
| 13 | **DRA money gate** | **Yes**, the most sophisticated vertical rule — expressed on a core engine | **Core** engine (Part XVIII) + **pack** matrix cells | The matrix of §XXI.6.1; the pack ships the collections column |
| + | **Bank/NBFC payout and billing pack** | **Yes** — the agency's revenue side, not HR at all | **Pack**, arguably a separate product boundary | Payout register, 194H annexure, GST service invoice per client per month, with a **unique constraint on the invoice number** (`N-…` D-23/S-11) |
| + | **Live earnings page** | **No** — commercially load-bearing, mechanically generic | **Core** | A subject-facing earnings view with an expiring token (§XXI.8.2) |
| + | **"Roster", "FOS", `companies.type='collections'`** | **No** — naming only | Core | Nothing |

**Count: six genuinely vertical (5, 7-behaviours, 8, 10, 11, 13 plus the billing
pack), seven generic (1-model, 2, 3, 4, 6, 9-register, 12-mechanism)** — exactly
the analysis's split (`N-…` §1.8.2).

**R-XXI-083** The core platform MUST ship, and MUST be sellable without any
vertical pack: the compliance item type model, the credential shape, policy
acknowledgement, the case/complaint register, the engagement model admitting
non-employees, the expiry sweep, the scoring engine, the gate engine and the
Command Centre.
*Test:* a facilities-management tenant with no pack installed tracks driving
licences, medical fitness certificates and police verifications, with alerts,
scoring and a payroll gate, using only core configuration.

**R-XXI-084** No collections-specific term — DRA, PCC, RBI, borrower, portfolio,
recovery agent, empanelment — may appear as an identifier, literal, enum value,
column name or hard-coded label anywhere in the core platform.
*Test:* a CI grep for the vertical vocabulary outside the pack's own artefacts
fails the build.
*Rationale — this is the difference between a payroll product with a collections
feature and a platform with a collections pack, and it is worth roughly the
entire addressable market outside collections.*

### XXI.8.2 Off-roll agents, modelled properly

This is the largest single structural change in this part.

**The problem, restated from evidence.** `offroll_agents` is a parallel person
model with two schemas (migration `2026_01_01_000500:14-26` vs runtime
`OffrollAgentController::ensure():32-65`, `F-…` DEFECT-F-43), whose `dra` and
`pcc` columns are `string` with **no expiry column at all** (V-02), which is read
by no compliance mechanism (V-01), consumes no seat (`N-…` §1.5.5), is billed at
no TDS (DEFECT-F-44), stores identity documents on the public disk (S-04), and is
reached through a permanent bearer token (S-05).

**The decision.**

**R-XXI-085** Off-roll agents MUST be modelled as a **first-class engagement
kind on the core person model**, not as a parallel table. A person holds one or
more **engagements**; `on_roll` and `off_roll` are engagement kinds, and both
carry the same identity, the same custom-field metadata, the same document store,
the same compliance items, the same gates and the same money-movement
obligations. Only the *payroll eligibility* and the *payout channel* differ, and
both are declared attributes of the engagement kind — never branches in domain
code.

```
Person ──1:N──► Engagement { engagement_kind_code: on_roll | off_roll
                                                 | contractor | vendor_staff
                                                 | trainee | consultant }
                   ├── EngagementAssignment  (effective-dated, spine S3)
                   ├── ComplianceItem[]      (§XXI.13)
                   ├── Document[]            (Part X)
                   ├── Obligation[]          (Part XVIII money movement)
                   └── EntityFieldValue[]    (spine S9)
```

| Property | `on_roll` | `off_roll` |
|---|---|---|
| Payroll eligible | yes | no |
| Payout channel | payslip + bank batch | earnings ledger + payout batch |
| Statutory profile | full (PF/ESI/PT/TDS) | 194H / vendor treatment, per configuration |
| Compliance items | by applicability | **by the same applicability** |
| Seat consumption | per the seat definition | **PO decision D-XXI-04** |
| Login | usually | usually not — served by token surfaces |
| Custom fields | entity schema engine | the same engine |

**R-XXI-086** Every compliance mechanism MUST operate on the **engagement**, so
off-roll agents are included **by construction** rather than by a second code
path. There is no `if (off_roll)` in any compliance, alerting, scoring, gate or
reporting path.
*Test:* a test asserts that the sweep, the score, the gate and the audit pack
each return results for an `off_roll` engagement with no configuration beyond the
applicability expression, and a static check asserts no engagement-kind literal
inside `Domain\Compliance`.
*Rationale — `N-…` req. 25 and V-01; `CONSOLIDATED-REQUIREMENTS` H-71:
*"Off-roll agents must be first-class workforce members for compliance purposes:
same certification registers, same expiry alerting, same audit file, whatever
their payroll status."**

**R-XXI-087** Off-roll credentials MUST be **dated compliance items**, never
free-text fields. The migration of `offroll_agents.dra` / `.pcc` is a **triage**,
not a copy: each non-empty string is parsed where it is parseable into
(reference, issue date, expiry), and where it is not, it produces an item in
state `due` with `assessment_basis = not_assessed` and a reason, on an exception
report for manual completion.
*Test:* a migration test over a fixture of real-shaped strings ("DRA ok",
"IIBF/2019/8871", "yes", "expired", "", "12-03-2024") produces zero silent
successes and a complete exception list.
*Rationale — `N-…` DISC-08 states the constraint exactly: the fix is "model the
data first", and A4 in `J-…` records that what those columns hold is **not
determinable from source** — see D-XXI-03.*

**R-XXI-088** Off-roll payouts MUST run through the same money-movement model as
on-roll commission (Part XVIII), and MUST therefore carry TDS treatment
(194H / no-PAN), GST handling for vendor invoices, a run-keyed ledger, and the
same gate.
*Rationale — `F-…` DEFECT-F-44: `agent_earnings` has no tax column and the
on-roll path deducts 194H while this one does not, so commission payments to
off-roll agents leave the system untaxed.*

**R-XXI-089** The subject-facing earnings view MUST be preserved — it is
genuinely good product — with: a **single-subject, expiring, rotatable,
revocable** token; tenant scope on lookup; **no row cap without a visible
indication and paging**; and the same "how you earned it" per-entry breakdown
with pending entries labelled.
*Rationale — preserve `N-…` §1.10 #9; fix S-05 (never expires, never rotates, no
tenant scope) and D-24 (silently capped at 100 entries, so a high-volume agent's
displayed total is wrong).*

**PRODUCT OWNER DECISION REQUIRED — D-XXI-01** (one table or two) is recorded in
§XXI.20 with a recommendation, because it touches Part V and Part XXVIII.

### XXI.8.3 Agent authorisation, clients and portfolios

**R-XXI-090** `clients` and `client_portfolios` MUST be **real entities** with
ids. Commission attribution, agent authorisation, the billing pack and the audit
pack all reference them by id.
*Rationale — R-XVIII-091; `N-…` §1.8.1: *"There is no bank master. The bank is
the free-text `portfolio` string carried on each commission entry."**

**R-XXI-091** An **agent authorisation** is a compliance item of
`obligation_kind = registration` whose subject is an engagement and whose
counterparty is a client portfolio, carrying: authorisation number, issuing
client, portfolio, geography, valid from/to, status
(`active｜suspended｜expired｜revoked`), the authorisation letter document, and the
revocation actor, date and reason.

**R-XXI-092** Validity MUST consider **status and dates together**. A revoked
authorisation is invalid regardless of `valid_to`; a suspended one is invalid for
the suspension window.
*Test:* an authorisation revoked on 02-Sep with `valid_to = 31-Dec` evaluates
invalid from 02-Sep and blocks at every bound gate point.
*Rationale — `F-…` DEFECT-F-13 exactly: `status` is never read, so a revoked
authorisation with a future end date reads Compliant on the RBI audit file.*

**R-XXI-093** Authorisation expiry MUST feed the standard alert ladder.
*Rationale — `N-…` V-03: *"An authorisation can lapse silently while the agent
keeps working the portfolio"* — it appears only inside the audit PDF and is named
in no scheduled task.*

**R-XXI-094** The authorisation letter MUST be generated from the platform
template engine with the client's own required fields, MUST be gated
(`letter_issue`, §XXI.6.2), and MUST be reissued rather than edited on renewal.

### XXI.8.4 Complaints, grievances and cases

**R-XXI-095** The core MUST provide a **case register**: case type, subject
(engagement, person, org unit, client portfolio, or **anonymous**), source,
channel, severity, description, evidence, assignee, SLA, state machine,
resolution and closure, with full audit. Case types are configuration.

**R-XXI-096** The pack MUST ship the `BORROWER-COMPLAINT` case type and the
compliance measure that consumes it, with the **window declared by the pack and
printed on the report**.
*Rationale — `F-…` BR-CMP-015 and `N-…` V-07: a fixed trailing 90 days, while the
code comment claims "current financial quarter approx". A measure whose window is
not stated cannot be defended to a bank.*

**R-XXI-097** POSH complaints MUST be a case type with **restricted visibility**
(a named committee only), separate retention, and exclusion from general
compliance exports.
*Rationale — `posh_complaints` is a runtime-created table with no access control
distinct from any other registry (`F-…` §2.1).*

### XXI.8.5 Escalations

**R-XXI-098** The pack MUST ship an `ESCALATION` case type: raised by a client
against the agency (not against a person), carrying client, portfolio, team,
issue, severity, priority, action taken, SLA and an escalation ladder, and
feeding the Command Centre as an entity-level obligation.
*Rationale — `N-…` §1.8.1 classes this as genuinely vertical; today it is a
`def()` row with no consumer at all.*

### XXI.8.6 Code of conduct, and policy acknowledgement generally

**R-XXI-099** Policy acknowledgement MUST be a **core** capability with:
tenant-owned policy text; explicit versions with publication dates; an SPX
audience expression; per-user, per-**version** acknowledgement rows carrying
timestamp, IP and user agent; **append-only history**; and automatic
re-acknowledgement demand when the version bumps.
*Test:* publishing v5 of a policy moves every v4 acknowledger to `due`, retains
every v4 acknowledgement row, and the audit pack cites the version acknowledged.
*Rationale — `F-…` BR-CMP-016, SEC-F-06, SEC-F-07: one row per user, overwritten
on re-acknowledgement, no version, no history, no IP, no signature, and the text
is `kb_topics` filtered by category **with no tenant filter**, so one tenant's
policy text is another's legal undertaking.*

**R-XXI-100** Acknowledgement MUST be possible for a subject **with no login**,
through the same token surface as evidence upload (R-XXI-065), and the
acknowledgement MUST be attributed to the **engagement**, never to "whichever
user row was found".
*Rationale — DEFECT-F-11 is caused precisely by resolving the subject through a
user lookup that may match nothing and then dropping the filter.*

**R-XXI-101** `policies.ack_required` MUST be honoured. A policy declared to
require acknowledgement raises obligations; one that does not, does not.
*Rationale — `F-…` §5.2 item 42: the column exists and nothing reads it.*

### XXI.8.7 Training and certification

**R-XXI-102** Training completion MUST be expressible as a compliance item type
with validity, evidence and a cross-check against the training register, so that
"DRA training completed under the applicable track" is assessable rather than
assumed.
*Rationale — `F-…` NEW-F-16: `dra_certs.track` is inert and is never cross-checked
against `training_records`; `CONSOLIDATED-REQUIREMENTS` H-60: *"Any content the
platform describes as mandatory with a validity period must be enforced by a
control — a due date, an alert and a compliance consequence — or must not be
labelled mandatory."**

### XXI.8.8 Compliance expiry across the vertical

Every dated artefact in the vertical feeds the one sweep: DRA expiry, PCC expiry,
BGV re-verification due, authorisation `valid_to`, policy version bump, training
validity, document expiry, insurance expiry, device calibration. There is no
second scanner and no per-artefact alerting code.

**R-XXI-103** Adding a newly dated artefact to the alerting engine MUST be a
configuration act (declare an item type or a projection), never a code change.
*Rationale — `F-…` BR-CMP-006: four alert sources, hard-coded as a closed literal
set.*

---

## XXI.9 Module and feature entitlement *(brief Section 80)*

**The enforcement mechanism is Part VI §VI.14 and is not restated here**:
`feature_catalogue`, `entitlement_grants`, `tenant_feature_states`, the
narrowing rule (R-VI-130), the request-scoped `EntitlementSet` (R-VI-131), the
five identical enforcement points, the positive-only publication to the browser
(R-VI-132), the CI declaration sweep (R-VI-133), the dependency refusal
(R-VI-134), the declared degraded mode (R-VI-135), and non-destructive disable
(R-VI-136).

This section adds the one thing VI.14 assumes and does not define, and states the
module-level behaviour the brief asks for explicitly.

### XXI.9.1 The module registry

**R-XXI-104** There MUST be **exactly one module registry**, held as platform
data, and the permission model, the entitlement grant, the licence, the price
list, the navigation, the scheduler, the API document and the module pack MUST
all read from it. A module that is not in the registry does not exist.
*Test:* a consistency test asserts that every permission's module, every
navigation entry's module, every scheduled task's module, every feature key's
module and every licence line's module resolve to a registry row.
*Rationale — `N-…` §1.7.1 found **six mutually inconsistent module
vocabularies** — `PERM_NAV` 16, `PLAN_GROUP_KEYS` 10, the plan seeder 14,
`Edition` nav sets 50, `MasterController::def()` 57, the SPA shell 107 — plus a
marketing claim of "16 modules" and a licence agreement selling a catalogue that
is in no repository. Q-01 is recorded as blocking the module-pack boundary.*

```
module_catalogue                                    (platform, versioned)
  module_key                   stable, e.g. compliance.command_centre
  name, description, icon
  kind                         core | engine | vertical | platform
  provided_by                  platform | module_pack:<pack_key>
  parent_module_key            optional grouping
  depends_on[]                 module keys
  optional_depends_on[]        with declared degraded modes
  feature_keys[]               → Part VI feature_catalogue
  permission_keys[]            → the permission catalogue
  navigation_keys[]            → the navigation registry
  scheduled_task_keys[]
  api_tag                      the OpenAPI tag its endpoints carry
  entitlement_default          included | licensed | pack_only
  is_removable                 false for the platform kernel
  minimum_platform_version
```

**R-XXI-105** Navigation MUST be **data**, derived from the module registry and
the entitlement set. There is no static navigation file and no runtime DOM
injection.
*Rationale — `N-…` D-12 and req. 55: 107 nav ids live in a 234 KB HTML file and
~14 more are inserted by `insertBefore` anchored to *other* nav items, so
"removing or renaming an anchor nav item silently deletes an unrelated screen
from the sidebar", with no test.*

**R-XXI-106** A navigation entry MUST NOT exist without a resolvable
implementation. The consistency test fails the build on an orphan.
*Rationale — `N-…` D-13: `ai-assistant` is shown to four roles including the
vendor's own, with no controller, no route and no renderer.*

### XXI.9.2 What "disabled" means, precisely

The brief states the requirement; these are its five surfaces. All five are
Part VI's enforcement points; this requirement asserts that a **module** toggle
reaches all of them identically.

**R-XXI-107** When a tenant administrator disables a module:

| Surface | Behaviour |
|---|---|
| **UI** | every navigation entry, dashboard widget, report, saved view, template merge field and settings section belonging to the module disappears — as a **convenience**, never as the control |
| **HTTP endpoint** | every route declaring the module's feature returns `403 feature_not_entitled` with the feature key, from the server, before any handler runs |
| **Public API** | the same routes are **absent from the tenant's generated OpenAPI document** and return `403` if called |
| **Scheduler** | every scheduled task declaring the module's feature is skipped for that tenant, and the skip is **recorded** — not silently omitted |
| **Queue** | an already-dispatched job re-checks entitlement at execution and fails **visibly** to the dead-letter queue |
| **Events** | events the module publishes are not emitted; subscribers see no synthetic or empty event |
| **Data** | nothing is deleted. Configuration remains stored and resolvable; re-enabling restores behaviour exactly (R-VI-136) |
| **Dependencies** | disabling is **refused** where an enabled module depends on it, naming the dependants (R-VI-134); an optional dependant enters its **declared** degraded mode (R-VI-135) |

**R-XXI-108** A tenant MUST NOT be able to enable a module it is not entitled
to. `tenant_enabled` is a narrowing (R-VI-130).

**R-XXI-109** Disabling a module MUST NOT disable a **compliance obligation
already raised**. Obligations remain in the register, visible and owned; only the
module's screens and jobs stop. A tenant cannot make an obligation disappear by
switching off a module.
*Rationale — the whole point of §XXI.2, and the reason a compliance register may
not be a module-private table.*

### XXI.9.3 Module, feature, pack — the three nouns

| Noun | Granularity | Owned by | Toggled by |
|---|---|---|---|
| **Module** | a coherent product area a customer recognises and a price list can name | the module registry (platform) | tenant admin (within entitlement) |
| **Feature** | a capability inside a module, individually grantable and limit-bearing | `feature_catalogue` (Part VI) | entitlement grant, then tenant narrowing |
| **Module pack** | a distributable bundle that *provides* modules, features and configuration | `module_packs` (§XXI.10) | installation, then entitlement |

**R-XXI-110** Installing a pack MUST NOT itself grant entitlement. Installation
makes the module *present*; the grant makes it *usable*. The two are separately
audited.
*Rationale — `N-…` D-08/D-09: three price points shipping a byte-identical
product, and plan gating dead in two independent places while the landing page
sells it. Conflating install with grant is how that happened.*

---

## XXI.10 The module pack *(brief Section 81)*

### XXI.10.1 What it is, and how it differs from a Policy Pack

Part VI §VI.12 specifies **Customer Policy Packs** — signed, versioned,
Ametecs-authored configuration bundles with slots, regression cases, lineage and
the three-state fork model (`pristine｜forked｜detached`). A **module pack** is a
Policy Pack with four additional powers, and inherits everything else unchanged.

| | Policy Pack (VI.12) | **Module pack** (this section) |
|---|---|---|
| Configuration artefacts (rules, formulas, workflows, templates, policies, calendars) | yes | yes |
| **Entity and field definitions** (spine S9) | no | **yes** |
| **Module and feature registry entries** | no | **yes** |
| **Report, dashboard and widget definitions** | reports only, within existing modules | **yes**, including dashboards |
| **Seeded master data** (value sets, issuing bodies, case types, document types) | limited | **yes**, declared and idempotent |
| Signing, slots, conflict handling, fork model, versioning, deprecation | VI.12 | **identical, reused** |
| May contain PHP | **no** | **no** |

**R-XXI-111** A module pack MUST reuse Part VI's package machinery — the
manifest, the signature verification (R-VI-110), the slot mapping, the
apply-as-drafts default (R-VI-114), the lineage fields (R-VI-115) and the
three-way diff on update (R-VI-115/116). It MUST NOT introduce a second
distribution mechanism.

### XXI.10.2 The manifest

```
module_pack.manifest
  pack_key                  collections-compliance
  name, vendor, description, support_contact
  pack_version              major.minor.patch
  minimum_platform_version
  requires_rule_packs[]     { jurisdiction, statute, min_version }   -- bindings only
  requires_modules[]        module keys that must be present and entitled
  provides_modules[]        module_catalogue rows
  provides_features[]       feature_catalogue rows
  artefacts[]               typed, see §XXI.10.3
  slots[]                   { key, type, prompt, default, required }
  seed_data[]               { table_or_value_set, natural_key, rows_ref }
  regression_cases[]        pinned expected outcomes (R-VI-078)
  migration_hooks[]         DATA transforms only — never DDL, never PHP
  uninstall_policy          retain_data | archive_data
  signature, public_key_id
```

**R-XXI-112** A pack MUST declare `requires_rule_packs` as **bindings**, never as
statutory values. A module pack contains no rate, slab, threshold or statutory
due date.
*Rationale — spine S12 and R-VI-112; the legal-values discipline is not weakened
by the vertical.*

### XXI.10.3 What a pack may and may not contain

| May contain | Notes |
|---|---|
| Entity definitions and field definitions | via spine S9; storage is `entity_field_values`, never a new column (Law 2) |
| Value sets and their members | idempotent by natural key |
| Compliance item types | §XXI.3 |
| Case types, document types, letter types | |
| Rules (SPX) and rule sets | Part VII; actions from the closed catalogue only |
| Formulas | Part VIII |
| Workflows | Part IX |
| Templates — letters, notifications, documents | |
| Report definitions, including the audit pack | Part on reporting |
| Dashboard and widget definitions | §XXI.11 |
| Gate policy matrices | §XXI.6.1 |
| Alert and escalation ladders | |
| Seeded master data — issuing bodies, tracks, categories, KB and help content | tenant-scoped, idempotent, editable after install |
| Module and feature registry entries | §XXI.9.1 |
| Regression cases | run on apply |

| MUST NOT contain | Why |
|---|---|
| **PHP, JavaScript, Python or any executable code** | spine S5: customer-supplied code is never accepted anywhere, for any customer, under any configuration |
| **DDL of any kind** | Law 2 |
| Statutory values | spine S12 |
| Credentials | R-VI-102 |
| Tenant business data — people, punches, payslips, evidence | a pack is configuration |
| A second navigation file, a second permission model, a second scheduler | it declares into the registries; it does not bring its own |
| Anything the Configuration Studio could not have produced | R-VI-113: a pack is authored in a pack-authoring tenant and exported |

**R-XXI-113** Pack import MUST be **validated against the same catalogue** as
ordinary configuration publication (Part VI §VI.9), including the SPX symbol
table, type checking, scope-conflict detection and the statutory-floor check. A
pack is not privileged.

**R-XXI-114** Pack import MUST be **idempotent**: re-importing the same version
produces "no change" for every artefact (R-VI-105).

### XXI.10.4 Distribution, install, upgrade, uninstall

| Step | Behaviour |
|---|---|
| **Publish** | Ametecs signs the pack with the private key that never leaves Ametecs (spine S12, S18); installations carry only the public key |
| **Discover** | the tenant sees available packs, their versions, their `minimum_platform_version` and their entitlement requirement |
| **Verify** | signature verified on import; a failure **rejects**, never warns (R-VI-110) |
| **Preview** | artefact counts, conflicts, the slot list, the impact preview and the regression case list, before anything is written |
| **Install** | applies **as drafts** by default; "apply and publish" is a separate, permissioned action that runs full validation and the pinned regression cases (R-VI-114) |
| **Entitle** | separate act (R-XXI-110) |
| **Upgrade** | three-way diff per artefact against the fork state; nothing is ever overwritten silently (R-VI-115/116) |
| **Deprecate** | a version may be deprecated with a stated successor; tenants are notified, never auto-migrated |
| **Uninstall** | modules and features are withdrawn; **configuration and data are retained or archived per `uninstall_policy`, never deleted**; obligations already raised remain in the register (R-XXI-109) |
| **On-premise** | identical. A pack is content, delivered like a rule pack, requiring **no application release** (spine S18) |

**R-XXI-115** Pack installation, upgrade, entitlement change and uninstall MUST
each write to the tenant's hash-chained audit ledger with the pack key, version,
signature key id, actor and artefact counts.

### XXI.10.5 Customising after installing

**R-XXI-116** Every artefact a pack creates carries `origin_pack_key`,
`origin_pack_version` and `origin_checksum`, and its lineage state is
`pristine｜forked｜detached` (R-VI-115). A customer edits a pack artefact through
the ordinary Studio; the edit forks it; a later pack update is offered as a
three-way diff and never overwrites the edit.

**R-XXI-117** A customer MUST be able to **add** its own artefacts alongside a
pack's — a further compliance item type, an extra gate matrix cell, an extra
dashboard widget — without forking any pack artefact, and those additions MUST
survive every pack upgrade untouched.
*Test:* install v2.1, add a tenant item type and a widget, upgrade to v2.2, and
both survive with no diff prompt.

### XXI.10.6 The candidate pack catalogue

| Pack | Core need it dresses | Distinctive contents |
|---|---|---|
| **Collections Compliance** *(reference implementation — §XXI.10.7)* | credential, case, policy ack, gate | DRA/PCC/agent-authorisation types, borrower complaints, escalations, the regulatory audit pack, the collections gate matrix, off-roll payout shapes, client/portfolio entities, the bank billing pack |
| **BPO** | shifts, roster, attendance | 24×7 rotation patterns, night-shift allowance, shift-anchored attendance day, occurrence-based late policy, client-process cost attribution, seat utilisation, transport rosters |
| **Manufacturing** | attendance, statutory, safety | contract-labour registers and licences, factory registers and returns, shop-floor shift patterns, piece-rate and production incentives, safety training and PPE certification expiry, machine operator authorisations |
| **Healthcare** | credential, roster | clinical registration and renewal (council registration validity), immunisation records, duty-roster patterns for clinical staff, restricted-visibility health data, mandatory training cadences |
| **Retail** | roster, incentives | store rosters and footfall-linked scheduling, cashier till accountability, store-level targets and incentives, seasonal and part-time engagement kinds |
| **Field Force** | attendance, geolocation | geofenced attendance, visit plans and beat coverage, travel and conveyance rules, per-visit payouts, vehicle document expiry |
| **Professional Services** | timesheets, billing | client/project/timesheet structures, billable utilisation, engagement-based costing, professional certification expiry |

**R-XXI-118** Every pack in this catalogue MUST be expressible with **no core
code change**. If a candidate pack cannot be, the missing capability is a **core
gap** and is raised as a platform requirement rather than as a pack exception.
*Test:* each pack's manifest passes pack validation against the platform's
artefact-type catalogue with no unsupported artefact type.

### XXI.10.7 The Collections Compliance pack, specified concretely

The reference implementation, and the migration target for every existing
SmartPRS 2 collections tenant.

```
pack_key           collections-compliance
provides_modules   collections.compliance_register
                   collections.agent_authorisation
                   collections.audit_pack
                   collections.client_billing
                   collections.escalation_desk
                   collections.offroll_payouts
requires_modules   core.compliance, core.cases, core.policies,
                   core.people, core.money_movement, core.documents
requires_rule_packs IN (TDS: 194H binding), IN-<state> (PT binding)  -- bindings only
```

**Entity and field definitions**

| Entity | Fields the pack adds |
|---|---|
| `client` | client type (bank｜NBFC｜ARC｜fintech), regulator category, GSTIN, billing entity, contract reference, contract validity, empanelment status |
| `client_portfolio` | portfolio code, product class, geography set, bucket range, commercial terms reference, requires-armed-escort flag, compliance annexure reference |
| `engagement` (off-roll extensions) | agent code, payout shape, per-visit rate reference, bank/UPI reference (encrypted), vendor linkage |
| `compliance_item` (DRA) | certificate number, institute, **track**, examination date |
| `case` (borrower complaint) | borrower reference (masked), loan account reference, complaint channel, RBI category, resolution SLA |

**Compliance item types seeded**

| Code | Kind | Subject | Valid states | Evidence | Gate bindings |
|---|---|---|---|---|---|
| `DRA-CERT` | credential | engagement | `[verified]` | required, regulatory | commission ▸ block · incentive ▸ block · off-roll earning ▸ block · work allocation ▸ block |
| `PCC` | credential | engagement | `[verified]` | required, regulatory | commission ▸ warn (30d) · work allocation ▸ block |
| `BGV` | verification | engagement | `[verified]` | required | commission ▸ warn |
| `AGENT-AUTHORISATION` | registration | engagement × portfolio | `[verified]` | letter required | commission ▸ block · portfolio assignment ▸ block · letter issue ▸ block |
| `CODE-OF-CONDUCT-ACK` | acknowledgement | engagement | `[acknowledged]` | none | commission ▸ warn · work allocation ▸ warn |
| `DRA-TRAINING` | training | engagement | `[verified]` | certificate | off |
| `CLIENT-EMPANELMENT` | registration | client_portfolio | `[verified]` | agreement | client billing ▸ block |

*Every state name, window, weight and severity above is a **pack default**, and
every one is editable by the tenant after install (R-XXI-116).*

**Case types:** `BORROWER-COMPLAINT`, `ESCALATION`, `DISCIPLINARY-CASE`.

**Policies:** the code-of-conduct policy document, versioned, tenant-owned,
seeded with content — replacing `kb_topics WHERE category = 'Code of Conduct'`
with **no tenant filter** (SEC-F-06).

**Rules:** the gate matrix (§XXI.6.1 collections column); the lawful
borrower-contact window rule (the `contact_window_start`/`contact_window_end`
keys that exist in SmartPRS 2's defaults and are **unsettable through any UI** —
`F-…` DEFECT-F-36); complaint-window measures; verdict rules.

**Reports:** the per-agent regulatory audit pack, its bulk variant, the specimen
variant, the client billing pack (payout register + 194H annexure + GST service
invoice), the compliance scorecard, the exposure report.

**Dashboards:** the compliance officer dashboard and the collections manager
dashboard (§XXI.11.4).

**Seeded master data:** issuing bodies, DRA track value set, RBI complaint
categories, product classes, the collections KB and screen-help content that
SmartPRS 2 seeds today per tenant (`H-…` §1.19: 12 training programmes, 25
subjects, 17 FAQs, 16 KB topics) — **seeded per tenant, never globally**, fixing
the `tenant_id = NULL` KB defect.

**Migration hooks (data only):** the `offroll_agents` triage of R-XXI-087; the
`commissions.portfolio` string → `client_portfolios` id reconciliation with an
exception report; the `dra_certs`/`bgv`/`agent_authorizations`/`complaints`/
`escalations`/`code_of_conduct_ack` register conversions.

---

## XXI.11 Dashboards *(brief Section 77)*

### XXI.11.1 The principle

**A dashboard shows actions, not counts.**

The brief insists on it, and the as-is analysis explains why the insistence is
necessary. SmartPRS 2's dashboard is `DashboardController::stats()`: **six fixed
cards** chosen by a branch on `hasRole('super_admin')` — *"a branch selector, not
a guard"* — with *"no card selection, no reordering, no custom KPIs, no
thresholds, no drill-through configuration"* (`H-…` NEW-DSH-101). A
`tenant_id IS NULL` non-super-admin falls through to the company card set **with
every tenant filter skipped**, producing cross-tenant totals (NEW-DSH-102). The
first tile carries a deliberate colour test marker with an un-actioned revert
instruction, shipped to every client (NEW-DSH-104). Global search on the same
controller has **no role guard and no self-scope**, so a self-service employee can
enumerate every colleague's contact details (NEW-DSH-105). A second dashboard —
`index()` plus `dashboard.blade.php` — is dead code that would throw
`RouteNotFoundException` on render and reads a schema that no longer exists
(NEW-DEFECT-10). The migration verdict is **REPLACE**: *"Needs a configurable
widget model over the report/metric layer"* (`H-…` §9).

**R-XXI-119** Every dashboard widget MUST resolve to a **work queue**: a
filtered list of records that a named role can act on, reachable in one click
from the widget, with the action available in that list. A widget that offers no
action is not a widget; it belongs in a report.
*Test:* a dashboard-conformance test asserts that every published widget declares
a non-empty `drill_target` and at least one `action`, and fails the build on a
count-only widget.

**R-XXI-120** A widget's number and its drill-through list MUST be produced by
the **same query definition**, so the count can never disagree with the list.
*Test:* for every widget, `count(drill list) == displayed value` on a seeded
fixture.

### XXI.11.2 The widget model

A dashboard is a configuration artefact (Part VI lifecycle); a widget is a typed
row inside it.

```
cfg_dashboard
  dashboard_key, name, description
  audience_expression      SPX Boolean over role, scope, module entitlement
  scope, priority, effective_from/to, version          (spine S4)
  layout_json              ordered widget placements, responsive breakpoints
  origin_pack_key          nullable

cfg_dashboard_widget
  widget_key, dashboard_key, position
  widget_kind              action_queue | metric_with_target | trend
                         | exception_list | approval_inbox | timeline
                         | health | shortcut
  title_template           translatable
  data_source              report_definition_key | metric_key | register_view_key
  filter_expression        SPX Boolean, evaluated in the viewer's scope
  drill_target             a register view, report or screen — MANDATORY
  actions[]                { action_key, label, permission_key, bulk_allowed }
  thresholds[]             { comparator, value_expr, severity, style_token }
  empty_state_text         what "nothing to do" says — never a blank card
  refresh_policy           on_load | interval(n) | on_event(event_key)
  required_feature_keys[]  → entitlement (§XXI.9)
  required_permission_key
  max_rows, cache_ttl_seconds
```

**R-XXI-121** Widgets, dashboards, thresholds, ordering, audiences and empty
states MUST be configuration. Nothing in this model is a literal in code.
*Rationale — `H-…` §5: the dashboard cards are named alongside report
definitions, screen help and document layouts as **not changeable by a tenant
admin**.*

**R-XXI-122** A widget MUST be evaluated in the **viewer's** data scope on the
server (spine S13). A dashboard never widens scope, and a role check is never a
branch selector.
*Test:* a cross-tenant isolation test exists per widget kind; an
unresolved-tenant user receives **no** dashboard, never an installation-wide one.
*Rationale — NEW-DSH-102 exactly.*

**R-XXI-123** A widget whose data source errors MUST render a **visible degraded
state** naming the widget, and MUST NOT render zero. Zero is a business fact.
*Rationale — a zero on a compliance dashboard is indistinguishable from a failed
query, and `F-…` DEFECT-F-09 is the same confusion in a different artefact.*

**R-XXI-124** Widget queries MUST be bounded and budgeted: every widget declares
`max_rows` and a cache TTL, and the dashboard as a whole has a query-count and
response-time budget asserted in CI against a seeded 10,000-employee, 5-year
tenant.
*Rationale — `CONSOLIDATED-REQUIREMENTS` P0 performance budget names `/dashboard`
explicitly.*

### XXI.11.3 Widget kinds

| Kind | Shows | Always carries |
|---|---|---|
| `action_queue` | a count plus the queue behind it | drill list + inline actions |
| `approval_inbox` | items awaiting **this viewer's** decision | approve/reject/return, respecting maker-checker |
| `exception_list` | things that are wrong and named | the fix action per row |
| `metric_with_target` | a number against a configured target and threshold bands | the drill list of contributors |
| `trend` | a measure over periods | drill to the period's contributors |
| `timeline` | what falls due in the next N days | the obligation, its owner, its action |
| `health` | scheduler heartbeat, queue depth, failed jobs, device sync, backup and last restore test (spine S18) | the operations screen |
| `shortcut` | a permissioned entry point to a task | the task |

### XXI.11.4 Role dashboards

Shipped as configuration; every one is editable and every widget is subject to
entitlement and permission.

| Dashboard | Audience | Widgets (all action-bearing) |
|---|---|---|
| **My work** | every user | my pending approvals · my compliance obligations · my leave and requests · my documents expiring · my acknowledgements due |
| **Manager (MSS)** | reporting managers | team approvals awaiting me · team absent today · team attendance exceptions · team compliance failures blocking work · team probation reviews due |
| **HR operations** | HR manager | employees missing mandatory documents · pending punch mappings · joiners not onboarded · confirmations due · exits pending settlement · unassigned compliance obligations |
| **Payroll** | payroll operator, finance | runs awaiting approval · preview exceptions blocking the run · employees missing bank/PAN/UAN · items blocking payment at a gate · statutory obligations due this month |
| **Compliance officer** | compliance role | credentials expiring by ladder rung · overdue obligations by risk · **not assessed** items · awaiting verification · gate blocks and warnings this period · audit packs issued this month |
| **Collections manager** *(pack)* | collections | agents blocked from allocation · authorisations expiring by portfolio · open borrower complaints by SLA · escalations by client · commission claims awaiting Accounts confirmation |
| **Operations / IT** | admin, platform support | scheduler heartbeat · queue depth and oldest job · failed jobs · devices offline · webhook failures · backup status and **last successful restore test** |
| **Platform (Ametecs)** | super admin | tenants past due · installations behind on rule packs (exposure register) · failed pack installs · licences expiring |

**R-XXI-125** The platform dashboard MUST be reachable only by a platform role
**and** MUST be a distinct dashboard, never the tenant dashboard with different
numbers.
*Rationale — NEW-DSH-101: the role check selects a card set rather than gating.*

### XXI.11.5 The worked examples

Each of the brief's examples, with the action it links to. This table is the
acceptance criterion for R-XXI-119.

| Widget | Number | Drill-through | Actions available in the queue |
|---|---|---|---|
| **Missing PCC** | 7 employees missing PCC | Command Centre → `item_type = PCC AND state IN (due, overdue)` | request document (bulk) · upload on behalf · assign owner · waive with reason · view gate exposure |
| **Payroll approvals** | 3 payroll records require approval | Payroll → run lines `state = awaiting_approval` in my scope | approve · reject with reason · return for edit · view "How was this calculated?" |
| **Absent yesterday** | 5 absent yesterday | Attendance → `date = yesterday AND status = absent` in my scope | mark leave · raise regularisation · notify employee · view punches |
| **Devices offline** | 2 biometric devices offline | Devices → `health = offline` | test connection · view last punch · view pending punches · raise ticket |
| **Statutory due** | PF due in 4 days | Command Centre → `head = PF AND period = current` | prepare ECR · view reconciliation · record payment · record filing · reassign owner |
| **Documents expiring** | 12 documents expire this month | Command Centre → `due_on within month` grouped by type | request renewal (bulk) · upload · verify · extend with reason |
| **Not assessed** | 4 checks could not be performed | Command Centre → `assessment_basis = not_assessed` | view reason · fix configuration · re-run assessment |
| **Blocking payment** | 9 subjects blocking payment | Gate decisions → `verdict = block AND context open` | view the failing item · override with reason (permissioned) · fix the item |
| **Unassigned** | 6 obligations have no owner | Command Centre → `owner IS NULL` | assign · set the type's default owner rule |
| **Authorisations expiring** *(pack)* | 3 authorisations expire in 30 days | `item_type = AGENT-AUTHORISATION` by portfolio | request renewal from client · reissue letter · suspend allocation |

**R-XXI-126** Every widget MUST declare an **empty state** that reads as
completion, not as absence: *"No compliance items are overdue."* A blank card is
a defect.

**R-XXI-127** A widget MUST NOT display a compliance count that mixes assessed
and unassessed items. Where both exist, the widget shows both figures.
*Rationale — R-XXI-054.*

---

## XXI.12 Configuration surface

Everything a tenant administrator or consultant can change without code, and
where it lives. Tested against the Section 127 standard by §XXI.3.11.

| # | What | Where | Scoped? | Effective-dated? | Approval? |
|---:|---|---|---|---|---|
| 1 | Create, edit, retire a **compliance item type** | Studio → Compliance | yes | yes | high-risk: yes |
| 2 | Applicability expression per type | same | yes | yes | yes |
| 3 | Periodicity, period anchor, due-date rule, non-working-day adjustment | same | yes | yes | yes |
| 4 | Validity source, validity period, renewal lead | same | yes | yes | yes |
| 5 | **Valid state set** — the single definition of "valid" | same | yes | yes | **yes, mandatory** |
| 6 | Owner assignment strategy chain | same | yes | yes | no |
| 7 | Evidence requirement, document types, size and MIME limits, evidence class | same | yes | yes | no |
| 8 | Verification workflow selection; self-verification permission | same | yes | yes | yes |
| 9 | Alert ladder — offsets, buckets, **both** window bounds, audience, channel, template per rung | same | yes | yes | no |
| 10 | Escalation ladder — rungs, targets, actions including `gate_tighten`, SLA | same | yes | yes | no |
| 11 | Risk level per type; per-instance override permission | same | yes | yes | no |
| 12 | Score weights, partial-credit map, cap, scale | Studio → Compliance → Scoring | yes | yes | yes |
| 13 | **Gate matrix** cells: item type × action type × severity × grace × `on_not_assessed` | Studio → Compliance → Gates | yes | yes | **yes** |
| 14 | Override permission, override workflow, override reason requirement | same | yes | yes | yes |
| 15 | Sweep cadence, send hour, timezone per tenant | Studio → Compliance → Schedule | tenant | no | no |
| 16 | Digest audience, item cap, overflow behaviour, dedupe window | same | yes | no | no |
| 17 | Register views, their filters, their default sort, who sees them | Studio → Compliance → Views | yes | no | no |
| 18 | Waiver policy — who may waive, whether a workflow is required, maximum waiver duration | Studio → Compliance | yes | yes | yes |
| 19 | Retention class per item type and per evidence class | Studio → Data governance | tenant | yes | yes |
| 20 | **Policy documents**: text, versions, audience, re-acknowledgement on bump | Studio → Policies | yes | yes | yes |
| 21 | Case types, their states, SLAs, visibility restrictions | Studio → Cases | yes | yes | no |
| 22 | Clients, client portfolios and their attributes *(pack)* | Masters | n/a | n/a | no |
| 23 | Audit pack item set, groups, order, weights, verdict bands, layout, branding | Studio → Reports | yes | yes | **yes** |
| 24 | Audit pack redaction policy | same | yes | yes | yes |
| 25 | Dashboards, widgets, thresholds, targets, ordering, audiences, empty states | Studio → Dashboards | yes | yes | no |
| 26 | Module enable/disable within entitlement | Administration → Modules | tenant | no | no |
| 27 | Install, upgrade, uninstall a module pack; fill its slots | Administration → Packs | tenant | n/a | yes |
| 28 | Engagement kinds and their payroll/payout attributes | Studio → People | tenant | yes | yes |
| 29 | Off-roll payout shapes and rates *(pack)* | Studio → Money | yes | yes | yes |
| 30 | Token lifetimes for subject-facing surfaces (evidence upload, acknowledgement, earnings view) | Studio → Security | tenant | no | yes |

**Not tenant-changeable, by design:** the ten-state vocabulary (§XXI.2.4), the
`missing_record_is_valid = false` rule (R-XXI-041), `on_not_assessed = off` for
regulatory types (R-XXI-054), the gate's fail-closed behaviour (R-XVIII-146), the
integrity-hash construction (R-XXI-079), the spine S3.2 precedence weights, and
any rule-pack-derived obligation definition (R-XXI-005).

---

## XXI.13 Data model

To the Section 123 standard. Conventions per spine S16 and Part XXVIII: every
table carries `id BIGINT UNSIGNED PK`, `tenant_id BIGINT UNSIGNED NOT NULL` (in
every unique index), `uuid BINARY(16)` on aggregate roots, `created_at`,
`updated_at`, and `created_by_user_id` where a human act creates the row. No
database `ENUM`; every vocabulary is a reference table. Archetypes: **A1**
aggregate root · **A3** child/line · **A4** append-only log · **A5**
effective-dated · **cfg payload** (immutable once its `config_version_id` is
published).

Part XXVIII §C11 registers six of these tables in outline. This section
specifies them fully and adds the remainder. **Where a column name differs from
the C11 outline, this part's spelling is the specification and Part XXVIII's
register should be updated** (noted in §XXI.20 as a cross-part reconciliation
item, not a design disagreement).

### XXI.13.1 `cfg_compliance_item_types` — cfg payload

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | — | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | |
| `config_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT → `config_versions` |
| `item_type_code` | VARCHAR(40) | N | — | stable identity; behaviour never derives from the name (R-XXI-024) |
| `name` | VARCHAR(160) | N | — | translatable |
| `description`, `help_text` | TEXT | Y | NULL | |
| `origin_code` | VARCHAR(20) | N | `tenant` | `core｜rule_pack｜module_pack｜tenant` |
| `origin_pack_key` | VARCHAR(80) | Y | NULL | with `origin_pack_version`, `origin_checksum` (R-XXI-116) |
| `obligation_kind_code` | VARCHAR(30) | N | — | `credential｜acknowledgement｜verification｜recurring_task｜filing｜training｜registration` |
| `subject_kind_codes_json` | JSON | N | — | one or more of §XXI.3.3 |
| `applies_expression` | TEXT | Y | NULL | SPX Boolean; NULL = always applicable |
| `applies_expression_ast` | JSON | Y | NULL | compiled AST stored with the source (spine S5) |
| `applies_reason_template` | VARCHAR(400) | Y | NULL | rendered into `not_applicable` rows |
| `periodicity_code` | VARCHAR(30) | N | — | |
| `period_anchor_code` | VARCHAR(30) | N | — | |
| `due_rule_expression` | TEXT | Y | NULL | SPX Date |
| `non_working_day_adjust_code` | VARCHAR(30) | N | `none` | `none｜previous_working_day｜next_working_day` |
| `attention_window_expression` | TEXT | N | — | SPX Days |
| `materialise_lead_expression` | TEXT | N | — | SPX Days |
| `validity_source_code` | VARCHAR(30) | N | — | `issue_plus_period｜explicit_expiry｜perpetual｜periodic_occurrence` |
| `validity_expression` | TEXT | Y | NULL | SPX Duration or Date |
| `renewal_lead_expression` | TEXT | Y | NULL | SPX Days |
| `owner_assignment_json` | JSON | N | — | the ordered strategy chain (§XXI.3.5) |
| `evidence_requirement_code` | VARCHAR(30) | N | `optional` | `none｜optional｜required_to_submit｜required_to_verify` |
| `evidence_document_type_codes_json` | JSON | Y | NULL | from the document-type catalogue |
| `evidence_class_code` | VARCHAR(20) | N | `internal` | `internal｜contractual｜regulatory` |
| `evidence_min_count`, `evidence_max_count` | SMALLINT UNSIGNED | Y | NULL | |
| `accepted_mime_types_json`, `max_bytes` | JSON / INT UNSIGNED | Y | NULL | magic-byte validated |
| `verification_workflow_key` | VARCHAR(80) | Y | NULL | FK-by-key → `cfg_workflows` |
| `verification_expiry_expression` | TEXT | Y | NULL | SPX Days — verification itself expires |
| `self_service_allowed` | TINYINT(1) | N | 1 | |
| `self_verification_allowed` | TINYINT(1) | N | 0 | forced 0 when `evidence_class = regulatory` (CHECK) |
| `valid_state_codes_json` | JSON | N | — | **the single definition of "valid"** (R-XXI-052) |
| `missing_record_is_valid` | TINYINT(1) | N | 0 | CHECK `= 0` — not configurable (R-XXI-041) |
| `on_not_assessed_code` | VARCHAR(10) | N | `block` | CHECK: not `off` when `evidence_class = regulatory` |
| `risk_level_code` | VARCHAR(20) | N | `medium` | |
| `risk_override_permitted` | TINYINT(1) | N | 1 | |
| `score_weight` | DECIMAL(9,4) | N | 0.0000 | |
| `score_partial_credit_json` | JSON | Y | NULL | state → fraction |
| `issuing_body_value_set_key` | VARCHAR(80) | Y | NULL | |
| `reference_format_regex` | VARCHAR(200) | Y | NULL | anchored, step-limited |
| `custom_entity_key` | VARCHAR(80) | Y | NULL | spine S9 field definitions for this type |
| `retention_class_code` | VARCHAR(40) | N | — | FK-by-key → `cfg_retention_classes` |
| `pii_class_code` | VARCHAR(20) | N | `personal` | |
| `waiver_max_days`, `waiver_workflow_key` | SMALLINT / VARCHAR(80) | Y | NULL | |
| `is_active` | TINYINT(1) | N | 1 | retire without delete |

**Keys.** PK `id` · UQ `(config_version_id)` · UQ `(tenant_id, item_type_code,
config_version_id)` · IX `(tenant_id, is_active)`.
**Policy.** Tenant-owned · versioned (S4) · **immutable once published** · soft
delete: no (retire) · audit `full` · PII `none` · retention `R-PERM`.
*Why:* replaces a closed set of PHP literals and five registry rows in a 460-line
config array (`F-…` BR-CMP-006; `N-…` §1.8.2).

**Children (A3, all keyed `(tenant_id, cfg_compliance_item_type_id, line_no)`):**

| Table | Payload |
|---|---|
| `cfg_compliance_alert_rungs` | `offset_days` (signed), `bucket_code`, `audience_ref`, `channel_code`, `template_key`, `dedupe_window_hours` |
| `cfg_compliance_escalation_rungs` | `trigger_kind_code` (`days_after_due｜days_in_state`), `trigger_value`, `target_kind_code`, `target_ref`, `action_code`, `sla_hours` |
| `cfg_compliance_gate_bindings` | `gate_point_code`, `required_state_expression`, `severity_code`, `grace_days_expression`, `as_of_basis_code`, `message_template_key` — compiled into `cfg_gate_policies` requirement rows (Part XVIII) |
| `cfg_compliance_type_checks` | for `verification` kinds: `check_code`, `label`, `is_mandatory` (the six BGV components become six rows, not six columns) |

### XXI.13.2 `compliance_items` — A1

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | BIGINT UNSIGNED | N | — | |
| `uuid` | BINARY(16) | N | — | UUIDv7; the only external identifier |
| `item_type_code` | VARCHAR(40) | N | — | |
| `item_type_config_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT — **the version that governed this instance** |
| `subject_kind_code` | VARCHAR(40) | N | — | |
| `subject_id` | BIGINT UNSIGNED | N | — | polymorphic by kind; FK enforced per kind by a trigger-free pattern of one nullable typed column per kind — see note |
| `subject_engagement_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT → `engagements` |
| `subject_org_unit_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT → `org_units` |
| `subject_client_portfolio_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `subject_device_id`, `subject_asset_id`, `subject_vendor_id`, `subject_person_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `counterparty_client_portfolio_id` | BIGINT UNSIGNED | Y | NULL | agent authorisation's issuing portfolio |
| `period_code` | VARCHAR(20) | Y | NULL | for periodic obligations |
| `reference_no` | VARCHAR(120) | Y | NULL | certificate/authorisation number |
| `issuing_body_code` | VARCHAR(60) | Y | NULL | |
| `issued_on` | DATE | Y | NULL | |
| `valid_from`, `valid_to` | DATE | Y | NULL | derived by the type's validity rule; `valid_to` NULL = perpetual |
| `due_on` | DATE | Y | NULL | derived; NULL only for `event_driven` types |
| `due_rule_trace_json` | JSON | Y | NULL | how `due_on` was derived (spine S15) |
| `lifecycle_state_code` | VARCHAR(30) | N | `upcoming` | §XXI.2.4 |
| `assessment_basis_code` | VARCHAR(20) | N | `evidence` | `evidence｜derived｜not_assessed` (R-XXI-004) |
| `not_applicable_reason` | VARCHAR(400) | Y | NULL | mandatory when state = `not_applicable` (CHECK) |
| `owner_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL surfaces on the Unassigned view |
| `owner_role_key` | VARCHAR(60) | Y | NULL | the strategy that resolved (or failed) |
| `risk_level_code` | VARCHAR(20) | N | — | from the type; overridable |
| `risk_override_reason` | VARCHAR(400) | Y | NULL | mandatory when it differs from the type (CHECK) |
| `evidence_state_code` | VARCHAR(20) | N | `none` | `none｜attached｜verified｜rejected｜waived` |
| `evidence_set_sha256` | BINARY(32) | Y | NULL | hash of the verified evidence set (R-XXI-037) |
| `verified_by_user_id`, `verified_at` | | Y | NULL | |
| `verification_workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `verification_expires_on` | DATE | Y | NULL | |
| `submitted_at`, `submitted_by_user_id` | | Y | NULL | |
| `paid_reference`, `paid_on`, `paid_amount_minor`, `paid_currency` | | Y | NULL | |
| `filed_reference`, `filed_on`, `filed_by_user_id` | | Y | NULL | |
| `acknowledged_reference`, `acknowledged_on` | | Y | NULL | |
| `is_waived` | TINYINT(1) | N | 0 | |
| `waiver_reason`, `waived_by_user_id`, `waiver_expires_on`, `waiver_workflow_instance_id` | | Y | NULL | reason mandatory when `is_waived` (CHECK) |
| `escalation_rung`, `next_escalation_at` | SMALLINT / DATETIME | Y | NULL | |
| `last_alert_rung`, `last_alerted_at` | | Y | NULL | ladder dedupe key |
| `superseded_by_item_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — renewal chain |
| `supersedes_item_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `source_system_code`, `source_record_ref` | | Y | NULL | migration provenance |
| `created_by_user_id`, `created_at`, `updated_at` | | N/Y | | |

**Keys.** PK `id` · UQ `(tenant_id, uuid)` · UQ
`(tenant_id, item_type_code, subject_kind_code, subject_id, period_code,
issued_on)` — the natural key; `period_code` and `issued_on` are the two
discriminators a renewal changes.
**Indexes.**
`(tenant_id, valid_to)` — the expiry sweep;
`(tenant_id, due_on, lifecycle_state_code)` — the register's default ordering;
`(tenant_id, subject_kind_code, subject_id, item_type_code)` — **the gate
lookup**, the hottest path in the system;
`(tenant_id, lifecycle_state_code, risk_level_code)` — the Overdue view;
`(tenant_id, owner_user_id, lifecycle_state_code)` — "My obligations";
`(tenant_id, assessment_basis_code)` — the Not assessed view;
`(tenant_id, next_escalation_at)` — the escalation sweep;
`(tenant_id, counterparty_client_portfolio_id)` — pack views.
**Constraints.** CHECK: `valid_to >= valid_from`; state `not_applicable` requires
a reason; `is_waived` requires a reason and an authoriser; a satisfying state
requires its reference column (R-XXI-008 enforced in schema, not only in
service).
**Policy.** Tenant-owned · history: supersession, never update-in-place for
identity fields · **delete: never** · audit `full` · PII **`sensitive`** (a PCC
or BGV result is about a person) · retention from the type's class, floor
`R-STAT-8Y`.

*Why every index exists:* `F-…` DEFECT-F-38 — on a runtime-built SmartPRS 2
install `dra_certs.employee_id`, `dra_certs.expiry`, `bgv.next_due`,
`code_of_conduct_ack.user_id` and `agent_earnings.agent_id` are **unindexed**, and
the nightly scan degrades linearly with headcount across every tenant on the
server.

> **Note on the polymorphic subject.** One typed nullable FK column per subject
> kind, plus a CHECK asserting that exactly the column matching
> `subject_kind_code` is non-null, and `subject_id` maintained as a generated
> column for the composite indexes. This buys real referential integrity —
> SmartPRS 2 has **zero foreign keys on business tables** and ships an admin
> endpoint whose job is sweeping up orphans (spine S16).

### XXI.13.3 `compliance_item_transitions` — A4

`compliance_item_id` FK CASCADE, `seq`, `from_state_code`, `to_state_code`,
`actor_user_id` FK RESTRICT, `actor_kind_code` (`user｜system｜integration`),
`reason`, `comment`, `evidence_set_sha256`, `workflow_instance_id`,
`ip_address` VARBINARY(16), `user_agent_hash`, `occurred_at`, `recorded_at`.
**Keys.** PK `(id)` · UQ `(tenant_id, compliance_item_id, seq)` ·
IX `(tenant_id, occurred_at)`, `(tenant_id, to_state_code, occurred_at)`.
**Policy.** Append-only (INSERT/SELECT privilege only) · audit `event-only` ·
retention with the item.
*Why:* makes the register answerable "as at" a date (R-XXI-003) and makes a
decided step immutable (R-XXI-010).

### XXI.13.4 `compliance_evidence` — A3

`compliance_item_id` FK RESTRICT, `line_no`, `document_id` FK RESTRICT,
`evidence_kind_code`, `content_sha256` BINARY(32) NN, `byte_size`,
`mime_type_verified` VARCHAR(120) NN (from magic bytes),
`uploaded_by_user_id` FK RESTRICT, `uploaded_via_code`
(`ess｜mss｜bulk｜api｜token_link｜self_onboarding`), `uploaded_at`,
`verification_state_code`, `verified_by_user_id`, `verified_at`,
`rejection_reason`, `redaction_profile_key`, `is_current` TINYINT(1).
**Keys.** UQ `(tenant_id, compliance_item_id, document_id)` ·
UQ `(tenant_id, compliance_item_id, line_no)` ·
IX `(tenant_id, verification_state_code)`.
**Policy.** Tenant-owned · delete: never (supersede) · audit `full` ·
PII **`sensitive`** · retention with the item · **private storage only**
(R-XXI-035).

### XXI.13.5 `compliance_renewal_tasks` — A3

`compliance_item_id` FK RESTRICT, `raised_on`, `due_on`,
`assigned_user_id` FK RESTRICT, `workflow_instance_id` FK RESTRICT,
`state_code` (`open｜in_progress｜completed｜cancelled`), `completed_at`,
`resulting_item_id` FK RESTRICT.
**Keys.** UQ `(tenant_id, compliance_item_id, raised_on)` ·
IX `(tenant_id, assigned_user_id, state_code)`, `(tenant_id, due_on)`.
*Why:* `F-…` §5.2 item 40 — *"there is no renewal workflow, no agent-facing
notification, no evidence attachment, no re-acknowledgement trigger, and no
working-status block for a non-compliant agent."*

### XXI.13.6 `compliance_scores` — A1 snapshot, and `compliance_score_contributions` — A3

**Parent.** `subject_kind_code`, `subject_id`, `as_of_date`,
`scoring_rule_config_version_id` FK RESTRICT, `score_earned` DECIMAL(9,4),
`score_assessed_max` DECIMAL(9,4), `score_theoretical_max` DECIMAL(9,4),
`not_assessed_weight` DECIMAL(9,4), `band_code`, `computed_at`,
`computed_reason_code` (`item_change｜sweep｜manual｜pack_change`).
**Child.** `item_type_code`, `compliance_item_id` FK RESTRICT NULL,
`state_code`, `assessment_basis_code`, `weight`, `credit_fraction`,
`contribution`.
**Keys.** parent UQ `(tenant_id, subject_kind_code, subject_id, as_of_date)` ·
IX `(tenant_id, band_code)`, `(tenant_id, computed_at)`.
**Policy.** Tenant-owned · append per as-of date · audit `event-only` ·
PII `personal` · retention `R-STAT-8Y`.
*Why three maxima:* a score is reported as *earned / assessed_max*, never as
*earned / 100*, so `not_assessed` cannot masquerade as compliance (R-XXI-054),
and `theoretical_max` makes the SmartPRS 2 defect where a fully compliant agent
tops out at 85 (`N-…` V-08) visible rather than invisible.

### XXI.13.7 `compliance_audit_packs` — A1, and `compliance_audit_pack_findings` — A3

**Parent.** `uuid`, `pack_definition_key`, `pack_definition_config_version_id`
FK RESTRICT, `subject_kind_code`, `subject_id`, `as_of_date`, `window_from`,
`window_to`, `verdict_code`
(`compliant｜compliant_with_open_items｜compliant_partially_assessed｜non_compliant`),
`score_earned`, `score_assessed_max`, `not_assessed_count`,
`scoring_rule_config_version_id` FK RESTRICT, `item_type_versions_json`,
`findings_canonical_json` LONGTEXT NN, `content_sha256` BINARY(32) NN,
`reference_hmac` VARBINARY(64) NN, `signing_key_id` VARCHAR(60) NN,
`document_id` FK RESTRICT, `issued_by_user_id` FK RESTRICT, `issued_at`,
`purpose`, `recipient_name`, `recipient_reference`,
`is_specimen` TINYINT(1) NN DEFAULT 0,
`superseded_by_pack_id` FK RESTRICT, `revoked_at`, `revocation_reason`.
**Child.** `finding_code`, `group_code`, `sequence`, `outcome_code`
(`pass｜attention｜fail｜not_assessed`), `compliance_item_id` FK RESTRICT NULL,
`source_record_kind`, `source_record_uuid`, `evidence_sha256`,
`assessed_at`, `reason`, `weight`, `rendered_text`.
**Keys.** parent UQ `(tenant_id, uuid)`, UQ `(tenant_id, reference_hmac)` ·
IX `(tenant_id, subject_kind_code, subject_id, issued_at)`,
`(tenant_id, verdict_code, issued_at)`, `(tenant_id, is_specimen)` ·
child UQ `(tenant_id, compliance_audit_pack_id, finding_code)`.
**Constraints.** CHECK: a finding with `outcome_code <> 'not_assessed'` MUST
carry a `source_record_uuid`; a finding with `not_assessed` MUST carry a
`reason`. **R-XXI-070 enforced in the schema.**
CHECK: `verdict_code = 'compliant'` requires `not_assessed_count = 0`
(R-XXI-074, in the schema).
**Policy.** Tenant-owned · **append-only** · delete: never · audit `full` ·
PII `sensitive` · retention `R-STAT-8Y`.
*Why:* `N-…` V-04 — *"No table stores an issued report. A bank cannot verify a
presented PDF, and Ametecs cannot prove what a given report said."*

### XXI.13.8 Policy acknowledgement — `cfg_policies`, `cfg_policy_versions`, `policy_acknowledgements`

`cfg_policies` (cfg payload): `policy_code`, `name`, `category_code`,
`requires_acknowledgement`, `reacknowledge_on_version_bump`,
`reacknowledge_period_months`, `audience_expression` (SPX),
`compliance_item_type_code` (the type that carries the obligation).

`cfg_policy_versions` (A3 of `cfg_policies`): `version_no`, `title`,
`body_document_id` FK RESTRICT, `body_sha256` BINARY(32) NN, `language_code`,
`published_on`, `published_by_user_id`, `change_note`, `is_current`.

`policy_acknowledgements` (A4): `policy_code`, `policy_version_id` FK RESTRICT,
`subject_kind_code`, `subject_engagement_id` FK RESTRICT,
`acknowledged_by_user_id` FK RESTRICT **NULL** (a subject may have no login),
`acknowledged_at`, `ip_address` VARBINARY(16), `user_agent_hash` BINARY(32),
`channel_code` (`app｜ess｜token_link｜kiosk｜paper_scan`),
`body_sha256_at_ack` BINARY(32) NN, `evidence_document_id` FK RESTRICT NULL.
**Keys.** UQ `(tenant_id, policy_version_id, subject_engagement_id)` ·
IX `(tenant_id, subject_engagement_id, acknowledged_at)`,
`(tenant_id, policy_code, policy_version_id)`.
**Policy.** Append-only — **an acknowledgement is never updated or deleted**;
audit `full`; PII `personal`; retention `R-STAT-8Y`.
*Why keyed on the **engagement** and hashed against the **text**:* `F-…`
DEFECT-F-11 is caused by resolving the subject through a user lookup that can
match nothing and then dropping the filter; SEC-F-07 by overwriting the single
row; SEC-F-06 by global, untenanted policy text. All three are structurally
impossible against this table.

### XXI.13.9 Cases — `cfg_case_types`, `cases`, `case_events`

`cfg_case_types` (cfg payload): `case_type_code`, `name`, `subject_kind_codes_json`,
`allows_anonymous_subject`, `severity_value_set_key`, `channel_value_set_key`,
`state_machine_key`, `sla_hours_expression`, `visibility_restriction_code`
(`normal｜restricted_committee`), `retention_class_code`,
`counts_toward_item_type_codes_json` (which compliance measures consume it),
`measure_window_expression` (SPX — **the window the audit pack prints**).

`cases` (A1): `uuid`, `case_type_code`, `case_no` (from a transactional
sequence), `subject_kind_code` + typed subject FKs (nullable for anonymous),
`client_portfolio_id` FK RESTRICT NULL, `raised_on`, `raised_by_user_id`,
`source_code`, `channel_code`, `severity_code`, `priority_code`, `summary`,
`detail`, `state_code`, `assigned_user_id` FK RESTRICT, `sla_due_at`,
`resolved_at`, `resolution_code`, `resolution_note`, `closed_at`,
`action_taken`, `linked_case_id`.
**Keys.** UQ `(tenant_id, uuid)`, UQ `(tenant_id, case_no)` ·
IX `(tenant_id, case_type_code, state_code, raised_on)`,
`(tenant_id, subject_kind_code, subject_id, raised_on)` — **the audit pack's
window query**, `(tenant_id, client_portfolio_id, state_code)`,
`(tenant_id, sla_due_at)`.
**Policy.** Tenant-owned · delete: never · audit `full` ·
PII `personal` (`sensitive` for restricted types) · retention per type.

`case_events` (A4): transitions, comments, attachments, SLA breaches.

### XXI.13.10 Clients and portfolios *(pack entities)*

`clients` (A1): `uuid`, `client_code`, `name`, `client_type_code`,
`regulator_category_code`, `gstin` (encrypted + blind index),
`billing_org_unit_id` FK RESTRICT, `contract_reference`,
`contract_valid_from/to`, `empanelment_state_code`, `is_active`.
UQ `(tenant_id, client_code)`, `(tenant_id, uuid)`.

`client_portfolios` (A1): `uuid`, `client_id` FK RESTRICT, `portfolio_code`,
`name`, `product_class_code`, `geography_set_json`, `bucket_from`, `bucket_to`,
`commercial_terms_reference`, `requires_armed_escort` TINYINT(1),
`compliance_annexure_document_id` FK RESTRICT, `valid_from`, `valid_to`,
`is_active`.
UQ `(tenant_id, client_id, portfolio_code)`, `(tenant_id, uuid)` ·
IX `(tenant_id, is_active)`.
**Policy.** Tenant-owned · A5 history on commercial terms · audit `full` ·
PII `none` · retention `R-PERM`.
*Why:* R-XVIII-091; the migration from a free-text `commissions.portfolio` string
is a reconciliation project with a mandatory exception report (§XXI.17).

### XXI.13.11 Module registry and packs *(platform-owned)*

| Table | Archetype | Key columns |
|---|---|---|
| `module_catalogue` | platform cfg | `module_key` U, `name`, `kind_code`, `provided_by_pack_key`, `parent_module_key`, `depends_on_json`, `optional_depends_on_json`, `entitlement_default_code`, `is_removable`, `minimum_platform_version`, `api_tag` |
| `module_navigation_entries` | A3 of `module_catalogue` | `navigation_key` U per module, `label_key`, `route_key`, `sort_order`, `required_permission_key`, `required_feature_key`, `parent_navigation_key` |
| `module_packs` | platform cfg | `pack_key` U, `name`, `vendor`, `kind_code` (`vertical｜policy`), `support_contact` |
| `module_pack_versions` | A3 of `module_packs` | `pack_version` U per pack, `manifest_json`, `minimum_platform_version`, `requires_rule_packs_json`, `artefact_count`, `signature` VARBINARY, `public_key_id`, `published_at`, `deprecated_at`, `successor_pack_version` |
| `tenant_module_pack_installs` | A1, tenant | `pack_key`, `pack_version`, `install_state_code` (`preview｜drafted｜installed｜upgrading｜uninstalled`), `installed_at`, `installed_by_user_id`, `slot_values_json`, `applied_artefact_count`, `regression_result_json`, `uninstalled_at`, `uninstall_policy_code`. UQ `(tenant_id, pack_key)` |

**Policy.** `module_catalogue`, `module_packs`, `module_pack_versions` are
**platform** tables (no `tenant_id`) — the only tables in this part that are, and
they carry no business data, so spine Law 6's "no global business table" is not
breached. `tenant_module_pack_installs` is tenant-owned, audited `full`.

### XXI.13.12 Dashboards

| Table | Archetype | Key columns |
|---|---|---|
| `cfg_dashboards` | cfg payload | `dashboard_key`, `name`, `audience_expression`, `layout_json`, `origin_pack_key`, `is_default_for_role_key` |
| `cfg_dashboard_widgets` | A3 | `widget_key`, `position`, `widget_kind_code`, `title_template`, `data_source_kind_code`, `data_source_key`, `filter_expression`, `drill_target_kind_code` **NN**, `drill_target_key` **NN**, `actions_json`, `thresholds_json`, `empty_state_text` NN, `refresh_policy_code`, `required_feature_keys_json`, `required_permission_key`, `max_rows`, `cache_ttl_seconds` |
| `user_dashboard_preferences` | A1, tenant | `user_id` FK CASCADE, `dashboard_key`, `layout_override_json`, `hidden_widget_keys_json`. UQ `(tenant_id, user_id, dashboard_key)` |

**Constraint.** CHECK: `drill_target_key` is NOT NULL — **R-XXI-119 enforced in
the schema**: a widget without a drill-through cannot be stored.

### XXI.13.13 The engagement change *(amends Part V / Part XXVIII C4)*

**R-XXI-128** The people context's engagement table MUST carry
`engagement_kind_code VARCHAR(30) NOT NULL` (FK-by-code to a reference table
seeded `on_roll｜off_roll｜contractor｜vendor_staff｜trainee｜consultant`), plus
`is_payroll_eligible`, `payout_channel_code` and `consumes_seat` as attributes of
the **kind**, resolved through configuration, never as branches in code.
`offroll_agents` and `agent_earnings` do not exist in SmartPRS 3.
*Cross-part:* this amends Part V §C4 and Part XXVIII cluster C4; recorded in
§XXI.20 as **D-XXI-01** with a recommendation.

### XXI.13.14 Reference vocabularies

Per spine S16, no database `ENUM`. These join the `status_values` domain
mechanism: `compliance_state`, `assessment_basis`, `obligation_kind`,
`subject_kind`, `evidence_state`, `evidence_class`, `verification_state`,
`risk_level`, `gate_severity`, `escalation_action`, `alert_bucket`,
`periodicity`, `period_anchor`, `validity_source`, `case_state`, `case_source`,
`case_channel`, `case_severity`, `client_type`, `empanelment_state`,
`module_kind`, `entitlement_default`, `pack_install_state`, `widget_kind`,
`audit_pack_verdict`, `finding_outcome`, `engagement_kind`.

**R-XXI-129** A module pack MAY introduce values into a declared subset of these
vocabularies (`case_source`, `case_channel`, `case_severity`, `client_type`,
`empanelment_state`, and any pack-declared value set). It MAY NOT introduce a
value into `compliance_state`, `assessment_basis`, `finding_outcome` or
`gate_severity`, because the gate, the score and the pack's verdict logic reason
over those.

---

## XXI.14 API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed on
every unsafe method, with the standard error envelope (spine S17). **Every route
carries an explicit policy binding and an explicit feature declaration**; the CI
sweep fails the build on either being absent (spine S13, R-VI-133).

### XXI.14.1 The register

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/compliance/register` | `compliance.register.view` | the Command Centre. Filters: `subject_kind`, `subject_uuid`, `item_type`, `state`, `assessment_basis`, `risk`, `owner`, `due_from`, `due_to`, `as_of`, `client_portfolio`, `view`. **`due_from` and `due_to` are both mandatory or neither is supplied**, and the span is capped by configuration (R-XXI-014) |
| GET | `/compliance/register/export` | `compliance.register.export` | asynchronous; the export is a document, delivered through a signed expiring URL; **PII columns honour the caller's masking** |
| GET | `/compliance/items/{uuid}` | `compliance.item.view` | full item with evidence metadata (never file bytes) |
| GET | `/compliance/items/{uuid}/trace` | `compliance.item.view` | the `ComplianceTrace` of §XXI.5.5 |
| POST | `/compliance/items` | `compliance.item.create` | creates an instance; type and subject by code/uuid |
| PATCH | `/compliance/items/{uuid}` | `compliance.item.edit` | PATCH semantics; only supplied fields change (spine S9) |
| POST | `/compliance/items/{uuid}/submit` | `compliance.item.submit` or subject-self | |
| POST | `/compliance/items/{uuid}/verify` | `compliance.item.verify` | rejects self-verification per R-XXI-011 |
| POST | `/compliance/items/{uuid}/reject` | `compliance.item.verify` | reason mandatory |
| POST | `/compliance/items/{uuid}/record-payment` | `compliance.item.record_payment` | reference + evidence mandatory |
| POST | `/compliance/items/{uuid}/record-filing` | `compliance.item.record_filing` | reference or acknowledgement mandatory |
| POST | `/compliance/items/{uuid}/waive` | `compliance.item.waive` | reason, authoriser, expiry mandatory |
| POST | `/compliance/items/{uuid}/reassign` | `compliance.item.assign` | |
| POST | `/compliance/items/{uuid}/renew` | `compliance.item.create` | creates the successor and supersedes |
| GET | `/compliance/status` | `compliance.status.read` | the `compliance_status()` service, bulk form: `subjects[]` × `item_types[]` × `as_of` |

### XXI.14.2 Evidence, scores, packs, gates

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/compliance/items/{uuid}/evidence` | `compliance.evidence.upload` | multipart; magic-byte validated; private disk only |
| GET | `/compliance/evidence/{uuid}/download` | `compliance.evidence.view` | **signed, expiring, single-use URL**; every access audited |
| DELETE | `/compliance/evidence/{uuid}` | `compliance.evidence.manage` | supersedes; never hard-deletes |
| GET | `/compliance/scores` | `compliance.score.view` | pre-computed; `earned / assessed_max / theoretical_max` |
| POST | `/compliance/scores/recompute` | `compliance.score.manage` | queued, rate limited, idempotent per (subject, as_of) |
| POST | `/compliance/audit-packs` | `compliance.pack.issue` | body: subject set, as-of, window, purpose, recipient. Returns the issued pack; **requires acknowledgement of the not-assessed count** |
| GET | `/compliance/audit-packs/{uuid}` | `compliance.pack.view` | metadata + findings |
| GET | `/compliance/audit-packs/{uuid}/document` | `compliance.pack.view` | signed expiring URL |
| GET | `/public/compliance/verify/{reference}` | **none** — public, rate limited (R-XXI-080) | returns only: known/unknown, issue date, masked subject, verdict, superseded flag |
| GET | `/compliance/gate-decisions` | `compliance.gate.view` | filter by gate point, subject, verdict, period *(defined in Part XVIII; listed for completeness)* |
| POST | `/compliance/gate/evaluate` | `compliance.gate.evaluate` | a preview evaluation; **never** mutates |

### XXI.14.3 Configuration, packs, dashboards

| Method | Path | Permission |
|---|---|---|
| GET/POST/PATCH | `/config/compliance/item-types` | `compliance.config.view` / `.publish` |
| POST | `/config/compliance/item-types/{key}/simulate` | `compliance.config.view` — evaluates applicability and due dates over a named employee set with no writes |
| GET/PUT | `/config/compliance/gate-matrix` | `compliance.gate.configure` |
| GET/PUT | `/config/compliance/scoring` | `compliance.score.configure` |
| GET/POST | `/config/policies`, `/config/policies/{code}/versions` | `policy.publish` |
| POST | `/policies/{code}/acknowledge` | authenticated subject, or a valid token |
| GET | `/modules` | `module.view` — the registry as the tenant sees it, **positive entitlement only** (R-VI-132) |
| PUT | `/modules/{key}/enabled` | `module.configure` — narrowing only |
| GET | `/packs/available`, `/packs/installed` | `pack.view` |
| POST | `/packs/{key}/preview`, `/packs/{key}/install`, `/packs/{key}/upgrade`, `/packs/{key}/uninstall` | `pack.manage` |
| GET | `/dashboards`, `/dashboards/{key}` | `dashboards.view` — widgets filtered by entitlement and permission |
| GET | `/dashboards/{key}/widgets/{widget}/drill` | the widget's own permission |
| GET/PUT | `/config/dashboards` | `dashboards.configure` |

### XXI.14.4 Non-negotiable API rules for this area

**R-XXI-130** No compliance endpoint may return an unbounded result set, an
unbounded date window, or a raw exception message. Errors return the standard
envelope with a machine-readable code and a correlation id; the detail is logged.
*Rationale — `F-…` SEC-F-01 and SEC-F-10: `alerts()` returns HTTP **200** with
the raw exception message, `?days=` is unbounded, and the same pattern repeats
across `StatutoryController`, `OffrollAgentController` and
`CodeOfConductController`.*

**R-XXI-131** A compliance status MUST NOT be disclosed to an actor who is not
authorised over the subject, and authorisation MUST be evaluated **before** any
gate or status evaluation that could leak it through an error message.
*Rationale — `F-…` DEFECT-F-31/SEC-F-09: the DRA gate fires at
`RequestController.php:1371` and the "you are not the approver" check is at
`:1397`, so the 422 text discloses any employee's DRA status and expiry date to a
non-approver.*

**R-XXI-132** Subject-facing token surfaces (evidence upload, acknowledgement,
earnings view) MUST be: single-subject, single-purpose, expiring, rotatable,
revocable, tenant-scoped on lookup, rate limited, and audited on use.
*Rationale — `N-…` S-05/S-06 and `F-…` SEC-F-08.*

---

## XXI.15 Events

**Published** (transactional outbox — the event row is written in the same
transaction as the state change, per Part V):

| Event | Payload | Consumed by |
|---|---|---|
| `compliance.item.raised` | item uuid, type, subject, due_on, risk | notifications, dashboards |
| `compliance.item.state_changed` | from, to, actor, reason | audit, gate re-evaluation, scores |
| `compliance.item.expiring` | days_to_expiry, rung | alert ladder, digests |
| `compliance.item.expired` | | **gate standing re-evaluation (R-XXI-062)**, Part XVIII |
| `compliance.item.verified` / `.rejected` | verifier, evidence hash | scores, subject notification |
| `compliance.item.waived` | reason, authoriser, expiry | audit, exception reports |
| `compliance.item.renewed` | predecessor, successor | gate, scores |
| `compliance.item.unassigned` | | the Unassigned view, admin alert |
| `compliance.assessment_failed` | reason, subject, type | **operator alert** — a `not_assessed` is an operational fault, not a quiet state |
| `compliance.score.changed` | earned, assessed_max, band | gates with score thresholds, dashboards |
| `compliance.audit_pack.issued` | pack uuid, subject, verdict, reference | audit ledger, notifications |
| `compliance.audit_pack.superseded` / `.revoked` | | verification endpoint |
| `policy.published` | policy, version | raises acknowledgement obligations |
| `policy.acknowledged` | subject, version, channel | scores, packs |
| `case.raised` / `.escalated` / `.resolved` | | compliance measures, dashboards |
| `module.enabled` / `.disabled` | module key, actor | navigation, scheduler, API document |
| `pack.installed` / `.upgraded` / `.uninstalled` | pack, version, artefact counts | audit, support |
| `compliance.sweep.completed` / `.failed` | tenant, counts, duration | operations dashboard, alerting |

**Consumed:**

| Event | From | Reaction |
|---|---|---|
| `employment.assignment_changed`, `employment.stage_changed`, `engagement.kind_changed` | People | re-evaluate applicability; materialise or close obligations |
| `employment.exited` | People | close live obligations per the type's post-exit rule |
| `employee_document.expiring` | Documents | project into the register |
| `rule_pack.activated` | Statutory | re-materialise future statutory obligations |
| `statutory_obligation.*` | Part XVII | project state changes into the register |
| `payroll.run.previewed`, `payout_batch.prepared`, `commission.claim.submitted` | Money | evaluate the gate; attach the decision |
| `case.raised` | Cases | recompute the measures that consume it |
| `config.published` (compliance artefacts) | Configuration | re-derive due dates and applicability for future instances only — **never retrospectively** (spine S4) |

**R-XXI-133** `compliance.assessment_failed` MUST raise an operator-visible
alert, not merely a log line. A check that cannot run is an incident.
*Rationale — spine S5.6: SmartPRS 2 destroys 50.5% of its caught exceptions, and
`ScanCompliance` returns SUCCESS regardless (`F-…` DEFECT-F-40).*

---

## XXI.16 Explainability

Per spine S15, `Explain(entity, field)` covers every derived value in this area.

| Derived value | The trace shows |
|---|---|
| **Compliance status** | §XXI.5.5 in full: applicability expression and its evaluation, the governing item-type version, the instance, evidence and its verification, valid-state set, assessment basis, gate exposure, score contribution |
| **Due date** | the due rule, its version, the period, the anchor, the non-working-day adjustment, and the arithmetic |
| **Score** | every contributing type, its weight, the item state, the credit fraction, the contribution, the assessed and theoretical maxima, and the scoring-rule version |
| **Gate verdict** | every item evaluated with its state, the policy version, the severity in force, the grace applied, the `as_of` basis and any override with actor and reason (R-XVIII-145) |
| **Audit-pack finding** | the source record, its evidence hash, when it was assessed, the finding rule, and — for `not_assessed` — the reason and what to configure to fix it |
| **Escalation rung** | which rung fired, its trigger, its target resolution, when the next fires |
| **`not_applicable`** | the expression, the substituted values, and the rendered reason |
| **Widget number** | the widget's data source, filter, the viewer's scope, and the drill list it counts |

**R-XXI-134** No compliance value may be displayed without an available
explanation. A trace-less compliance result is a programming error that throws
(spine Law 4).

---

## XXI.17 Migration implication

### XXI.17.1 What comes across

| SmartPRS 2 source | Becomes | Transformation |
|---|---|---|
| `dra_certs` rows | `compliance_items` of type `DRA-CERT` | `employee_id` → engagement id; `expiry` → `valid_to`; `status` mapped to the state vocabulary; `cert_no`, `institute`, `track` → custom fields. Rows with a null `employee_id` or a null `status` — **possible today, because every column is nullable and unindexed** — become exceptions, never silent items |
| `employees.dra_status`, `dra_expiry` | the same, where no `dra_certs` row exists | the **legacy fallback becomes a one-time migration**, not a runtime branch, which is what makes the five implementations collapse into one |
| `employees.pcc_status`, `pcc_expiry`, `pcc_deadline` | `compliance_items` of type `PCC` | `pcc_deadline` — **dead in SmartPRS 2 (DEFECT-F-16, NEW-F-14)** — becomes the item's `due_on`, which is the meaning the column always claimed to have |
| `employees.dra_declared`, `pcc_declared` | a self-declaration field on the item | **never read today** (NEW-F-15); becomes evidence of a declaration, distinct from a verified credential |
| `bgv` rows | `compliance_items` of type `BGV` + `cfg_compliance_type_checks` rows for the six components | `next_due` where it exists; where the column is **absent because the install never opened the BGV screen** (DEFECT-F-14), `next_due` is derived from `verified_on + revalidate_months`, or flagged for completion |
| `agent_authorizations` | `compliance_items` of type `AGENT-AUTHORISATION` + `client_portfolios` | `bank` and `portfolio` strings reconcile to entities; **`status` is now read** (DEFECT-F-13) |
| `complaints` | `cases` of type `BORROWER-COMPLAINT` | anonymous complaints preserved as subject-less cases |
| `escalations` | `cases` of type `ESCALATION` | |
| `code_of_conduct_ack` | `policy_acknowledgements` against a **v0** policy version | **both** competing schemas are read (`employee_id`-keyed and `user_id`-keyed — DEFECT-F-15) and reconciled to the engagement; where neither resolves an engagement, the row is an exception. Text version recorded as *"unknown — pre-migration"* |
| `offroll_agents` | `engagements` of kind `off_roll` + person records | R-XXI-087's triage; documents move from the **public** disk to private storage with new signed URLs |
| `agent_earnings` | `obligations` + ledger (Part XVIII) | historical rows carry **no tax split to recover**; they migrate as recorded, flagged `pre_migration_no_tds` |
| `min_wages`, `policies`, `tds_returns`, `overtime` registers | their proper homes (Part XVII, policies, masters) | consumers are rebuilt |
| Tenant `dra_gate` value | the gate matrix's three collections cells, **verbatim** (R-XXI-059) | |
| Seeded industry content (12 training programmes, 25 subjects, 17 FAQs, 16 KB topics) | the Collections pack's seed data | **per tenant**, fixing the `tenant_id = NULL` global KB rows |

### XXI.17.2 What does not come across

| Not migrated | Why |
|---|---|
| The compliance **score** values | derived; recomputed on the new weights, with the old value retained on the migration report for comparison |
| Any **audit pack** previously issued | none was ever stored (`N-…` V-04). Reissue is available; the historical PDFs a customer holds are outside the system and are marked as such |
| The **synthetic** 24Q filing history | fabricated (`F-…` DEFECT-F-07). Discarded, with `tds_returns` rows retained as manually-entered history |
| `MasterController::def()` registry definitions | replaced by typed item types and case types |
| Runtime schema-creation code | forbidden (Law 2) |
| The `'overdue'` PCC values destroyed by `2026_08_27_000100` | **unrecoverable from the live table.** Where the audit history retains the prior value it is restored; where it does not, the affected employees are listed on a migration exception report and re-derived from `pcc_deadline` — which is exactly what the migration claimed had already happened |
| The public earnings tokens | not migrated. New tokens are minted on demand with a lifetime, and every old link stops working — an **intended** break, communicated in the cutover plan |

### XXI.17.3 Mandatory pre-cutover reports

**R-XXI-135** The following MUST be produced, reviewed and signed off before
cutover. Each exists because the data cannot be migrated correctly without a
human decision.

| Report | Why |
|---|---|
| **Free-text credential triage** — every `offroll_agents.dra`/`.pcc` value with its parse result | DISC-08: what those columns hold is not determinable from source (**D-XXI-03**) |
| **Portfolio reconciliation** — every distinct `commissions.portfolio` string → proposed client and portfolio | it is a free-text join today |
| **Validity divergence** — every subject whose DRA validity differs between the five SmartPRS 2 implementations | this is the population whose compliance status **will visibly change** on day one, and the customer must see it before it does |
| **Gate exposure** — every subject that would be blocked or warned under the seeded matrix | R-XXI-059 |
| **Score movement** — old score vs new, per subject, with the contributions | the NDA leg (worth 15, unreachable — V-08) disappears, so scores move for everyone |
| **Orphan register rows** — rows with null subjects, unresolvable employees, or null statuses | possible today because no register has a foreign key or a NOT NULL |
| **Acknowledgement reconciliation** — every `code_of_conduct_ack` row that cannot be resolved to an engagement | DEFECT-F-11's population is precisely the set of employees with no email |
| **Public-disk evidence inventory** — every file to be moved to private storage, with its old URL | SEC-F-02; the old URLs remain live until `storage:link` is broken, so the cutover must include that step |

**R-XXI-136** The validity-divergence and score-movement reports MUST be
delivered to the customer **before** cutover, not after. A compliance status that
changes silently at a migration is indistinguishable, to a bank, from one that
was wrong all along.

---

## XXI.18 Edge cases and failure states

| # | Case | Required behaviour |
|---:|---|---|
| E1 | An employee moves from Telecalling to Field Recovery mid-month | applicability re-evaluates from the assignment's effective date; DRA and PCC materialise from that date; the prior period reads `not_applicable` with its reason |
| E2 | An employee transfers between legal entities | obligations follow the engagement, not the org unit, unless the type's applicability is establishment-scoped, in which case the old obligation closes and a new one opens, both retained |
| E3 | A certificate is renewed **before** expiry | the successor is created, the predecessor is `superseded`, and validity is continuous with no gap — the gate never sees a lapse |
| E4 | A certificate is renewed **after** expiry | the gap is retained and visible; the audit pack shows the lapse period; historical gate decisions in the gap remain as decided |
| E5 | A certificate's expiry is corrected downward, retroactively invalidating an approved payment | the item changes; **the historical gate decision does not** (it records what was known then); an exception is raised naming the affected payments |
| E6 | Evidence is replaced after verification | the item returns to `submitted`, `evidence_changed_after_verification` is set, the verifier is notified (R-XXI-037) |
| E7 | The applicability expression cannot be evaluated (an assignment gap) | `not_assessed` with the reason; the gate applies `on_not_assessed`; `compliance.assessment_failed` raises an operator alert |
| E8 | The item type is unpublished or retired while instances are live | live instances continue under their stored `item_type_config_version_id`; no new instances are materialised; the register shows the type as retired |
| E9 | A tenant disables the compliance module | screens and jobs stop; **obligations remain in the register and gates already bound continue to evaluate** (R-XXI-109) |
| E10 | A pack is uninstalled while its item types have live instances | uninstall is **refused** unless `uninstall_policy = archive_data`, and then instances are archived, never deleted, and the refusal names the instance count |
| E11 | Two packs declare the same item type code | import conflict at phase 1, presented with both payloads (R-VI-104); publication is blocked |
| E12 | A tenant edits a pack-owned item type, then upgrades the pack | three-way diff per artefact; nothing overwritten (R-VI-115) |
| E13 | The subject has no login and no email | the token surface is used; acknowledgement and evidence attach to the **engagement**; the check never degenerates (R-XXI-100) |
| E14 | Two evidence documents, one verified and one rejected | `evidence_state` reflects the **current** set; the item's state follows the type's rule; both documents are retained |
| E15 | A waiver expires | the item returns to its underlying state automatically; the gate re-evaluates; the owner and the authoriser are notified |
| E16 | A waiver is granted for longer than `waiver_max_days` | refused at the API boundary with the configured maximum named |
| E17 | The daily sweep does not run (queue worker stopped) | no state advances; the operations dashboard shows the missed run; **the gate still evaluates live**, because the gate calls `compliance_status()` and does not read a sweep-maintained flag |
| E18 | The sweep runs twice in one day | idempotent: alert rungs are deduped on the ledger key; no duplicate escalation |
| E19 | Clock skew or a timezone change moves a due date by a day | dates are business dates in the tenant's timezone (spine S16); the derivation trace records the timezone used |
| E20 | A leap-year or month-end due rule (`31st` in February) | `CLAMP_DAY_TO_MONTH` — declared, not implicit |
| E21 | An off-roll agent works for two agencies (two tenants) | two persons, two engagements, complete isolation; **no cross-tenant credential sharing**, even where the certificate is the same physical document |
| E22 | The same person holds an on-roll and an off-roll engagement | two engagements, two obligation sets; a type may declare `person_scoped` so one valid credential satisfies both, and that declaration is explicit |
| E23 | An audit pack is requested for a subject with unassessed items | allowed, with an explicit acknowledgement, the count stamped on the document, and the verdict qualified (R-XXI-074) |
| E24 | An audit pack is requested for 500 agents | queued, chunked, per-subject branding resolved (R-XXI-075), delivered as a bundle; there is **no silent cap** — SmartPRS 2 slices at 150 (`F-…` BR-CMP-012) |
| E25 | The verification endpoint is probed at volume | rate limited per IP and globally; returns "not known" identically for a malformed and an unknown reference (no oracle) |
| E26 | The pack signing key is rotated | packs signed by the retired key remain verifiable through the key id; new packs use the new key; installations receive keys through the update channel (spine S18) |
| E27 | A rule pack activation changes a statutory due date mid-period | future undue obligations follow the new rule; obligations already `prepared` keep theirs, recorded on the row (R-XVII-127) |
| E28 | A widget's data source is deleted or its module disabled | the widget renders a degraded state naming the cause and does not render zero (R-XXI-123) |
| E29 | A user's role changes mid-session | the entitlement set and dashboard resolve per request, so the next request reflects the change; nothing is cached across an authorisation change |
| E30 | An evidence document fails a virus scan | the upload is rejected, the item stays in its prior state, the attempt is audited, and the uploader is told plainly |
| E31 | A subject is anonymised under DPDP while regulatory evidence must be retained | the person is anonymised, the evidence is retained under its statutory retention against a pseudonymous reference, and a retention exception is recorded (R-XXI-069, D-XXI-06) |
| E32 | A migrated item cannot be assigned an owner | it is created unassigned, appears on the Unassigned view, and raises a dashboard exception (R-XXI-033) |

---

## XXI.19 Tests

Every scenario below requires an exact-value regression test. Those marked
**[GOLDEN]** compare a rendered artefact byte-for-byte against a stored fixture.

### Validity — the one definition

| # | Scenario | Expected |
|---|---|---|
| T-XXI-01 | The five SmartPRS 2 DRA scenarios (latest-row, any-verified, latest-with-legacy-fallback, any-verified-no-legacy, blank-string) run against `compliance_status()` | **one** verdict per scenario; the fixture records the SmartPRS 2 divergence for each and asserts it is gone |
| T-XXI-02 | Grep sweep for a validity computation outside the service | fails the build; seeded violation detected |
| T-XXI-03 | Subject with no record, type applicable | `state=due · is_valid=FALSE · basis=evidence`; never TRUE |
| T-XXI-04 | Register table emptied entirely | score falls to zero, basis `not_assessed`; **never** rises |
| T-XXI-05 | Applicability unevaluable | `not_assessed`, gate blocks, operator alert raised |
| T-XXI-06 | `valid_state_codes` changed from `[verified]` to `[verified, submitted]` | register, score, gate, digest and audit pack all change in one publish; the impact preview named the count first |
| T-XXI-07 | Status as at a past date | reproduces that date's verdict exactly, ignoring later evidence |

### The audit pack

| # | Scenario | Expected |
|---|---|---|
| T-XXI-08 **[GOLDEN]** | Full pack for a fixture agent | every finding resolves to a stored record id or is `not_assessed` with a reason |
| T-XXI-09 | No disciplinary-case register configured | the finding is `not_assessed`, never `'0' · ok` |
| T-XXI-10 | Employee with no login and no email | the code-of-conduct finding resolves against the **engagement**; with no acknowledgement it is `fail`, never `pass` |
| T-XXI-11 | Authorisation revoked with a future `valid_to` | `fail` |
| T-XXI-12 | One finding altered in the stored payload | `content_sha256` and the printed reference both change |
| T-XXI-13 | Two packs for the same agent on the same day with different contents | different references |
| T-XXI-14 | Verification endpoint with a genuine, an altered and an unknown reference | verdict / not known / not known — no oracle |
| T-XXI-15 | Bulk pack across three legal entities | each subject rendered under its own branding |
| T-XXI-16 | Pack containing any `not_assessed` finding | verdict cannot be `COMPLIANT`; it is `COMPLIANT — PARTIALLY ASSESSED` |
| T-XXI-17 | Specimen pack | watermarked, no tenant branding, unreachable from an authenticated tenant session |
| T-XXI-18 | Pack issuance | writes an audit-ledger row with actor, subject, purpose and recipient |
| T-XXI-19 | A fully compliant agent | scores the full assessed maximum — the SmartPRS 2 ceiling of 85 is absent |

### Gate and money

| # | Scenario | Expected |
|---|---|---|
| T-XXI-20 | Expired DRA at `commission_claim_approval`, severity `block` | blocked; decision row records every item and its state |
| T-XXI-21 | Fault injected inside gate evaluation | `error_blocked`, payment refused, operator alert |
| T-XXI-22 | Same subject, off-roll engagement | identical verdict from the identical policy |
| T-XXI-23 | `warn` verdict | payment proceeds **and** appears on the standing exception report for its period |
| T-XXI-24 | Override without a reason | refused; with a reason, recorded on the decision and the exception report |
| T-XXI-25 | DRA expires between claim submission and approval | the approval screen shows `BLOCK` at approval time (R-XXI-062) |
| T-XXI-26 | Migration seeding | each tenant's `dra_gate` value lands verbatim in exactly three cells; every other cell is `off`; the exposure report is produced |

### Register, sweep and alerts

| # | Scenario | Expected |
|---|---|---|
| T-XXI-27 | Statutory, people and document obligations in one register | one row shape, one sort, one state vocabulary |
| T-XXI-28 | One projection source errors | the section shows a degraded marker; the register is not empty |
| T-XXI-29 | Sweep fails on tenant 3 of 20 | tenants 4–20 process; the failure is recorded and alerted; the run does not report success |
| T-XXI-30 | Sweep skipped for a day, then run | ladder rungs catch up; no rung is lost; no duplicate sent |
| T-XXI-31 | Fresh `migrate:fresh` install | BGV re-verification alerts fire without any screen having been opened *(the direct test for DEFECT-F-14)* |
| T-XXI-32 | Item expired two years ago | outside the ladder's **lower** bound; not reported forever |
| T-XXI-33 | Carbon major-version upgrade | signed day arithmetic unchanged; a future due date never buckets as overdue *(DEFECT-F-21)* |
| T-XXI-34 | Digest audience resolves to nobody | nothing is sent **and** an operator alert is raised |
| T-XXI-35 | Unchanged digest on consecutive days | deduped per the configured window |
| T-XXI-36 | Resigned employee | generates no live obligation and appears in no digest |

### Configuration and Section 127

| # | Scenario | Expected |
|---|---|---|
| T-XXI-37 | §XXI.3.11's firearms licence, created entirely through the UI | appears in register, score, gate, digest, dashboard and audit pack with **no deployment** |
| T-XXI-38 | Item type renamed | no behaviour changes anywhere |
| T-XXI-39 | Score weights changed | scorecard, gate thresholds and audit pack change together |
| T-XXI-40 | Item type published mid-period | affects future materialisation only; existing instances keep their governing version |
| T-XXI-41 | Two types with identical scope and priority whose applicability can both be true for a gate cell | publication **blocked** with both named (spine S3.2) |

### Off-roll and the vertical

| # | Scenario | Expected |
|---|---|---|
| T-XXI-42 | Off-roll engagement across sweep, score, gate, digest, audit pack | included with no configuration beyond applicability |
| T-XXI-43 | Static check for engagement-kind literals in `Domain\Compliance` | none |
| T-XXI-44 | Free-text credential triage over the fixture strings | zero silent successes; a complete exception list |
| T-XXI-45 | Earnings token | expires, rotates, revokes, is tenant-scoped, and pages beyond 100 entries with a visible total |
| T-XXI-46 | Evidence storage | no compliance document resolves on any public path in any deployment shape |
| T-XXI-47 | CI grep for vertical vocabulary in the core | none (R-XXI-084) |

### Entitlement, packs and dashboards

| # | Scenario | Expected |
|---|---|---|
| T-XXI-48 | Module disabled | UI hides, endpoint 403s, API document omits, scheduler skips and records, queued job dead-letters visibly, data intact, re-enable restores exactly |
| T-XXI-49 | Disable a module another depends on | refused, dependants named |
| T-XXI-50 | Ungranted module force-enabled through the API | no effect on `effective()` |
| T-XXI-51 | Registry consistency | every permission, nav entry, scheduled task, feature key and licence line resolves to a module row |
| T-XXI-52 | Nav entry with no implementation | build fails *(the `ai-assistant` test)* |
| T-XXI-53 | Pack imported twice | second import reports "no change" for every artefact |
| T-XXI-54 | Pack with a tampered signature | rejected, not warned |
| T-XXI-55 | Pack upgrade over a forked artefact | three-way diff; the tenant's edit survives unless it chooses otherwise |
| T-XXI-56 | Tenant additions alongside a pack | survive upgrade untouched |
| T-XXI-57 | Pack uninstall with live instances | refused unless `archive_data`; instances archived, never deleted |
| T-XXI-58 | Every candidate pack manifest | validates with no unsupported artefact type (R-XXI-118) |
| T-XXI-59 | Every published widget | declares a drill target and an action; a count-only widget fails the build |
| T-XXI-60 | Widget count vs drill list | equal on the fixture |
| T-XXI-61 | User with an unresolved tenant | receives **no** dashboard; never installation-wide figures *(NEW-DSH-102)* |
| T-XXI-62 | Widget data source errors | degraded state, not zero |
| T-XXI-63 | Dashboard on a 10,000-employee, 5-year tenant | within the declared query-count and response-time budget |

### Security and isolation

| # | Scenario | Expected |
|---|---|---|
| T-XXI-64 | Generated cross-tenant isolation test per compliance endpoint and per widget | all pass (spine Law 6) |
| T-XXI-65 | Non-approver probing a gate | authorisation evaluated first; no status leaked in the error |
| T-XXI-66 | Self-service user calling the register | sees only their own subject's items |
| T-XXI-67 | Evidence download URL | signed, expiring, single-use, audited; a replayed URL fails |
| T-XXI-68 | Any compliance endpoint erroring | standard envelope, no raw exception message, correct status code |

---

## XXI.20 Open items — PRODUCT OWNER DECISION REQUIRED

| # | Question | What is known | Recommendation | Trade-off |
|---|---|---|---|---|
| **D-XXI-01** | **One engagement table with a kind, or two tables?** Modelling off-roll agents as an engagement kind amends Part V §C4 and Part XXVIII cluster C4 | `offroll_agents` is a parallel person model excluded from every compliance mechanism (`N-…` V-01); the analysis recommends "a core Worker abstraction with off-roll as a worker type" (§1.8.1) | **One table, one kind column.** Compliance, documents, custom fields, gates and money movement then include off-roll agents **by construction** | A single table carries columns meaningless to some kinds (payroll fields for an off-roll agent), which metadata handles. Two tables re-create the defect this part exists to remove |
| **D-XXI-02** | **Does the collections vertical ship as a paid pack, or in core?** | `N-…` Q-06 and Part XVIII D-XVIII-12 both put it to the Product Owner | **Pack.** Core = credential, policy ack, case, engagement kinds, expiry engine, scoring, gate. Pack = DRA/PCC/authorisation instances, portfolios and billing, escalations, the audit pack, the collections gate matrix, off-roll payout shapes | Core-only makes every non-collections customer carry a vertical; pack-only would strand the six generic capabilities the analysis says are **not** vertical, and make the core unsellable to security, staffing and logistics |
| **D-XXI-03** | **What do `offroll_agents.dra` and `.pcc` actually contain?** | *"Not determinable from source. Blocks the off-roll agent compliance model"* (`J-…` A4; `N-…` DISC-08) | Obtain a production extract of distinct values before writing the triage rules; until then the migration produces `not_assessed` items and an exception report | Guessing a parse produces confident wrong compliance data — the exact failure mode this part exists to eliminate |
| **D-XXI-04** | **Do off-roll agents consume a licensed seat?** | *"An agency can run 500 off-roll agents on a ₹1,000/month 25-seat Starter plan"* (`N-…` §1.5.5, Q-05) | **Yes, at a distinct rate**, once they are compliance-scanned and gated — the platform is doing real work for them | Charging for a population that has been free is a commercial conversation with every existing customer, and must precede migration, not follow it |
| **D-XXI-05** | **Which regulatory item set does the audit pack ship with?** | The landing page builds a campaign on directions *"Effective 1 October 2026"* and lists five obligations; *"the referenced artefacts are not in this repository"* (`N-…` Q-12) | Ametecs' named compliance owner (spine D-03) supplies the item set, and it ships as **pack content**, versioned, with a source reference per item | Without a verified item set the pack is an unverified assertion in a regulated setting — precisely the SmartPRS 2 failure |
| **D-XXI-06** | **DPDP erasure versus regulatory retention** | Erasure must be possible (`N-…` req. 44); regulatory evidence must be retained | Anonymise the person, retain the evidence under its statutory retention against a pseudonymous reference, record a retention exception | The alternative — deleting evidence on request — destroys the audit trail a bank relies on. Legal sign-off required |
| **D-XXI-07** | **Default gate severity for the newly available gate points** | Payroll and payout gates do not exist today; the business assumption *"a non-compliant agent cannot be paid"* is stated by the product and **payroll checks nothing** (`N-…` §1.4) | Seed **`off`** everywhere new, publish the exposure report, and let each customer tighten deliberately | Seeding `block` stops payroll on day one for every lapsed credential; seeding `off` leaves the promise unmet until the customer acts — the exposure report is what makes that a choice rather than an accident |
| **D-XXI-08** | **Who may waive, and to what maximum?** | No waiver concept exists today; the only escape is not recording the obligation | A distinct `compliance.item.waive` permission, a mandatory reason and authoriser, a maximum duration per type, and every waiver on the audit pack | A generous waiver policy makes the register decorative; a strict one drives users to avoid recording obligations at all |
| **D-XXI-09** | **The canonical module catalogue** | Six disagreeing vocabularies; a licence agreement selling a catalogue that exists nowhere (`N-…` Q-01) | Adopt the module registry of §XXI.9.1 and populate it **commercially first** — the price list is the source of truth, not the controller set | Until it is populated, entitlement, licensing and the pack boundary are all blocked. This is the single most schedule-critical decision in this part |
| **D-XXI-10** | **Cross-part reconciliation** | Part XVIII §XVIII.9.1 cites *"Part XXII"* for the compliance item model; that model is this part (XXI). Part XXVIII §C11 outlines six tables whose column spellings differ slightly from §XXI.13 | Retarget the Part XVIII reference to Part XXI, and adopt §XXI.13's spellings in Part XXVIII's register | Editorial, not architectural — but a blueprint that cross-references a part that does not exist will not survive review |

---

## XXI.21 Requirements index

| Range | Subject |
|---|---|
| R-XXI-001 – 022 | The Compliance Command Centre: one register, the row shape, the two sources, the ten-state model and its transitions, `not_applicable`, views, the sweep, alert ladders, escalation |
| R-XXI-023 – 046 | The compliance item type model: types as data, no name-derived behaviour, applicability as SPX, subject kinds, periodicity and due-date derivation, owner assignment, evidence, verification, ladders, scoring, gate bindings, the seeded DRA type |
| R-XXI-047 – 048 | Coverage: rule-pack projection and its state mapping; document expiry projection |
| R-XXI-049 – 056 | **One definition of validity**: the service, its closed consumer list, the CI sweep, the absent-record rule, `not_assessed` propagation, as-of evaluation, and the ban on literal assertions |
| R-XXI-057 – 063 | The generalised gate: the item-type × action-type matrix, scoping, migration seeding, operational gate points, attendance never destroyed, standing re-evaluation, fail-closed |
| R-XXI-064 – 082 | Evidence and the audit pack: capture, token surfaces, verification, retention, DPDP, the derivation rule, configurable item sets, four outcomes, the verdict rule, per-subject branding, the specimen, the issued-pack register, the keyed hash, verification endpoint, audit-logged issuance, supersession |
| R-XXI-083 – 103 | The vertical: core versus pack, the vocabulary ban, off-roll agents as an engagement kind, dated credentials, taxed payouts, the earnings view, clients and portfolios, authorisation status, cases, escalations, policy acknowledgement, training |
| R-XXI-104 – 110 | Module registry, navigation as data, disabled-module behaviour across six surfaces, obligations surviving disablement, install ≠ entitle |
| R-XXI-111 – 118 | The module pack: reuse of Part VI machinery, the manifest, permitted and forbidden contents, validation, idempotency, distribution and lifecycle, post-install customisation, the candidate catalogue |
| R-XXI-119 – 127 | Dashboards: actions not counts, count-equals-list, the widget model as configuration, server-side scoping, degraded states, budgets, platform separation, empty states, assessed versus unassessed |
| R-XXI-128 – 129 | The engagement change; pack-extensible vocabularies and the four it may not extend |
| R-XXI-130 – 134 | API rules: bounded results, authorisation before evaluation, token discipline, assessment failure as an incident, explainability |
| R-XXI-135 – 136 | Migration: the eight mandatory pre-cutover reports, delivered before cutover |

**Totals for this part: 136 numbered requirements · 19 specified tables plus one
amended table and 14 reference vocabularies · 68 named regression tests · 10
Product Owner decisions.**

---

## XXI.22 Conformance with the seven laws

| Law | How this part complies | Evidence in this part |
|---|---|---|
| **1 — No business literal in code** | Item types, states, valid-state sets, applicability, periodicity, due rules, ladders, buckets, windows, weights, thresholds, verdict bands, gate severities, audit-pack item sets, dashboard widgets and module lists are all configuration rows | §XXI.3, §XXI.6.1, §XXI.7.4, §XXI.11.2, §XXI.12; R-XXI-024's static rule; R-XXI-084's vocabulary grep |
| **2 — No schema change at runtime** | Every table in §XXI.13 is migration-owned with real constraints and indexes; a pack may add **field definitions**, never columns; `MasterController::ensureCols()` has no successor | §XXI.13; R-XXI-113; the pack's forbidden-contents table |
| **3 — Nothing overwritten; everything versioned and dated** | Item types are versioned artefacts; instances supersede rather than update; transitions are append-only; acknowledgements are per version and never overwritten; issued packs are append-only and superseded, never regenerated | R-XXI-003, R-XXI-010, §XXI.13.3, §XXI.13.8, R-XXI-082 |
| **4 — Every number explains itself** | `ComplianceTrace` on every status, due date, score, gate verdict, finding, escalation rung and widget number; a trace-less result throws | §XXI.5.5, §XXI.16, R-XXI-134 |
| **5 — Authorisation server-side, declarative, fails closed** | Every route policy-bound and feature-declared; views scope on the server; authorisation precedes gate evaluation; gates return `error_blocked`; self-verification prohibited | §XXI.14, R-XXI-013, R-XXI-131, R-XXI-063, R-XXI-011 |
| **6 — Tenancy is structural** | `tenant_id` NOT NULL and in every unique index on every table here; the three platform registry tables carry no business data; generated cross-tenant tests per endpoint and per widget | §XXI.13, T-XXI-64, R-XXI-122 |
| **7 — Money is exact** | This part holds no money arithmetic. Where it touches money it does so through Part XVIII's obligations and ledger, in integer minor units | §XXI.8.2, R-XXI-088 |

**On the Section 127 standard.** The test the brief sets is that
*"3 late arrivals over 10 minutes = half day"* must not require code. This part's
equivalent — §XXI.3.11 — is *"an armed-escort employee whose firearms licence has
lapsed cannot be allocated to a client site, is warned on payroll for 30 days and
then blocked, and shows on the compliance officer's dashboard from 120 days
before expiry"* — and it is thirty fields in a form, no deployment, and a
simulation before publication.

---

*End of Part XXI.*
