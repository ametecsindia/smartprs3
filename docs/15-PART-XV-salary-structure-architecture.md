# PART XV — SALARY STRUCTURE ARCHITECTURE

**Covers master-brief Sections 32 (the salary component engine) and 33 (the
salary structure builder).**

Conforms to: spine **S1** (the seven laws), **S3.1/S3.2** (scope and
precedence), **S4** (effective dating, draft/publish, approval), **S5** (SPX),
**S7** (the formula engine), **S8** (the workflow engine), **S12** (statutory
bases declared by flag, never by name), **S14** (audit), **S15**
(explainability), **S16** (data conventions), **S19.4** (preserve the penny-safe
residue allocation).

Depends on and does not duplicate: **Part VI** (configuration architecture — the
artefact, version, scope and publish machinery), **Part VII** (rule engine),
**Part VIII** (formula engine — the type system of §5, money arithmetic of §6,
the dependency graph of §7, the calculation context of §8, publish gates G1–G12
of §9, and formulas FL-01…FL-28), **Part IX** (workflow engine — `SALARY-CHANGE`,
`CONFIG-PUBLISH-RULE`), **Part XIV** (payroll — proration flags are consumed
there), **Part XVI** (payslip presentation), **Part XXVIII** (physical schema for
`cfg_salary_components`, `cfg_salary_structures`, `cfg_structure_lines`,
`employee_compensations`).

---

## XV.0 Purpose

This part decides **what a salary component is, what a salary structure is, and
how an employee's pay is derived from them.** Three things are fixed here: a
component's every statutory and grouping semantic is a **declared flag**, so no
code anywhere may inspect a component's name; a structure is a scoped,
effective-dated, versioned artefact resolved by spine S3.2 precedence with
per-employee override as the most specific case; and an employee's CTC is
**effective-dated salary history**, so a historical recompute uses the CTC of
the period rather than the CTC of today.

---

## XV.1 What SmartPRS 2 does today, and why it must change

Every claim traces to `analysis/A-payroll.md` or `analysis/F-statutory-compliance.md`
with its `file.php:line` citation.

### XV.1.1 Roughly twenty-five component name literals are written into the payslip and read back out of it

`A-payroll.md` §5.3 Q4 lists them exhaustively. Written into
`payslips.earnings` / `payslips.deductions` JSON as string keys:

> `'Basic'`, `'HRA'`, `'Special Allowance'` (`PayrollGenController:2627`, `:1656`,
> `:2180`; `AppDataController:2371`; `computeSlipFromComponents:2257`),
> `'Commission'` (`:2638`, fallbacks at `:644`, `:649`, `:1319`),
> `'Night Shift Allowance'` (`:2642`), `'Overtime'` (`:2646`),
> `'Commission / Incentive'`, `'Overtime (Xh × Nx)'`,
> `'Night Shift Allowance (N nights)'` (simulator `:2182`, `:2185`, `:2189`),
> `'Incentive'` (`IncentiveController:89`), `'Salary Arrears'`
> (`IncrementController:400`), `'PF'`, `'ESI'`, `'Professional Tax'`, `'TDS'`
> (`computeSlipFromComponents:2288`; `PayrollGenController:2650`;
> `AppDataController:2372`), **`'PF (employee)'` — a different string for the same
> head** (`:1662`, `:2194`), `'Labour Welfare Fund'` (`:2303`, `:2196`),
> `'Conveyance'` (`:2308`, `:2652`, `:2197`), `'Loan EMI'` (`:2657`, `:1715`,
> `:1876`, `:2200`), `'Advance EMI'` (`:2660`), `'Salary Advance Recovery'`
> (`:2663`, `:2203`), **`'Salary advance recovery (this month)'` — a third variant
> of the same line** (`:1726`), `'Clawback / Reversal'` (`:2667`).

And read back **by literal key** at:
`AppDataController::payslipViewData():1250-1257` and `:1310-1321`;
`SalaryApprovalController::sheet():294-299`;
`ReportController:172-192` (the wage register, by substring);
`AppDataController::esiPeriodLock():2036`.

A string is both the storage key and the semantic. Renaming a component in the
master screen therefore does not rename a line — it creates a **different** line
that nothing recognises.

### XV.1.2 The salary approval sheet a manager signs shows ₹0 for renamed components

`SalaryApprovalController::sheet():294-299` reads `$earn['Basic']`,
`$earn['HRA']`, `$ded['PF']`, `$ded['ESI']`, `$ded['Professional Tax']`,
`$ded['TDS']` by literal key. A company whose Salary Setup names its components
"Basic Pay", "House Rent Allowance", "EPF" or "Prof. Tax" gets a sheet where
**every one of those columns reads ₹0**, while Gross, Deductions and Net are
correct — so the sheet looks plausible and is wrong. **That sheet is the document
the approver signs off** (DEF-P11).

### XV.1.3 The ESI half-year lock is a substring search for `'esi'` in a stored JSON key

`esiPeriodLock():2011-2046`, specifically `:2036`:
`stripos((string) $k, 'esi') !== false` over the **deduction keys** of stored
payslips. Rename the ESI line to "State Insurance" and the entire statutory
contribution-period lock silently stops working — an employee who should continue
to be covered above the ceiling for the rest of the contribution period is
dropped, with no error anywhere. The same query has no tenant filter (SEC-P08).

A statutory obligation is being decided by a display string.

### XV.1.4 PF eligibility is `str_contains(name, 'basic')`

`computeSlipFromComponents():2173`:

```php
isBasic($c) = str_contains(strtolower($c['code'].' '.$c['name']), 'basic');
```

Consequences, all live (`F-statutory-compliance.md` §Q6):

- **"Basic Travel Allowance"** and code `BASIC_TA` are counted into the PF wage.
- **"Consolidated Pay"** is not — and when nothing matches, `:2260-2262` falls
  back to `reset($earnings)`, **the lowest-`seq` earning**, so PF is computed on
  whatever component happened to sort first.
- A component named `Basic` with `base = 'balance'` is added to `$basic` in
  Pass 1 as `val/100 × gross` (usually 0) and then **skipped** in Pass 3, so
  `$basic` ends at 0 and the fallback fires (DEF-P20a).

Dearness allowance is found by
`str_contains($name,'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $name)`
(`:2269`) — a boundary class of `[^a-z]`, so digits and punctuation both open the
match. A reimbursement is found by `category === 'reimbursement'` **or** the
substring `reimburs` (`:2181-2188`). HRA, for display, by
`stripos($name,'hra') || stripos($name,'rent')` (`:2316`). Payslip
Fixed/Variable/Reimbursement grouping by a nine-keyword list
(`payslipGroupEarnings():1617-1624`).

### XV.1.5 `salary_components.taxable` exists and is never read

The column is migrated (`2026_01_01_000300:25-35`) and exposed in the master
screen definition (`MasterController.php:242-247`). Grep finds **no read site**
(`F-statutory-compliance.md` §Q6; `A-payroll.md` §5.2 item 8). There is no
`pf_applicable`, `esi_applicable`, `pt_applicable`, `lwf_applicable`,
`gratuity_wage` or `bonus_wage` flag on a component at all. A non-taxable
component cannot be excluded from the TDS base except by naming it
"reimbursement".

Two employee-level flags, `pf_applicable` and `esi_applicable`, are likewise
inert — the payslip **prints** them and the engine never reads them
(`F-…` NEW-F-10; carried into Part XVI).

### XV.1.6 `calc_value` is `VARCHAR` on migrated installs and `DECIMAL` on runtime-created ones

The migration declares `calc_value` as a **string**
(`2026_01_01_000300:25-35`); `MasterController::ensureCols():563-564` adds it as
`decimal(12,2)`. Whether a given install has one or the other depends on whether
an administrator ever opened the Salary Setup screen (`A-payroll.md` §2.4).
Part XXVIII records the consequence: a formula string such as `"0.4 * BASIC"`
stored in a `DECIMAL` column becomes the number `0.40`.

Six further columns the engine branches on — `scope`, `scope_target`, `category`,
`base`, `seq`, `company_name` — are **runtime-created** by
`MasterController::ensureCols():496-570`. On a freshly migrated database none of
them exists, and `ApprovalService::safeRow():263-266` —
`array_intersect_key($row, array_flip(Schema::getColumnListing($table)))` —
**silently drops any key that is not a real column**, so a missing column
produces a wrong number rather than an error.

### XV.1.7 The default structure is two literals

`AppDataController::computeSlip():2102-2105`:

```php
$gross   = round($ctc / 12, 2);
$basic   = round($gross * 0.5, 2);      // 0.5 — a literal
$hra     = round($basic * 0.4, 2);      // 0.4 — a literal
$special = round($gross - $basic - $hra, 2);
```

The analysis records the Basic percentage as *"the single most commonly varied
number in Indian payroll"* (BR-PAY-011) and it is not configurable at any scope.
The divisor is the literal `12` regardless of the pay cycle. There are four
component bases — `fixed`, `pct_gross`, `pct_basic`, `balance` — and anything
unrecognised, **including the documented `pct_ctc`**, silently falls through to
percent-of-monthly-gross (`:2225-2237`, DEF-P20f). Component scope is
employee → team → company only (`resolveComponents():990-1012`).

Where there is no balance component and `abs(remainder) >= 1`, the residue is
dumped into a literal `'Special Allowance'` key; a residue **under ₹1 is silently
discarded** while `gross` stays `ctc/12`, so earnings do not sum to gross
(`:2255-2259`, DEF-P20c/d). A negative remainder prints as a negative earning.

### XV.1.8 CTC is one column, overwritten in place

`IncrementController:342` overwrites `employees.ctc`. There is no salary history
table. `salary_components` has **no effective-dating column at all**
(`A-payroll.md` §5.3 Q5). Consequences: regenerating a past draft prices it at
today's CTC; changing a structure re-prices every regenerable month; and the
question "what was this employee paid, under what structure, in March?" is
unanswerable.

### XV.1.9 What is not replicated

| SmartPRS 2 behaviour | SmartPRS 3 |
|---|---|
| Component identity as a display string | **Not replicated.** Identity is `component_code`; the name is display only and renameable with no consequence (R-XV-002) |
| Statutory bases derived from names and regexes | **Not replicated.** Ten declared flags; `CONTAINS` is not applicable to a `Code` (Part VII §5.6), so name-based statutory logic is **inexpressible**, not merely forbidden |
| A `taxable` column nothing reads | **Not replicated.** Every declared flag has a consumer, asserted by a test (R-XV-020) |
| Four bases with a silent fallback | **Not replicated.** Seven bases; an unrecognised base is a publish-time error |
| A residue under ₹1 discarded | **Not replicated.** `ALLOCATE` residue-to-last; the parts always sum to the whole |
| A negative residue printed as an earning | **Not replicated.** A publish-time and run-time validation |
| `calc_value` typed differently per install | **Not replicated.** Typed columns per calculation kind, Law 2 |
| Scope: employee → team → company, resolved by row order | **Not replicated.** Full S3.1 dimension set, S3.2 weights, ties block publication |
| CTC overwritten in place | **Not replicated.** `employee_compensations` is effective-dated with a maker-checker workflow |
| Structure change silently re-prices history | **Not replicated.** Versions are pinned by the run's input snapshot (Part XIV §XIV.4.3) |

