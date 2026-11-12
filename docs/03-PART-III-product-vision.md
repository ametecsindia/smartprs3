# PART III — SMARTPRS 3 PRODUCT VISION

---

## III.0 Purpose

This part states what SmartPRS 3 is, who it is for, what it must never become,
and how Ametecs will know it has succeeded. Everything that follows in the
blueprint is engineering in service of this part. If a design decision later in
the document cannot be traced to something here, it is over-build and should be
challenged.

---

## III.1 The vision statement

> **SmartPRS 3 is a configurable HR, Payroll, Attendance, Workforce and
> Compliance Operating System for Indian businesses.**
>
> One product core. One codebase. One release. Every customer's policy expressed
> as configuration, every number it produces explained on demand, and every
> statutory change delivered as signed content rather than a software upgrade.

Three sentences, each of which is an engineering commitment with a test attached.

---

## III.2 The central thesis

**CHANGE CONFIGURATION, NOT CODE.**

An Indian employer's differences from every other Indian employer are large in
number and small in kind. Two collection agencies in the same city, running the
same business, will differ on: how many weekly offs, which Saturdays, what
counts as late, what three lates cost, whether late departure offsets late
arrival, whether agents get overtime and managers do not, what Sunday work earns,
which leave types sandwich a weekend, how Basic is derived, what appears on the
payslip, who approves a loan, and which alert reaches which person.

None of that is a difference in *what the software does*. It is a difference in
*what the software has been told*. SmartPRS 2 encodes those differences in PHP —
which is why the same twenty-line policy conversation ends in a code change, a
release, a regression risk and, eventually, the temptation to fork.

SmartPRS 3 treats every one of them as data.

### III.2.1 The test of the thesis

The thesis is not a slogan; it is falsifiable. Part VI §VI.16 takes each example
the governing brief gives and shows the exact artefact a consultant edits, in
which screen, and what the stored configuration looks like. If any of them
requires a developer, the thesis has failed for that case and the platform is
incomplete.

| A customer asks for… | Must be… |
|---|---|
| 3 late arrivals over 10 minutes = half day | a rule |
| 2 weekly offs instead of 1 | a weekly-off pattern |
| 2nd and 4th Saturday off | a weekly-off pattern |
| Include Sunday in sandwich leave | a leave adjacency policy |
| Exclude Sick Leave from sandwich | a leave adjacency policy scope |
| Hyderabad gets OT, Pune does not | a rule scope |
| Agents eligible for OT, Managers not | a rule scope |
| Show HRA, hide Employer PF on the payslip | a payslip template |
| Add a LinkedIn Profile field | a field definition |
| Make PCC mandatory during onboarding | a field surface rule |
| Absent alert to the employee and branch HR | a notification subscription |
| A changed PF or PT rule | a signed statutory rule pack |

Twelve requests. Zero deployments. That is the product.

---

## III.3 The twenty non-negotiable product attributes

The governing brief names twenty. They are restated here as what each one
*means operationally*, because an attribute with no operational meaning is
decoration.

| # | Attribute | What it means in practice | Where it is realised |
|---|---|---|---|
| 1 | Configuration-first | A new customer policy is a Studio edit, not a ticket | Part VI |
| 2 | Rule-driven | Outcomes are decided by scoped, dated, versioned rules | Part VII |
| 3 | Metadata-driven | Entities and fields are data; every surface renders from one definition | Parts IX·X |
| 4 | Workflow-driven | Every approval in the product uses one engine; no module writes its own | Part IX |
| 5 | API-first | Every business function has a versioned API, and the product's own UI uses it | Part XXIV |
| 6 | Multi-tenant safe | Tenancy is structural, enforced in persistence, tested per entity | Part XXII |
| 7 | On-premise ready | The same artefact installs on a customer's server and self-verifies | Part XXVI |
| 8 | Cloud ready | The same artefact runs the SaaS estate | Part XXV |
| 9 | Auditable | Every sensitive action is in a hash-chained ledger with before/after values | Part XXIII |
| 10 | Effective-dated | Every policy has a validity range; August uses August's policy | Spine S4 |
| 11 | Versioned | Nothing is overwritten; every change creates a version with a reason | Spine S4 |
| 12 | India-compliance ready | Jurisdiction is modelled; statutory content is signed, dated, layered | Part XVII |
| 13 | Modular | Bounded modules with enforced dependency rules | Part IV |
| 14 | Extensible | Adapters, connector recipes, module packs — no customer forks | Parts XI·XXI·XXIV |
| 15 | Secure by default | Fails closed, no default credentials, encryption and masking by classification | Part XXII |
| 16 | Explainable | Every derived number answers "how was this calculated?" | Spine S15 |
| 17 | Reversible | Runs version rather than destroy; recoveries reverse; configuration rolls back | Parts XIV·XVIII |
| 18 | Testable | Configuration is simulated before publication; money has exact-value tests | Parts VI·XXX |
| 19 | Upgrade safe | Signed releases, backup, rollback, and statutory content on its own channel | Part XXVII |
| 20 | Customisable without source modification | The whole point | Part VI |

