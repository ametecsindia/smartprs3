# PART XXXIII — RISK REGISTER

---

## XXXIII.0 Purpose and how to read this part

This is the honest list. A blueprint that presents only the plan is selling; a
blueprint that names what can go wrong, how likely it is, what it costs and what
would have to be true to see it coming is a plan someone can actually run.

Each risk carries: **what it is**, **why it is credible here specifically**
(usually because the evidence base already shows it happening), **likelihood**,
**impact**, **the early-warning signal**, **the mitigation**, and **the owner**.

Scales: likelihood **Low / Medium / High**; impact **Low / Moderate / Severe /
Programme-threatening**.

A risk with no owner is not managed, so every row has one. Where the owner is
"Product Owner", it means the decision is genuinely his and no engineering
mitigation substitutes for it.

---

## XXXIII.1 The five risks that matter most

If attention is scarce, spend it here. These are the ones that end programmes.

| # | Risk | L | I |
|---|---|---|---|
| **1** | Configuration reconstruction is far larger than estimated, because customers' real policies live in PHP and in people's heads, not in their data | **High** | **Programme-threatening** |
| **2** | The rebuild is delivered while SmartPRS 2's live defects go unremediated, and one of them becomes an incident during the build | **Medium** | **Severe** |
| **3** | The "configuration, not code" discipline erodes under delivery pressure and a business literal is committed "just this once" | **High** | **Programme-threatening** |
| **4** | No named statutory compliance owner is appointed, so rule packs cannot be signed and the statutory phase stalls indefinitely | **Medium** | **Programme-threatening** |
| **5** | The on-premise estate cannot be migrated because customers control their own timing, some are on lapsed AMC, and the update channel is itself being replaced | **High** | **Severe** |

Each is expanded below in its category.

---

## XXXIII.2 Programme and delivery risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **PR-01** | **Configuration reconstruction underestimated.** SmartPRS 2 encodes policy in code, so a customer's actual rules are not in their database. Discovering them per customer is the largest unpriced item in the programme | High | Programme-threatening | The first three reconstruction interviews (Part XXIX §XXIX.5) take materially longer than planned, or produce policies the customer disputes | Run the interviews on three representative customers **during Phase 1**, before any commitment. Build the shadow-run diff harness early — it converts an interview problem into a measurement problem. Price Phase 10 per tenant, never in aggregate | Product Owner |
| **PR-02** | **Scope creep through "while we're rebuilding anyway".** Every stakeholder has a wish; a rebuild invites all of them at once | High | Severe | Requests arriving that are not traceable to a Part III §III.10 scope line | The out-of-scope list is confirmed by the Product Owner before Phase 1 (R-III-007) and every addition is an explicit scope change with a stated schedule impact | Product Owner |
| **PR-03** | **Second-system effect** — the platform becomes more general than any customer needs, and generality is built for its own sake | Medium | Severe | Engine features with no rule in the worked libraries that needs them; abstraction layers with one implementation | Every engine capability in this blueprint traces to a specific SmartPRS 2 hard-coding. Enforce that rule: a proposed capability with no evidenced customer need is rejected | Lead architect |
| **PR-04** | **Estimating before the unknowns close**, then being held to it | High | Severe | A date quoted externally before Part XXXII §XXXII.5's three inputs exist | R-XXXII-005. Size Phases 1–2 in detail, deliver, re-estimate against measured velocity | Product Owner |
| **PR-05** | **Parallel SmartPRS 2 feature work** widens the migration surface and does the work twice | Medium | Moderate | Any SmartPRS 2 commit that is not a remediation | D-XXXII-05: remediation only | Product Owner |
| **PR-06** | **Key-person dependency.** A small senior core is the right shape for Phases 1–2 and is also a single point of failure | Medium | Severe | Any engine with exactly one person who understands it | Pair on the engines; the conformance suites are the executable specification; the blueprint is the written one | Engineering lead |
| **PR-07** | **The blueprint is approved but not read**, and the build drifts from it silently | Medium | Severe | Design decisions in code that contradict a part, discovered late | The enforcement toolchain (Part IV §IV.10) makes the most important parts mechanically checkable. For the rest: the per-module definition of done is signed against the blueprint | Lead architect |