---

## XV.2 The Salary Component Engine *(brief Section 32)*

### XV.2.1 What a component is

**R-XV-001** A salary component is a spine S4 configuration artefact,
`artefact_type = 'salary_component'`, with `scope_json`, `priority`,
`effective_from`, `effective_to`, `version_no`, `status` and the full
draft → testing → pending_approval → published → superseded lifecycle. It is
declared `high_risk`, so publishing one requires a diff, an impact preview and an
approval (spine S4, Part IX `CONFIG-PUBLISH-RULE`).

**R-XV-002** A component's **identity** is `component_code` — immutable for the
life of the component, unique per tenant, referenced by every structure line,
every payslip line, every formula, every rule action, every report mapping and
every statutory return mapping. `name` is display, is renameable at any time,
and **nothing anywhere branches on it**. *Directly replacing the twenty-five
literals of §XV.1.1, the approval sheet's key lookups (§XV.1.2) and the ESI
lock's substring search (§XV.1.3).*

**R-XV-003** There is **no limit** on the number of components a tenant may
define. There is no seeded set that cannot be disabled, and no component the
engine requires to exist by code. A tenant with 4 components and a tenant with
140 use the same code path.

**R-XV-004** The `Code` type may not be the operand of `CONTAINS`, `MATCHES`,
`UPPER`, `LOWER` or any text operator in a statutory or grouping context
(Part VII §5.6, Part VIII R-VIII-056). Name-driven statutory logic is therefore
**not expressible in SPX**, not merely discouraged. A CI static-analysis rule
additionally fails the build on any string comparison against a component name in
`Domain\*`.

### XV.2.2 Component categories

**R-XV-005** Two orthogonal classifications, both reference tables (spine S16 —
no database `ENUM`), both extensible by a tenant:

**`component_kind_code`** — what the component *does* to the payslip:

| Kind | Effect |
|---|---|
| `earning` | Adds to gross |
| `deduction` | Subtracts from gross to reach net |
| `employer_contribution` | Employer cost; never deducted; shown as a memo and in CTC |
| `reimbursement` | Paid to the employee but carved out of declared wage bases per its flags |
| `information` | Computed and traced but never added to any total (a notional, an accrual, a YTD marker) |
| `recovery` | A deduction driven by a ledger (loan, advance, clawback, notice, asset, attachment) with its own schedule |

**`category_code`** — what the component *is*, for grouping, reporting and
proration defaults:

| Category | Typical members |
|---|---|
| `fixed` | Basic, DA, HRA, conveyance allowance, special allowance |
| `variable` | Commission, incentive, overtime, night allowance, shift allowance, attendance incentive, performance pay |
| `reimbursement` | Fuel, telephone, books, medical, LTA, uniform |
| `statutory_deduction` | PF, ESI, PT, LWF, TDS |
| `statutory_employer` | Employer PF, EPS, EDLI, employer ESI, PF admin charges, employer LWF |
| `arrear` | Any back-dated settlement line |
| `terminal` | Gratuity, leave encashment, notice pay, retrenchment compensation |
| `recovery` | Loan EMI, advance, clawback, notice recovery, asset recovery, court attachment |
| `bonus` | Statutory bonus, ex-gratia, festival advance, retention bonus |
| `perquisite` | Non-cash benefits valued for tax |
| `accrual` | Gratuity accrual, leave accrual — `information` kind, never paid |

**R-XV-006** Categories are data. A tenant adding "Union Deduction" or
"Cooperative Society" as a category needs no release. Kinds are platform data,
because a new kind changes the arithmetic of a total.

### XV.2.3 The statutory and grouping flags — every semantic is declared

**R-XV-007** Every statutory and grouping semantic is a **declared boolean on the
component version**. The complete flag set:

| Flag | True means | Consumed by |
|---|---|---|
| `is_pf_base` | The component's amount enters the PF wage | `salary.wage_base('PF')`, pipeline stage 17 |
| `is_esi_base` | Enters the ESI wage | `salary.wage_base('ESI')` |
| `is_pt_base` | Enters the PT gross | `salary.wage_base('PT')` |
| `is_lwf_base` | Enters the LWF wage | `salary.wage_base('LWF')` |
| `is_bonus_wage` | Enters the statutory-bonus wage | `salary.wage_base('BONUS')`, FL-17 |
| `is_gratuity_wage` | Enters the gratuity wage | `salary.wage_base('GRATUITY')`, FL-16 |
| `is_ot_wage` | Enters the overtime rate base | `salary.wage_base('OT')`, FL-05 |
| `is_minimum_wage_component` | Counts toward the minimum-wage comparison | `salary.wage_base('MIN_WAGE')`, exception `minimum_wage_breach` |
| `is_encashment_base` | Enters the leave/comp-off encashment base | FL-14, FL-15 |
| `is_taxable` | Enters the taxable-salary base | `salary.wage_base('TAXABLE')`, pipeline stage 18 |
| `in_gross` | Enters the printed and stored **gross** | Payslip, registers, Part XVI §XVI.4.3 |
| `in_ctc` | Enters the **CTC** definition | CTC reconciliation, CTC statement |
| `in_net` | Participates in the net computation | Stage 21 |
| `is_employer_cost` | Employer-side; never deducted | Employer-cost memo, CTC |
| `prorates` | A period-boundary factor applies | Stage 8 (Part XIV §XIV.3.3) |
| `lop_affected` | Unpaid absence reduces it | Stage 8 |
| `arrear_eligible` | A back-dated change generates an arrear line | Stage 16 |
| `is_perquisite` | Valued as a perquisite for tax | Stage 18 |
| `counts_toward_epf_admin_charges` | Enters the administrative-charges base where the pack declares one | Stage 22 |

**R-XV-008** **No statutory logic anywhere in SmartPRS 3 inspects a component's
name, and none is expressible.** Every wage base is `salary.wage_base('<CODE>')`
— the exact sum of the components whose flag for that base is true (Part VIII
R-VIII-056). This single symbol replaces `str_contains(…,'basic')` at `:2173`,
the DA regex at `:2269`, the `reimburs` substring at `:2186`, the HRA key test at
`:2316` and the ESI lock's `stripos($k,'esi')` at `:2036`.

**R-XV-009** The wage-base composition is **traced**: the trace for a PF
deduction lists every contributing component code and its amount, so
"why is my PF wage ₹15,000?" is answerable without reading code.

**R-XV-010** The flag set is **platform-extensible**: a new rule pack may declare
a new wage base, which registers a new flag with a default of `0` on every
existing component and raises a configuration task naming the components an
administrator must review. It never guesses. *Because SmartPRS 2's answer to a
new statutory head is a code release (`F-…` §5.2 item 28).*

**R-XV-011** `is_taxable`, `is_pf_base` and `is_esi_base` **have consumers**, and
a CI test asserts that every declared flag is read by at least one published
formula, rule or engine stage. A flag with no consumer fails the build.
*Directly because `salary_components.taxable` has been shipping as a settable,
UI-exposed, meaningless column (§XV.1.5), alongside `pt_amount` — settable,
validated, and read by nothing (NEW-PAY-19).*

### XV.2.4 The complete per-component attribute set

**R-XV-012** A component version declares, in full:

**Identity and classification**

| Attribute | Type | Notes |
|---|---|---|
| `component_code` | `Code` | Immutable identity (R-XV-002) |
| `name`, `short_name` | Text | Display; renameable freely |
| `description` | Text | Business meaning, shown in the builder and in the guide |
| `component_kind_code` | `Code` | R-XV-005 |
| `category_code` | `Code` | R-XV-005 |
| `currency_code` | `Code` | `INR` default; no implicit FX ever |
| `statutory_scheme_code` | `Code` NULL | Links a statutory line to its scheme, for the return |

**Calculation**

| Attribute | Type | Notes |
|---|---|---|
| `calculation_kind_code` | `Code` | One of the seven bases of §XV.3.4 |
| `fixed_amount_minor` | `Money` NULL | When `fixed` |
| `percent_value` | `Percentage` NULL | When `percent_of_*` |
| `basis_component_code` | `Code` NULL | When `percent_of_component` |
| `formula_config_version_id` | ref NULL | When `formula`; FK RESTRICT |
| `slab_table_code` | `Code` NULL | When `slab` |
| `input_definition_code` | `Code` NULL | When `input` (a variable input supplies the amount) |
| `sequence_no` | Number | Evaluation order within a structure; the dependency graph overrides it where a real dependency exists |

**Bounds and rounding**

| Attribute | Type | Notes |
|---|---|---|
| `min_amount_minor` | `Money` NULL | Floor, applied after calculation, before rounding |
| `max_amount_minor` | `Money` NULL | Ceiling |
| `cap_expression` | SPX NULL | A **computed** cap — e.g. `MIN(result, salary.component('BASIC') % 40)` |
| `floor_expression` | SPX NULL | A computed floor |
| `annual_cap_minor` | `Money` NULL | Financial-year cap; consumption is ledgered |
| `rounding_mode_code` | `Code` | **Mandatory.** `half_up \| half_down \| half_even \| ceil \| floor \| truncate` |
| `rounding_unit` | Number | `0.01`, `1.00`, `10.00`, … |
| `rounding_point_code` | `Code` | `per_component \| after_proration \| at_total` — where the boundary sits |

**R-XV-013** There is **no default rounding anywhere** (spine S5.4). A component
version without a declared mode and unit cannot be published (Part VIII gate G9).
*`A-payroll.md` §4.16: ESI is the only `ceil`, statutory bonus and one gratuity
report are the only whole-rupee roundings, LWF is never rounded at all, and the
two gratuity reports round differently from each other — producing a 46-paise
disagreement between the provision report and the F&F statement on the same
employee.*

**Proration**

| Attribute | Notes |
|---|---|
| `prorates`, `lop_affected`, `arrear_eligible` | R-XV-007 |
| `prorate_basis_code` | Which of the five bases values it (Part XIV §XIV.3.1); NULL inherits the structure's |
| `min_prorated_amount_minor`, `max_prorated_amount_minor` | Applied after proration |
| `arrear_component_code` | Where an arrear for this component posts; NULL means into itself |