---

## III.4 Who SmartPRS 3 is for

### Primary market

Indian employers of 20 to 10,000 people who have statutory payroll obligations
and an attendance problem. In practice:

- **Debt collection and recovery agencies** — Ametecs' home market, ~3,000
  agencies already engaged, and the vertical where the product's compliance
  capability is a genuine differentiator rather than a checkbox.
- **BPO, call centres and telemarketing** — shift work, rotational offs, night
  allowances, high attrition, incentive-heavy compensation.
- **IT and professional services** — simpler attendance, complex compensation,
  demanding ESS expectations.
- **Retail, healthcare, manufacturing, field force, financial services** —
  rosters, multi-site, state-by-state statutory divergence.

### The buyer and the users

| Actor | What they need from SmartPRS 3 |
|---|---|
| Business owner / MD | Payroll that is right, compliance that is provable, and no surprises |
| HR head | To implement a policy change themselves, this afternoon |
| Payroll officer | A run that reconciles, exceptions surfaced before approval, and a payslip they can defend to an employee |
| Compliance officer | One screen showing what is due, what is overdue, and the evidence |
| Reporting manager | Their team's attendance and approvals, and nothing else |
| Employee | Their attendance, their leave, their payslip, and an answer to "why is this number this?" |
| Ametecs implementation consultant | To configure a customer without asking engineering for anything |
| Ametecs engineer | To ship one product, not forty |

The last two rows are the ones SmartPRS 2 fails hardest, and they are where the
commercial return lives.

---

## III.5 Positioning

SmartPRS 3 sits between two unsatisfactory options that Indian employers
currently choose between.

**Below it:** cheap attendance-and-payslip tools that handle the easy 70% and
break on a cut-off payroll cycle, a night shift, a state change mid-year, or a
compliance audit. They are configurable in appearance only.

**Above it:** global HCM suites that are genuinely configurable but are priced,
scoped and implemented for enterprises, treat Indian statutory compliance as a
localisation afterthought, and cannot be deployed on a customer's own server in
a district office with intermittent connectivity.

SmartPRS 3's position: **enterprise-grade configurability, Indian statutory
depth, and deployment on the customer's own terms.** The collections vertical is
the proof that the compliance machinery is real, not the limit of the market.

---

## III.6 The commercial thesis

The rebuild is not primarily a quality exercise. It changes the economics.

| | SmartPRS 2 today | SmartPRS 3 |
|---|---|---|
| Implementing a customer's unusual policy | Engineering ticket, release, regression risk | Consultant, same day, in the Studio |
| Shipping a statutory rate change to 200 on-prem installs | A full application release, 200 manual upgrades, and lapsed-AMC customers get nothing | A signed rule pack over a separate channel |
| A customer asks for a field | 6 code edits minimum, 13–16 sites for full parity | One field definition |
| A new biometric model | Engine change | An adapter, or a connector recipe with no code at all |
| A vertical (BPO, healthcare, manufacturing) | A conversation about a fork | A module pack |
| Marginal cost of customer N+1 | Rises | Roughly flat |
| Sales ceiling | Whatever engineering can absorb | Whatever implementation can absorb |

Three new revenue shapes become possible only after the rebuild:

1. **Rule-pack subscription** — statutory content as an ongoing service, separable from the software licence.
2. **Vertical module packs** — the Collections Compliance pack as the reference implementation, then BPO, field force, healthcare.
3. **A partner ecosystem** — implementation partners who configure rather than code, which is only safe once configuration cannot break the product.

---

## III.7 What SmartPRS 3 must never become

Stated as prohibitions, because these are the failure modes with gravity behind
them.

1. **No customer-specific fork.** There is never a `smartprs-client-a`, a
   `smartprs-hyderabad`, a `smartprs-maharashtra`. Every customer runs the same
   platform version. A request that seems to need a fork is a gap in the
   configuration model, and the fix is to close the gap once.
2. **No business literal in code.** The moment a component name, a threshold or a
   role string is compared in PHP, the thesis has leaked.
3. **No arbitrary customer code.** No PHP, JavaScript or Python from a customer,
   under any configuration, for any account size. The expression language is
   deliberately not a programming language.
4. **No schema change at runtime.** A custom field never creates a column.
5. **No silent failure.** A subsystem that cannot do its work says so, loudly, to
   someone who can act.
6. **No unexplainable number.** If the product cannot say why, the product should
   not have said it.
7. **No default credential.** In any seeder, installer, demo or release, in any
   environment.
8. **No fabricated data.** Not a placeholder payroll run, not a compliance
   assertion that was never checked, not a filing marked filed.

Items 5 through 8 are prohibitions written directly out of confirmed SmartPRS 2
behaviour. They are in the vision because they are cultural, not merely
technical.

---

## III.8 Honest boundaries

A promise that is not bounded is a promise that will be broken in a sales
meeting. The extensibility limit (governing brief Section 128, specified in
Part VI §VI.14) belongs in the vision, not buried in an appendix.

**"Change configuration, not code" means:** a new *customer policy* is
configuration.