---

## XXXIII.3 Technical and architectural risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **TR-01** | **Discipline erosion.** A business literal, a runtime `ALTER`, a fail-open guard "temporarily" | High | Programme-threatening | Any CI gate is skipped, disabled or made non-blocking | Every law in spine S1 has a mechanical gate built in Phase 1 (R-XXXII-002). Disabling a gate requires the lead architect's sign-off and is logged. This is the single most important cultural control in the programme | Lead architect |
| **TR-02** | **The rule engine is not expressive enough** for a real customer's policy, and someone writes PHP to close the gap | Medium | Severe | A customer requirement that the Studio cannot express and that is not a Part VI §VI.14 platform-capability case | The worked rule library (Part VII §12) is drawn from real SmartPRS 2 hard-codings, which is the best available proxy. Every gap found is closed **once, in the engine**, never in a module. The reconstruction interviews in Phase 1 surface gaps before the engine is finished | Lead architect |
| **TR-03** | **The rule engine is too expressive** — customers build rule sets nobody can reason about, and support cannot debug them | Medium | Severe | Rule sets with deep nesting, many overlapping scopes, or frequent conflict-detector blocks | The simulator, the decision trace and the conflict detector exist for this. Add a complexity warning at publish time and a "why did this rule not fire?" diagnostic | Lead architect |
| **TR-04** | **Performance under the configuration model.** Resolving scoped, dated configuration per employee per day is more work than a hard-coded constant | Medium | Severe | Payroll run duration or attendance ingestion missing the Part XXXI §XXXI.9 budgets at 10,000 employees | The scope-signature optimisation (Part VII §14) collapses 10,000 employees to ~120 rule-set resolutions. Compiled-AST caching, materialised aggregation counters, batch engine paths. Performance tests are part of the golden matrices, not a later exercise | Lead architect |
| **TR-05** | **Cross-midnight and timezone semantics get built wrong a second time** — they are subtle, and SmartPRS 2 gets them structurally wrong | Medium | Severe | Any golden test in Part XXX §5's cross-midnight set failing late in Phase 4 | Explicit design review before implementation; the attendance-date rule is specified in Part XI before code; the golden tests are written first | Lead architect |
| **TR-06** | **The custom-field storage model does not perform** at scale for filtering and sorting | Low | Moderate | Directory and report queries degrading as tenants add fields | The promotion path to a generated column (Part X) exists precisely for this, as a controlled migration rather than a runtime `ALTER` | Lead architect |
| **TR-07** | **Cross-part inconsistency** in a 66,000-line blueprint written in parallel — table names, requirement conflicts, duplicated ownership | High | Moderate | Already observed: several parts flagged naming divergences during authoring | A reconciliation appendix records every known divergence and its resolution; the spine wins by default. Re-run consistency checks before each phase begins | Lead architect |
| **TR-08** | **SPX becomes Turing-complete by accretion** — one loop, one function call, one escape hatch at a time | Medium | Severe | Any proposal to add iteration, recursion, assignment or a host-language call to SPX | It is a hard architectural prohibition (spine S5, Part III §III.7). New expressiveness arrives as new *primitives* in the closed function catalogue, never as general computation | Lead architect |

---