**Effective dating and lifecycle**

| Attribute | Notes |
|---|---|
| `effective_from`, `effective_to` | On the version (spine S4) |
| `status` | draft / testing / pending_approval / published / superseded / archived |
| `is_disabled` | A published component may be **disabled** for future structures; it is never deleted, because issued payslips reference it (`ON DELETE RESTRICT`) |
| `replaced_by_component_code` | For a rename-by-replacement migration path |

**Payslip presentation** *(the contract Part XVI consumes)*

| Attribute | Notes |
|---|---|
| `show_on_payslip` | `always \| never \| if_non_zero` |
| `payslip_group_code` | Which printed group it belongs to — **from the definition, never from a keyword** |
| `payslip_sequence` | Order within the group |
| `payslip_caption_key` | The localised caption; may differ from `name` |
| `include_in_subtotal_code` | Which subtotal it rolls into |
| `detail_visibility_code` | `show_detail \| include_in_total_but_hide_detail \| mask` |
| `mask_rule_code` | When masked, how |

**R-XV-014** `show_if_non_zero` is a first-class value, not a template
workaround, because "print HRA only when it is paid" is the single most common
payslip request and today requires a template edit.

**Access and audit**

| Attribute | Notes |
|---|---|
| `visibility_role_codes` | Which roles may see the component's **amount** in the sheet, the report and the API |
| `is_confidential` | Retention/masking class for genuinely restricted components (a retention bonus, a settlement) |
| `audit_class` | `full` for every component; field-level before/after on every change (spine S14) |

### XV.2.5 The relationship between flags and the payslip

**R-XV-015** `in_gross`, `is_taxable` and `payslip_group_code` are three
**independent** declarations. A reimbursement may be `in_gross = 0`,
`is_taxable = 0` and grouped as `C · Reimbursements`; a taxable perquisite may be
`in_gross = 0`, `is_taxable = 1`, `in_net = 0` and shown as an information line.
*SmartPRS 2 conflates all three: reimbursements are carved out of `wageGross` for
statutory purposes but **included** in the stored `payslips.gross`, while the
printed "Gross Earnings (A+B)" **excludes** them — two numbers, both labelled
gross, differing (NEW-PAY-31, Part XVI §XVI.4.3).*

**R-XV-016** There is exactly **one** definition of gross:
`gross = SUM(lines where in_gross = 1)`. The payslip prints it, the run totals
sum it, the registers use it, and the reconciliation assertion (Part XIV `RC-01`,
`RC-04`) checks it. A template may present subtotals; it may not present a
different gross.

### XV.2.6 Component-level worked example

```
component_code           HRA
name                     House Rent Allowance     (renameable; nothing branches on it)
component_kind_code      earning
category_code            fixed
calculation_kind_code    percent_of_component
basis_component_code     BASIC
percent_value            40  (Percentage)
formula                  FL-03 · F-HRA-PCT-BASIC
                         salary.component('BASIC') % policy.value('hra_pct_of_basic')
cap_expression           NULL
rounding                 half_up, 0.01, per_component
prorates                 true
lop_affected             true
prorate_basis_code       NULL  (inherits the structure's)
arrear_eligible          true
arrear_component_code    NULL  (posts into HRA itself)

flags   is_pf_base 0 · is_esi_base 1 · is_pt_base 1 · is_lwf_base 0
        is_bonus_wage 0 · is_gratuity_wage 0 · is_ot_wage 1
        is_minimum_wage_component 1 · is_encashment_base 0
        is_taxable 1 · in_gross 1 · in_ctc 1 · in_net 1 · is_employer_cost 0

payslip show_on_payslip if_non_zero · group A_FIXED · sequence 20
        caption_key payslip.component.hra · detail show_detail
```

*The flag values above are illustrative of the mechanism. Which components enter
which statutory base is a rule-pack and tenant-policy determination
(spine S12, Part XIV **D-XIV-03**), never an assertion of law made here.*

---

## XV.3 The Salary Structure Builder *(brief Section 33)*

### XV.3.1 What a structure is

**R-XV-017** A salary structure is a spine S4 artefact,
`artefact_type = 'salary_structure'`, declared `high_risk`, comprising a header
and an ordered set of **structure lines**, each naming a component **code** and a
pinned `component_config_version_id`.

**R-XV-018** There is **no limit** on the number of structures a tenant may
define, and no structure the platform requires. A tenant may hold one structure
or three hundred.

**R-XV-019** A structure line may **override** a component's calculation for this
structure only — `override_calculation_kind_code`, `override_value`,
`override_cap_expression` — without forking the component. This is how
"Basic is 50% of gross for Corporate and 40% for Collections" is expressed with
one `BASIC` component rather than two.

**R-XV-020** A line carries `applies_expression` — an SPX Boolean evaluated per
employment at run time — so a structure may contain a line that is present only
for some of its members (`employee.category = 'FIELD'`, `service.years >= 5`).
The expression is validated at publish against the declared symbol table.

**R-XV-021** A structure declares:

| Header attribute | Meaning |
|---|---|
| `structure_code`, `name`, `description` | Identity and display |
| `currency_code` | |
| `gross_definition_code` | Which flag set sums to gross (default: `in_gross`) |
| `ctc_definition_code` | Which flag set sums to CTC (default: `in_ctc`) |
| `balance_component_code` | The residue absorber (§XV.3.5) |
| `derivation_mode_code` | `from_gross` \| `from_ctc` \| `from_component_sum` (§XV.3.6) |
| `default_prorate_basis_code` | Inherited by lines that do not declare their own |
| `lop_presentation_code` | `reduce_lines` \| `full_lines_plus_lop_deduction` |
| `min_wage_check_enabled`, `min_wage_scope_code` | Publish-time and run-time floor checks |
| `rounding_reconcile_component_code` | Where a rounding residue lands, when it is not the balance component |

### XV.3.2 Assignment by every scope dimension

**R-XV-022** A structure version is targeted by a **Scope** (spine S3.1) over the
full dimension set:

```
legal_entity · company · establishment · state · zone · branch · department
· sub_department · team · cost_centre · business_unit · client_process
· designation · grade · employee_category · employment_type · employment_stage
· shift · location · pay_group · employee
```

*SmartPRS 2 offers exactly three: employee (by `emp_code`), team, and
company/blank — resolved by taking the first non-empty level in a fixed order
(`componentRows():970-987`, `resolveComponents():990-1012`) — with the company
matched by **name string**. `A-payroll.md` §5.2 item 7 lists grade, designation,
branch, location, cost centre and union/non-union as needed and absent.*

**R-XV-023** An employment may additionally hold an explicit assignment
(`employee_compensations.structure_config_version_id`), which is the
`employee`-dimension case and therefore the most specific — weight 1000.

**R-XV-024** Resolution is `resolve('salary_structure', key, scope, as_of)` where
`as_of` is a date **inside the payroll period**, never today. The resolved
version id is pinned into the run's input snapshot (Part XIV §XIV.4.3).

### XV.3.3 Inheritance, override and precedence — worked

Spine S3.2: sum the weights of the dimensions a candidate constrains; higher
total wins; a tie is broken by declared `priority`; a remaining tie **blocks
publication**.

**The scenario.** Employment `E-4417`: company *Acme Services* (a company node),
state *Telangana*, department *Collections*, designation *Telecaller*, grade
*G3*, and an individual arrangement negotiated at hire.

Five candidate structure versions all match on 01-Jul-2026:

| # | Structure | Constrained dimensions | Weights | Total |
|---|---|---|---|---|
| 1 | `CORP-STD` "Corporate Standard" | company | 100 | **100** |
| 2 | `TG-STD` "Telangana Standard" | company + state | 100 + 140 | **240** |
| 3 | `TG-COLL` "Telangana Collections" | company + state + department | 100 + 140 + 400 | **640** |
| 4 | `TG-COLL-TC` "Telangana Collections — Telecaller" | company + state + department + designation | 100 + 140 + 400 + 350 | **990** |
| 5 | `E-4417-SPECIAL` | employee | 1000 | **1000** |

Resolution: **#5 wins at 1000**, and it wins by ten points over #4. Without #5,
#4 wins at 990. Without #4, #3 wins at 640. The ladder is
Corporate → Telangana → Collections → Telecaller → employee override, exactly as
the brief describes, and the winner is deterministic rather than dependent on row
order.

**A tie, and what happens.** Suppose a sixth candidate `TG-G3` constrains
company + state + grade = 100 + 140 + 340 = 580, and a seventh `TG-CC-OPS`
constrains company + state + cost_centre + employment_type = 100 + 140 + 250 + 310
= 800. No tie. But if `TG-COLL` (640) is joined by `TG-G3-CAT` constraining
company + state + grade + employee_category = 100 + 140 + 340 + 320 = 900 and by
`TG-SHIFT-TYPE` constraining company + state + designation + shift + employment_type
= 100 + 140 + 350 + 330 + 310 = 1230 — still no tie. **When two candidates do
total the same**, the declared integer `priority` decides; and if both `priority`
values are also equal, **publication is blocked** with a message naming both
structures and listing the employments affected. There is no third tie-break and
no coin toss.

**R-XV-025** The **explanation** of a resolution is part of its output: the
winning structure, its total weight, and every losing candidate with its weight
and the dimension that cost it. This appears in `Explain(payslip, component)` and
in the structure builder's "who gets this?" preview.

**R-XV-026** Inheritance is **whole-structure**, not line-merged, by default: the
winning structure is the structure. A tenant that wants line-level inheritance
declares `inherits_from_structure_code` on the header, in which case the child's
lines override the parent's **by component code** and the parent's remaining
lines are inherited. The resulting effective line set is materialised at
**publish** time, validated as a whole, and stored — so run-time resolution is a
single lookup and the merge cannot produce a surprise. *SmartPRS 2 merges nothing
and resolves by "first non-empty level", which means a team-scoped structure
containing one component silently replaces the company structure entirely.*

**R-XV-027** Per-employee **component-level** overrides — "this person's HRA is
₹12,000 flat" without a bespoke structure — live in
`employee_compensation_lines` (Part XXVIII), are effective-dated with the
compensation, and are applied after structure resolution. They are visible on the
compensation record and in the trace as an explicit override step.

### XV.3.4 Component bases — all seven, each with min, max and cap

**R-XV-028** `calculation_kind_code` values, complete:

