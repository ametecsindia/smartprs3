# APPENDIX A — CROSS-PART RECONCILIATION AND CONSISTENCY REGISTER

Status: OUTPUT OF THE CONSISTENCY PASS — FOR THE LEAD ARCHITECT AND THE PRODUCT OWNER
Scope: `SPINE.md` plus the thirty-four parts in `/home/claude/blueprint/docs/`, 78,898 lines
Authority: `SPINE.md` wins every conflict. Where the spine is silent, the **owning part**
for the concept wins. Where neither settles it, the divergence is recorded, not resolved.

---

## A.1 Method and scope

The thirty-four parts were authored in parallel against a shared spine. This pass
looked for the failure modes that parallel authoring actually produces, not for
prose disagreement.

**What was checked, and how.**

| # | Check | Method |
|---|---|---|
| 1 | Table names that contradict the spine | Token search for the `cfg_`-prefixed spellings of spine S9's tables across all parts |
| 2 | References to the wrong part number | Every `Part <ROMAN>` reference read in context against the part's actual subject |
| 3 | References to a section that does not exist | Script: every heading in every part indexed (both the `## XXVIII.4` and the bare `## 5.6` styles Parts VII and VIII use); every `Part <R> §<n>` reference resolved against that index |
| 4 | Duplicate requirement IDs within a part | Script: all `**R-<PART>-nnn**` definitions counted per part; every collision read to separate a genuine double-definition from a bolded forward reference |
| 5 | Requirement-ID contiguity | Script: min, max, distinct count and gap list per prefix |
| 6 | Table catalogue completeness and arithmetic | Script: every `#####`/`###` table-specification heading in every part extracted, de-noised, and diffed against Part XXVIII §XXVIII.5 (core) and §XXVIII.6 (derivable) |
| 7 | The same concept named two ways | Manual read of the diff from check 6, cluster by cluster |
| 8 | Permission vocabulary | Script: Part XXII's 176-entry `verb.resource` catalogue extracted; every `module.resource.verb`-shaped code used in every other part tested against it |
| 9 | Two parts owning one capability | Manual: entitlement, notification, audit, compliance, workflow and scope ownership traced to a declaring sentence |
| 10 | Decision-ID coverage | Script: every `D-*` and `PO-*` identifier in every part tested for presence in Part XXXIV's consolidated register |
| 11 | SPX surface completeness | Part VII's EBNF, type table, function catalogue and namespace catalogue diffed against spine S5 and against every SPX expression written anywhere in the blueprint |
| 12 | Contradictory recommendations | Manual, on the decision registers of Parts XXVI, XXVII, XXXI and XXXIV |

**What this pass is not.** It did not verify a single statutory value, a single
calculation, or a single claim about SmartPRS 2's source. It did not read every
requirement. It checked structure, naming, references and arithmetic — the things a
script can prove and a reviewer cannot hold in their head across 78,898 lines.

**Headline.** The blueprint is internally coherent on its **laws, engines and
mechanisms** — the seven laws, SPX, the configuration envelope, effective dating and
the trace contract are consistent everywhere and no part contradicts them. It is
**not** coherent on its **physical schema, its permission catalogue, or its own
counts**. Three of the four registers that other parts depend on — Part XXVIII's
table catalogue, Part XXII's permission catalogue, and Part XXXIV's decision
register — are materially incomplete against what the rest of the blueprint
already assumes. Those three gaps, not the twelve reported divergences, are the
real finding of this pass.

---

## A.2 Fixed in place

Eighteen edits, in ten files. Every one is a mechanical correction against a stated
authority. No design decision was made here, and no section was rewritten.

| # | What | Where | Why | Authority |
|---|---|---|---|---|
| F-01 | `cfg_value_sets` → `value_sets` (4 occurrences) | Part V §V (artefact register, configuration inventory rows 4 and 9) | The spine names the table `value_sets`; the `cfg_` prefix is Part V's alone | **Spine S9** |
| F-02 | `cfg_field_definitions` → `field_definitions` (3 occurrences) | Part V (artefact register; configuration inventory rows 6 and 7) | As F-01 | **Spine S9** |
| F-03 | `cfg_field_surface_rules` → `field_surface_rules` | Part V (configuration inventory row 8) | As F-01 | **Spine S9** |
| F-04 | `cfg_status_transitions` → `status_transitions` | Part V (configuration inventory row 9) | Parts IX, X, XVIII, XX and XXVIII §XXVIII.4 all use the unprefixed name; Part V was the sole outlier | **Part XXVIII §XXVIII.4** |
| F-05 | `cfg_field_definitions` → `field_definitions` | Part VI §VI.2, row K4 | Same defect, same authority — found by the same token search, so fixed in the same pass rather than left as a known-wrong instance | **Spine S9** |
| F-06 | `cfg_value_sets` → `value_sets` | Part XI §XI.15, configuration inventory row 21 | As F-05 | **Spine S9** |
| F-07 | "Part XVIII" → "Part XIX" | Part XIII, §XIII.1 comparison table ("Silent best-effort notification") | Part XVIII is Loans, Advances, Commission and Incentives. The notification engine is Part XIX | **Part XIX** (owning part) |
| F-08 | "(Part XVIII)" → "(Part XIX)" | Part XIII, R-XIII-094 | As F-07 | **Part XIX** |
| F-09 | "(Part XXII)" → "(Part XXI)" | Part XVIII §XVIII.9.1, "Validity" row | Part XXII is Roles, Security and Tenancy. The compliance item model is Part XXI. Part XXI itself flags this as **D-XXI-10** and asks for exactly this fix | **Part XXI** (owning part), corroborated by D-XXI-10 |
| F-10 | "Compliance (Part XXII)" → "Compliance (Part XXI)" | Part XVIII §XVIII.16, inbound event table | Same error, second site — not reported by any author, found by reading every `Part XXII` reference in Part XVIII | **Part XXI** |
| F-11 | "Part XXIII" → "Part XIX" | Part XVIII §XVIII.14, configuration inventory row 23 ("Notification audiences and templates") | Part XXIII is Audit. A third mis-target in the same part, of the same class | **Part XIX** |
| F-12 | "Part XXVIII §127" → "Part XXVIII R-XXVIII-008" | Part XVIII, R-XVIII-082 rationale | Part XXVIII has no §127; `127` was a line number. R-XXVIII-008 is the rule being cited ("No column stores a list") | **Part XXVIII R-XXVIII-008** |
| F-13 | "Part VII §609" → "Part VII §5.7" | Part X §X.4 | Part VII has no §609; `609` was the line number of the `employee.custom('<field_key>')` row, which sits in §5.7, the variable namespace catalogue | **Part VII §5.7** |
| F-14 | "Part XVI §XVI.4.5" → "Part XVI §XVI.3.6 (R-XVI-027)" | Part XV §XV.16, D-XV-… caption-rename trade-off | Part XVI §XVI.4 stops at §XVI.4.4. Caption pinning — the requirement actually being relied on — is §XVI.3.6, R-XVI-027 | **Part XVI §XVI.3.6** |
| F-15 | Ten statutory-base flags replaced by Part XV's nineteen, in Part XV's spellings, with the six superseded spellings named inline | Part XXVIII §XXVIII.5 C7, `cfg_salary_components` | Part XV R-XV-007 is the owning specification for the flag set. Part XXVIII declared ten and used six different spellings (`is_pf_wage`, `is_esi_wage`, `is_pt_gross`, `is_minimum_wage_comparable`, `is_ctc_component`, `is_net_payable`) | **Part XV R-XV-007** |
| F-16 | Eight flag names in R-VIII-056's list realigned to Part XV's spellings, with the ownership stated | Part VIII §11.0, R-VIII-056 | Knock-on of F-15: `salary.wage_base()` cannot read columns under names the schema does not use. Part VIII cited "spine S12", but S12 names no flag — it only requires that flags exist | **Part XV R-XV-007** |
| F-17 | Added `DayFraction`, `Percentage` and `Rate<Money/U>` to the type table as Part VIII-owned extensions; added the seven Part VIII namespaces to the EBNF `namespace` production and to the namespace catalogue; added `OVERLAP_MINUTES` to the function catalogue as a new "Interval ★" group | Part VII §5.3, §5.4.1, §5.6, §5.7 | Part VIII R-VIII-014 registers its types in the **shared** `spx_types` registry and states SPX is one language; Part VIII §11.0 does the same for namespaces. Part VII's EBNF `namespace` production is a **closed enumeration**, so an SPX expression using `settlement.*` was un-parseable against the published grammar. Part XI writes `OVERLAP_MINUTES` in rule WT-05 against a closed function catalogue that did not contain it | **Part VIII §5.1/§11.0**; **Part XI** (WT-05); Part VII R-VII-017 (closed catalogue) |
| F-18 | Every permission code in the scope-mapping table retargeted to a code that exists in Part XXII §XXII.2.2, plus an explicit note naming the two capabilities Part XXII's catalogue does not cover | Part XXIV §XXIV.2.5, R-XXIV-021 table | Part XXII is the sole authority for the `verb.resource` catalogue (R-XXII-010: codes are platform-owned and closed). Nineteen of the twenty-two codes Part XXIV named did not exist in it — `employee.view`, `payslip.view`, `report.execute`, `config.publish`, `integration.webhook.manage`, `platform.cross_tenant` and thirteen others | **Part XXII §XXII.2.2** |