## XXXIII.4 Data and migration risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **DR-01** | **The real SmartPRS 2 schema differs from every assumption**, because ~49 tables exist only because code created them and no production dump has ever been taken | High | Severe | The first discovery pack (Part XXIX §XXIX.2) differs materially from the development install | Gate G-1 in Part XXIX makes every migration figure provisional until the packs exist. Start discovery on blueprint approval (R-XXXII-008), not at Phase 10 | Migration lead |
| **DR-02** | **Contaminated data is migrated as truth** — fabricated `paid` payroll runs, unreliable loan balances, stranded commissions, two-day night shifts, PCC values destroyed by a migration | Medium | Severe | Reconciliation passing when it should not | Part XXIX §XXIX.4 requires an explicit disposition decision per contamination class before any load. The tolerance policy's Class C reports a defect correction as a *success*, with a direction check that catches a correction moving the wrong way | Product Owner |
| **DR-03** | **The `app_state` JSON blob** — the analysis names it the highest-risk unknown; nobody currently knows what is in it per tenant | High | Severe | Discovery reveals business-critical configuration living only in that blob | It is item one of the discovery programme. Until it is profiled per tenant, Phase 10 cannot be sized | Migration lead |
| **DR-04** | **Reconciliation cannot be made to pass** because SmartPRS 2's own numbers do not reconcile with themselves | Medium | Severe | Class E (exact) checks failing on historical periods | Historical payroll is migrated **frozen** rather than recomputed (Part XXIX). A frozen record does not have to reconcile to a recomputation; it has to be faithfully carried | Migration lead |
| **DR-05** | **Broken audit chains cannot migrate as verified history**, because SmartPRS 2's chain is already broken in four places | High | Moderate | — (already certain) | Migrate as *records* explicitly marked unverified, starting a new verified chain at cutover. Do not claim tamper-evidence for data that never had it | Migration lead |
| **DR-06** | **A migration destroys data** — SmartPRS 2 has precedent: a migration deleted `attendance_logs` cross-tenant and unbounded, and another destroyed `'overdue'` PCC values | Medium | Severe | Any migration step without a verified pre-step snapshot | Mandatory verified backup before every migration run; every load is idempotent and restartable; per-entity dry runs; nothing is deleted, only superseded | Migration lead |
| **DR-07** | **Parallel-run divergence is unexplainable**, so nobody can tell a bug from a correction | Medium | Severe | Diffs the harness cannot classify | Every difference must classify into Class E, X or C with a named reference. An unclassifiable difference is treated as a failure, not waved through | Migration lead |

---

## XXXIII.5 Compliance and legal risks

> Nothing in this section is legal advice, and no legal conclusion is asserted.
> Where a risk requires counsel, that is the mitigation.

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **CR-01** | **No named compliance owner is appointed**, so no rule pack can be signed and Phase 6 cannot start or finish | Medium | Programme-threatening | The appointment slipping past Phase 5 | Part XXXIV Tier 1. This is an appointment, not an engineering task; escalate it early and treat the phase as blocked until it lands | Product Owner |
| **CR-02** | **A statutory value is shipped without verification** and a customer files on it | Low | Severe | Any pack value lacking a `source_reference` | The signing gate mechanically refuses a pack containing an unverified value. The blueprint asserts no legal value anywhere, deliberately | Compliance owner |
| **CR-03** | **KYC, PCC and PAN documents on a publicly readable disk in SmartPRS 2** may constitute a disclosure event with a legal clock, independent of the rebuild | Medium | Severe | — (the exposure is confirmed; the question is whether it was accessed) | Routed to remediation Phase 0, not to the migration. Counsel decides notification; engineering provides access-log evidence | Product Owner + counsel |
| **CR-04** | **LOP leave paid in full** — an ongoing overpayment of unknown magnitude. Recovering, writing off or disclosing are all decisions with consequences | High | Severe | — (the defect is confirmed) | Quantify first (a live query, Part XXIX §XXIX.2), then decide. The decision is the Product Owner's with counsel; the engineering position is that the correction must be reported as a correction, not hidden inside a reconciliation | Product Owner + counsel |
| **CR-05** | **Fabricated compliance assertions in SmartPRS 2's generated audit pack** — a document asserting "Disciplinary actions: 0 · Compliant" that checked nothing, potentially relied on by a customer in a regulatory context | Medium | Severe | — (confirmed) | Remediate in SmartPRS 2 now. In SmartPRS 3, "not assessed" is a first-class result that propagates into scoring and gating, so compliant-by-default is unreachable | Product Owner + counsel |
| **CR-06** | **A published retention policy contradicts operational reality** — the product's own privacy policy states a period that the system does not implement | Medium | Moderate | — (flagged by two independent parts) | Reconcile the policy and the implementation together; the retention class per entity is a Tier 1 decision | Product Owner + counsel |
| **CR-07** | **A statutory change lands mid-build** and the pack format cannot express it | Low | Moderate | A change requiring a new *concept*, not a new value | The pack content schema includes a rule type; a genuinely new concept is a platform capability added once (Part III §III.8) | Compliance owner |