| Kind | Expression | Min / Max / Cap | Notes |
|---|---|---|---|
| `fixed` | `fixed_amount_minor` | ✓ | A flat amount |
| `percent_of_component` | `salary.component('<CODE>') % p` | ✓ | Any component, not just Basic. The dependency graph resolves order; a cycle is rejected at publish |
| `percent_of_gross` | `salary.gross % p` | ✓ | FL-01 |
| `percent_of_ctc` | `(compensation.annual_ctc / period.periods_per_year) % p` | ✓ | FL-02. **A real base.** *SmartPRS 2 names `pct_ctc` in a comment and computes it as percent-of-gross (DEF-P20f)* |
| `balance` | `salary.balance_pool` | ✓ | The residue (§XV.3.5) |
| `slab` | `SLAB(value, slab_table_code, as_of)` | ✓ | Banded on any typed value — service years, gross, grade points |
| `formula` | Any published SPX formula returning `Money` | ✓ | The general case; the dependency graph and gates G1–G12 apply |
| `input` | `input.value('<CODE>')` | ✓ | Supplied by an approved variable input for the period |

**R-XV-029** Every base supports `min_amount`, `max_amount`, `cap_expression`,
`floor_expression` and `annual_cap`. A cap is applied **after** calculation and
**before** rounding, and the cap's binding is recorded in the trace:
`"capped at ₹6,000.00 by cap_expression MIN(result, BASIC % 40)"`.
*SmartPRS 2 has no min, max or cap on any component at any base
(`A-payroll.md` §5.2 item 2).*

**R-XV-030** An unrecognised `calculation_kind_code` is a **publish-time error**.
There is no fallback branch. *`:2225-2237` falls through to percent-of-gross for
anything it does not recognise, silently, including a documented base.*

### XV.3.5 The residue, and penny-safety

**R-XV-031** A structure declares exactly one `balance_component_code`, or none.
Where one is declared, its amount is
`gross_target − SUM(non-balance earning components already computed)`, evaluated
in topological order (`salary.balance_pool`, Part VIII §11.0).

**R-XV-032** Where **more than one** line is of kind `balance`, the pool is split
with `ALLOCATE(pool, weights)` (Part VIII §6.4), which distributes the residue
**to the last element** so the parts always sum to the whole, exactly. This
generalises the one correct money-splitting pattern in SmartPRS 2 (spine S19
item 4, `:2247-2254`).

**R-XV-033** Where **no** balance component is declared and a residue remains:

- A residue of any size — including under ₹1 — is **never discarded**. It is
  posted to `rounding_reconcile_component_code`, or, if none is declared, the
  structure **fails publish validation V4** (§XV.3.8).
- A **negative** residue is a publish-time validation failure when it is
  structurally reachable, and a blocking run-time exception
  (`structure_does_not_reconcile`) when it arises from data.

*`:2255-2259`: a residue with `abs(remainder) >= 1` is dumped into a literal
`'Special Allowance'` key; a residue **under ₹1 is silently discarded** while
`gross` remains `ctc/12`, so the earnings do not sum to gross; and a negative
residue prints as a negative earning (DEF-P20c/d, NEW-PAY-08).*

### XV.3.6 CTC-based reverse calculation

**R-XV-034** `derivation_mode_code = 'from_ctc'` computes the structure
**backwards** from a target annual CTC. The algorithm:

```
1  target_ctc  := compensation.annual_ctc                (Money, exact)
2  period_ctc  := target_ctc / period.periods_per_year    (exact rational)
3  Partition the lines into:
      D  — components whose amount is DERIVED from gross or from another
           component (percent_of_gross, percent_of_component, formula, slab)
      F  — components with a fixed amount
      E  — employer-cost components in the CTC definition (in_ctc = 1,
           is_employer_cost = 1), each itself a function of a wage base
      B  — the balance component
4  Solve for `gross` such that
      SUM(D(gross)) + SUM(F) + SUM(E(gross)) + B(gross) = period_ctc
   The system is piecewise-linear in `gross` (percentages are linear; slabs,
   caps, floors and statutory ceilings introduce breakpoints).
5  Solve exactly by interval bisection over the breakpoint set, in integer
   paise, to the exact paise. The breakpoints are enumerable because every
   slab, cap and ceiling is declared data.
6  Assert: recomputing the structure forward from the solved `gross` reproduces
   `period_ctc` exactly. If it does not, FAIL — never approximate.
```

**R-XV-035** The reverse calculation is **exact or it fails.** There is no
tolerance, no epsilon and no "close enough". Where the target CTC is
unachievable — because a statutory floor, a minimum wage or a cap makes a gap
unbridgeable — the builder reports the **achievable band** and the constraint
that binds, and refuses to publish a structure that cannot hit its target.

**R-XV-036** A CTC-derived structure records, on every payslip it produces, the
solved gross and the breakpoints that applied, so the derivation is explainable
three years later.

**Worked — target CTC ₹6,00,000, 12 periods, a structure with employer PF in CTC.**

```
period_ctc = 6,00,000 / 12                                    = ₹50,000.00
lines      BASIC   percent_of_gross 50%
           HRA     percent_of_component(BASIC) 40%
           SPECIAL balance
           EPF-ER  employer_contribution, in_ctc, 12% of min(BASIC, <pack ceiling>)

Trial gross G:
  BASIC   = 0.50 G
  HRA     = 0.40 × 0.50 G = 0.20 G
  SPECIAL = G − BASIC − HRA = 0.30 G
  EPF-ER  = 12% × MIN(0.50 G, ceiling)          ← the breakpoint is at
                                                   G = 2 × ceiling
CTC(G) = G + 0.12 × MIN(0.50 G, ceiling)

Below the breakpoint: CTC(G) = G × 1.06        → G = 50,000 / 1.06
Above the breakpoint: CTC(G) = G + 0.12 × ceiling  → G = 50,000 − 0.12 × ceiling

The solver evaluates both segments, selects the one whose solution lies inside
its own segment, and asserts the forward recomputation matches to the paise.
The trace records: which segment bound, the breakpoint value, and the exact
rational before the declared rounding.
```

*The employer PF ceiling is `<from rule pack>` and is not asserted here.*

### XV.3.7 A worked structure, forward

**Structure `TG-COLL` "Telangana Collections", derivation `from_gross`, monthly
gross ₹50,000.00, full attendance, period 2026-07.**

| Seq | Component | Kind | Base | Amount | `in_gross` | `is_pf_base` | `is_esi_base` | `is_taxable` |
|---|---|---|---|---|---|---|---|---|
| 10 | `BASIC` | earning | `percent_of_gross` 50% | ₹25,000.00 | 1 | 1 | 1 | 1 |
| 20 | `HRA` | earning | `percent_of_component(BASIC)` 40% | ₹10,000.00 | 1 | 0 | 1 | 1 |
| 30 | `CONV-REIMB` | reimbursement | `fixed` | ₹1,600.00 | 0 | 0 | 0 | 0 |
| 40 | `SPECIAL` | earning | `balance` | ₹13,400.00 | 1 | 0 | 1 | 1 |

```
gross          = 25,000.00 + 10,000.00 + 13,400.00            = ₹48,400.00
balance_pool   = 48,400.00 − 25,000.00 − 10,000.00            = ₹13,400.00
PF wage base   = SUM(is_pf_base)   = BASIC                    = ₹25,000.00
ESI wage base  = SUM(is_esi_base)  = BASIC + HRA + SPECIAL    = ₹48,400.00
Taxable base   = SUM(is_taxable)   = ₹48,400.00
Reimbursement  = ₹1,600.00, paid, outside every declared base
Total paid     = gross + reimbursement                        = ₹50,000.00
```

Every base above is a **flag sum**. No name is inspected anywhere. Renaming
`SPECIAL` to "City Compensatory Allowance" changes the caption on the payslip and
nothing else — no wage base moves, no lock breaks, no approval-sheet column goes
to ₹0.

*Contrast: SmartPRS 2 computes the same structure with `wageGross = gross −
reimbursements = ₹48,400.00` and stores `payslips.gross = ctc/12 = ₹50,000.00`,
so the ESI test at `computeSlipFromComponents:2273` uses ₹48,400 while the
pre-check at `compute():1297` uses ₹50,000 — and at the ceiling the two disagree,
so the ESI period lock may not be looked up when it should be (DEF-P20g).*

### XV.3.8 Publish-time validation

**R-XV-037** A structure version cannot be published unless every gate passes.
These extend Part VIII's formula gates G1–G12; each writes a validation row
naming the offending line.