**Verification after editing.** The section cross-reference script reports **zero**
unresolvable references across all thirty-four parts. The `cfg_`-prefixed spellings
of spine S9's tables are **absent** from the corpus.

---

## A.3 Recorded, not fixed

Each of these is a design, ownership or business decision. None was touched.

### R-01 — Part XXVIII's table catalogue is short by roughly 304 tables

| | |
|---|---|
| **Positions** | **Part XXVIII** declares "120 core tables specified in full … Total application schema: **162 tables**, every one of them named here" (§XXVIII.5, and restated in the part's totals). **Twenty-four other parts** specify **304 further tables** to the Section 123 standard — table, purpose, columns, types, keys, indexes, tenancy, history, audit, PII — that appear in neither §XXVIII.5's catalogue nor §XXVIII.6's derivable register. |
| **Owner** | Part XXVIII (the catalogue), but every module part owns its own tables. |
| **Severity** | **Highest structural finding in this pass.** Part XXVIII is the part a migration engineer and a DBA will read as the schema. It currently describes about a quarter of it. |
| **Recommendation** | Part XXVIII stops claiming a complete enumeration and becomes what it is good at: the **conventions, archetypes, integrity model, tenancy mechanism, indexing and partitioning contract**, plus the fifteen bounded-context clusters and their roots. Delegate the full table list to the owning parts and generate the master register mechanically from the parts, at build time, rather than maintaining it by hand in one part. A hand-maintained 460-row list in a parallel-authored document will be wrong again within a month. |
| **Decides** | Lead Architect. |

### R-02 — Part XXVIII's own table arithmetic does not add up

Independently of R-01, Part XXVIII's internal counts are inconsistent:

| Claim | Site | Actual |
|---|---|---|
| "120 tables specified in full" | §XXVIII.5 summary, and the part's totals | The fifteen cluster headings declare **117** between them; the clusters actually specify **118**; adding the three vocabulary tables (`status_domains`, `status_values`, `status_transitions`) specified in §XXVIII.4 but counted in no cluster gives **121** |
| C14 — "16 tables" | Cluster heading | 15 specified |
| C2 — "7 tables" | Cluster heading | 8 specified (`api_client_scopes` is separately marked derivable) |
| C8 — "8 tables" | Cluster heading | 9 specified (`tax_declaration_items` is separately marked derivable) |
| "42 derivable tables" | §XXVIII.6 | The register lists **48** distinct table names |

**Recommendation:** correct the three cluster headings and both totals. This is
mechanical, but it is *not* mechanical to guess which of C14's sixteen intended
tables was dropped, so it is recorded rather than fixed. **Decides:** Part XXVIII's
author.

### R-03 — Part XXII's permission catalogue is short by roughly 110 codes

| | |
|---|---|
| **Positions** | **Part XXII §XXII.2.2**: "The catalogue below is complete for v3.0", 176 codes, and R-XXII-010 makes the catalogue **closed** — a code not in it binds to nothing. **Fourteen other parts** declare permissions on their operations using approximately **110 codes that are not in it**, including every `compliance.*` code Part XXI uses (24), every `ops.*` code Parts XIX, XXVI and XXXI use (23), every `statutory.*` code Part XVII uses (14), every `workflow.*` code Part IX uses (8), every `leave.*` code Part XIII uses (7), and `payroll.period.lock` / `payroll.cycle.manage` / `payroll.payout.confirm` from Part XIV. |
| **Owner** | Part XXII. |
| **Consequence** | R-XXII-010 plus R-XXIV-022 ("a route with no declaration fails the build") means the build fails on roughly 110 routes on day one. This is the second most consequential finding after R-01, and it is the same shape: a register that claims closure while the rest of the blueprint has already outgrown it. |
| **Recommendation** | Same as R-01: generate the catalogue from the module parts' declared operations rather than maintaining it by hand, and keep R-XXII-010's *closure* property by making the generated catalogue the seeded migration. Two capabilities need a genuine design answer rather than a generated row: a **machine punch-ingest permission** distinct from `attendance.punch.create.manual` (Part XXIV `attendance.ingest` needs it; a device is not a human doing a manual punch), and a **period-settlement read**. |
| **Decides** | Lead Architect, with Part XXII's author. |

### R-04 — The leave schema is named twice, with no overlap

| | |
|---|---|
| **Positions** | **Part XXVIII C6**: `cfg_leave_types`, `leave_requests`, `leave_request_days`, `leave_ledger_entries`, `compoff_ledger_entries`, `holiday_calendar_days`. **Part XIII §XIII.13**: `leave_types` + `leave_type_versions`, `leave_applications`, `leave_application_days`, `leave_ledger`, `comp_off_credits`, plus nine more. Part XIII never uses a single Part XXVIII name; Part XXVIII never uses a single Part XIII name. |
| **Owner** | Part XIII owns the leave domain; Part XXVIII owns schema conventions. |
| **Why not fixed** | These are not spelling variants. `cfg_leave_types` is one config-payload row per published version; `leave_types` + `leave_type_versions` is a two-table artefact/version pair. Choosing between them changes the resolution query. That is a design decision. |
| **Recommendation** | Adopt **Part XIII's** shapes (it is the owning module and it models versioning explicitly), but apply Part XXVIII's `cfg_` prefix convention for configuration payloads so the schema reads consistently. Then delete C6 from Part XXVIII per R-01. |
| **Decides** | Lead Architect. |

### R-05 — The audit ledger is named twice

**Part XXVIII C15** calls it `audit_events`; **Part XXIII** calls it `audit_entries`
throughout — in R-XXIII-075's partitioning rule, in the trigger specification, in
the CI sweep of T1, and in §XXIII.10.1's full table specification — and never
mentions `audit_events`. Part XXIII additionally specifies eight audit tables
Part XXVIII does not name (`audit_chain_heads`, `audit_verification_checkpoints`,
`audit_legal_holds`, `audit_redactions`, `audit_integrity_incidents`,
`audit_archive_segments`, `audit_action_definitions`, `issued_artefacts`).
**Recommendation:** Part XXIII wins; it is the owning part and its specification is
the one with the partitioning, the hash chain and the privilege separation.
**Decides:** Lead Architect. **Note:** spine S14 names no table, so the spine does
not settle this.

### R-06 — The platform feature catalogue is named twice

**Part VI §VI.17.14** specifies `feature_catalogue` (platform-owned, `feature_key`
unique, with `depends_on_json`, `degrades_to_json`, `is_core`). **Part XXVII
§XXVII.8.6** declares an FK to `platform_features`, "the **generated** catalogue of
R-XXVII-060", and lists it again in §XXVII.8 as generated. Same concept, two names,
and one is an FK target — so one of them is a broken foreign key.
**Recommendation:** `feature_catalogue`, Part VI, which specifies it in full;
retarget Part XXVII's two references. Recorded rather than fixed because Part XXVII
describes its version as **generated** from module manifests while Part VI's is
authored, and that difference may be deliberate.
**Decides:** Lead Architect.

### R-07 — Six requirement IDs in Part XXV carry two different requirements each

The only genuine duplicate-ID defect in the corpus. In every case the second
occurrence states an unrelated requirement, and three of them are labelled
"*(restated)*" when they are not restatements:

| ID | First definition | Second definition |
|---|---|---|
| R-XXV-050 | A discount must apply for a stated number of periods | *(labelled "restated as the governing invariant")* `current_period_start` / `current_period_end` semantics |
| R-XXV-051 | Provisioning on credit must be an approval workflow instance | *(labelled "restated")* The period-advance operation must be atomic with payment |
| R-XXV-052 | A quotation must be a snapshot document with its own number series | *(labelled "restated")* Renewing early must not lose days |
| R-XXV-060 | The subscription lifecycle must be table-driven | *(seat definition)* Seat consumption must be defined by a published, versioned rule |
| R-XXV-061 | Every transition must write an audit entry | There must be exactly one seat counter |
| R-XXV-062 | `suspended` degrades to read-only plus the billing surface | The seat-limit message must be generated, not literal |

(`R-XXV-080`'s second occurrence is an explicit "reserved" numbering note and is
benign.) **Why not fixed:** renumbering requires choosing which cluster keeps the
number and re-pointing every citation; Part XXV has free numbers in its 1–161 range.
**Recommendation:** the **billing-lifecycle** cluster (the second occurrences) takes
new IDs. **Decides:** Part XXV's author.

### R-08 — Update ordering: code swap before migration

| | |
|---|---|
| **Positions** | **Spine S18**: "verify signature → compatibility check → database backup → code backup → maintenance mode → **migrate → update code** → rebuild caches → restart workers → health check → exit maintenance". **Part XXVII §XXVII.4.5**: `… BACKUP_DB → BACKUP_CODE → MAINTENANCE → SWAP_CODE → DEPENDENCIES → MIGRATE → CACHE_REBUILD → …` — the swap **before** the migration. |
| **Owner** | The spine owns the update contract; Part XXVII owns the update mechanism. |
| **The author's argument** | Migrations ship *inside* the release. Running `migrate` before `SWAP_CODE` means running the old code's migration set — the new migrations are not on disk yet. Part XXVII also needs `DEPENDENCIES` (composer install) between the two, because a new migration may use a class the new dependency lock brings in. |
| **Recommendation** | **Part XXVII's order is correct and the spine's is wrong.** Spine S18's sequence is only executable if migrations are delivered out of band, which contradicts S18's own signed-artefact model. Amend spine S18 to `… maintenance mode → update code → dependencies → migrate → rebuild caches → …`. Note that this makes the rollback contract *more* demanding, not less, and Part XXVII already carries it: from `SWAP_CODE` onward rollback is defined, and from `MIGRATE` onward it requires a database restore (R-XXVII-099 forbids unattended resumption past `SWAP_CODE`). |
| **Decides** | Lead Architect, then a spine amendment. This is the only place in the pass where a part is right and the spine is wrong. |

### R-09 — Absolute refusal versus audited override on stale attendance

| | |
|---|---|
| **Positions** | **Part XXVI R-XXVI-172** (absolute): "An operation whose correctness depends on fresh background data **MUST refuse** rather than proceed on stale data, naming the staleness", with test T40 asserting the payroll preview refuses. **Part XXXI D-31-08** (softer): the gate "**may** be overridden — but only with a named authorising role, a typed reason, an audit entry, and the staleness printed on the run's own exception list and on every payslip trace." |
| **Owner** | Genuinely contested. Part XXVI owns on-premise operational integrity; Part XXXI owns the deployment and run-book model; Part XIV owns the payroll run. |
| **Why not fixed** | This is a business decision about whether a month-end can be forced. It is not resolvable by reference to any authority in the blueprint. |
| **Both arguments are good** | Part XXXI's: a hard block will eventually stop a legitimate month-end for a device that is genuinely decommissioned. Part XXVI's: an override is how "paying on incomplete attendance" comes back, and it comes back at 11pm on the 30th, which is exactly when the authorising role will grant it. |
| **Recommendation** | Part XXXI's audited override, **with two additions**: the override must name the *affected employee count*, and the staleness must appear on the payslip trace (which D-31-08 already requires) **and** on the payslip itself for the affected employees. An override nobody downstream can see is the SmartPRS 2 failure with a log line added. |
| **Decides** | **Product Owner.** Route to the Part XXXIV register; it is currently in neither part's register as a cross-part conflict. |

### R-10 — Part XVIII's restructuring of Part XXVIII cluster C9 — ACCEPTED, not yet applied

Part XVIII §XVIII.11.0 proposes four changes to C9, argues each, and states they
"must be accepted into Part XXVIII":

| Part XXVIII | Part XVIII | Verdict |
|---|---|---|
| `recoveries` as one A1 among several | renamed **`obligations`**, made the root of every money movement in both directions | **Accept.** Part XXVIII's own column list for `recoveries` (`employment_id`, `cause_code`, `total_amount`, `recovered_amount`, `max_percent_of_net`, `priority_no`) is already the general obligation; the name is the only thing that is directional |
| `loans`, `advances` as independent A1 roots | 1:1 extension tables on `obligations` | **Accept.** Same pattern Part XXVIII already uses for `cfg_workflows` on `config_versions`; preserves both names and every column |
| `loan_ledger_entries` + derivable `advance_ledger_entries` | unified **`obligation_ledger_entries`**, one parent column | **Accept.** Part XXVIII's `loan_ledger_entries` already carries two nullable parents behind a `CHECK` — the construction R-XVIII-150 removes |
| `loan_schedules` (flat) | **`obligation_schedules`** (version header) + **`obligation_schedule_lines`** | **Accept.** Restructure, prepayment, pause and spread-recovery need supersession, which a flat line table cannot express (spine S4, Law 3) |

**Action required:** Part XXVIII C9 must be updated. It has not been — Part XXVIII
still specifies `recoveries`, `loans`, `advances`, `loan_schedules` and
`loan_ledger_entries` as it did before. **Not fixed here** because rewriting five
table specifications is not a surgical edit. C9's count becomes **11 specified + 4
derivable**, up from 8 + 2. **Minor:** §XVIII.11.0's prose says "three deliberate
structural changes" above a table listing four. **Decides:** Part XXVIII's author,
executing an accepted decision.

### R-11 — Part XXXIV's consolidated decision register omits eight decisions

Part XXXIV states its scope as "the spine and Parts I–XXXI" and totals 328
identifiers. Parts **XXXII** and **XXXIII** each raise Product Owner decisions —
`D-XXXII-01` … `D-XXXII-05` and `D-XXXIII-01` … `D-XXXIII-03` — and **none of the
eight appears in Part XXXIV**. A part whose stated purpose is "one queue" that "can
be worked through in sittings" leaves eight decisions outside the queue, including
phase-sequencing and risk-acceptance decisions that are plausibly Tier 1.
**Recommendation:** extend Part XXXIV's scope to Parts I–XXXIII and add the eight
rows; the totals become 338 identifiers / 336 register rows before re-merging.
**Decides:** Part XXXIV's author. **Not fixed** because each row needs the tier
assignment and the merge analysis that only Part XXXIV's method produces.

### R-12 — Four decision-ID conventions coexist

| Convention | Used by | Example |
|---|---|---|
| `D-nn` | the spine | `D-01` |
| `D-<ROMAN>-nn` | Parts I–XXX, XXXII, XXXIII | `D-XXI-10` |
| `D-<arabic>-nn` | **Part XXXI only** | `D-31-08` |
| `PO-<ROMAN>-nn` | **Parts XIX and XXIII only** | `PO-XIX-01` |

Part XXXIV carries all four faithfully, so nothing is lost — but a reader searching
for `D-XXXI-08` finds nothing, and the two `PO-` parts look decision-free to any
script that greps for `D-`. **Recommendation:** normalise to `D-<ROMAN>-nn`
everywhere except the spine, with a redirect table in Part XXXIV for one release.
**Decides:** Lead Architect. Low severity, high nuisance value.

### R-13 — `agg.nth` takes a fourth argument that spine S5.5 does not have

Spine S5.5 gives `agg.nth(event, partition, order)`. Part VII §5.9 gives
`agg.nth(event: Code, partition: Code, order: Code, n: Number) → DateTime | Null`
and states plainly that this "refines spine S5.5, which gives a three-argument form;
the ordinal cannot be expressed without `n`." **Part VII is right** — a function
named "nth" that cannot be told which *n* is not usable, and Part XI's punch rules
call the four-argument form. **The spine was not edited.** **Recommendation:** amend
spine S5.5 to the four-argument signature. **Decides:** Lead Architect, as a spine
amendment. Recorded here as the authoritative note in the interim.

### R-14 — `cfg_` prefixing is a convention nobody declared

Part XXVIII prefixes configuration-payload tables `cfg_` (`cfg_leave_types`,
`cfg_salary_components`, `cfg_notification_events`, …). **R-XXVIII-006, the naming
rule, does not mention the prefix.** Consequently Parts XIII, XIX, XXIII and XXVII
drop it (`leave_types`, `notification_events`, `template_versions`,
`licence_store`), while Parts IX, X, XI, XIV, XV, XVI, XX, XXI use it. Roughly half
the R-04, R-05 and R-06 disagreements are downstream of this one unstated rule.
**Recommendation:** state it in R-XXVIII-006 — configuration-payload tables keyed to
`config_versions` are prefixed `cfg_`, and nothing else is — then it becomes
mechanically checkable and most of the naming divergence resolves itself.
**Decides:** Part XXVIII's author.

### R-15 — Part XV's own flag-addition list contradicts its own requirement

Fix F-15 aligned Part XXVIII to Part XV R-XV-007's nineteen flags. In doing so,
Part XV's §XV.7.1 was found to disagree with Part XV §XV.2.3:

- R-XV-007 lists **nineteen** flags. §XV.7.1 lists ten "additions", of which
  `in_net` is not an addition (it is `is_net_payable` renamed) and **`is_disabled`
  appears nowhere in R-XV-007**.
- §XV.7.1 **omits `is_employer_cost`**, which R-XV-007 requires and Part XXVIII did
  not carry — so following §XV.7.1's migration list would produce eighteen flags,
  one of them undeclared.

F-15 implemented **R-XV-007**, the requirement, not §XV.7.1, the migration note.
**Recommendation:** correct §XV.7.1 to nine additions (`is_lwf_base`,
`is_encashment_base`, `is_perquisite`, `counts_toward_epf_admin_charges`,
`in_gross`, `is_employer_cost`, `prorates`, `lop_affected`, `arrear_eligible`) plus
the two renames, and either add `is_disabled` to R-XV-007 or drop it.
**Decides:** Part XV's author.

### R-16 — Entitlement tables specified in two parts

Parts XXV §XXV.14.7 and XXVII §XXVII.8.6 both specify `entitlement_sets`,
`entitlement_set_versions`, `entitlement_features`, `entitlement_limits` and
`entitlement_quotas`. **This is not a conflict** — Part XXV explicitly delegates the
grammar to §XXVII.3 and Part XXVII names Part XXV as joint owner — but it is
duplicated Section-123 specification of five tables in two files, and only Part
XXVII carries the column lists. **Recommendation:** Part XXV keeps the delegation
sentence and drops the table heading. Low severity; recorded because duplicated
specification is how the R-04/R-05 divergences began.

---

## A.4 The authoritative registers, after reconciliation

### A.4.1 Table register — Part XXVIII's core catalogue

Owner of the **conventions**: Part XXVIII §XXVIII.2. Owner of each **table**: the
part named. Counts are as-specified, not as-declared (see R-02).

| Cluster | Domain | Declared | **Actual** | Specification owner |
|---|---|---:|---:|---|
| §XXVIII.4 | Vocabularies (`status_domains`, `status_values`, `status_transitions`) | — | **3** | Part XXVIII |
| C1 | Platform | 5 | **5** | Parts XXV, XXVII |
| C2 | Identity & Access | 7 | **8** | Part XXII |
| C3 | Organisation | 6 | **6** | Parts V, X |
| C4 | People | 10 | **10** | Parts V, X, XXI (`engagement_kind_code`) |
| C5 | Time & Attendance | 10 | **10** | Part XI |
| C6 | Absence | 6 | **6** | Parts XII, XIII — **naming unresolved, R-04** |
| C7 | Compensation & Payroll | 12 | **12** | Parts XIV, XV, XVI |
| C8 | Statutory | 8 | **9** | Part XVII — **rises to 22, R-01** |
| C9 | Money Movement | 8 | **8** | Part XVIII — **becomes 11 + 4, R-10** |
| C10 | Talent | 6 | **6** | Part XX |
| C11 | Compliance | 6 | **6** | Part XXI |
| C12 | Communication | 4 | **4** | Part XIX |
| C13 | Integration | 9 | **9** | Part XXIV |
| C14 | Configuration & Rules | 16 | **15** | Parts VI, VII, VIII, IX, X |
| C15 | Audit & Observability | 4 | **4** | Part XXIII — **naming unresolved, R-05** |
| | **Core total** | *117 declared / 120 asserted* | **121** | |
| §XXVIII.6 | Derivable register | *42 declared* | **48 named** | archetype-generated |

### A.4.2 Table register — specified elsewhere, absent from Part XXVIII

**304 distinct tables**, extracted from Section 123-grade specification headings.
Part XXIX is excluded (it specifies SmartPRS 2's legacy schema, not SmartPRS 3's).
Five names appear under two parts (`api_credentials`, and the five entitlement
tables of R-16), so the row counts sum to 310.

| Owning part | Count | Tables |
|---|---:|---|
| **Part VI** | 21 | `compliance_exceptions`, `config_change_set_items`, `config_change_sets`, `config_dependencies`, `config_import_items`, `config_import_runs`, `config_package_items`, `config_packages`, `config_sandboxes`, `config_simulations`, `config_test_cases`, `config_test_runs`, `config_validation_findings`, `entitlement_grants`, `feature_catalogue`, `platform_defaults`, `platform_scope_dimensions`, `policy_pack_versions`, `policy_packs`, `tenant_feature_states`, `tenant_pack_applications` |
| **Part VII** | 13 | `action_definitions`, `agg_counter_definitions`, `agg_counters`, `agg_metric_definitions`, `agg_window_dirty`, `decision_points`, `decision_traces`, `rule_pack_items`, `rule_publication_checks`, `rule_test_cases`, `rule_version_actions`, `rule_versions`, `spx_functions` |
| **Part VIII** | 6 | `calculation_points`, `day_length_policies`, `formula_publication_checks`, `formula_sample_employees`, `formula_test_cases`, `rounding_profiles` |
| **Part IX** | 9 | `cfg_sla_calendar_days`, `cfg_sla_calendars`, `cfg_workflow_bindings`, `cfg_workflow_node_participants`, `workflow_group_members`, `workflow_groups`, `workflow_node_activations`, `workflow_outbox`, `workflow_timers` |
| **Part X** | 16 | `cfg_expiry_alert_ladders`, `cfg_expiry_alert_steps`, `cfg_journey_step_fields`, `cfg_journey_steps`, `cfg_onboarding_journeys`, `employee_field_change_requests`, `entity_field_value_history`, `expiry_alert_emissions`, `export_jobs`, `export_profiles`, `import_batches`, `import_columns`, `import_row_issues`, `import_rows`, `journey_consents`, `journey_otp_challenges` |
| **Part XI** | 10 | `attendance_arrears_items`, `attendance_day_effects`, `attendance_recalc_diffs`, `attendance_regularisation_days`, `attendance_regularisations`, `cfg_egress_profiles`, `cfg_settlement_policies`, `cfg_vendor_profiles`, `device_commands`, `device_health_snapshots` |
| **Part XII** | 9 | `employee_calendar_days`, `holiday_calendar_versions`, `holiday_calendars`, `holiday_elections`, `weekly_off_allocations`, `weekly_off_pattern_versions`, `weekly_off_patterns`, `weekly_off_substitutions`, `work_on_off_settlements` |
| **Part XIII** | 15 | `comp_off_credits`, `leave_accrual_rules`, `leave_adjacency_policies`, `leave_application_days`, `leave_applications`, `leave_balances_snapshot`, `leave_blackout_periods`, `leave_carry_forward_rules`, `leave_documents`, `leave_encashment_rules`, `leave_ledger`, `leave_restriction_rules`, `leave_staffing_rules`, `leave_type_versions`, `leave_types` |
| **Part XIV** | 6 | `cfg_proration_policies`, `cfg_recovery_priority_policies`, `employee_period_statutory_locks`, `payout_instructions`, `payroll_period_locks`, `payroll_run_stages` |
| **Part XV** | 3 | `cfg_component_flag_definitions`, `cfg_structure_test_cases`, `salary_structure_assignments` |
| **Part XVI** | 9 | `cfg_payslip_template_components`, `cfg_payslip_template_fields`, `cfg_payslip_template_sections`, `cfg_payslip_templates`, `payslip_access_log`, `payslip_acknowledgements`, `payslip_deliveries`, `payslip_verifications`, `payslip_ytd_lines` |
| **Part XVII** | 11 | `payroll_statutory_snapshots`, `rule_pack_bindings`, `rule_pack_imports`, `rule_pack_signing_keys`, `statutory_applicability_heads`, `statutory_applicability_versions`, `statutory_challans`, `statutory_company_policy_versions`, `statutory_exposure_snapshots`, `statutory_filing_files`, `statutory_obligations` |
| **Part XVIII** | 9 | `commission_achievements`, `commission_scheme_runs`, `expense_claim_lines`, `expense_claim_tax_details`, `expense_claims`, `obligation_ledger_entries`, `obligation_schedule_lines`, `obligation_schedules`, `obligation_shortfalls` |
| **Part XIX** | 14 | `audience_definitions`, `channel_providers`, `delivery_attempts`, `delivery_events`, `digest_items`, `notification_campaigns`, `notification_digests`, `notification_events`, `notification_outbox`, `notification_subscriptions`, `provider_credentials`, `scheduled_communications`, `template_channel_bodies`, `template_versions` |
| **Part XX** | 35 | `candidate_contact_suppressions`, `candidate_conversions`, `cfg_checklist_templates`, `cfg_document_template_versions`, `cfg_document_templates`, `cfg_document_type_versions`, `cfg_evaluation_criteria`, `cfg_evaluation_form_versions`, `cfg_evaluation_forms`, `cfg_notice_policies`, `cfg_pipeline_stages`, `cfg_pipeline_transitions`, `cfg_pipeline_versions`, `cfg_probation_policies`, `cfg_probation_policy_versions`, `cfg_recruitment_pipelines`, `cfg_retro_policies`, `cfg_sourcing_channels`, `checklist_instance_items`, `checklist_instances`, `compensation_revisions`, `document_access_tokens`, `document_signature_events`, `document_token_access_log`, `employment_transfers`, `evaluation_scorecards`, `exit_settlement_lines`, `generated_documents`, `hiring_drive_slots`, `hiring_drives`, `interview_panel_members`, `probation_cases`, `probation_events`, `retro_effects`, `scorecard_criterion_scores` |
| **Part XXI** | 8 | `case_events`, `cfg_case_types`, `cfg_policy_versions`, `compliance_audit_pack_findings`, `compliance_audit_packs`, `compliance_item_transitions`, `compliance_score_contributions`, `compliance_scores` |
| **Part XXII** | 12 | `access_scope_types`, `access_scope_units`, `access_scopes`, `api_credentials`, `auth_policies`, `auth_tokens`, `impersonation_sessions`, `login_events`, `permission_implications`, `role_permissions_effective`, `user_password_history`, `user_sessions` |
| **Part XXIII** | 9 | `audit_action_definitions`, `audit_archive_segments`, `audit_chain_heads`, `audit_entries`, `audit_integrity_incidents`, `audit_legal_holds`, `audit_redactions`, `audit_verification_checkpoints`, `issued_artefacts` |
| **Part XXIV** | 16 | `api_credentials`, `api_idempotency_keys`, `api_request_log`, `api_usage_counters`, `bridge_installations`, `egress_profile_hosts`, `egress_profiles`, `integration_connector_runs`, `integration_connectors`, `integration_cursors`, `integration_partners`, `webhook_events`, `webhook_inbound_deliveries`, `webhook_inbound_endpoints`, `webhook_inbound_nonces`, `webhook_secrets` |
| **Part XXV** | 30 | `config_prerequisites`, `config_template_versions`, `config_templates`, `coupon_redemptions`, `credit_note_lines`, `credit_notes`, `demo_invitations`, `demo_sessions`, `document_number_sequences`, `entitlement_features`, `entitlement_limits`, `entitlement_quotas`, `entitlement_set_versions`, `entitlement_sets`, `invoice_lines`, `meter_readings`, `meter_snapshots`, `payment_allocations`, `plan_versions`, `price_book_lines`, `price_book_versions`, `price_books`, `provisioning_runs`, `quota_events`, `seat_policies`, `subscription_period_history`, `subscription_state_transitions`, `tenant_offboarding`, `tenant_prerequisite_status`, `tenant_quotas` |
| **Part XXVI** | 12 | `artefact_imports`, `backup_policies`, `backup_runs`, `health_check_results`, `health_checks`, `installation_preflight_results`, `installation_services`, `operations_alerts`, `restore_assertions`, `restore_runs`, `scheduled_job_runs`, `scheduled_jobs` |
| **Part XXVII** | 21 | `advisory_exposures`, `entitlement_features`, `entitlement_limits`, `entitlement_quotas`, `entitlement_set_versions`, `entitlement_sets`, `installation_reports`, `licence_documents`, `licence_instances`, `licence_store`, `licence_verifications`, `release_artefacts`, `release_manifest_entries`, `release_signatures`, `releases`, `security_advisories`, `signing_keys`, `update_backups`, `update_policies`, `update_run_steps`, `update_runs` |
| **Part XXX** | 7 | `qa_budget_results`, `qa_budgets`, `qa_case_results`, `qa_customer_baselines`, `qa_dod_signoffs`, `qa_gate_results`, `qa_test_registry` |
| **Part XXXI** | 9 | `alert_notifications`, `alert_rules`, `incident_events`, `incident_tenants`, `job_dead_letters`, `release_flag_states`, `release_flags`, `slo_definitions`, `slo_windows` |

### A.4.3 The corrected total table count

| Component | Count |
|---|---:|
| Part XXVIII core catalogue, as actually specified (§XXVIII.4 vocabularies + fifteen clusters) | **121** |
| Part XXVIII derivable register, distinct names in §XXVIII.6 | **48** |
| Specified in the other parts, absent from both of the above (A.4.2) | **304** |
| Less the 17 of those already carried in the derivable register | *(already excluded)* |
| **Reconciled total, before resolving R-04, R-05, R-06 and R-10** | **473** |
| Part XVIII's accepted C9 restructure (R-10), net | **+3** |
| Deduplication once R-04/R-05/R-06 are decided (leave 6, audit ~4, feature catalogue 1) | **−11 est.** |
| **Working figure for planning** | **≈ 465** |

**Against Part XXVIII's stated "162 tables total", the reconciled figure is roughly
2.9×.** No estimate in this appendix should be treated as precise: the extraction is
heading-based, five names are double-counted across parts, and R-04/R-05 could move
a dozen either way. The order of magnitude is the point, and it is not 162.

For comparison: the as-is analysis records SmartPRS 2 at 102 migration tables plus 49
controller-created tables. SmartPRS 3 at ~465 is a **4.5× schema**, which is a
capacity, migration and index-maintenance fact the delivery plan (Part XXXII) does
not currently reflect.

### A.4.4 SPX register — types

Owner of the language: spine S5 and Part VII. Extensions owned as noted. All are
registered in the one shared `spx_types` registry (Part VIII R-VIII-014).

| Type | Kind | Owner |
|---|---|---|
| `Number`, `Money`, `Days`, `Hours`, `Minutes`, `Duration`, `Date`, `DateTime`, `Time`, `Boolean`, `Text`, `Code`, `List<T>`, `Null` | base — 14 | **Spine S5.1**, specified in Part VII §5.4.1 |
| `DayFraction` | refinement of `Days`, `[0,1]` | **Part VIII §5.1 (R-VIII-014)** — added to Part VII §5.4.1 by **F-17** |
| `Percentage` | refinement of `Number`, tagged `percent` | **Part VIII §5.1 (R-VIII-014)** — added by **F-17** |
| `Rate<Money/U>`, `U ∈ {Days, Hours, Minutes}` | parametric | **Part VIII §5.1 (R-VIII-014)** — added by **F-17** |

**17 types.** Part XXVIII §XXVIII.2's type table already maps `DayFraction` to
`DECIMAL(9,4)`, so the schema was ahead of Part VII's catalogue on this point.

### A.4.5 SPX register — namespaces

| Namespace | Owner |
|---|---|
| `employee.*`, `org.*`, `service.*`, `period.*`, `shift.*`, `day.*`, `attendance.*`, `leave.*`, `salary.*`, `statutory.*`, `policy.*`, `agg.*` | **Spine S5.3 / S5.5** — 12 |
| `credential.*`, `compliance.*` | **Part VII §5.7** (the DRA gate; spine S19 item 1) — 2 |
| `request.*`, `run.*`, `context.*` | **Part VII §5.7** — 3 |
| `settlement.*`, `ytd.*`, `input.*`, `claim.*`, `loan.*`, `prior.*`, `formula.*` | **Part VIII §11.0** — 7. Added to Part VII's EBNF production and namespace catalogue by **F-17** |

**24 namespaces.** Part VIII additionally extends `salary.*` with
`wage_base('<BASE_CODE>')`, `balance_pool`, `net_after_statutory`, and `period.*`
with `periods_per_year`, `fin_year_start`, `fin_year_end`.

### A.4.6 SPX register — functions

| Group | Owner | Count |
|---|---|---:|
| Spine S5.4's closed set, as specified in Part VII §5.6 | **Spine S5.4** | **52** |
| `MONEY_AS_NUMBER`, `IS_NULL`, `SLAB_MARGINAL_RELIEF`, `WEEK_OF_MONTH`, `HOLIDAY_TYPE`, `DATE_OF`, `TIME_OF`, `LEAVE_DAYS_IN_RANGE` | **Part VII §5.6** (marked ★, each with its justification) | **8** |
| `TO_MINUTES`, `TO_HOURS`, `TO_DAYS` | **Part VII §5.6** ★ — required by R-VII-013's ban on unit coercion | **3** |
| `OVERLAP_MINUTES` | **Part XI** (rule WT-05, the night-window allowance) — added to Part VII §5.6 by **F-17** | **1** |
| **Total closed catalogue** | | **64** |

Aggregation primitives are separate (Part VII §5.9): `agg.count`, `agg.sum`,
`agg.nth`, `agg.occurrence_index`, `agg.collapse_repeats`, `agg.streak`,
`agg.balance` — **7**, all from spine S5.5, with `agg.nth` carrying a fourth
argument (**R-13**).

### A.4.7 Requirement-ID register

Distinct identifiers per prefix, after this pass. "Range" is min–max of the numeric
suffix; a range wider than the count means reserved or retired blocks, which several
authors used deliberately.

| Prefix | Distinct | Range | Prefix | Distinct | Range |
|---|---:|---|---|---:|---|
| `R-I-` | 24 | 1–24 | `R-XVIII-` | 174 | 1–174 |
| `R-II-` | 30 | 1–112 | `R-XIX-` | 138 | 1–138 |
| `R-III-` | 7 | 1–7 | `R-XX-` | 177 | 1–177 |
| `R-IV-` | 13 | 1–13 | `R-XXI-` | 136 | 1–136 |
| `R-V-` | 35 | 1–91 | `R-XXII-` | 239 | 1–317 |
| `R-VI-` | 113 | 1–211 | `R-XXIII-` | 114 | 1–114 |
| `R-VII-` | 75 | 1–75 | `R-XXIV-` | 214 | 1–214 |
| `R-VIII-` | 77 | 1–77 | `R-XXV-` | 128 | 1–161 ⚠ |
| `R-IX-` | 117 | 1–117 | `R-XXVI-` | 94 | 1–201 |
| `R-X-` | 120 | 1–120 | `R-XXVII-` | 144 | 1–161 |
| `R-XI-` | 128 | 1–128 | `R-XXVIII-` | 53 | 1–112 |
| `R-XII-` | 49 | 1–49 | `R-XXIX-` | 154 | 1–154 |
| `R-XIII-` | 99 | 1–99 | `R-XXX-` | 78 | 1–78 |
| `R-XIV-` | 79 | 1–79 | `R-XXXI-` | 120 | 1–134 |
| `R-XV-` | 54 | 1–54 | `R-XXXII-` | 9 | 1–9 |
| `R-XVI-` | 80 | 1–80 | `R-XXXIII-` | 6 | 1–6 |
| `R-XVII-` | 176 | 1–176 | `R-XXXIV-` | 0 | — |

**3,254 distinct requirement identifiers.** ⚠ marks Part XXV, where six identifiers
carry two requirements each (**R-07**) — so 128 identifiers cover 134 requirements.
Part XXXIV declares no requirements by design; it consolidates decisions.

**Decision identifiers:** 328 across the spine and Parts I–XXXI (Part XXXIV's own
count, verified: 308 `D-*` plus ~20 `PO-*`), **plus 8 uncounted in Parts XXXII and
XXXIII** (**R-11**) → **336**.

---

## A.5 Consistency verification results

| Check | Result |
|---|---|
| **Section cross-references** (every `Part <R> §<n>` resolved against that part's actual heading index) | **3 broken before this pass, 0 after.** `Part VII §609` (Part X — a line number), `Part XVI §XVI.4.5` (Part XV — §XVI.4 stops at .4), `Part XXVIII §127` (Part XVIII — a line number). All fixed: F-12, F-13, F-14 |
| **Wrong part references** | **5 found, 5 fixed.** Part XIII ×2 (XVIII→XIX), Part XVIII ×3 (XXII→XXI twice, XXIII→XIX once). Only three of the five were reported by authors; two were found by reading every `Part XXII`/`Part XXIII` reference in Part XVIII |
| **Duplicate requirement IDs — genuine double definitions** | **6, all in Part XXV** (R-XXV-050, 051, 052, 060, 061, 062). See R-07 |
| **Duplicate requirement IDs — false positives** | **10**, in Parts XIII, XIX (×5), XXIV, XXVIII. Each is a bolded forward reference in prose plus one definition, or (R-XXVIII-030) a deliberate restatement with identical content. **None is a defect.** Several parts claim contiguity in their requirements index; those claims hold |
| **Requirement-ID contiguity** | 20 of 33 numbered parts are contiguous with no gaps. Parts II, V, VI, XXII, XXV, XXVI, XXVIII have large gaps (60–108 numbers) and Parts X, XI, XII, XIII, XIV, XVII, XXIII, XXVII, XXXI have small ones. **No part was found asserting contiguity it does not have**, but no part explains its reserved blocks either — a reader cannot tell a retired requirement from a typo |
| **Table names contradicting the spine** | **9 occurrences across 3 parts**, all the `cfg_`-prefixed spellings of spine S9's `field_definitions`, `field_surface_rules`, `value_sets` (plus `cfg_status_transitions`). All fixed: F-01 … F-06. **Zero remain** |
| **Orphaned table names** (a table referenced as "specified elsewhere" that is specified nowhere) | **1 class, benign.** Part X cites `cfg_code_sequences` and `code_sequence_counters` as being in "Part XXVIII §XXVIII.5" — they are in §XXVIII.6, the derivable register, not §XXVIII.5. The tables exist; the pointer is one section off |
| **Duplicate table names with different column definitions** | **None found.** Every table specified in two parts (59 of them) is an explicit *extension* — the module part restates Part XXVIII's table and adds columns, saying so. Not one silently redefines a column Part XXVIII declares |
| **Same concept, two names** | **4 found**: the leave schema (R-04), the audit ledger (R-05), the feature catalogue (R-06), and the C9 money-movement roots (R-10, accepted). All recorded |
| **Two parts claiming one capability** | **1 found and benign** (R-16, entitlements — both parts name the other as joint owner and one delegates the grammar explicitly). Every other boundary examined — notification (XIX), audit (XXIII), workflow (IX), compliance (XXI), scope (XXII), payroll (XIV) — has exactly one owner and the dependent parts cite it |
| **Permission codes bound to a closed catalogue that does not contain them** | **~110 codes across 14 parts.** See R-03. This is the check with the largest gap between what the blueprint claims and what it contains |
| **Decision-register coverage** | **8 decisions orphaned** (R-11). All other `D-*` and `PO-*` identifiers reach Part XXXIV |
| **SPX expressions using undeclared surface** | **8 found, all now declared**: 7 namespaces and 1 function, all used in live rule and formula examples against a closed grammar that omitted them. F-17 |
| **Contradictory recommendations on the same decision** | **2 found**: update-step ordering (R-08, spine vs Part XXVII) and the stale-attendance gate (R-09, Part XXVI vs Part XXXI). Both recorded |

---

## A.6 Residual risk

**1. This pass proves nothing about content.** It checked names, numbers, references
and counts. It did not check a single calculation, a single statutory value, a single
claim about SmartPRS 2's source code, or whether any requirement is *right*. A part
can be perfectly consistent and completely wrong.

**2. Three registers are the load-bearing risk, and all three fail the same way.**
Part XXVIII's table catalogue, Part XXII's permission catalogue and Part XXXIV's
decision register each declare themselves complete and each is materially
incomplete — by ~304 tables, ~110 permissions and 8 decisions respectively. In every
case the cause is identical: a hand-maintained central register in a document written
in parallel by different authors. **Fixing the three instances does not fix the
cause.** Any register that must stay in step with thirty-three other files should be
generated, not written.

**3. The reconciled table count is an estimate.** ~465 is derived from heading
extraction with a hand-built noise filter. Names appearing only in prose were missed;
a few names in headings are not tables. Treat it as "between 430 and 490", and treat
"162" as disproven.

**4. Part XXVIII C9 and Part XXVIII C7's flag list are now inconsistent with each
other's provenance.** F-15 updated C7's flag list to Part XV's nineteen. C9 was
*not* updated for Part XVIII's accepted restructure (R-10). A reader of Part XXVIII
today gets a current C7 and a stale C9. This is a known, deliberate, temporary state
and it must not survive the next revision.

**5. Part XXIV §XXIV.2.5 now names two scopes that cannot be bound.**
F-18 deliberately left `attendance.ingest` and half of `attendance.read` pointing at
capabilities Part XXII does not define, with a note saying so, because inventing two
permission codes on Part XXII's behalf would be a design decision. Under R-XXIV-023
these fail the build — which is correct behaviour and a visible prompt, not a defect,
but a reader who skips the note will read it as an error.

**6. Requirement-ID gaps are unexplained.** Parts VI, XXII, XXV, XXVI and XXVIII each
have 60–108 unused numbers inside their range. None documents whether these are
reserved, retired, or the residue of an editing pass. A reader cannot distinguish
`R-XXVI-150` "does not exist" from `R-XXVI-150` "was deleted for a reason".

**7. Eight of the sixteen recorded divergences need a *person*, not an editor.**
R-01, R-03, R-04, R-05, R-06 and R-08 need the Lead Architect. R-09 needs the Product
Owner and is currently in no decision register. R-11's eight orphaned decisions need
Part XXXIV's tiering method applied. None of these will resolve by being written down
again.

**8. What this appendix does not claim.** It does not claim the blueprint is now
consistent. It claims that eighteen mechanical defects are corrected, that sixteen
substantive divergences are now visible and attributed, and that three central
registers are demonstrably incomplete. A document of 78,898 lines written by
thirty-four authors in parallel will contain more than this pass found — in
particular, this pass could not check whether two parts describe the *same behaviour*
in incompatible prose, which is the failure mode no script detects and the one that
costs the most to discover in code.

---

## A.7 Second pass — the three registers are now generated (2026-09-03)

This pass implements the recommendation §A.6 residual risk 2 makes: *"Any register
that must stay in step with thirty-three other files should be generated, not
written."* It does not re-audit; it builds the generator, reports what the corpus
actually contains, and corrects the three parts that asserted a completeness they
did not have.

### A.7.1 What was built

`tools/generate-registers.py` — standard library only, no third-party
dependencies, `--selftest` runs an assertion suite against a synthetic part
covering every specification shape found in the corpus. It parses all forty
documents in `docs/` and emits four machine-generated files into
`docs/registers/`, each carrying a "do not hand-edit" header naming the script:

| File | Contents |
|---|---|
| `REGISTER-TABLES.md` | Every table, its owning part(s), its cluster where one is stated, whether it is derivable, and a divergence flag where one name is specified in two parts with different columns |
| `REGISTER-PERMISSIONS.md` | Every permission code, the parts that use it, whether Part XXII's catalogue carries it, and the evidence class the code was extracted from |
| `REGISTER-DECISIONS.md` | Every `D-*` / `PO-*` identifier, its part, its one-line question, and Part XXXIV's tier |
| `REGISTER-REQUIREMENTS.md` | Every `R-*` identifier by owning part, with counts, ranges, gaps and duplicate definitions |

Six distinct table-specification shapes are in use across the corpus and all six
are handled: a `#####` heading naming the table; a numbered `###` heading; a
heading naming several tables joined by `·` or `+`; a heading with a
`(+ … derivable)` child list; a **bold paragraph** (Part XXIX's whole `mig_`
tooling schema, plus tables in Parts XV, XX and XXVI); and a register-style
markdown table whose first column is the table name (Part XXVIII §XXVIII.6,
Part XVII §XVII.14.4, Parts XXI and XXVII). The 2026-09-03 audit's extraction
saw only the first three, which is the main reason its table count was low.

### A.7.2 What the corpus actually contains

| Register | This pass | The audit above (§A.3, §A.4) | Why they differ |
|---|---:|---:|---|
| **Tables, distinct names** | **540** | ~465 estimated | The audit missed the bold-paragraph and register-table shapes, and Appendices B and C had not been written |
| Part XXVIII core, as specified | **118** | 118 | Agrees exactly. Declared: 117 across the headings, 120 in the totals |
| Part XXVIII §XXVIII.6 derivable, distinct | **48** | 48 | Agrees. Declared: 42 |
| **Permission codes used** | **545** | not counted | — |
| Part XXII catalogue | **150** | 176 asserted | The audit's 176 was itself wrong; the catalogue has 150 rows |
| **Used but not catalogued** | **395** | ~110 | The audit tested only the three-segment `module.resource.verb` shape and only fourteen parts |
| **Decision identifiers** | **360** | 336 | Appendices B and C add 22; Part XXXV adds 2 |
| Rows Part XXXIV carried | 328 (+2 `I-` items) | 328 | Agrees |
| **Requirement identifiers** | **3,565** | 3,254 (Parts I–XXXIV) | Appendix B adds 216, Appendix C 96, Part XXXV 53 |

**Conflicts the generator found.**

- **41 table names are specified in two parts with different column sets.**
  Forty of the 41 pair a module part with Part XXVIII — the extension pattern
  §A.5 characterised as benign — but only **three** of them (`cfg_formulas`,
  `field_definitions`, `rule_packs`) *say so* in a form the extractor can see.
  The other **38 are silent extensions**, which is exactly how R-04 and R-05
  began. The forty-first, `api_credentials`, is two module parts (XXII and XXIV)
  defining one name independently with no shared column beyond the archetype, and
  it remains unreconciled.
- **395 permission codes are used and not catalogued**, across the whole corpus —
  not 110. Eleven of them fall inside what is now a reserved generative namespace
  (`form.*`, `report.*`, `dashboard.*`); **384 need an Ametecs-authored catalogue
  row**. Listed by module at Part XXII §XXII.2.2a.
- **33 decision identifiers reached no queue.** Thirty-two are real —
  Part XXXII (5), Part XXXIII (3), Part XXXV (2), Appendix B (11),
  Appendix C (11). The thirty-third, `D-XXXI-08`, **names no decision**: it is a
  mis-spelling of `D-31-08` occurring once, in §A.3 R-12 of this appendix, and it
  is the only identifier in the corpus for which no question can be recovered.
- **27 requirement identifiers carry two definitions inside their own part:**
  seven in Part XXV (R-07's six, plus the benign `R-XXV-080` numbering note),
  **eighteen in Part XXXV**, which states several requirements twice — once in
  the body and again in its closing index — and two more, `R-XIX-093` and
  `R-XXVIII-030`, which are the bolded-forward-reference and deliberate-restatement
  false positives §A.5 describes. The register reports the sites and does not
  judge which is which. Note that a part which restates *every* requirement in a
  closing index is not flagged: the generator takes a part's prose definitions
  where it has them and consults its tables only for parts that define nothing in
  prose, so Parts III, XXXII and XXXIII are counted from their tables and the
  index-carrying parts are not double-counted.
- **59 requirement identifiers are cited but never defined in the part that owns
  the prefix** — dangling references, listed in the register's UNKNOWN section.
- **3 table specifications could not be parsed** and are counted as UNKNOWN, not
  dropped: Part VI §VI.17.15 `platform_defaults`, one Part XXIV register row, and
  one Part XXIX heading that is not a table at all.

### A.7.3 What was corrected in the three parts

| # | Part | What |
|---|---|---|
| G-01 | XXVIII | "162 tables total" and "120 core tables specified in full" replaced with the generated figures: **118** core + **3** vocabularies = **121** specified here, **48** derivable, **168** distinct names in this part, **540** in the schema |
| G-02 | XXVIII | Cluster headings corrected: **C2 7 → 8**, **C8 8 → 9**, **C14 16 → 15**. The fifteen headings now sum to 118, which is what they specify |
| G-03 | XXVIII | "42 derivable tables" → **48**; "this is the complete remainder of the schema" → the complete remainder *of this part's clusters* |
| G-04 | XXVIII | §XXVIII.0's scope statement now says plainly that 372 tables are specified elsewhere and that this part is conventions plus its own clusters, not an enumeration of the schema |
| G-05 | XXVIII | New **§XXVIII.6.1**: the authoritative register is generated; the part is the *specification*, the register is the *index*; a register row never overrides a part. Adds **R-XXVIII-115** (CI regenerates and fails on drift) and **R-XXVIII-116** (an unparsed spec is UNKNOWN, never dropped) |
| G-06 | XXVIII | A cluster-arithmetic table added to §XXVIII.5's preamble, core and derivable split, with the three corrections named |
| G-07 | XXII | "The catalogue below is complete for v3.0" replaced with the real position: 150 catalogued, 545 used, 395 uncatalogued, and what that means under R-XXIV-022 |
| G-08 | XXII | **R-XXII-010 amended from closed to extensible by a defined process**, with the contradiction stated (a closed catalogue cannot coexist with Appendix B `D-B-06`'s tenant-created forms) and resolved: two paths — Ametecs-authored rows, and three **reserved generative namespaces** (`form.*`, `report.*`, `dashboard.*`) whose codes the platform mints from published artefact versions. New **R-XXII-010a/b/c** carry the generative rule, the closure property where it is still true, and the closure of the namespace set itself |
| G-09 | XXII | `permissions` table gains `origin_code` and `source_config_version_id` so an authored row and a generated row are distinguishable in the schema, not only in prose |
| G-10 | XXII | New **§XXII.2.2a** lists all 395 uncatalogued codes grouped by module, generated, with the eleven generative-namespace codes separated out and the two capabilities that need a design answer (machine punch ingest; period-settlement read) restated from R-03 |
| G-11 | XXXIV | Scope corrected from "the spine and Parts I–XXXI" to the spine, every numbered part and every appendix. Totals restated: **362 register rows · 360 identifiers · 295 distinct decisions** |
| G-12 | XXXIV | New **§XXXIV.6.1** adds the 32 missing identifiers with tier, owner and merge note. Tier counts updated (T1 26 → 35, T2 176 → 190, T3 53 → 58); four of the 32 merge into existing decisions, which is why 32 identifiers add 28 decisions |
| G-13 | XXXIV | §XXXIV.2 states that nine of the new Tier 1 decisions do not yet have a worked-up `T1-nn` entry, and that writing them is the next editorial task — rather than letting the count and the section silently disagree |

**Verification.** `python3 tools/generate-registers.py` reports, after these edits:
540 tables (689 specification sites) · 545 permission codes · 361 decision
identifiers of which **1** is absent from Part XXXIV (`D-XXXI-08`, which names
nothing) · 3,565 requirement identifiers. `--selftest` passes.

### A.7.4 What this pass did *not* do

- **R-01's substance is unresolved.** Part XXVIII no longer *claims* to be the
  schema, and the register now names all 540 tables — but the Lead Architect's
  decision on whether Part XXVIII should shed C6, C9 and C15 entirely (R-04,
  R-05, R-10) has not been taken, and those clusters are unchanged.
- **The 384 uncatalogued permission codes are listed, not written.** Each still
  needs a `business_description`, a `risk_class`, an `implies[]` edge and an
  assurance level, which are Part XXII's author's to write. Until then the routes
  that bind to them fail the build — correctly.
- **Nine Tier 1 decisions have a register row and no worked-up entry** (G-13).
- **The 38 silent table extensions are flagged, not reconciled.**
- **Extraction has a known false-positive floor.** It is pattern-based over prose.
  A handful of names in the table register are column or artefact references that
  read like table names, and a handful of permission codes are error or event
  codes that appear in a permission sentence. Both are visible in the registers'
  evidence columns. The remedy is a stricter authoring convention in the parts,
  not a longer hand-tuned filter in the script — a filter that grows with the
  corpus is the hand-maintained register in a different file.

---

## A.8 Third pass — two defects found by *implementing* the specification (2026-09-04)

Both entries below were found while building the SmartPRS 3 authorisation core
against this blueprint — not by reading it. Neither survived first contact with a
seeder and a route binding, and **neither of the two reconciliation passes above
caught either one**, which is the observation worth recording: A.1's method reads
the corpus for agreement between documents, and both defects are internally
consistent on the page. One is a namespace collision that only bites when
something has to *put a code somewhere*; the other is a sentence a human reads
correctly and a generator does not. A document review cannot find either.

| ID | Part | Defect | Change |
|---|---|---|---|
| I-01 | XXII, XXI, XXIV, VI, XVIII, B | **Fourteen Ametecs-authored engine permissions were parked inside the three namespaces R-XXII-010a reserves for tenant-minted artefact codes.** `report.run`, `dashboards.view`, `form.definition.manage` and the rest are platform capabilities, not codes minted from a published tenant artefact. R-XXII-010a forbids a migration from seeding a row in a reserved namespace and requires the validator to reject one, so those codes could never be catalogued — and R-XXII-010b/R-XXIV-022 then fail the build on every route binding one of them. `form.definition.manage` was worse than unreachable: a tenant form keyed `definition` mints that exact string. The catalogue seeder had to skip all fourteen | Thirteen engine codes moved to the **plural** prefixes `reporting.*`, `dashboards.*`, `forms.*`, everywhere they appear — the §XXII.2.2 catalogue rows, Appendix B's `R-B-045` table, its report/dashboard/form API surfaces and worked examples, Part XXI's dashboard routes, Part XXIV's OAuth scope map, Part VI's K9 row and Part XVIII's report route. A paragraph next to **R-XXII-010a** now states that the reserved namespaces are *exclusively* tenant-minted, that engine capabilities take the plural prefix, and **why** — so the collision is not re-introduced. The fourteenth, `report.execute`, was left alone: it is declared nowhere, and its only occurrence is §XXIV.2.5's own note recording it as a superseded spelling this catalogue does not define (see I-02 — same class of defect, opposite decision) |
| I-02 | `tools/generate-registers.py` | **The register generator read a counter-example as a declaration.** R-XXII-013 names `payroll.run.approve.hyderabad` as the code the validator must reject — "Hyderabad is a scope, not a capability". The generator extracted it as a used permission, so the register asserted as valid the one code the blueprint says must be invalid, and the catalogue seeder had to carry a hand-written exception to avoid seeding it | The generator now reads the **sentence** around a code, not the line (the marker sits on the line after the code), and skips one presented as a counter-example — *schema error*, *rejected by the catalogue validator*, *counter-example*, *is not a permission*, *is invalid*. Every skip is listed with its sentence in the register's new **§5**, so a wrong skip is visible rather than silent. Deliberately **not** a rule on shape: the 21 legitimate four-segment codes are untouched, and the exclusion cost exactly one code (545 → 544). "MUST NOT" is deliberately not a marker — *a user MUST NOT hold `x.y`* declares `x.y`. Neither is §XXIV.2.5's "Part XXII does not define": true of the code, but for three of the six codes in that sentence it is their only prose occurrence, so keying on it would delete `platform.cross_tenant` and `integration.webhook.manage` from the register. The seeder's hand-written exception was deleted as now redundant |

**Verification.** `python3 tools/generate-registers.py` reports **544** permission
codes (was 545: 13 renamed one-for-one, 1 excluded as a counter-example), **150**
catalogued, **394** used but not catalogued (was 395). `--selftest` passes, with a
new case asserting the counter-example is skipped. The catalogue seeder now seeds
**543** declared entries (was 530) and skips **1** (was 15). Part XXII §XXII.2.2a's
reproduced gap table was regenerated from the register: **393** codes now need an
Ametecs-authored row, up from 384, because the thirteen renamed codes are ordinary
permissions that must be authored like any other.

---

*End of Appendix A.*