---

## XXXIII.6 Commercial risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **MR-01** | **Existing customers see no benefit** for a long time and disengage, because the first two phases are invisible to them | High | Severe | Customer questions about what they are getting | Sequence a visible win early: Phase 3 alone removes the largest implementation friction they experience. Communicate the remediation track as the near-term benefit | Product Owner |
| **MR-02** | **L1/L2 edition customers** have been sold a differentiated product they did not receive — the editions are byte-identical | Medium | Severe | A customer noticing, or an audit | Part XXVII D-XXVII-01 is a commercial and possibly contractual decision, not a technical one. Resolve deliberately and in advance, not reactively | Product Owner |
| **MR-03** | **Rule packs withheld from lapsed-AMC customers** creates a commercial position that is difficult to defend if a customer files incorrectly as a result | Medium | Severe | The first lapsed-AMC customer needing a statutory change | Recommendation across Parts XVII and XXVII: do not gate statutory content on AMC; gate features, support and updates instead | Product Owner |
| **MR-04** | **Pricing does not adjust** to the new cost structure, so the efficiency gain is not captured | Medium | Moderate | Implementation cost falling with no pricing change | Revisit pricing and the partner model once Phase 3 measurably reduces implementation effort | Product Owner |
| **MR-05** | **A large prospect demands a capability the platform genuinely cannot configure**, and the fork temptation returns | Medium | Programme-threatening | The words "just for this one customer" | The no-fork rule is a stated product guarantee (R-III-001, D-III-04). The answer is always: build the capability once in the platform, or decline the requirement | Product Owner |
| **MR-06** | **Partner ecosystem opens too early**, before configuration is safe enough for third parties to touch | Low | Moderate | Partner-caused incidents | Partners get the Studio only after the validation, simulation and diff machinery of Part VI is complete and proven internally | Product Owner |

---

## XXXIII.7 Operational risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **OR-01** | **On-premise customers do not upgrade.** They control timing, some are on lapsed AMC, and the update channel is itself being replaced | High | Severe | Version-register drift after GA | Cloud-first GA (D-XXXII-03) buys a quarter of evidence. Per-customer migration plans, an incentive to move, and an explicit end-of-life policy for SmartPRS 2 | Product Owner |
| **OR-02** | **Customers still do not run the scheduler and worker**, exactly as today | High | Severe | Health checks failing on new installs | The installer refuses to complete without them (Part XXVI); the health check surfaces failure loudly; degradation is visible rather than silent | Engineering lead |
| **OR-03** | **The unconditional nightly demo reset** creating a known-password administrator may be live on customer servers now | Medium | Severe | — (the schedule is unconditional; whether it is deployed is unknown) | Audit the installed base immediately. This is a remediation Phase 0 item with a security clock | Product Owner |
| **OR-04** | **The default on-premise credential** is published in the README and created by a non-environment-gated seeder | Medium | Severe | — (confirmed) | Audit every deployed install; force a credential rotation; remove the seeder path in SmartPRS 2 now | Product Owner |
| **OR-05** | **Restore has never been proven** on any SmartPRS 2 install, and there is no restore path at all | High | Severe | — (confirmed absent) | SmartPRS 3 ships a restore path with a scheduled drill whose last success date is visible. For SmartPRS 2, take verified backups before any migration touches an install | Engineering lead |
| **OR-06** | **Support cannot debug a configuration-driven product** as easily as a hard-coded one | Medium | Moderate | Support escalations about "why did it do that?" | The decision trace and the simulator are support tools as much as product features. Train support on them in Phase 2, not at GA | Engineering lead |

---