| Gate | Assertion | Because |
|---|---|---|
| **V1 · Reconciliation** | Under the declared derivation mode, the component lines **sum to the declared gross** for every sample employee in the mandatory test set, exactly, in paise | Earnings that do not sum to gross is the state a discarded sub-₹1 residue creates (DEF-P20d) |
| **V2 · No cycle** | The dependency graph over `percent_of_component`, `formula` and cap/floor expressions is acyclic; a cycle is rejected **naming the cycle** | Part VIII §7.2 |
| **V3 · No disabled reference** | No line references a disabled, archived or superseded-without-successor component version, and no cap/floor expression references one | *SmartPRS 2 hard-deletes components, destroying the explainability of every payslip computed from them* |
| **V4 · No unabsorbed residue** | Either a `balance_component_code` is declared, or `rounding_reconcile_component_code` is, or the lines provably sum to gross with zero residue | DEF-P20d |
| **V5 · No negative residue** | The balance pool cannot be negative for any point in the declared CTC/gross range, given the lines' fixed amounts and caps | DEF-P20c |
| **V6 · Rounding declared** | Every line's component version declares mode and unit (R-XV-013) | §XV.1.7 / §4.16 |
| **V7 · Type and unit** | Every expression type-checks under Part VIII §5, including `Days`/`Hours` and `Rate<Money/U>` discipline | Part VIII gates G3/G4 |
| **V8 · Minimum wage** | Where `min_wage_check_enabled`, `SUM(is_minimum_wage_component)` at the structure's minimum reachable gross is ≥ the resolved minimum-wage floor for every state and category in scope, `<from rule pack>` | *`ReportController:136-155` takes `MAX(monthly_min)` across all tenant rows and compares it to CTC/12 for **every** employee, so a tenant holding a Delhi skilled rate and a Bihar unskilled rate compares everyone to the highest; there is **no payroll enforcement at all** (`F-…` §Q7)* |
| **V9 · Statutory floor** | No company policy encoded in the structure falls below the rule pack's baseline; severity per the pack's declared rule (spine S12) | |
| **V10 · Wage-base sanity** | Every declared wage base has at least one contributing component, or the absence is explicitly acknowledged with a reason | *Because a PF base that matched nothing silently falls back to the first earning in the map (`:2260-2262`)* |
| **V11 · Scope and overlap** | No overlapping published version for the same artefact and scope; no S3.2 tie (§XV.3.3) | Spine S4 |
| **V12 · Sample-employee tests** | The mandatory test set (≥3 employees spanning the structure's range) produces the asserted values, to the paise | Part VIII gate G10 |
| **V13 · CTC solvability** | Where `derivation_mode = from_ctc`, the reverse calculation converges exactly for every sample, and the achievable band is recorded | R-XV-035 |
| **V14 · Impact preview** | The count of employments whose net changes, with the aggregate and the largest individual delta, is computed and attached to the approval | Spine S4 `high_risk` |
| **V15 · Approval** | A completed `SALARY-CHANGE` or `CONFIG-PUBLISH-RULE` instance exists (Part IX) | Maker-checker on configuration is the same mechanism as on payroll |

**R-XV-038** Validation runs against **real employee samples**, not synthetic
ones, where the tenant permits it, so V1, V8 and V12 exercise the actual data.

**R-XV-039** The Labour-Code style check — "Basic + DA ≥ N% of wage gross" — is
V8's sibling and is **enforceable**, with `warn` or `block` severity declared per
scope. *`MasterController::labourCodeWarning():846-892` runs the real engine on a
**hardcoded ₹12,00,000 sample CTC** and produces an advisory string that never
blocks anything (NEW-PAY-09).*

---

## XV.4 Effective-dated CTC and salary history

**R-XV-040** What an employment is paid is an **effective-dated row**,
`employee_compensations` (Part XXVIII C7), never a column on the employee. Every
revision creates a new row with `effective_from`, closing the prior row's
`effective_to` the day before. *`IncrementController:342` overwrites
`employees.ctc` in place, and there is no salary history table of any kind
(§XV.1.8).*

**R-XV-041** A compensation row carries: `annual_ctc`, `structure_config_version_id`,
`pay_group_code`, `currency_code`, `revision_reason_code`
(`hire | increment | promotion | correction | structure_change | transfer |
migration_baseline`), `revision_percent`, `previous_annual_ctc`,
`arrears_policy_code`, and the `workflow_instance_id` of the `SALARY-CHANGE`
approval that authorised it.

**R-XV-042** Creating or amending a compensation row requires a completed
`SALARY-CHANGE` maker-checker instance (Part IX §IX.12.6, R-IX-072). There is no
direct write path. *Today an increment approval overwrites the CTC with no period
guard and the increment letter re-fires on re-approval.*

**R-XV-043** A compensation row effective inside a **locked** period is refused by
`PeriodLockGuard` (Part XIV R-XIV-016) and instead raises an **arrears
instruction** per its `arrears_policy_code`. The revision is recorded with its
true effective date; the money settles in the next open period as a traced arrear
line. *So a back-dated increment neither silently re-prices a paid month nor is
lost.*

**R-XV-044** `AsOf(employment, date)` returns the compensation, the structure
version, the component versions and the org assignment that applied on that date,
in **one service call** (spine Law 3's test). Every historical recompute, every
arrear difference and every register uses it. A payroll run pins its result into
the input snapshot.

**R-XV-045** Per-employee component overrides (`employee_compensation_lines`) are
dated with their parent compensation row, so an override is as historical as the
CTC it modifies.

**R-XV-046** A compensation row is **never deleted**. A row created in error is
superseded by a `correction` revision that states what it corrects; the erroneous
row remains queryable and every payslip it produced remains explainable.

**Worked — a historical recompute.**

```
Employment E-4417
  compensation #4471  eff 01-Apr-2025 → 30-Jun-2026   CTC ₹6,00,000  structure TG-COLL v6
  compensation #5210  eff 01-Jul-2026 → open          CTC ₹7,20,000  structure TG-COLL v7
                       revision_reason increment · approved SALARY-CHANGE #8812

Recomputing the May-2026 period today:
  AsOf(E-4417, 2026-05-15) → compensation #4471, structure TG-COLL **v6**,
                              component versions as at 15-May-2026,
                              statutory pack IN-TG v2026.1
  → the May payslip reproduces exactly, at the CTC and the structure of May.

SmartPRS 2, same request: employees.ctc reads ₹7,20,000 (overwritten),
salary_components has no effective date so v7 is used, and
SettingsController::rates() returns today's blob — the May payslip is
recomputed at July's everything, silently (A-payroll.md §5.3 Q5).
```

---

## XV.5 Explainability *(spine S15, Law 4)*

**R-XV-047** `Explain(payslip_line, 'amount')` returns, for a structure-derived
figure:

```
House Rent Allowance                                          ₹10,000.00

  Component        HRA  (component_code — the identity)
                   name "House Rent Allowance" · cfg_salary_components v4
                   effective 01-Apr-2026 → · published 28-Mar-2026 by A. Rao
                   approved CONFIG-PUBLISH-RULE #7741

  Structure        TG-COLL "Telangana Collections" v7, line 20
                   resolved for 2026-07 by scope:
                     WON  company=ACME(100) + state=TG(140)
                          + department=COLLECTIONS(400)
                          + designation=TELECALLER(350)      total 990
                     lost TG-COLL         (640)  — no designation constraint
                     lost TG-STD          (240)  — no department constraint
                     lost CORP-STD        (100)  — company only
                   no employee-level override on this employment

  Calculation      percent_of_component(BASIC) 40%
                   formula FL-03 · F-HRA-PCT-BASIC v3
                   salary.component('BASIC') % policy.value('hra_pct_of_basic')
                   BASIC = ₹25,000.00  (line 10, same structure version)
                   = 2,500,000 paise × 40 / 100 = 1,000,000 paise exact
  Cap / floor      none declared
  Rounding         half_up, 0.01, per_component  →  no adjustment
  Amount           ₹10,000.00

  Wage bases       enters ESI (is_esi_base 1) · PT (is_pt_base 1)
                   · taxable (is_taxable 1) · OT rate (is_ot_wage 1)
                   does NOT enter PF (is_pf_base 0)
                   — every one of these is a declared flag on v4, not a name test
```

**R-XV-048** The structure builder shows, for any component, **"who gets this,
and what do they get?"** — the employment count by resolved structure, and a
sample of computed amounts — before publication, from the impact preview (V14).

**R-XV-049** A wage base explains its own composition:
`Explain(payslip, 'pf_wage_base')` lists every component whose `is_pf_base` is
true, with its amount and its component version, and the total. *This is the
question SmartPRS 2 cannot answer at all, because the base is the output of a
substring match over concatenated code and name.*

---

## XV.6 Configuration surface

Every row is changeable by a tenant administrator through a UI, with no code
release, and takes effect from a declared date.

| # | What an administrator changes | Where | Scope | Dated |
|---|---|---|---|---|
| 1 | Create, rename, describe, disable a component | Salary Components | tenant | Yes |
| 2 | A component's kind and category | Salary Components | component version | Yes |
| 3 | Every one of the nineteen flags of R-XV-007 | Salary Components | component version | Yes |
| 4 | Calculation kind and its parameters, including a formula | Salary Components | component version | Yes |
| 5 | Min, max, cap expression, floor expression, annual cap | Salary Components | component version | Yes |
| 6 | Rounding mode, unit and point | Salary Components | component version | Yes |
| 7 | `prorates`, `lop_affected`, `arrear_eligible`, prorate basis, arrear target | Salary Components | component version | Yes |
| 8 | Payslip visibility (`always`/`never`/`if_non_zero`), group, order, caption, subtotal, detail visibility, masking | Salary Components | component version | Yes |
| 9 | Which roles may see a component's amount | Salary Components | component version | Yes |
| 10 | Create a structure; add, remove, reorder lines | Salary Structures | tenant | Yes |
| 11 | Per-structure override of a component's calculation | Structure line | structure version | Yes |
| 12 | A line's `applies_expression` | Structure line | structure version | Yes |
| 13 | Gross definition, CTC definition, balance component, rounding reconcile component | Salary Structures | structure version | Yes |
| 14 | Derivation mode (`from_gross` / `from_ctc` / `from_component_sum`) | Salary Structures | structure version | Yes |
| 15 | Structure scope — any of the 21 dimensions, any combination | Salary Structures | structure version | Yes |
| 16 | `inherits_from_structure_code` and the line-merge behaviour | Salary Structures | structure version | Yes |
| 17 | Default prorate basis and LOP presentation | Salary Structures | structure version | Yes |
| 18 | Minimum-wage check on/off, its scope and its severity | Salary Structures | structure version | Yes |
| 19 | The Labour-Code style Basic+DA percentage check and its severity | Structure Policy | any scope | Yes |
| 20 | Slab tables used by `slab` components | Slab Tables | any scope | Yes |
| 21 | Policy values referenced by formulas (`basic_pct_of_gross`, `hra_pct_of_basic`, …) | Policies | any scope | Yes |
| 22 | An employment's CTC, structure, pay group, per-component overrides | Compensation (via `SALARY-CHANGE`) | employee | Yes |
| 23 | The arrears policy applied to a back-dated revision | Compensation | employee / scope | Yes |
| 24 | The mandatory sample-employee test set attached to a structure | Salary Structures | structure version | Versioned |

**The Section 127 standard, applied.** *"Basic is 50% of gross for Corporate and
40% of gross for the Telangana Collections structure, effective 1 July, and HRA
for Telecallers only is capped at ₹6,000"* is: one policy value per scope, one
structure-line override, one cap expression, one effective date, one approval —
**and no code**. Today it is two PHP literals at `AppDataController:2103-2104`,
no cap mechanism at all, and no scope beyond team.

---

## XV.7 Data model

Tables specified in **Part XXVIII C7** (`cfg_salary_components`,
`cfg_salary_structures`, `cfg_structure_lines`, `employee_compensations`,
`employee_compensation_lines`) are referenced, not restated. This section
specifies the additional columns this part requires and the tables it adds.

### XV.7.1 Columns added to `cfg_salary_components`

Part XXVIII declares ten statutory-base flags; R-XV-007 requires nineteen. The
additions, all `TINYINT(1) NOT NULL DEFAULT 0`:

`is_lwf_base` · `is_encashment_base` · `is_perquisite` ·
`counts_toward_epf_admin_charges` · `in_gross` · `in_net` · `prorates` ·
`lop_affected` · `arrear_eligible` · `is_disabled`

*(Part XXVIII's `is_ctc_component` is this part's `in_ctc`;
`is_net_payable` is `in_net`. The names are reconciled to R-XV-007's vocabulary
in the schema, and the reconciliation is recorded in the migration.)*

Plus:

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `short_name` | VARCHAR(40) | Y | NULL | |
| `description` | VARCHAR(1000) | Y | NULL | Rendered in the builder and the generated guide |
| `slab_table_code` | VARCHAR(40) | Y | NULL | When `slab` |
| `input_definition_code` | VARCHAR(40) | Y | NULL | When `input` |
| `min_amount_minor`, `max_amount_minor` | BIGINT | Y | NULL | **Signed integer paise** |
| `annual_cap_minor` | BIGINT | Y | NULL | |
| `cap_expression`, `floor_expression` | TEXT | Y | NULL | SPX source; the compiled AST lives in `config_version_expressions` |
| `rounding_point_code` | VARCHAR(40) | N | `per_component` | |
| `min_prorated_amount_minor`, `max_prorated_amount_minor` | BIGINT | Y | NULL | |
| `payslip_caption_key` | VARCHAR(120) | Y | NULL | Localisation key (Part XVI) |
| `include_in_subtotal_code` | VARCHAR(40) | Y | NULL | |
| `detail_visibility_code` | VARCHAR(40) | N | `show_detail` | `show_detail\|include_in_total_but_hide_detail\|mask` |
| `mask_rule_code` | VARCHAR(40) | Y | NULL | |
| `is_confidential` | TINYINT(1) | N | 0 | |
| `replaced_by_component_code` | VARCHAR(40) | Y | NULL | |
| `statutory_scheme_code` | VARCHAR(40) | Y | NULL | |

**Keys and policy.** As Part XXVIII: PK `id`; UQ `(config_version_id)`,
`(tenant_id, component_code, config_version_id)`; IX
`(tenant_id, component_code)`, `(tenant_id, category_code)`, and additionally
**`(tenant_id, is_pf_base)`, `(tenant_id, is_esi_base)`, `(tenant_id, is_taxable)`**
— because wage-base assembly is a filtered scan and must not be a full table
read. Tenant-owned · versioned · **`ON DELETE RESTRICT` from every payslip line**
· audit `full` (field-level before/after mandatory) · PII `none` on the
definition · retention `R-PERM`.

**R-XV-050** `calc_value` does not exist. Each calculation kind has its own
**typed** column — `fixed_amount_minor` BIGINT, `percent_value`
DECIMAL(9,6), `formula_config_version_id` BIGINT, `slab_table_code` VARCHAR.
*Eliminating the `VARCHAR`-vs-`DECIMAL` divergence (§XV.1.6) and the class of
defect where a formula string becomes the number `0.40`.*

### XV.7.2 `cfg_component_flag_definitions` — platform-owned reference

**Purpose.** The flag vocabulary itself, so a rule pack can register a new wage
base without a schema change (R-XV-010), and so a CI test can assert every flag
has a consumer (R-XV-011).

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `flag_key` | VARCHAR(60) | N | — | U · `is_pf_base`, … |
| `label`, `help_text` | VARCHAR(120) / VARCHAR(1000) | N / Y | — | |
| `wage_base_code` | VARCHAR(40) | Y | NULL | The `salary.wage_base('X')` code it feeds |
| `consumer_kind_code` | VARCHAR(40) | N | — | `statutory\|grouping\|proration\|presentation\|tax` |
| `introduced_by_pack_id` | BIGINT UNSIGNED | Y | NULL | FK `rule_packs(id)` RESTRICT |
| `default_value` | TINYINT(1) | N | 0 | Always 0 — a new flag never guesses |
| `is_platform` | TINYINT(1) | N | 1 | |
| `storage_column` | VARCHAR(60) | Y | NULL | The real column, where one exists; NULL means it lives in the typed flag-value table |

**Keys.** PK `id` · UQ `(flag_key)` · IX `(wage_base_code)`.
**Policy.** Platform-owned, **not tenant-editable** · audit `full` ·
retention `R-PERM`.

**R-XV-051** Flags introduced by a rule pack that have no real column are stored
in `cfg_component_flag_values` (`component_config_version_id`, `flag_key`,
`value`), UQ `(component_config_version_id, flag_key)`. **No runtime DDL** ever
adds a flag column; promotion of a hot flag to a real column is an
Ametecs-authored migration (spine S9's promotion pattern, Law 2).

### XV.7.3 Columns added to `cfg_salary_structures`

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `derivation_mode_code` | VARCHAR(40) | N | `from_gross` | `from_gross\|from_ctc\|from_component_sum` |
| `default_prorate_basis_code` | VARCHAR(40) | N | — | Inherited by lines |
| `lop_presentation_code` | VARCHAR(40) | N | `reduce_lines` | |
| `rounding_reconcile_component_code` | VARCHAR(40) | Y | NULL | R-XV-033 |
| `inherits_from_structure_code` | VARCHAR(40) | Y | NULL | R-XV-026 |
| `inherited_lines_materialised_at` | DATETIME(3) | Y | NULL | The merge is resolved at publish |
| `min_wage_scope_code` | VARCHAR(40) | Y | NULL | |
| `min_wage_severity_code` | VARCHAR(40) | N | `block` | |
| `basic_da_floor_pct` | DECIMAL(9,6) | Y | NULL | The Labour-Code style check |
| `basic_da_floor_severity_code` | VARCHAR(40) | N | `warn` | |
| `ctc_solved_gross_minor` | BIGINT | Y | NULL | Recorded for `from_ctc` |
| `ctc_achievable_min_minor`, `ctc_achievable_max_minor` | BIGINT | Y | NULL | R-XV-035 |

### XV.7.4 Columns added to `cfg_structure_lines`

`override_cap_expression` TEXT NULL · `override_rounding_mode_code` VARCHAR(40)
NULL · `override_rounding_unit` DECIMAL(18,4) NULL · `override_prorate_basis_code`
VARCHAR(40) NULL · `inherited_from_structure_code` VARCHAR(40) NULL (populated by
the publish-time merge) · `is_inherited` TINYINT(1) NOT NULL DEFAULT 0.

### XV.7.5 `cfg_structure_test_cases` — A3

**Purpose.** The mandatory sample-employee test set (gate V12), stored with the
version so publishing re-runs it.

`config_version_id` FK RESTRICT, `test_no`, `label`, `subject_kind_code`
(`real_employment | synthetic`), `employment_id` FK RESTRICT NULL,
`input_json` (CTC, period, attendance measures for a synthetic subject),
`expected_json` (component code → expected minor units), `last_run_at`,
`last_result_code`, `last_actual_json`.
**Keys.** UQ `(tenant_id, config_version_id, test_no)`.
**Policy.** Tenant-owned · immutable with its version · audit `event-only` ·
PII: `sensitive` where a real employment is referenced · retention `R-PERM`.

### XV.7.6 `salary_structure_assignments` — A5 *(the resolution audit)*

**Purpose.** Which structure version actually resolved for which employment on
which date, materialised so that resolution is a lookup, the precedence trail is
queryable, and a scope change's impact is computable without re-running the
resolver over every employee.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | N | | |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `structure_config_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `resolution_source_code` | VARCHAR(40) | N | — | `scope\|employee_explicit\|inherited\|migration_baseline` |
| `winning_weight` | SMALLINT UNSIGNED | N | — | The S3.2 total |
| `precedence_trail_json` | JSON | N | — | Every candidate with its weight and losing dimension |
| `effective_from`, `effective_to`, `open_marker` | A5 | | | |

**Keys.** PK `id` · UQ `(tenant_id, employment_id, effective_from)`,
`(tenant_id, employment_id, open_marker)` ·
IX `(tenant_id, structure_config_version_id)` ← "who gets this structure?",
`(tenant_id, employment_id, effective_from, effective_to)`.
**Policy.** Tenant-owned · history A5 · delete: never · audit `full` ·
PII `none` · retention `R-PAY-8Y`.

### XV.7.7 Retention, PII and tenancy summary

| Table | Tenant scope | PII | Retention | Soft delete |
|---|---|---|---|---|
| `cfg_salary_components`, `cfg_structure_lines`, `cfg_salary_structures` | `tenant_id` NOT NULL, in every UQ | none | `R-PERM` | never; `is_disabled` instead |
| `cfg_component_flag_values` | as above | none | `R-PERM` | never |
| `cfg_structure_test_cases` | as above | sensitive where a real employment is named | `R-PERM` | never |
| `employee_compensations`, `employee_compensation_lines` | as above | **sensitive** (salary), masked by role, access audited | `R-PAY-8Y` | never |
| `salary_structure_assignments` | as above | none | `R-PAY-8Y` | never |
| `cfg_component_flag_definitions` | **platform-owned** | none | `R-PERM` | never |

---

## XV.8 API surface

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/salary/components` | `salary.component.view` | Cursor-paginated; filter by kind, category, flag |
| `POST` | `/salary/components` | `salary.component.manage` | Creates a draft version |
| `PATCH` | `/salary/components/{uuid}/versions/{n}` | `salary.component.manage` | Draft only; PATCH semantics (spine S9) |
| `POST` | `/salary/components/{uuid}/versions/{n}/publish` | `config.publish` | Runs gates; requires the approval instance |
| `POST` | `/salary/components/{uuid}/disable` | `salary.component.manage` | Never deletes |
| `GET` | `/salary/components/{uuid}/usages` | `salary.component.view` | Structures, formulas, rules, report mappings, issued payslip count |
| `GET` | `/salary/wage-bases/{code}/members` | `salary.component.view` | The components whose flag for the base is true, as at a date |
| `GET` | `/salary/structures` | `salary.structure.view` | |
| `POST` | `/salary/structures` | `salary.structure.manage` | |
| `PUT` | `/salary/structures/{uuid}/versions/{n}/lines` | `salary.structure.manage` | Whole line set, draft only |
| `POST` | `/salary/structures/{uuid}/versions/{n}/validate` | `salary.structure.manage` | Runs V1–V13 without publishing |
| `POST` | `/salary/structures/{uuid}/versions/{n}/simulate` | `salary.structure.manage` | Compute for one employment or a synthetic input; returns lines + full trace |
| `POST` | `/salary/structures/{uuid}/versions/{n}/solve-ctc` | `salary.structure.manage` | Reverse calculation; returns the solved gross, the binding breakpoint, or the achievable band |
| `GET` | `/salary/structures/{uuid}/versions/{n}/impact` | `salary.structure.manage` | Employment count, aggregate Δ, largest individual Δ (V14) |
| `POST` | `/salary/structures/{uuid}/versions/{n}/publish` | `config.publish` | |
| `GET` | `/salary/structures/{uuid}/members` | `salary.structure.view` + data scope | Who resolves to this structure, as at a date |
| `GET` | `/salary/resolve` | `salary.structure.view` + data scope | `?employment=&as_of=` → winner + precedence trail (R-XV-025) |
| `GET` | `/employments/{uuid}/compensations` | `salary.compensation.view` + data scope | The dated history |
| `POST` | `/employments/{uuid}/compensations` | `salary.compensation.request` | Starts `SALARY-CHANGE`; **no direct write** |
| `GET` | `/employments/{uuid}/compensations/as-of` | `salary.compensation.view` + data scope | R-XV-044 |
| `GET` | `/salary/explain/{payslipLineUuid}` | inherits the payslip's policy | R-XV-047 |

**R-XV-052** Every salary endpoint enforces **both** the permission and the data
scope (spine S13). Salary is `sensitive` PII: amounts are masked by role and
every access is audited. *`GET /app/salary-runs/{id}/sheet` today returns every
employee's Basic, HRA, PF, ESI, PT, TDS, gross, deductions and net guarded only
by `auth` (SEC-P11).*

---

## XV.9 Events

| Event | When | Payload |
|---|---|---|
| `salary.component.version_created` | Draft created | Component code, version, author |
| `salary.component.published` | Publish | Version, effective range, scope, impacted structures |
| `salary.component.flag_changed` | A flag changes between versions | Flag key, old, new, affected wage base, impacted employment count |
| `salary.component.disabled` | Disable | Structures still referencing it |
| `salary.structure.version_created` | Draft created | |
| `salary.structure.validated` | Gates run | Per-gate result |
| `salary.structure.published` | Publish | Version, scope, impact summary, approval instance |
| `salary.structure.resolution_changed` | A publish changes the winner for ≥1 employment | Employment count, old and new structure per employment |
| `salary.structure.tie_detected` | V11 fails | Both artefacts, affected employments — **publication blocked** |
| `salary.structure.min_wage_breach` | V8 fails | Floor, source, shortfall, scope |
| `compensation.revised` | `SALARY-CHANGE` completes | Employment, old/new CTC, effective date, reason, arrears policy |
| `compensation.arrears_raised` | R-XV-043 | Target periods, instruction id |
| `compensation.correction_recorded` | A `correction` revision | What it corrects |

Consumed:

| Event | Source | Effect |
|---|---|---|
| `rule_pack.published` | Spine S12 | May register a new flag (R-XV-010) and raise a review task |
| `formula.version_published` | Part VIII | Structures referencing it are re-validated; a breaking change blocks the formula's publish |
| `payroll.period.locked` | Part XIV | `PeriodLockGuard` refuses compensation and structure changes effective inside it |
| `employment.assignment_changed` | Employee module | Re-resolves `salary_structure_assignments` from the change date forward; a changed winner emits `salary.structure.resolution_changed` |

---

## XV.10 Migration implication

| SmartPRS 2 artefact | Comes across as | Transformation | Does not come across |
|---|---|---|---|
| `salary_components` rows | One `cfg_salary_components` artefact per distinct `(tenant, code)`, wrapped in a version dated from the earliest period it could have affected, flagged `migration_baseline` | `calc_value` is read as `VARCHAR` **or** `DECIMAL` per install and normalised into the typed column for its calculation kind; `base` maps `fixed→fixed`, `pct_gross→percent_of_gross`, `pct_basic→percent_of_component(BASIC)`, `balance→balance`; a row whose `base` is unrecognised becomes a **blocking migration task**, never a percent-of-gross guess | The name heuristics; the runtime-created columns as columns; the `VARCHAR`/`DECIMAL` ambiguity |
| Component **semantics** | **Derived once, by review, and recorded** — never inferred at run time | For each migrated component the migration proposes flags from the current name heuristics (`is_pf_base` where `str_contains(name,'basic')` matched, `is_esi_base` where it was not a reimbursement, …) and presents them as a **mandatory per-tenant review** before cutover. Every proposal is labelled with the heuristic that produced it | The heuristics themselves. After migration no name is inspected, ever |
| `salary_components.taxable` | `is_taxable`, **now with a consumer** | Where the column holds a value, it seeds the flag and the review confirms it | The dead column |
| `category` (`fixed\|variable\|reimbursement`) | `category_code` + `payslip_group_code` | Direct where set; the **keyword heuristic** is used only to *propose* a value in the review, never to decide one | The nine grouping keywords |
| The 50%/40% default split | A tenant `policy` artefact (`basic_pct_of_gross`, `hra_pct_of_basic`) plus a seeded `MIGRATED-DEFAULT` structure, for tenants that had no components at all | The literals become dated policy values | The literals |
| `'Special Allowance'` residue key | A real `SPECIAL` component of kind `balance` | Where the residue was being dumped into the literal key, a component is created and back-referenced by migrated payslip lines | The literal, and the sub-₹1 discard |
| `employees.ctc` | `employee_compensations`, one `migration_baseline` row per employment | Dated from the employment's start, or from the earliest payslip, whichever is earlier. **Where increment history exists in `increments`, a dated row per increment is reconstructed** so historical recomputes have something explicit to resolve | The single overwritten column |
| `increments` | `employee_compensations` rows with `revision_reason_code = 'increment'` and a synthetic completed `SALARY-CHANGE` instance preserving the approver and date | | The re-firing letter behaviour |
| Component scope (`scope`, `scope_target`, `company_name`) | `scope_json` on the structure version | `employee` → employee dimension; `team` → team dimension; company **name** → company **id** via a reviewed mapping; an unmatched name becomes a blocking task | Name matching |
| Payslip earning/deduction JSON keys | Mapped to component codes by a per-tenant mapping table (Part XIV §XIV.11) | An unmapped key becomes `MIGRATED-UNMAPPED-nnn` with **every flag 0** and a review task — because a wrong flag is worse than an obviously incomplete one | Any inference from the key text |

**R-XV-053** The component-semantics review (row 2) is a **cutover gate**. A
tenant may not go live with any component whose flags are unreviewed. *Because
the flags decide statutory liability, and inheriting a guess from
`str_contains(name,'basic')` would carry the defect across the migration into a
system that claims not to have it.*

**R-XV-054** Migration recomputes no historical payslip. Migrated payslip lines
carry the component codes the mapping assigns and the amounts SmartPRS 2 stored;
where they do not reconcile, the discrepancy is recorded (Part XIV R-XIV-079).

---

## XV.11 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | A component is renamed | Nothing else changes. No wage base moves, no lock breaks, no report column empties. Asserted by `T-XV-01` |
| 2 | A component code is reused after disabling | Refused. Codes are permanently unique per tenant, because issued payslips reference them |
| 3 | Two components both claim `is_pf_base` and one is a reimbursement | Permitted — it is a declaration, not an inference. V10 warns if the combination is unusual; the tenant confirms |
| 4 | No component has `is_pf_base` | V10 blocks publication unless the absence is acknowledged with a reason. *Never a silent fallback to the first earning* |
| 5 | `percent_of_component` names a component absent from this structure | Publish-time error (V2/V3) naming both |
| 6 | A dependency cycle `A → B → A` | Publish-time error naming the cycle (Part VIII §7.2) |
| 7 | A cap expression references a component computed later | The dependency graph reorders; if the graph is cyclic, V2 blocks |
| 8 | Two structures tie on specificity and priority | **Publication blocked**, both named, affected employments listed. No coin toss |
| 9 | An employment matches no structure | `missing_structure` blocking payroll exception (Part XIV). Never a default 50/40 split |
| 10 | An employment matches a structure whose currency differs from its compensation | Publish-time error; no implicit FX ever |
| 11 | Residue is exactly ₹0.00 | Balance component line is emitted at ₹0.00 and shown per `show_on_payslip` (`if_non_zero` hides it, and the total is unaffected) |
| 12 | Residue is negative | V5 at publish where reachable; `structure_does_not_reconcile` blocking at run time otherwise |
| 13 | Residue is ₹0.004 | Absorbed exactly by `ALLOCATE`/`rounding_reconcile_component_code`. **Never discarded** |
| 14 | Two balance lines | `ALLOCATE` splits by declared weights, residue to last, parts sum to the whole exactly |
| 15 | `from_ctc` target is unreachable | The builder reports the achievable band and the binding constraint; publication refused (R-XV-035) |
| 16 | `from_ctc` where a statutory ceiling creates a breakpoint | The solver evaluates each linear segment and asserts the forward recomputation; the binding segment is traced |
| 17 | Structure changes mid-period | Two structure windows; each prorated to its window; `SUM(windows)` equals the full-period entitlement exactly (Part XIV edge case 25) |
| 18 | A published component version is edited | Impossible. A published version is immutable; editing creates a new version |
| 19 | A component version is referenced by an issued payslip and someone tries to delete it | `ON DELETE RESTRICT`. Disable instead |
| 20 | A flag is introduced by a new rule pack | Defaults to 0 on every component; a review task lists the components an administrator must consider. Nothing is guessed |
| 21 | A structure inherits from a parent that is later superseded | The child's materialised line set is unaffected — the merge happened at publish. Re-publishing the child re-merges against the parent's then-current version, and the diff shows it |
| 22 | Per-employee override for a component absent from the resolved structure | Publish-time validation on the compensation: the override is refused with the component and structure named |
| 23 | Compensation effective inside a locked period | Refused by `PeriodLockGuard`; arrears instruction raised (R-XV-043) |
| 24 | Two compensation rows with the same `effective_from` | Refused by the unique index `(tenant_id, employment_id, effective_from)` |
| 25 | An employment with no compensation row at the period | `missing_compensation` blocking payroll exception |
| 26 | Minimum-wage floor rises mid-period | The floor is resolved by date; the check is evaluated against the floor applicable in the period, and a breach raises the exception for that period only |
| 27 | A component's rounding unit is ₹1 and proration produces ₹1,234.56 | Rounded per the declared mode at the declared point; the residue flows to the reconcile component so `RC-01` still holds exactly |
| 28 | An employment holds a confidential component and a manager views the sheet | The amount is masked per `visibility_role_codes`; the total is not adjusted (the manager sees a correct total and a masked line, never a wrong total) |

---

## XV.12 Tests

Every test asserts exact values in paise and is regression-locked in CI.

| ID | Asserts |
|---|---|
| `T-XV-01` | **Rename immunity.** Rename every component in a tenant; re-run a payroll period; every payslip line, every wage base, every statutory amount, every register column and the approval sheet are **byte-identical**. *This is the direct test of §XV.1.1–XV.1.4* |
| `T-XV-02` | **Flag-driven PF base.** A component named "Consolidated Pay" with `is_pf_base = 1` enters the PF wage; a component named "Basic Travel Allowance" with `is_pf_base = 0` does not. Both are the exact inverse of the current behaviour |
| `T-XV-03` | **No name inspection.** Static analysis: no string literal comparison against a component name in `Domain\*`; SPX publish rejects `CONTAINS` on a `Code` in a statutory context |
| `T-XV-04` | **Every flag has a consumer.** For each row of `cfg_component_flag_definitions`, at least one published formula, rule or engine stage reads it; a flag with none fails the build (R-XV-011) |
| `T-XV-05` | **ESI period lock.** Determination is read from `employee_period_statutory_locks`; renaming the ESI component changes nothing; the query is tenant-scoped |
| `T-XV-06` | **Precedence.** The five-candidate ladder of §XV.3.3 resolves to the employee override at 1000; removing it resolves to 990; removing that, 640; and the precedence trail is returned |
| `T-XV-07` | **Tie blocks publish.** Two structures with equal weight and equal priority: publication refused, both named, affected employments listed |
| `T-XV-08` | **Inheritance.** A child structure overriding two of a parent's six lines materialises exactly six lines at publish, two overridden, four inherited and marked so |
| `T-XV-09` | **All seven bases.** `fixed`, `percent_of_component`, `percent_of_gross`, `percent_of_ctc`, `balance`, `slab`, `formula` each produce their asserted value; `percent_of_ctc` is **percent of CTC**, not of gross (DEF-P20f) |
| `T-XV-10` | **Min / max / cap.** A cap expression binds, is traced, and the capped amount flows correctly into every wage base |
| `T-XV-11` | **Annual cap.** A component with an annual cap stops at the cap mid-year, ledgers the consumption, and resumes in the new financial year |
| `T-XV-12` | **Residue penny-safety.** CTC ₹13,00,000, monthly gross ₹1,08,333.33: components sum to gross exactly; the 1-paise drift SmartPRS 2 pushes into Special Allowance every month does not occur, or is absorbed by the declared reconcile component and traced |
| `T-XV-13` | **Two balance lines.** `ALLOCATE` residue-to-last; parts sum to the whole; the split is deterministic across shards |
| `T-XV-14` | **Sub-₹1 residue.** A residue of ₹0.004 is absorbed, not discarded; `RC-01` holds |
| `T-XV-15` | **Negative residue.** Refused at publish where reachable; blocking exception at run time otherwise |
| `T-XV-16` | **CTC reverse calculation.** Target ₹6,00,000 with employer PF in CTC solves exactly, the forward recomputation reproduces the target to the paise, and the binding segment is recorded |
| `T-XV-17` | **CTC unreachable.** A target below the minimum-wage floor reports the achievable band and refuses publication |
| `T-XV-18` | **Cycle detection.** `A = 40% of B`, `B = 50% of A` is refused at publish naming the cycle |
| `T-XV-19` | **Disabled reference.** A structure referencing a disabled component version is refused at publish |
| `T-XV-20` | **Minimum wage.** The `is_minimum_wage_component` sum below the resolved floor blocks; the floor is resolved **per state and category**, not as `MAX(monthly_min)` across the tenant |
| `T-XV-21` | **Basic+DA floor.** The Labour-Code style check blocks or warns per its declared severity, against the structure's real range — not a hardcoded ₹12,00,000 sample |
| `T-XV-22` | **Effective-dated recompute.** Recomputing a May period after a July increment uses May's CTC, May's structure version and May's component versions (§XV.4 worked example) |
| `T-XV-23` | **Locked-period compensation.** A revision effective inside a locked period is refused and raises an arrears instruction with the correct target periods |
| `T-XV-24` | **Maker-checker.** A direct write to `employee_compensations` without a completed `SALARY-CHANGE` instance is refused (R-IX-072) |
| `T-XV-25` | **Structure change mid-period.** Two windows; `SUM(windows) = full-period entitlement` exactly |
| `T-XV-26` | **Wage-base explainability.** `Explain(payslip, 'pf_wage_base')` lists every contributing component, its amount and its version, and the listed amounts sum to the base |
| `T-XV-27` | **Impact preview accuracy.** V14's predicted count and aggregate Δ match the actual result of the next run, exactly, for a fixture tenant |
| `T-XV-28` | **Publish gates.** Each of V1–V15 fails on a purpose-built invalid structure and passes on the valid one |
| `T-XV-29` | **No runtime DDL.** Component and structure management runs with a DB user holding no `ALTER`/`CREATE`/`DROP`; a pack-introduced flag lands in `cfg_component_flag_values`, not in a new column |
| `T-XV-30` | **Cross-tenant isolation.** Generated for every component, structure, compensation and assignment endpoint |
| `T-XV-31` | **Masking.** A confidential component is masked for a role without visibility while the payslip total remains correct |
| `T-XV-32` | **Typed calc value.** A formula reference and a fixed amount cannot occupy the same column; a `VARCHAR`/`DECIMAL` ambiguity is not representable |

---

## XV.13 Open items — PRODUCT OWNER DECISION REQUIRED

**D-XV-01 · Who owns the component-semantics review at migration?**
Every migrated component's nineteen flags must be reviewed and confirmed before
cutover (R-XV-053), because the flags decide statutory liability and the only
available seed is the name heuristic being removed. *Recommendation: Ametecs
prepares the proposal per tenant, labelled with the heuristic that produced each
value; the **customer's** named payroll owner signs it; the compliance owner of
spine D-03 counter-signs anything touching a statutory base. Trade-off: this is
real customer effort per tenant and it gates cutover. The alternative — inferring
and going live — carries the defect across the migration into a system that
claims not to have it.*

**D-XV-02 · Does the platform ship a seeded component library?**
*Recommendation: yes — a seeded, disable-able library of the common Indian
components (Basic, DA, HRA, Conveyance, Special Allowance, LTA, Medical, PF, ESI,
PT, LWF, TDS, employer PF/EPS/EDLI, Gratuity accrual, Bonus, Overtime, Night
Allowance, Loan EMI, Advance Recovery) with **flags left at platform defaults and
explicitly marked "confirm before use"**. Trade-off: a seeded library speeds
onboarding and risks becoming a de facto hard-coded set. Mitigated by making
every seeded component an ordinary tenant artefact that can be disabled,
re-flagged or ignored.*

**D-XV-03 · Line-level inheritance: on by default, or opt-in?**
R-XV-026 makes whole-structure resolution the default with opt-in inheritance.
*Recommendation: opt-in. Whole-structure is easier to reason about and to
explain; inheritance is powerful for a group with many near-identical
structures. Trade-off: a customer with 40 structures differing in one line will
want inheritance from day one, and retrofitting it into published structures is
awkward — which is why the field exists at launch even though the default is
off.*

**D-XV-04 · Should a tenant be able to define a new component `kind`?**
Categories are tenant-extensible; kinds are not (R-XV-006), because a new kind
changes the arithmetic of a total. *Recommendation: keep kinds platform-owned. A
customer needing "employer-funded but employee-visible and non-CTC" is expressing
a flag combination, not a new kind.*

**D-XV-05 · Is `percent_of_ctc` computed on annual CTC or on period CTC?**
*Recommendation: `annual_ctc / periods_per_year`, so the base tracks the pay
cycle rather than assuming twelve. Trade-off: a customer who means "5% of annual
CTC as a one-off" wants the annual figure — expressed as an `input` or `formula`
component, not by redefining the base.*

**D-XV-06 · Does a rename require a new component version?**
*Recommendation: no — `name`, `short_name`, `description` and
`payslip_caption_key` are **display metadata** and may be edited on a published
version, audited, without superseding. Nothing branches on them, so a rename
cannot change a number, and forcing a version for a typo correction would make
the version history unreadable. Trade-off: a payslip issued before the rename
will re-render with the new caption unless the payslip pins its captions —
which Part XVI §XVI.3.6 (R-XVI-027) requires it to do. **Both halves of this decision must
be taken together.***

**D-XV-07 · Minimum-wage enforcement severity at launch.**
*Recommendation: `block` at the structure publish gate (V8) and `block` as a
payroll exception, with a documented per-scope override to `warn` that records a
compliance exception. Trade-off: `block` will stop runs at customers whose data
is currently below a floor they were unaware of — which is the point, but it must
be surfaced during migration rehearsal, not on the first live run.*

**D-XV-08 · How many structure versions may be in flight?**
*Recommendation: unlimited drafts, one published version per artefact per scope
per effective range (enforced by V11). Trade-off: none material; the constraint
is already implied by spine S4's overlap rejection.*

**D-XV-09 · Should per-employee component overrides be permitted at all, or must
everything be a structure?**
*Recommendation: permit them (R-XV-027), because negotiated individual
arrangements are real and forcing a bespoke structure per person produces
structure sprawl that nobody can audit. Trade-off: overrides are less visible
than structures. Mitigated by making every override appear as an explicit step in
the trace, by reporting override counts per structure, and by requiring the same
`SALARY-CHANGE` approval.*

---

## XV.14 Compliance with the seven laws

| Law | How this part complies | Test |
|---|---|---|
| **1 — No business literal in code** | Component identity is a code; every semantic is a flag; the 50/40 split is a dated policy value; the seven bases are declarations; grouping comes from the definition. No component name, category, base, flag or percentage appears as a literal in `Domain\*` | `T-XV-01`, `T-XV-03`, `T-XV-09` |
| **2 — No schema change at runtime** | No `ensureCols()`. A rule-pack-introduced flag lands in a typed value table; promotion to a column is an authored migration | `T-XV-29` |
| **3 — Nothing overwritten, everything versioned and dated** | Components and structures are versioned artefacts; a published version is immutable; components are disabled, never deleted; CTC is effective-dated history with `AsOf` | `T-XV-22`, `T-XV-19` |
| **4 — Every number explains itself** | Every component amount carries formula version, substituted values, cap binding, rounding and the structure-resolution precedence trail; every wage base explains its composition | `T-XV-26` |
| **5 — Authorisation server-side, declarative, fails closed** | Every endpoint enforces permission **and** data scope; salary is masked by role and access-audited; compensation changes require maker-checker | `T-XV-24`, `T-XV-30`, `T-XV-31` |
| **6 — Tenancy is structural** | `tenant_id` NOT NULL and in every unique index on every table here | `T-XV-30` |
| **7 — Money is exact** | Amounts are signed integer paise; rounding is declared per component with no default; residue uses `ALLOCATE`; the CTC solver is exact or fails | `T-XV-12`, `T-XV-13`, `T-XV-14`, `T-XV-16` |

### XV.14.1 Requirement index

`R-XV-001`–`004` the component artefact and its identity ·
`005`–`006` kinds and categories · `007`–`011` the flag set and its
enforcement · `012`–`014` the per-component attribute set ·
`015`–`016` flags versus presentation, and the single definition of gross ·
`017`–`021` the structure artefact · `022`–`027` scope, precedence,
inheritance and overrides · `028`–`030` the seven bases with bounds ·
`031`–`033` residue and penny-safety · `034`–`036` CTC reverse calculation ·
`037`–`039` publish-time validation · `040`–`046` effective-dated compensation ·
`047`–`049` explainability · `050`–`051` typed storage and the flag registry ·
`052` API authorisation · `053`–`054` migration gates.

**54 requirements · 3 new tables + 4 extended · 32 tests · 9 Product Owner
decisions.**