**It does not mean:** a new device protocol, a new government API, a new
statutory concept, a new communication channel or an entirely new product
capability appears without platform development. For those, Ametecs builds the
reusable capability **once** — an adapter, a channel, a rule-pack rule type, a
new action in the catalogue. After the platform supports it, every
tenant-specific variation of it becomes configuration.

This is the sentence to give a customer, and it should be given early:

> *"Anything about how your policies work, you can change yourself. Anything
> about what the platform can physically talk to or produce, we build once, and
> then you configure it."*

---

## III.9 What success looks like

Success criteria, in the order Ametecs will feel them.

| Horizon | Criterion | How it is measured |
|---|---|---|
| Build | Zero business literals in domain code | Static-analysis gate in CI |
| Build | Zero runtime DDL | Production DB user holds no `ALTER`; CI grep |
| Build | Every route has an explicit policy binding | Automated route sweep |
| Build | Every money path has an exact-value test | Coverage gate on `Domain\Payroll` |
| First customer | A complete implementation with zero code changes | Count of commits attributable to that customer: 0 |
| First customer | Implementation time per customer | Target: weeks, measured against the SmartPRS 2 baseline |
| Statutory | A rate change reaches every install without a release | Elapsed time from pack publication to activation |
| Operations | No silent failure | Every failure class has an alert and an owner |
| Support | "Why is this number this?" answered by the customer, not by support | Volume of calculation-explanation tickets |
| Commercial | Customers per engineer | Rising, not flat |
| Commercial | Forks in existence | Permanently zero |

---

## III.10 What is explicitly out of scope for version 3.0

Scope discipline protects the delivery date. These are deferred deliberately,
not overlooked, and each is a **PRODUCT OWNER DECISION** to confirm.

| Deferred | Reasoning | Revisit |
|---|---|---|
| Performance management beyond the existing points and review capability | Adjacent product, large surface, no current customer blocking on it | v3.1 |
| Learning management / training delivery | Currently only compliance-training tracking is needed, and that is a compliance item | v3.2 |
| Non-India jurisdictions | The jurisdiction model is built to allow it; no content or validation exists | When a customer pays for it |
| Multi-currency payroll | Money carries currency from day one; multi-currency *payroll* is not implemented | On demand |
| Separately deployable engine services | Parity with on-premise matters more than scale headroom today | At scale, behind the existing interfaces |
| A public app marketplace | The module-pack mechanism is built; the marketplace around it is not | After two packs exist |

---

## III.11 The relationship to the rest of the Ametecs ecosystem

SmartPRS 3 is one product in a connected family — SmartDCM, SmartEPT, Smart
Biometric Bridge, Smart31, SmartRMI, TeloZar. The architectural rule is short
and absolute:

> **Products integrate through versioned APIs and signed webhooks. No two
> Ametecs products ever share a database table.**

The commercial value of the ecosystem is a single partner relationship carrying
several products. That value is destroyed the first time a schema change in one
product breaks another, which is precisely what shared tables guarantee. The
boundary is specified in Part XXIV.

---

## III.12 Requirements arising from this part

| ID | Requirement |
|---|---|
| R-III-001 | There shall be exactly one SmartPRS 3 product core; no customer-specific branch, fork or build shall exist at any time. |
| R-III-002 | Every one of the twelve conformance cases in §III.2.1 shall be demonstrable by configuration alone, and each shall be an automated acceptance test. |
| R-III-003 | The extensibility limit in §III.8 shall be stated in customer-facing documentation and in the sales collateral, in those terms. |
| R-III-004 | The eight prohibitions in §III.7 shall each have a mechanical enforcement (CI gate, database privilege, static analysis or automated test), not merely a policy statement. |
| R-III-005 | The success criteria in §III.9 shall be instrumented and reported to the Product Owner from the first release, not retrofitted. |
| R-III-006 | No Ametecs product shall read or write another Ametecs product's database schema. |
| R-III-007 | The out-of-scope list in §III.10 shall be confirmed by the Product Owner before Phase 1 begins, and any addition shall be an explicit scope change with a schedule impact. |

---

## III.13 Product Owner decisions

| ID | Decision | Recommendation |
|---|---|---|
| **D-III-01** | Confirm the v3.0 scope boundary in §III.10 | Accept as listed; each deferral is reversible and none blocks a customer today |
| **D-III-02** | Confirm the primary market ordering in §III.4 — is collections still first, or is the generic HR core now the lead? | Lead with the generic core, sell the collections pack as the differentiator. The analysis shows the vertical is thinner than assumed (six genuinely vertical capabilities of thirteen claimed), which makes a core-plus-pack model both honest and viable |
| **D-III-03** | Is the rule-pack subscription a separate commercial line, or included in the licence? | Separate line, but **never withheld for a lapsed AMC** — see Part XVII D-XVII-02 and Part XXVII D-XXVII-02, which are one decision |
| **D-III-04** | Does Ametecs commit publicly to "no forks" as a product guarantee? | Yes. It is the promise that makes the price defensible, and it is only credible if stated before the first customer asks for one |

---

*End of Part III.*