## XXXIII.8 Organisational risks

| ID | Risk | L | I | Early signal | Mitigation | Owner |
|---|---|---|---|---|---|---|
| **GR-01** | **The implementation team keeps asking engineering for changes** out of habit, and the configuration model never gets exercised | Medium | Severe | Engineering tickets that should have been Studio edits | Implementation consultants configure the internal alpha themselves in Phase 3. If they cannot, that is a product defect, not a training problem | Product Owner |
| **GR-02** | **Nobody owns the statutory content** as an ongoing operational responsibility after the first packs ship | Medium | Severe | Packs going stale | CR-01's appointment is permanent, not a project role. The version register tracks which customer is on which pack | Product Owner |
| **GR-03** | **Documentation lags and the blueprint becomes the only source of truth**, then goes stale | High | Moderate | A module completed without its documentation | Documentation is an item in the per-module definition of done (Part XXXII §XXXII.7); a module without it is not complete | Engineering lead |

---

## XXXIII.9 The risk of not doing this

Every risk above is a risk of action. Balance requires the risks of inaction,
and they are not smaller.

| Risk of continuing with SmartPRS 2 | Assessment |
|---|---|
| Live financial defects continue to accrue — loans never recovered, LOP leave paid in full, commissions stranded | Certain. These are confirmed and ongoing |
| A statutory change cannot be delivered to the on-premise estate in reasonable time | Certain. The mechanism is a PHP constant and 200 manual upgrades |
| Implementation cost per customer stays high, capping growth at engineering capacity | Certain |
| The fork happens. It is the natural next step when configuration cannot absorb a requirement | High likelihood within a year of continued growth |
| A security incident from one of the confirmed critical findings — fail-open authorisation, ungated endpoints leaking API keys and PAN, documents on a public disk, unsigned update packages | Medium likelihood, severe impact. The unsigned update channel in particular is remote code execution on customers' payroll servers |
| A customer discovers fabricated payroll history or a fabricated compliance assertion | Medium likelihood, severe reputational impact |
| The codebase becomes unmaintainable in fact rather than in theory — 12,533 lines in one file, 50.5% of exceptions destroyed, 25 unmitigated race conditions, 4.3% route coverage | Already substantially true |

**The judgement this part supports:** the rebuild carries real, nameable,
manageable risk. Continuing does not carry less risk; it carries risk that is
already materialising and that Ametecs has less control over.

---

## XXXIII.10 Requirements arising from this part

| ID | Requirement |
|---|---|
| R-XXXIII-001 | This register shall be reviewed at every phase gate, with likelihood and impact re-scored and new risks added. |
| R-XXXIII-002 | Every risk shall have a named owner at all times; an unowned risk shall block the phase gate. |
| R-XXXIII-003 | The five risks in §XXXIII.1 shall be reported on monthly to the Product Owner with their early-warning signal explicitly assessed. |
| R-XXXIII-004 | No CI enforcement gate shall be disabled or made non-blocking without the lead architect's recorded sign-off and an expiry date. |
| R-XXXIII-005 | The remediation items with a security or legal clock (CR-03, CR-04, OR-03, OR-04) shall be actioned independently of the rebuild schedule. |
| R-XXXIII-006 | The configuration-reconstruction interviews on three representative customers shall be completed during Phase 1, before any Phase 10 estimate is given. |

---

## XXXIII.11 Product Owner decisions

| ID | Decision | Recommendation |
|---|---|---|
| **D-XXXIII-01** | Accept the risk posture, or reduce scope to reduce it? | Accept. The scope reductions available (Part III §III.10) are already taken; further reduction removes the configurability that is the entire point |
| **D-XXXIII-02** | Who owns the risk register operationally? | The lead architect maintains it; the Product Owner reviews it at every gate |
| **D-XXXIII-03** | Are the four clock-carrying remediation items (CR-03, CR-04, OR-03, OR-04) actioned now, ahead of everything? | Yes. Two are security, two are potentially legal. None of them benefits from waiting |

---

*End of Part XXXIII.*
