# PART XVIII — LOANS, ADVANCES, COMMISSION, INCENTIVES AND REIMBURSEMENTS

Conforms to: SPINE.md — S1 (the seven laws, and **Law 7 above all**), S3.1–S3.2
(scope and precedence), S4 (effective dating, draft/publish), S5 (SPX), S6 (the
rule engine and its `CREATE_RECOVERY` action), S7 (the formula engine), S8 (the
workflow engine), S11 (the payroll pipeline stages *commission · incentive ·
reimbursement · loans and advances · other recoveries*), S12 (the statutory rule
pack, for 194H and the taxability of a reimbursement), S13 (security), S14
(audit), S15 (explainability), S16 (data conventions), S17 (API), S19.1 (the DRA
gate — preserve it).

Covers master-brief Sections **50** (loan management), **51** (salary advances),
**52** (commission scheme builder), **53** (the incentive engine) and **54**
(reimbursements and expenses).

Evidence base: `analysis/A-payroll.md` (§1.7–§1.11, §2.6–§2.10, §3.8–§3.9, §4.0,
§4.12, §4.16, §5, §6 DEF-P02/P03/P07/P19, §9, §10); `analysis/E-leave-requests-
notifications.md` (§1.2, §2, §3.1, §4.1, §4.2, §4.6–§4.8, §6 DEF-REQ-01/04, §7A,
§10.2); `analysis/M-quality-performance-testing.md` (§6.2 C-2, C-3, C-7, C-12,
C-13, C-14); `analysis/N-product-business-modules.md` (§1.4, §1.8.1, §1.8.3);
`analysis/H-recruitment-hrops-reports.md` (§3 BR-REC-036, §4.5) for the points
keyword-bucketing defect.

Related parts: **Part XIV** (payroll architecture) owns the run pipeline and
calls into this part at five stages; **Part XXVIII** (database) registers cluster
**C9 — Money Movement** and this part specifies it in full and extends it;
**Part IX** (workflow engine) owns every approval named here; **Part VII** (rule
engine) and **Part VIII** (formula engine) own the evaluation contracts.

---

## XVIII.0 Purpose

This part decides three things.

1. **That loans, advances, recoveries, clawbacks, commission, incentives and
   reimbursements are one domain, not five modules.** Each is an **obligation**
   — a stated sum owed by someone to someone on some schedule — plus a
   **ledger** of movements against it. Everything else is configuration: who
   raises it, what computes its amount, what approves it, when it settles, and
   whether it is recovered from pay or paid out with pay. There is exactly one
   obligation model, one ledger, one reversal mechanism and one reconciliation
   invariant in SmartPRS 3.
2. **That every movement of money in this domain is run-keyed, reversible and
   reconciled, inside the payroll transaction.** SmartPRS 2 applies every one of
   these side effects *after* `DB::commit()` in a swallow-all `try/catch`
   (`PayrollGenController.php:2691`, side effects `:2695–2832`;
   `M-…` §6.2 C-2), which is why a payslip can state a loan EMI that the loan
   never records. SmartPRS 3 does not have an "apply the side effects" phase at
   all: the ledger entry **is** the payslip line's counterpart, written in the
   same transaction or not written.
3. **That the amount is always computed by a published, versioned, traced
   artefact** — a formula, a slab table, a rule — and never by a controller.
   This is what makes a commission scheme, an incentive policy and a
   reimbursement limit configurable to the Section 127 standard.

**R-XVIII-001** SmartPRS 3 MUST implement loans, advances, other recoveries,
clawbacks, commission payouts, incentive payouts and reimbursement payouts as
instances of **one** obligation-and-ledger model, sharing one ledger table shape,
one reversal mechanism and one reconciliation assertion. *Test:* a Deptrac rule
forbids any namespace outside `Domain\MoneyMovement` from writing a row to any
`*_ledger_entries` table; a CI assertion proves each obligation kind resolves
through the same `ObligationResolver` interface.

**R-XVIII-002** No obligation type name, recovery order, shortfall policy, EMI
rule, commission rate, slab boundary, TDS rate, incentive trigger, expense
category, per-category limit, gate severity or approval threshold may appear as a
literal in application code (Law 1). Every one is a column of a published
`config_versions` payload. *Test:* the Law 1 static-analysis rule; plus a
targeted grep asserting that the strings `Loan EMI`, `Advance EMI`, `Salary
Advance Recovery`, `Clawback / Reversal`, `Commission`, `Incentive`, `Salary
Arrears` — the seven payslip labels SmartPRS 2 hard-codes at
`PayrollGenController.php:2657`, `:2660`, `:2663`, `:2667`, `:2638`,
`IncentiveController.php:89` and `IncrementController.php:400` — appear nowhere
in `Domain\*` or `Http\*`.

**R-XVIII-003** Every recovery, payout, waiver, reversal and write-off MUST be
applied **inside** the transaction that writes the payslip it belongs to, or not
at all. There is no post-commit side-effect phase. *Test:* an injected failure
immediately after the ledger write leaves neither the payslip nor the ledger
entry; a second test asserts `Domain\Payroll` contains no `DB::commit()` followed
by further writes, enforced by a static rule. *Rationale — `M-…` §6.2 C-2.*

**R-XVIII-004** For every obligation, at every instant, the stored balance MUST
equal the signed sum of its ledger entries, and this MUST be asserted by the
application before a run is finalised and by a scheduled reconciliation job.
*Test:* `assert(obligation.outstanding_amount = SUM(ledger.amount_delta))` for
every open obligation touched by a run; a mismatch aborts the run.
*Rationale — SmartPRS 2's `loans.outstanding` is migrated `default(0)` and
written by nothing, verified by grep (`A-payroll.md` §2.6, DEF-P02).*

---

## XVIII.1 What SmartPRS 2 does today, and why it must change

This section is the justification for everything that follows. It is evidenced,
not editorial. Severity labels are the analysis files' own.

### XVIII.1.1 Loans are broken end to end — the most serious money defect in the product

`PayrollGenController::loanEmiDue():670–709` decides whether a loan instalment is
due using four tests. **Three of them read columns that exist in no migration and
are created by no code anywhere in the application**, and the fourth reads a
column that nothing ever writes.

| The engine reads | Line | Exists? | Consequence |
|---|---|---|---|
| `emi ?: installment_amount` | `:682` | `emi` migrated; `installment_amount` **no** | the fallback is dead |
| `installments_total` / `tenure_months` | `:687` | **no** | the instalment count is unknown |
| `start_month` | `:691` | **no** | the moratorium / start delay never applies |
| `type === 'advance'` | `:702` | **no** | the `Advance EMI` payslip line can never appear |
| `outstanding !== null && (float) outstanding <= 0 → continue` | `:696` | migrated `decimal(12,2) **default 0**`, **written by nothing** | see below |

Migration: `2026_01_01_000300_create_attendances_table.php:119–129`. The whole
migrated table is `id, tenant_id, employee_id, amount, emi, tenure, outstanding,
status, timestamps`; `tenure` is migrated and **never read**;
`installments_paid` is created at runtime by `generate():2580–2587`.

The two branches, both wrong:

- **A loan created on a freshly migrated schema has `outstanding = 0`** (the
  column default). The guard at `:696` evaluates `0 !== null && (float) 0 <= 0`
  → `continue`. **The EMI is never recovered, for any loan, ever.** This is the
  dominant real-world behaviour (`A-payroll.md` §9 item 2, DEF-P02).
- **Where `outstanding` is non-zero** — a legacy row, a manual database edit —
  `installments_total` is absent, so `$total` is null, so the
  `installments_paid < total` stop condition can never be true and the
  auto-close at `:2803` can never fire. **The EMI is deducted every month
  forever**, until a human sets `status = 'closed'` by hand.

The in-app guide promises the opposite: *"Loans auto-close after the last
installment"* (`CalcGuideController.php:116`) — a claim the code cannot satisfy
(`A-payroll.md` §8, item 4).

Compounding it, the request path that creates the loan **silently discards its
own inputs**: `ApprovalService::safeRow():262–266` is
`array_intersect_key($row, array_flip(Schema::getColumnListing($table)))`, with
no exception, no log, no return value and no user signal. On the loan module it
drops **`tenure_months`** (the column is `tenure`), **`reason`**, **`entered_by`**
and **`company_id`**, and the user is told the request succeeded
(`E-…` §6 DEF-REQ-01, CRITICAL). So the tenure the employee typed is not merely
unread — it was never stored, and cannot be recovered from the database
(`E-…` PO-13).

Finally, the decision to deduct is a **float comparison**:
`if ($emi <= $netAvail - $applied)` (`:1357`). Spine S1's Law 7 exists because of
this line.

**Design consequence.** SmartPRS 3 does not carry any of this forward. The loan
carries a real, generated **instalment schedule**; the balance is a **derived
ledger sum**, never a mutable column that something forgets to write; and there
is no float anywhere in the decision.

### XVIII.1.2 No commission reaches a payslip on a freshly migrated database

`commissions.status` is migrated
`ENUM('provisional','confirmed','reversed')` (`…000300…:80`). **Not one of those
three values is written by any code path.** The writers are:

| Writer | Value written | Evidence |
|---|---|---|
| Bulk incentive commit | `pending`, `approved` | `IncentiveController.php:81`, `:238` |
| Scheme claim paths | `rejected`, `approved`, `pending` | `SchemeController.php:644`, `:839`, `:901` |
| Increment arrears | `approved` | `IncrementController.php:400` |
| Payroll's inclusion filter | reads `status = 'approved'` | `commissionByEmployee():605` |

On strict MySQL every insert throws `SQLSTATE[01000] Data truncated`; on
non-strict MySQL the status is **silently blanked**, so nothing is ever
`approved` and **no commission, incentive or arrear ever reaches a payslip**
(`A-payroll.md` DEF-P03, CRITICAL). The project's own standard remedy —
`ALTER TABLE … MODIFY status VARCHAR` — exists at
`SchemeController::ensure():102–117`, but it is applied to `incentive_schemes`,
never to `commissions`.

This is the single clearest argument for spine S16's rule that **no business
status is a database `ENUM`**. SmartPRS 3 status columns are `VARCHAR(40)` with a
foreign key to `status_values`, so adding a state is data.

### XVIII.1.3 Advances are one-shot, unledgered and irreversible

`advanceRecovery():869–887` sums `advances.amount` for rows with
`status = 'approved'` whose **`created_at`** falls inside the payroll month, and
recovers the whole sum at once, capped at whatever net remains (`:1370`).

- The migrated columns `per_month` and `balance` (`…000300…:108–117`) model a
  **spread** recovery. Grep confirms **neither is read anywhere**
  (`A-payroll.md` §2.7, §6 DEF-P19).
- The window keys on `created_at`, not on an approval date or an effective month,
  so an advance created 31 July and approved 2 August is recovered from **July**
  and never from August (NEW-PAY-25).
- If the net cannot cover it, the shortfall is **noted in prose and never
  retried** — the window has passed (DEF-P19).
- There is **no advance ledger**. Nothing is stamped on the `advances` row: no
  `recovered_run_id`, no `recovered_at`, no amount. A regenerate therefore
  **cannot reverse an advance recovery**, and the two views of the same money can
  never be reconciled (BR-PAY-033; `M-…` §6.2 C-12).
- An advance approved after that month's run was finalised is **never recovered
  by any run** (`M-…` C-12).
- `advances.reason` is one of the nine fields `safeRow()` silently drops
  (`E-…` DEF-REQ-01).

### XVIII.1.4 Every payroll side effect runs after the transaction commits

The transaction at `PayrollGenController.php:2620–2691` covers **only** the run
header and the payslips. Everything that moves a ledger runs *after*
`DB::commit()` at `:2691`, each inside
`try { … } catch (\Throwable $e) { Log::warning(…); }`:

| Side effect | Lines |
|---|---|
| Commission lock (`locked_at`, `locked_by`, `lock_source`) + `commission_payments` passbook debit + history line | `:2695–2740` |
| Overtime `approved → paid` and `paid_run_id` | `:2743–2762` |
| Loan: `loan_recoveries` insert, `installments_paid++`, auto-close | `:2765–2806` |
| Clawback stamp (`recovered_run_id`, `recovered_amount`, `recovered_at`) | `:2809–2831` |

The code's own comment at `:2618–2619` claims the opposite: *"write the run
header + every payslip ATOMICALLY, so a failure mid-way can never leave a run
with a partial set of payslips."* It is true of the payslips and false of every
ledger.

Consequences, quoting `M-…` §6.2 C-2 (CRITICAL): a payslip can say *"Loan EMI
₹5,000 deducted"* while `installments_paid` never advances — the loan is
recovered forever. A commission can be paid on the payslip while `locked_at` is
never set, so it is eligible again next month. An OT entry can be paid and never
flip to `paid`, so it is paid again. **Every one is silent**: the log line is the
only trace, and production runs `LOG_LEVEL=warning` with an error digest that
filters for `ERROR`.

Three further concurrency defects in the same area:

- **C-3.** Regenerate hard-deletes the prior run's payslips at `:2389–2390`,
  *outside* any transaction, minutes before the replacement transaction opens at
  `:2620`. A crash in between leaves the month with **no payslips at all**. Two
  concurrent regenerates both run the loan reversal, so
  `decrement('installments_paid')` at `:2440` fires twice and a correctly closed
  loan is **re-opened**.
- **C-13.** Two concurrent generates both read `locked_at IS NULL` and **both
  insert a passbook debit** — the employee's commission passbook shows the same
  commission paid twice. And the regenerate reversal matches
  `reference LIKE 'run #<id> %'` (`:2400`) — a string match on free text, safe
  from matching run #12 when reversing run #1 **only because of one trailing
  space character** (`A-payroll.md` §5 Q7, DEF-P10 note).
- **C-7.** The commission payment balance check is a read-then-write with nothing
  in between (`RequestController.php:929` `sum()` → `:932` check → insert), so
  two ₹10,000 payments against a ₹10,000 balance both commit and ₹60,000 is
  recorded against a ₹50,000 commission.

### XVIII.1.5 Recovery order and shortfall policy are hard-coded

`compute():1350–1389` applies recoveries in a fixed sequence after statutory
deductions: **Loan EMI → Salary Advance → Clawback**, with a hard
net-never-negative floor.

| Rule | Behaviour | Configurable? | Evidence |
|---|---|---|---|
| BR-PAY-009 | Order is fixed statutory → loan → advance → clawback | **No** | `:1350–1389` |
| BR-PAY-020 | Loan EMI is **full-EMI-only**: if the whole EMI does not fit, the month is skipped entirely and the instalment stays due. Never partial | **No** | `:1356–1368`, note `:1414–1416` |
| BR-PAY-022 | An advance is recovered **in full in the month it was approved**, capped at the remaining net; the shortfall is never retried | **No** | `:869–887`, `:1370` |
| BR-PAY-023 | A clawback is attempted **exactly once** in its due month; the shortfall stays open on the row for manual handling | **No** | `:903–934`, `:1374–1388` |
| NEW-PAY-24 / `clawbacksDue` | A silent `->limit(50)` on both loans and clawbacks per employee | **No** | `:679`, `:913` |
| Net floor | `net >= 0` only. No minimum-net policy of any kind | **No** | `:1350`, `:1393` |

The analysis's own conclusion is that all four are policy decisions being
presented as arithmetic (`A-payroll.md` §3.8 "Customer may want different?" =
**Yes** on every row; §10 requirements 32–35).

### XVIII.1.6 Commission and incentive: what is genuinely good, and what is not

This is not a defect dump. Three things in SmartPRS 2's commission handling are
**good design and are carried forward** (spine S19):

1. **Payout-date routing.** A commission's `payout_date` decides which month's
   payslip pays it; absent that, the earned month (`cycle_month`) decides
   (BR-PAY-018, `commissionByEmployee():617–639`). The concept survives; the
   failure mode — *with neither, no payslip ever pays it* — does not.
2. **The permanent lock and the run-keyed passbook debit** (BR-PAY-019,
   `generate():2695–2740`). The intent is right. The implementation is
   post-commit, racy and reversed by a `LIKE` on free text.
3. **Server-side money.** A scheme claim's amount is computed from the scheme and
   never accepted from the browser (BR-PAY-034,
   `SchemeController::validateClaim():243–295`). SmartPRS 3 keeps this absolutely.

What must change:

| Finding | Evidence |
|---|---|
| A scheme supports only `base × rate / 100`, `fixed`, or `open`. **No slabs, no tiers, no per-portfolio rate, no accelerator, no cap beyond a flat per-person cap** | BR-PAY-034; `CONSOLIDATED-REQUIREMENTS` §20 |
| The bulk calculator *does* have basis / formula / slab / portfolio — but it is **request-scoped and unsaved**. There is no reusable named scheme. Configure it again next month, by hand | `IncentiveController::calculate():38–69`, `commit():72–282`; `A-payroll.md` §9 |
| The slab walk picks the first band with `upto > 0 && figure <= upto`, else **silently defaults to the last band's rate** — so a value above every band is priced at the top rate by accident of loop initialisation, not by design | `payoutFor():299–344`; worked example `A-payroll.md` §4.12 |
| TDS 194H default rate is the literal **5.0**, in three places, and the per-tenant `comm_tds_rate` setting **is not read** on the claim path | `IncentiveController.php:217–221`; `SchemeController.php:818`, `:848`; `E-…` §4.1 |
| A row whose net is ≤ 0 after TDS is **silently dropped** | `commit():125–132` |
| There is **no no-PAN higher rate** on this path, unlike the 24Q register | `A-payroll.md` §5 Q8 item 23 |
| `commissions.amount` holds the **NET after TDS** — and that is what payroll, Live Salary and the approver threshold consume, while `gross_amount` is a runtime-added column | `E-…` §4.1; `A-payroll.md` §2.9 |
| Commission, incentive, overtime, night allowance and arrears are added **after** `computeSlip` returns, so **no PF, ESI, PT or TDS is charged on any of them** | BR-STAT-006, `compute():1350`, `:1391` — *"ESI is statutorily due on OT; all of it is taxable salary"* |
| Withdrawing a scheme restores claims by matching `remarks LIKE '%withdrawn%'` — a substring of a human-typed remark | BR-PAY-711, `reopen():869–922` |
| The client bank is the **free-text `commissions.portfolio` string**. There is no bank master; `BankPackController` groups by the string | `N-…` §1.8.1 |
| `incentive_schemes` has **two incompatible shapes in one table**; `SchemeController::ensure():29–131` creates the table at request time, heals each column, relaxes legacy `NOT NULL` and widens an ENUM to VARCHAR; `save()` writes both `title` and `name` with the same value purely to satisfy a legacy column | `A-payroll.md` §2.10 |
| `incentive_schemes.employee_ids` is a **comma-separated string of ids in a `TEXT` column** — not a relation | `A-payroll.md` §2.10; `XXVIII` §127 |
| ~17 of the columns the payroll engine reads on `commissions` are runtime-created or absent, each guarded by `Schema::hasColumn` at every read site | `A-payroll.md` §2.9, §2.13 |

### XVIII.1.7 The incentive "engine" is a keyword bucketer

There is no incentive rule engine. There is a points module whose rules are
**bucketed by substring match on the rule's free-text `event` field, first match
wins**, in the fixed order `late → absent → award|reward → test|pass|exam|
assessment → perfect|present|attendance` (`PointsController.php:56–69`;
`H-…` BR-REC-036).

The stated consequence, in the analysis's own words:

> **a rule named "Perfect attendance — no lates" is classified as a LATE
> PENALTY.**

It matches `late` before it ever reaches the `perfect` bucket, so the rule's
points value is multiplied by the employee's *late count*
(`PointsController.php:94–124`) — and because penalties are expressed as negative
point values, a reward configured by an administrator becomes a penalty scaled by
the very behaviour it was meant to reward. The same module's late threshold is a
hard-coded 09:30 with no grace, no shift and no late policy
(`PointsController.php:25`, `:164–168`; `B-…` NEW-ATT-020), and its working-day
count is Sunday-only regardless of the tenant's weekly-off configuration
(`:259`).

Points never convert to money — at most they gate incentive eligibility via
`points_gate_min` (BR-PAY-037) — which is the one thing about the module that is
correct and is preserved.

### XVIII.1.8 Reimbursements are identified by a substring

A salary component is treated as a reimbursement — and therefore carved out of
the wage gross and excluded from PF, ESI and TDS — when
`category = 'reimbursement'` **OR its code or name contains the substring
`reimburs`** (`computeSlipFromComponents():2181–2188`, `:2220`; NEW-PAY-05).
The payslip's Fixed / Variable / Reimbursement grouping falls back to a
**nine-keyword heuristic** when `category` is unset (`reimburs` → reimbursement;
`commission|incentive|bonus|overtime|arrear|ex-gratia|ex gratia|payout|variable`
→ variable) (`payslipGroupEarnings():1591–1636`, NEW-PAY-30). And the printed
"Gross Earnings (A+B)" **excludes** reimbursements while the stored
`payslips.gross` **includes** them — two different numbers, both labelled gross
(NEW-PAY-31, blade `:153` vs `computeSlipFromComponents():2329`).

There is no expense-claim engine to speak of. The `expenses` table is
`id, tenant_id, employee_id, type, amount, date, status, approver_id,
timestamps` (`…000300…:131–141`). It has **no category master, no per-category
limit, no proof requirement, no GST fields, no taxability flag and no cost
centre**, and `expenses.reason` — the note that says what the claim is *for* —
is one of the nine fields `safeRow()` silently drops. The analysis's phrasing:
*"An expense claim's reason — the whole point of the claim — is never stored"*
(`E-…` DEF-REQ-01).

### XVIII.1.9 The one control worth keeping

The **DRA money gate** is, in the analysis's assessment, *"the most sophisticated
vertical rule in the product"* and *"the product's only implemented
compliance-to-money rule"* (`N-…` §1.8.1, DISC-06). A per-tenant setting
`dra_gate ∈ {off, warn, block}` (`SettingsController.php:57`, `:154`) is applied
at three money points:

| Money point | Evidence |
|---|---|
| Commission approval | `RequestController.php:1373` |
| Bulk incentive commit | `IncentiveController.php:135` |
| Off-roll agent earning approval | `OffrollAgentController.php:249–262` |

Its limits are equally clear: the gate's **subject is hard-coded to DRA** — it
cannot gate on PCC, BGV or agent authorisation (`N-…` §5 item 42); its **points
of application are three literal call sites**; payroll itself checks nothing
(`N-…` §1.4); and for off-roll agents it can only detect a *blank* DRA field,
because `offroll_agents.dra` is free text with **no expiry column at all**
(`N-…` V-02).

SmartPRS 3 preserves the shape exactly and generalises the three variables:
**which compliance items**, **which money events**, **what severity**.

### XVIII.1.10 Summary of the migration verdicts this part inherits

From `A-payroll.md` §9 and `E-…` §10.2, verbatim in substance:

| Capability | Verdict | Why |
|---|---|---|
| Loans | **REPLACE** | The schema is broken end to end (DEF-P02); full-EMI-only is not negotiable in code. *"Audit every existing loan for un-recovered or over-recovered instalments before cutover."* |
| Advances | **REPLACE** | One month, keyed on `created_at`, no ledger, `per_month`/`balance` ignored. *"Rebuild with a schedule and a ledger; reconcile outstanding balances manually."* |
| Clawbacks | **REDESIGN** | Run-keyed stamping and reversal are right; one-month-only is not configurable |
| Commission on the payslip | **RETAIN (port)** | Payout-date routing, per-purpose lines, the permanent lock and the run-keyed reversal are genuinely good design. **Widen the status ENUM first (DEF-P03)** |
| Incentive schemes | **RETAIN (port)** | Server-side money, caps, hierarchy approval, retroactive recalc and announcements are a real differentiator. Normalise `employee_ids`; drop the legacy columns |
| Bulk incentive calculator | **REDESIGN** | Good shape, request-scoped and unsaved — no reusable named rule. Nothing to migrate |
| Arrears | **REDESIGN** | A whole proration model hidden in `IncrementController`, riding the commission table, untaxed, increases only. **No decrease path exists — audit for unrecovered overpayments** |
| Commission sub-system as a vertical | **MIGRATE, REDESIGN internally** | The capability is a genuine differentiator; its implementation — 1,100 of one controller's 1,929 lines — must not survive |

---
## XVIII.2 The Money Movement model — one pattern, seven instances

### XVIII.2.1 The insight

The five subjects of this part look like five modules. They are not. Every one of
them is the same two objects:

> An **obligation**: a stated sum, owed **by** a party **to** a party, governed
> by a **schedule**, settled through a **channel**.
>
> A **ledger**: an append-only, run-keyed, signed record of every movement
> against that obligation, reversible by compensating entry, whose signed sum
> **is** the balance.

Nothing else in this domain is real. "Loan", "advance", "clawback", "commission",
"incentive", "reimbursement" are **obligation types** — configuration rows that
declare direction, how the amount arises, what approves it, how it settles, and
what it does to statutory bases.

Read the seven as one shape:

| Instance | Owed by → to | Amount arises from | Schedule | Settlement channel | Direction on the payslip |
|---|---|---|---|---|---|
| **Loan** | employee → employer | a sanctioned principal + an interest method | a generated instalment schedule, N periods | payroll recovery (or manual payment) | deduction |
| **Salary advance** | employee → employer | a sanctioned amount | one period, or N periods | payroll recovery | deduction |
| **Clawback** | employee → employer | a reversed earning (a bounced commission, an overpayment) | one period, or carry-forward | payroll recovery | deduction |
| **Other recovery** | employee → employer | an asset, a notice shortfall, a penalty, an excess payment | configurable | payroll recovery | deduction |
| **Commission payout** | employer → employee | a scheme evaluated against achievement | earned period + payout lag | payroll earning, or a separate payout batch | earning |
| **Incentive payout** | employer → employee | a policy triggered by an event | earned period + payout cycle | payroll earning, or a separate payout batch | earning |
| **Reimbursement** | employer → employee | an approved claim against a category limit | claim approval + payout cycle | payroll earning, or a reimbursement run | earning (non-wage by default) |

The columns that differ between rows are exactly the columns that must be
**configuration**. The columns that are the same are exactly the machinery that
must be written **once**.

**This is the architectural claim of this part**: SmartPRS 2 built these as seven
bespoke code paths, and the defects are not coincidental — they are what happens
when the same problem is solved seven times by different hands. The loan has a
counter and no ledger; the clawback has a stamp and no ledger; the advance has
neither; the commission has a passbook ledger but reverses it with a `LIKE` on
free text. One model, built once, correctly, removes the class.

### XVIII.2.2 The obligation

```
Obligation
  uuid, tenant, obligation_type_code            → cfg_obligation_types
  subject: employment_id  (or worker_id — Part XXVI's non-employee worker)
  counterparty_kind:  employer | client | third_party
  direction:          recover_from_employee | pay_to_employee
  principal_amount    Money                      the stated sum
  currency
  origin:  origin_kind_code + origin_id          claim, scheme run, exit case, request
  workflow_instance_id                           the sanction/approval that authorised it
  schedule_id                                    NULL for a single-movement obligation
  lifecycle_state_code                           from status_values, per type
  outstanding_amount  Money                      DERIVED — the ledger sum, stored for query,
                                                 asserted equal to it (R-XVIII-004)
  effective_period_code                          the first period it may move in
  priority_no                                    recovery competition order (§XVIII.5)
  gate_profile_code                              which compliance gate applies (§XVIII.9)
  trace_id
```

**R-XVIII-005** An obligation MUST NOT be created by any code path other than
`ObligationService::create()`, which resolves the type, validates the payload
against the type's declared field set with **named errors on unknown fields**,
opens the sanction workflow, and writes the audit row. *Test:* a static rule
forbids direct inserts into `obligations`. *Rationale — `safeRow()` silently
drops nine known fields on this exact path, and tells the user it succeeded
(`E-…` DEF-REQ-01, CRITICAL).*

**R-XVIII-006** Submitting a field the obligation type does not declare MUST fail
with a named error identifying the field. Silent discard is forbidden anywhere in
SmartPRS 3. *Test:* posting `tenure_months` to a type that declares
`instalment_count` returns HTTP 422 with `code = field.unknown`, `field =
tenure_months`, and no row is written.

**R-XVIII-007** `obligations.outstanding_amount` is a **denormalisation of the
ledger**, never an independent fact. It is written only by the ledger service, in
the same statement batch as the entry that changed it, and is re-derived by the
nightly reconciliation. *Test:* a mutation test that writes an entry without
updating the balance fails the reconciliation job.
*Rationale — `loans.outstanding` is read as a stop condition and written by
nothing (`A-payroll.md` §2.6).*

### XVIII.2.3 The ledger

One shape, used by every obligation kind. Entries are **append-only**; the
database user holds `INSERT` and `SELECT` only (Part XXVIII, archetype A4).

```
LedgerEntry
  obligation_id
  entry_type_code    disbursement | recovery | payout | waiver | interest_accrual
                     | adjustment | reversal | write_off | opening_balance
  amount_delta       SIGNED Money in integer minor units
  schedule_line_id   the instalment this settles, when there is one
  payroll_run_id     NOT NULL for anything a run produced
  payslip_id         NOT NULL for anything a payslip line produced
  payslip_line_id    the exact line — the counterpart of this movement
  reversal_of_entry_id   self-FK; a reversal never edits, it compensates
  occurred_at, recorded_at
  trace_id           the S15 trace that explains the amount
  actor_user_id      for manual movements
  reason_code, reason_text
```

**R-XVIII-008** Every ledger entry produced by a payroll run MUST carry
`payroll_run_id`, `payslip_id` and `payslip_line_id`, and MUST be written in the
**same transaction** as that payslip line. *Test:* a database constraint
`CHECK (payroll_run_id IS NULL OR payslip_id IS NOT NULL)`; plus the injected
failure test of R-XVIII-003. *Rationale — SmartPRS 2's loan recoveries,
commission locks, OT flags and clawback stamps are all applied after
`DB::commit()` in swallow-all catches (`M-…` C-2).*

**R-XVIII-009** The database MUST enforce **one movement of a given type per
obligation per payslip**: `UNIQUE (tenant_id, obligation_id, payslip_id,
entry_type_code)`. *Test:* a concurrency test issuing two simultaneous generates
proves the second fails on the constraint rather than double-debiting.
*Rationale — two concurrent generates in SmartPRS 2 both insert a passbook debit
and the employee's commission shows as paid twice (`M-…` C-13); two concurrent
regenerates decrement `installments_paid` twice and re-open a closed loan
(`M-…` C-3).*

**R-XVIII-010** A ledger entry MUST NEVER be updated or deleted. A correction is
a **compensating entry** with `reversal_of_entry_id` set, written inside the
transaction that supersedes the run. *Test:* `UPDATE` and `DELETE` on every
ledger table are refused at the database for the application user (Part XXVIII
DB-T11). *Rationale — SmartPRS 2 deletes `loan_recoveries` rows and
`commission_payments` rows during regeneration (`:2435–2452`, `:2398–2419`), so
the audit trail of what the prior run did is destroyed.*

**R-XVIII-011** Reversal MUST be **run-keyed and identity-based**, never
text-matched. Superseding run *R* reverses exactly
`WHERE payroll_run_id = R`. *Test:* an entry whose `reason_text` contains the
string `run #1` is untouched when run #1 is superseded, and every entry keyed to
run #1 is reversed. *Rationale — SmartPRS 2 reverses commission passbook debits
with `reference LIKE 'run #<id> %'`, safe from matching run #12 only because of a
trailing space, and deletes any manual entry whose free text happens to match
(`A-payroll.md` §5 Q7; `M-…` C-13).*

**R-XVIII-012** Reversal MUST be **idempotent**. Reversing an already-reversed
run is a no-op that returns the original reversal set. *Test:* two concurrent
supersessions of the same run produce exactly one reversal entry per original
entry. *Rationale — `M-…` C-3, the double decrement.*

### XVIII.2.4 What each obligation type declares

`cfg_obligation_types` is a K3 configuration artefact (Part VI). One row per
type, per version, scoped and effective-dated like everything else.

| Declaration | Type | Effect |
|---|---|---|
| `direction_code` | `recover_from_employee` \| `pay_to_employee` | which side of the payslip |
| `amount_source_code` | `stated` \| `schedule` \| `formula` \| `scheme` \| `claim` | where the period amount comes from |
| `amount_formula_key` | formula ref | when `amount_source = formula` |
| `requires_schedule` | bool | loans yes; a single-shot clawback no |
| `interest_supported` | bool | loans yes; advances no |
| `sanction_workflow_key` | workflow ref | Part IX; amount-band routing lives there |
| `settlement_channel_codes[]` | `payroll` \| `payout_batch` \| `reimbursement_run` \| `manual` | permitted channels |
| `payslip_component_code` | component ref | **the component, by code** — the label is the component's, never a literal |
| `statutory_base_flags` | the S12 flag set | whether the movement enters PF / ESI / PT / bonus / gratuity / OT / minimum-wage / taxable bases |
| `shortfall_policy_code` | `skip` \| `partial` \| `carry_forward` \| `spread` | §XVIII.5.3 |
| `default_priority_no` | int | recovery competition order |
| `min_net_policy_ref` | policy ref | the net floor that applies |
| `gate_profile_code` | gate ref | §XVIII.9 |
| `waiver_workflow_key` | workflow ref | who may forgive it |
| `reopen_policy_code` | `never` \| `workflow` | whether a closed obligation may reopen |
| `retention_class_code` | ref | Part XXVIII |
| `explain_template_key` | ref | the S15 trace rendering |

**R-XVIII-013** Adding a new obligation type — "festival advance", "asset
recovery", "notice-period shortfall", "referral bonus" — MUST require **no
application code**: a new `cfg_obligation_types` version, a component, a formula
if the amount is computed, and a workflow binding. *Test:* an acceptance test
creates a working "Festival Advance" type through the API alone and runs a
payroll that recovers it.

**R-XVIII-014** The payslip label of every money movement MUST come from the
resolved salary component's own name, in the employee's resolved language. No
label may be assembled in code. *Test:* renaming the component through
configuration changes the payslip, the register, the bank narration and the
explain trace together. *Rationale — SmartPRS 2 carries at least seven distinct
hard-coded labels for these lines, including three different spellings of the
salary-advance recovery (`A-payroll.md` §5.3: `:1726`, `:2203`, `:2663`).*

### XVIII.2.5 Where the model sits in the payroll pipeline

Spine S11 names five of this part's stages. Their contract with Part XIV:

```
… → commission        ObligationService::earningsDueForPeriod(kind=commission)
  → incentive         ObligationService::earningsDueForPeriod(kind=incentive)
  → reimbursement     ObligationService::earningsDueForPeriod(kind=reimbursement)
  → arrears           (Part XIV; an arrear is an obligation of kind `arrear`)
  → statutory …
  → income tax
  → loans and advances }
  → other recoveries  } RecoveryOrchestrator::apply(net, obligations, policy)
  → net pay
```

**R-XVIII-015** The earnings stages MUST run **before** the statutory stages, so
that each obligation type's declared statutory base flags actually take effect.
*Test:* a commission whose type declares `is_esi_base = true` increases the ESI
contribution on the payslip; one that declares it false does not.
*Rationale — SmartPRS 2 adds commission, incentive, overtime, night allowance and
arrears **after** `computeSlip` returns, so **no PF, ESI, PT or TDS is charged on
any of them** (BR-STAT-006, `compute():1350`, `:1391`) — which the analysis flags
as a live compliance exposure, since ESI is statutorily due on overtime and all
of it is taxable salary.*

**R-XVIII-016** Whether a given obligation type enters a given statutory base
MUST be a **declared flag on the type**, resolved from the statutory rule pack's
declared base set (spine S12) — never inferred from a name, a code substring or a
purpose string. *Test:* a type named `Reimbursement of Fuel` with
`is_taxable = true` is taxed; one named `Fuel` with `is_taxable = false` is not.
*Rationale — `reimburs` as a substring currently decides a statutory carve-out
(NEW-PAY-05).*

**R-XVIII-017** Every stage in this part MUST return a value **and** a trace, or
throw. A trace-less result is a programming error (Law 4). *Test:* the engine
contract test asserts no code path returns a bare `Money`.

### XVIII.2.6 The reconciliation invariant

Three assertions, all exact, all in integer paise, all fatal on failure.

| # | Assertion | When | On failure |
|---|---|---|---|
| **M1** | For every obligation touched by the run: `outstanding_amount = SUM(amount_delta)` over its entries | before run finalisation, and nightly for all open obligations | abort the run; raise an operator alert naming the obligation |
| **M2** | For every payslip: `SUM(money-movement lines) = SUM(ledger entries for that payslip)`, line by line, by obligation | before run finalisation | abort |
| **M3** | For every obligation with a schedule: `SUM(recovered against schedule lines) + SUM(waived) + outstanding = principal + accrued interest` | on every movement, and nightly | abort the movement; quarantine the obligation for review |

**R-XVIII-018** M1, M2 and M3 MUST be executed as blocking assertions, not as
reports. *Test:* a deliberately corrupted ledger row causes the run to fail with
a named reconciliation error. *Rationale — SmartPRS 2 has no reconciliation of
any kind: `net_total` is a running float sum with no check that it equals
`SUM(payslips.net)` (`A-payroll.md` §4.16, DEF-P17).*

**R-XVIII-019** All money in this part is **integer minor units (paise)** in a
`Money` value object from input to persistence (Law 7). No comparison, no
threshold test and no eligibility decision in this domain may be a float
comparison. *Test:* a type-level ban on float arithmetic in
`Domain\MoneyMovement`; a regression test asserts an EMI of exactly the remaining
net **is** recovered. *Rationale — `if ($emi <= $netAvail - $applied)` at
`:1357` is a float comparison that decides whether an instalment is deducted, and
`if ((float) $camt == 0.0)` at `:2632` is a loose float equality guarding whether
a commission line is written at all (`A-payroll.md` DEF-P07).*

**R-XVIII-020** Every division in this part is exact rational until an explicit
`ROUND` with a declared mode and unit, taken from the obligation type's or
component's rounding profile. There is no default rounding (spine S5.4).
*Test:* publishing a type or component without a rounding profile is refused.

---
## XVIII.3 Loan management — master-brief Section 50

A loan is the fullest instance of the model: a sanctioned principal, an optional
interest method, a generated schedule, many movements, and a defined end.
Everything specified here is the general obligation machinery with the
loan-specific parts named.

### XVIII.3.1 Loan types are configuration

`cfg_loan_types` is the loan-specific payload attached to an
`cfg_obligation_types` version. A tenant defines as many as it needs. Nothing
about a loan type is a code literal.

| Setting | Type | Notes |
|---|---|---|
| `loan_type_code`, `label` | code, i18n text | e.g. `SALARY-LOAN`, `FESTIVAL`, `VEHICLE`, `EMERGENCY-MEDICAL` |
| `eligibility_expr` | SPX Boolean | evaluated against `employee.*`, `service.*`, `org.*`, `salary.*`, `agg.*` |
| `max_principal_expr` | SPX Money | e.g. `MIN(salary.gross % 300, MONEY(200000))` |
| `min_principal` | Money | |
| `interest_method_code` | `none` \| `flat` \| `reducing_balance` | §XVIII.3.4 |
| `interest_rate_percent_expr` | SPX Number | may vary by grade, tenure or amount band |
| `interest_rate_basis_code` | `per_annum` \| `per_period` | |
| `min_tenure_periods`, `max_tenure_periods_expr` | int, SPX Number | |
| `moratorium_periods_max` | int | interest-bearing or not, per `moratorium_interest_code` |
| `moratorium_interest_code` | `none` \| `accrue_and_capitalise` \| `accrue_and_collect_first` | |
| `concurrent_loans_max_expr` | SPX Number | how many open loans of any type / of this type |
| `cooling_period_expr` | SPX Days | since the last closure of this type |
| `emi_cap_percent_of_net_expr` | SPX Number | the loan's own ceiling, on top of the global net floor |
| `prepayment_allowed`, `prepayment_charge_expr` | bool, SPX Money | |
| `foreclosure_allowed`, `foreclosure_charge_expr` | bool, SPX Money | |
| `restructure_allowed` | bool | |
| `pause_allowed`, `pause_periods_max` | bool, int | |
| `waiver_workflow_key` | ref | forgiveness is always a workflow |
| `exit_recovery_policy_code` | `full_from_fnf` \| `full_or_convert_to_receivable` \| `continue_as_receivable` \| `waive` | §XVIII.3.13 |
| `guarantor_required`, `guarantor_count` | bool, int | |
| `documents_required_json` | document-type codes | |
| `disbursement_channel_codes[]` | `payout_batch` \| `payroll` \| `external` | |
| `principal_component_code`, `interest_component_code` | component refs | the payslip lines |
| `rounding_profile_code` | ref | applies to EMI derivation and every movement |
| `sanction_workflow_key` | ref | Part IX, with amount bands |

**R-XVIII-021** A tenant MUST be able to create a new loan type, with its own
eligibility, ceiling, interest method, tenure limits and sanction path, entirely
through the Configuration Studio, and MUST be able to do so **without any
component of the platform knowing the type's name**. *Test:* Section 127
conformance — a type "Two-wheeler loan, max ₹75,000 or 3× gross whichever is
lower, 8% flat, 24 months, sanction over ₹50,000 by the Finance Manager" is
created and used end to end with no deployment.

**R-XVIII-022** Loan eligibility MUST be an SPX expression over the declared
namespaces, evaluated at sanction request time and re-evaluated at approval time,
with both results traced. *Test:* an employee who becomes ineligible between
request and approval causes the approval screen to display the failing predicate
by name.

Worked eligibility, illustrative tenant values:

```spx
-- SALARY-LOAN eligibility
service.completed_years >= policy.loan_min_service_years
AND employee.stage NOT IN ['probation', 'intern']
AND agg.balance('obligation:SALARY-LOAN', period.end) = MONEY(0)
AND agg.count('loan_closed', rolling(6,'month'),
              obligation.type = 'SALARY-LOAN') = 0

-- SALARY-LOAN maximum principal
MIN( salary.gross % policy.loan_max_percent_of_gross ,
     policy.loan_absolute_ceiling )
```

Every symbol on the right of a comparison is a `policy.*` value resolved from a
published policy artefact. None is a literal.

### XVIII.3.2 The loan lifecycle

```
requested ─(sanction workflow)─→ sanctioned ─→ disbursed ─→ recovering
                │                                              │
                │                                    ┌─────────┼──────────┐
                ├─→ rejected                         │         │          │
                └─→ withdrawn                     paused  restructured  foreclosed
                                                       │         │          │
                                                       └────→ recovering ←──┘
                                                                 │
                                            ┌────────────────────┼───────────────┐
                                         closed             written_off      transferred
                                       (balance 0)        (workflow, audit)  (to receivable
                                                                              on exit)
```

**R-XVIII-023** The state vocabulary and its permitted transitions MUST be
`status_values` + `status_transitions` rows, not code. *Test:* adding a tenant
state `awaiting_disbursement` between `sanctioned` and `disbursed` requires no
deployment. *Rationale — SmartPRS 2's loan states are the bare strings
`approved`, `active`, `closed` written in three controllers.*

**R-XVIII-024** A loan MUST close **automatically and transactionally** at the
moment the movement that clears its balance is written — in the same transaction,
asserted by M3 — and MUST NOT depend on a counter maintained elsewhere.
*Test:* the final EMI's payslip and the loan's `closed` state commit together;
killing the process immediately after the ledger insert leaves neither.
*Rationale — SmartPRS 2's auto-close is a post-commit `try/catch` on a counter
whose stop condition reads a column that does not exist, so it can never fire
(`A-payroll.md` DEF-P02; `M-…` C-2).*

### XVIII.3.3 Sanction through the workflow engine, with amount-based routing

There is **no loan approval code in this part**. Sanction is a workflow instance
on the `LOAN-SANCTION` definition (Part IX §IX.9.2), bound by scope, with
amount bands on `loan.principal` and the segregation and immutability guarantees
Part IX specifies.

**R-XVIII-025** Loan sanction MUST use the workflow engine, with the routing
input declared as `subject_amount_path = loan.principal_amount`. This part
declares no threshold of its own. *Test:* changing the band boundary is a
workflow version publish, not a deployment. *Rationale — SmartPRS 2 has one
literal threshold, ₹50,000, in a PHP array (`ApprovalService.php:32`), a fixed
two-level chain, and a degenerate `[NULL, 'Branch / Company Admin']` outcome that
sends **no approver email at all** (`E-…` §4.2).*

**R-XVIII-026** Loan sanction MUST be a **maker-checker** workflow (spine S8): the
requester, the person who edits the terms and the person who sanctions may not be
the same user, and the sanctioning user may not also release the disbursement
payout. *Test:* Part IX's segregation health check reports every tenant where one
user satisfies both sides.

**R-XVIII-027** The **sanctioned terms are frozen at sanction**. Principal,
interest method, rate, tenure, first recovery period and moratorium are copied
onto the loan from the approved instance and are thereafter immutable. Changing
any of them is a **restructure** (§XVIII.3.11), which is its own workflow and its
own schedule version. *Test:* a `PATCH` on a sanctioned loan's principal returns
409 with `code = loan.terms_frozen`. *Rationale — SmartPRS 2 has no notion of
sanctioned terms at all; the loan row is directly editable through the generic
request editor.*

### XVIII.3.4 Interest methods

Three, all configuration-selected. The arithmetic is a **domain service**, not an
SPX expression, because generating N schedule rows is iteration and SPX has no
loops by design (spine S5). SPX supplies the *inputs* — rate, tenure, principal —
and the service produces the schedule deterministically.

| Method | Interest computation | Schedule shape |
|---|---|---|
| `none` | zero | principal split across N instalments by `ALLOCATE`, residue to the last |
| `flat` | `interest_total = principal × rate_per_annum × tenure_years`, rounded once by the profile | `(principal + interest_total)` split by `ALLOCATE`; each line carries its own principal and interest components, themselves allocated |
| `reducing_balance` | per period on the opening balance | level instalment derived by the standard amortisation identity; the **final instalment is adjusted** so the closing balance is exactly zero |

**R-XVIII-028** The schedule generator MUST be deterministic and MUST produce a
schedule whose principal components sum **exactly** to the principal and whose
instalment totals sum exactly to principal + total interest, in integer paise,
using `ALLOCATE` residue-to-last (spine S7). *Test:* a property test over 10,000
random (principal, rate, tenure) triples asserts both sums exactly, for all three
methods. *Rationale — the balance-residue allocation in SmartPRS 2's salary
structure (`:2249–2251`) is the one correct piece of money splitting in the
codebase (spine S19.4); this generalises it.*

**R-XVIII-029** An interest-bearing loan MUST record its interest as a **separate
ledger entry type (`interest_accrual`) and a separate schedule component**, and
the payslip MUST show principal and interest as separate lines when the loan
type declares an `interest_component_code`. *Test:* the sum of `interest_accrual`
entries equals the schedule's total interest at closure.

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** Interest is specified because
customers ask for it and because the master brief names it. SmartPRS 2 has **no
interest concept whatsoever** — `loans` carries `amount`, `emi`, `tenure`,
`outstanding` and nothing else. Where a tenant lends interest-free (the common
Indian salary-advance-as-loan case), `interest_method = none` and the interest
component is simply never resolved.

#### Worked example A — interest-free, uneven division

Loan `FESTIVAL`, principal ₹50,000.00, `interest_method = none`, tenure 7
periods, rounding profile `2dp, half_up`, residue to last.

```
principal          5,000,000 paise
per instalment     FLOOR(5,000,000 / 7) = 714,285 paise = ₹7,142.85
instalments 1..6   ₹7,142.85  (6 × 714,285 = 4,285,710 paise)
instalment 7       5,000,000 − 4,285,710 = 714,290 paise = ₹7,142.90
Σ                  ₹50,000.00  exactly
```

#### Worked example B — flat interest

Loan `VEHICLE`, principal ₹1,20,000.00, `interest_method = flat`,
`rate = 10% per_annum`, tenure 12 periods.

```
tenure_years       12 / 12 = 1
interest_total     ₹1,20,000.00 × 10% × 1 = ₹12,000.00
gross              ₹1,32,000.00
instalment         ₹1,32,000.00 / 12 = ₹11,000.00 exactly
  of which principal ₹10,000.00, interest ₹1,000.00 per instalment
```

#### Worked example C — reducing balance

Loan `SALARY-LOAN`, principal ₹1,50,000.00, `interest_method =
reducing_balance`, `rate = 12% per_annum` → 1.00% per monthly period, tenure 24.

```
level instalment   ₹7,061.02   (derived, then rounded by the profile)
instalment 1       interest ₹1,500.00   principal ₹5,561.02   closing ₹1,44,438.98
instalment 2       interest ₹1,444.39   principal ₹5,616.63   closing ₹1,38,822.35
…
instalment 24      adjusted so the closing balance is exactly ₹0.00
total interest     the sum of the 24 interest components, stored on the schedule
```

The **adjusted final instalment** is the only place rounding drift can land, and
it lands there by construction, not by accident. This is asserted by M3.

### XVIII.3.5 The schedule is generated at sanction

**R-XVIII-030** The **full instalment schedule MUST be generated and persisted at
sanction**, one row per instalment, before any disbursement. It is not derived at
recovery time. *Test:* immediately after sanction, `GET
/loans/{uuid}/schedule` returns `instalment_count` rows summing exactly to
principal + interest. *Rationale — SmartPRS 2 has no schedule at all: the
instalment count is read from a column that does not exist
(`installments_total`, `A-payroll.md` §2.6), so the loan has no defined end.*

Each `loan_schedule_lines` row carries: `instalment_no`, `due_period_code`,
`due_on`, `opening_balance`, `principal_component`, `interest_component`,
`total_amount`, `status_code` (`scheduled` | `recovered` | `part_recovered` |
`waived` | `deferred` | `cancelled` | `superseded`), `recovered_amount`,
`schedule_version_no`.

**R-XVIII-031** The first recovery period MUST be an explicit, stored value
(`first_recovery_period_code`), derived at sanction from the disbursement date,
the moratorium and the pay cycle — never re-derived at recovery time.
*Test:* a loan disbursed on 28-Aug with a one-period moratorium recovers first in
the October run, and still does after the pay cycle's cut-off day is later
changed. *Rationale — SmartPRS 2 reads `start_month`, a column that exists
nowhere, so the delay never applies at all (`A-payroll.md` DEF-P02).*

**R-XVIII-032** A moratorium MUST declare its interest treatment
(`none` | `accrue_and_capitalise` | `accrue_and_collect_first`) and the schedule
MUST reflect it. *Test:* the three treatments produce three different, exactly
asserted schedules for the same loan.

### XVIII.3.6 Disbursement

**R-XVIII-033** Disbursement MUST be an explicit, separately authorised command
that creates a `disbursement` ledger entry and, where the channel is
`payout_batch`, a payout line — never a side effect of approval, and never a side
effect of generating or downloading a file. *Test:* generating the disbursement
file twice produces one disbursement. *Rationale — SmartPRS 2's bank file
**disburses on download**, outside any transaction and on a GET, so two Finance
users downloading simultaneously instruct the bank to pay twice
(`M-…` C-14; `A-payroll.md` SEC-P04, DEF-P10).*

**R-XVIII-034** A loan MUST NOT enter `recovering` until a `disbursement` entry
exists whose absolute value equals the sanctioned principal. *Test:* a partially
disbursed loan cannot be recovered from.

**PRODUCT OWNER DECISION REQUIRED — D-XVIII-01: are part-disbursed loans in
scope for v3.0?** *Recommendation:* **no** — one disbursement per loan; a
tranche facility is modelled as separate loans under a facility group. *Trade-off:*
customers financing an asset in tranches must raise several loans, which is
clerical; supporting tranches means the schedule must be regenerated on each
tranche, which is the restructure path and materially widens the reconciliation
surface for a case no analysed customer has.

### XVIII.3.7 Recovery through payroll

Per period, per employee, the recovery stage asks each open obligation for its
**due amount** and hands the set to the orchestrator (§XVIII.5).

For a loan, the due amount is the sum of the scheduled instalments whose
`due_period_code` is **on or before** the run period and whose status is
`scheduled` or `part_recovered` — so a skipped instalment is naturally carried,
without a separate arrears mechanism.

**R-XVIII-035** A loan's due amount MUST be derived from the **schedule**, never
from a stored `emi` column, and MUST include instalments carried from earlier
periods according to the type's shortfall policy. *Test:* an employee whose net
was insufficient in September has two instalments due in October under
`carry_forward`, and one under `skip`.

**R-XVIII-036** Recovery MUST support **partial recovery of an instalment** where
the type's shortfall policy is `partial`, writing a `recovery` entry for the
amount taken and leaving the schedule line `part_recovered` with a stated
residue. *Test:* net allows ₹3,000 of a ₹5,000 instalment; the ledger shows
₹3,000, the schedule line shows ₹2,000 outstanding, and the payslip note states
the shortfall in structured fields. *Rationale — SmartPRS 2 is full-EMI-only and
skips the entire month if the instalment does not fit (BR-PAY-020, `:1356–1368`);
the analysis records "most clients expect a partial recovery".*

**R-XVIII-037** There MUST be no limit on the number of open loans or recoveries
considered for an employee in a run. *Test:* an employee with 60 open
obligations has all 60 evaluated. *Rationale — SmartPRS 2 carries silent
`->limit(50)` clauses on both the loan and clawback queries (`:679`, `:913`), so
an employee beyond 50 silently loses recovery on the excess (`M-…` C-12).*

### XVIII.3.8 Manual payment

An employee may repay outside payroll — cash, transfer, or a deduction from a
final settlement.

**R-XVIII-038** A manual repayment MUST be recorded as a `recovery` ledger entry
with `payroll_run_id = NULL`, an actor, a reason, a receipt reference and, where
a receipt is issued, the document id. It MUST settle schedule lines in the type's
declared allocation order (`oldest_first` | `interest_first` | `principal_first`
| `latest_first`). *Test:* a ₹15,000 manual payment against three ₹7,061.02
instalments settles two fully and one partially under `oldest_first`, and the
schedule reflects it exactly.

**R-XVIII-039** A manual repayment MUST pass the same maker-checker as any other
money entry where the tenant's configuration requires it, and MUST NOT be
reversible except by a compensating entry with a reason. *Test:* editing a
recorded repayment is impossible through every surface including the API.

### XVIII.3.9 Prepayment and foreclosure

| Operation | Effect |
|---|---|
| **Prepayment** (part) | A `recovery` entry above the due instalment. The tenant's `prepayment_effect_code` decides: `reduce_tenure` (drop trailing instalments, regenerate the tail) or `reduce_instalment` (regenerate the remaining schedule at the same count). A `prepayment_charge_expr`, if configured, raises its own obligation of type `charge`. |
| **Foreclosure** | Settle the entire outstanding in one movement. A `foreclosure_charge_expr` may apply. On a `reducing_balance` loan, unaccrued future interest is **not** collected; on a `flat` loan, the tenant's `flat_foreclosure_rebate_code` (`none` \| `rule_of_78` \| `pro_rata_unexpired`) decides the rebate. The loan closes; the remaining schedule lines become `cancelled`. |

**R-XVIII-040** Prepayment and foreclosure MUST both regenerate the schedule as a
**new schedule version**, leaving the prior version's lines `superseded` and
queryable, never deleted. *Test:* after a prepayment, the original 24-line
schedule is still retrievable with its version number, and the explain trace for
instalment 13 names which version priced it.

**R-XVIII-041** A foreclosure quote MUST be a **read-only, dated computation**
returning the settlement amount, its components (principal, accrued interest,
charge, rebate) and a validity date, and MUST be reproducible from the trace.
*Test:* a quote taken on day D and executed on day D produces the identical
amount; executed later, it is recomputed and the difference is shown.

### XVIII.3.10 Waiver

**R-XVIII-042** A waiver MUST be a workflow-approved `waiver` ledger entry with a
mandatory reason code and free text, and MUST be capable of waiving principal,
interest, or a stated amount. It MUST NOT be achievable by editing a balance.
*Test:* no surface exists that writes `outstanding_amount` directly; the
reconciliation job proves it.

**R-XVIII-043** A waiver MUST declare its **tax treatment** by referencing the
statutory rule pack's declared head for a forgiven employee obligation, rather
than assuming one. *Test:* the waiver's payslip effect follows the pack, and the
pack version is on the trace. *Rationale — brief hard rule 1: no statutory
treatment is asserted here as fact.*

### XVIII.3.11 Restructure

**R-XVIII-044** Restructure MUST be a workflow-approved operation that creates a
**new schedule version** from the current outstanding, optionally changing tenure,
instalment, rate or first recovery period, and MUST preserve every prior movement
untouched. The obligation's identity, its ledger and its `principal_amount` do not
change. *Test:* after restructure, `SUM(ledger) = outstanding` still holds (M1),
and the total of all schedule versions' recovered amounts is unchanged.

### XVIII.3.12 Pause

**R-XVIII-045** A pause MUST be a dated, approved, reason-bearing state that
suppresses recovery for N periods without altering the schedule's amounts, and
MUST declare whether the paused instalments are appended to the tail or carried
into the resumption period. *Test:* a two-period pause on a 24-instalment loan
either produces a 26-period schedule or two instalments due on resumption,
according to configuration, with the difference visible in the diff preview.

### XVIII.3.13 Exit recovery

**R-XVIII-046** On an employment exit, every open obligation of the exiting
employee MUST be surfaced on the full-and-final settlement with its outstanding
balance, and the loan type's `exit_recovery_policy_code` MUST decide the
treatment: recover in full from the F&F, recover what the F&F allows and convert
the residue to a **receivable** obligation outside payroll, continue as a
receivable, or waive by workflow. *Test:* an exit with an outstanding loan cannot
reach `settled` while any obligation is unresolved; the exception report lists
every conversion to receivable. *Rationale — SmartPRS 2's `exits` table carries a
free-text `advances_recovered` field and no linkage to any loan or advance row at
all (`E-…` §2).*

**R-XVIII-047** A converted receivable MUST retain the original obligation's
identity and ledger, changing only its type and settlement channel, so the money
story is continuous. *Test:* the explain trace of a receivable shows the
disbursement, every payroll recovery and the conversion, in one timeline.

### XVIII.3.14 Opening balances for migrated loans

**R-XVIII-048** A loan migrated from SmartPRS 2 MUST be created with an explicit
`opening_balance` ledger entry, a **reconstructed schedule**, and a
`migration_confidence_code` (`reconciled` | `inferred` | `disputed`). It MUST NOT
be created by writing a balance column. *Test:* every migrated loan satisfies M1
and M3 on day one, or is quarantined and reported.

**R-XVIII-049** Where the SmartPRS 2 balance cannot be reconciled — which the
analysis states will be the normal case, since `outstanding` is `default(0)` and
written by nothing — the loan MUST be created in state `pending_reconciliation`,
MUST NOT be recovered from, and MUST appear on a blocking cutover exception
report until a named person confirms the balance. *Test:* the cutover gate
refuses to complete while any loan is `pending_reconciliation`.
*Rationale — `A-payroll.md` §9: "Audit every existing loan for un-recovered or
over-recovered instalments before cutover"; `XXVIII` T8: "reconciliation
exceptions are expected and must be listed, not absorbed".* See §XVIII.15.2.

**R-XVIII-050** The explain trace of a migrated loan MUST state that its opening
balance is a migrated figure, name its confidence code, name who confirmed it and
when, and link to the legacy row it came from. *Test:* the trace of instalment 1
of a migrated loan shows the migration provenance block.

---
## XVIII.4 Salary advances — master-brief Section 51

An advance is a **separate obligation type from a loan**, not a loan with a
tenure of one. It is separate because its eligibility, its approval path, its
frequency limits and its recovery expectations are different, and because
customers report them separately. Structurally it is the same obligation with
`requires_schedule = false` by default and `interest_supported = false`.

**R-XVIII-051** A salary advance MUST be a distinct obligation type with its own
configuration, its own workflow binding, its own limits and its own reporting,
and MUST NOT be implemented as a loan variant in code. *Test:* the advance
register and the loan register are separate report definitions over the same
tables, and neither is produced by a `WHERE type = 'advance'` literal.
*Rationale — SmartPRS 2 attempts exactly that shortcut: `loanEmiDue():702` tests
`$ln->type === 'advance'` on a column that does not exist, so the `Advance EMI`
payslip line can never appear at all (`A-payroll.md` DEF-P02).*

### XVIII.4.1 Configuration surface

| Setting | Type | Notes |
|---|---|---|
| `advance_type_code`, `label` | code, i18n | e.g. `SALARY-ADVANCE`, `TRAVEL-ADVANCE`, `MEDICAL-ADVANCE` |
| `eligibility_expr` | SPX Boolean | service duration, stage, category, org scope, no open advance |
| `max_amount_expr` | SPX Money | e.g. `MIN(salary.net % 50, policy.advance_absolute_cap)` |
| `min_service_expr` | SPX Boolean | expressed inside `eligibility_expr`; named separately for the UI |
| `frequency_window_code` | `calendar_month` \| `payroll_period` \| `quarter` \| `financial_year` \| `rolling(n,unit)` \| `service_year` | |
| `frequency_max_count_expr` | SPX Number | how many advances per window |
| `frequency_max_amount_expr` | SPX Money | how much per window |
| `concurrent_max_expr` | SPX Number | open advances at once |
| `recovery_plan_default_code` | `single` \| `spread` | |
| `recovery_periods_max` | int | for `spread` |
| `recovery_start_offset_periods` | int | 0 = the period of approval |
| `shortfall_policy_code` | `skip` \| `partial` \| `carry_forward` \| `spread` | §XVIII.5.3 |
| `component_code` | ref | the payslip deduction line |
| `waiver_workflow_key`, `exit_recovery_policy_code` | ref, code | as loans |
| `payslip_visibility_code` | `line` \| `line_with_balance` \| `hidden_show_in_ess` | §XVIII.4.6 |
| `sanction_workflow_key` | ref | amount-band routed |

**R-XVIII-052** The maximum advance amount and the eligibility test MUST both be
SPX formulas, resolvable per scope, not a single tenant number. *Test:*
"50% of net for grades A–C, 75% for D and above, absolute ceiling ₹40,000,
minimum six months' service" is expressible as configuration and produces
different ceilings for two employees in one evaluation.

```spx
-- max advance
MIN( salary.net % policy.advance_percent_of_net ,
     policy.advance_absolute_cap )

-- eligibility
service.months >= policy.advance_min_service_months
AND employee.stage <> 'probation'
AND agg.count('advance_sanctioned', frequency_window,
              obligation.type = 'SALARY-ADVANCE')
      < policy.advance_max_per_window
AND agg.balance('obligation:SALARY-ADVANCE', period.end) = MONEY(0)
```

**R-XVIII-053** Frequency limits MUST be evaluated over a **declared window** and
MUST count sanctions, not requests. *Test:* a rejected advance does not consume
the allowance; a withdrawn one does not either.

### XVIII.4.2 Approval

**R-XVIII-054** Advance sanction MUST use the workflow engine with amount-band
routing, and this part MUST declare no threshold. *Test:* as R-XVIII-025.
*Rationale — the ₹10,000 escalation threshold is a literal in a PHP array
(`ApprovalService.php:31`); it is seeded in SmartPRS 3 **as the value the code
currently uses**, marked unconfirmed, per Part IX D-IX-08.*

**R-XVIII-055** The advance's **effective recovery period** MUST be derived from
the approval date and `recovery_start_offset_periods`, stored on the obligation
at approval, and MUST NOT be derived from a record-creation timestamp.
*Test:* an advance created 31-Jul and approved 02-Aug recovers from **August**,
not July, and the stored period proves it. *Rationale — SmartPRS 2's window is
bounded on both sides by `created_at` (`:875–882`, NEW-PAY-25), which produces
exactly the inverse and unrecoverable case: an advance approved after the July
run was finalised is **never recovered by any run** (`M-…` C-12).*

### XVIII.4.3 Recovery in one period or across periods

**R-XVIII-056** An advance MUST support both `single` and `spread` recovery
plans. Where `spread`, an instalment schedule MUST be generated at approval,
exactly as for a loan, with the same schedule tables and the same reconciliation.
*Test:* a ₹30,000 advance spread over 3 periods produces three ₹10,000
instalments and satisfies M3. *Rationale — SmartPRS 2's `advances` table carries
migrated `per_month` and `balance` columns that model a spread recovery, and grep
confirms **neither is read anywhere in the application** (`A-payroll.md` §2.7,
DEF-P19). The schema promised a feature the code never implemented.*

**R-XVIII-057** An advance MUST have a **balance**, derived from its ledger, shown
on the employee's self-service and on the payslip where the type's visibility
setting says so. *Test:* after a partial recovery, the balance shown to the
employee equals principal minus the sum of `recovery` entries, exactly.

### XVIII.4.4 Partial recovery and the shortfall policy

**R-XVIII-058** An advance's behaviour on shortfall MUST be governed by the same
four-valued policy as every other recovery (`skip` | `partial` | `carry_forward`
| `spread`), configured on the type and overridable per obligation where the type
permits. *Test:* the same ₹20,000 advance against a ₹12,000 available net
produces four distinct, exactly asserted outcomes under the four policies.
*Rationale — SmartPRS 2 caps the recovery at the available net and then **notes
the shortfall in prose and never retries it**, because the `created_at` window
has passed (`:1370`, DEF-P19).*

The four outcomes, worked. Advance ₹20,000.00 due, available net after statutory
deductions ₹12,000.00, net floor ₹0:

| Policy | Recovered now | Obligation after | Payslip |
|---|---|---|---|
| `skip` | ₹0.00 | ₹20,000.00 open, due again next period | no line; a structured shortfall note |
| `partial` | ₹12,000.00 | ₹8,000.00 open, due next period | one line ₹12,000.00 + residue note |
| `carry_forward` | ₹12,000.00 | ₹8,000.00 open, explicitly stamped `carried_from = 2026-09` | as above, with the carry stated |
| `spread` | ₹12,000.00 | ₹8,000.00 **re-scheduled** across the type's `recovery_periods_max` | as above, with the new schedule shown |

### XVIII.4.5 Waiver and exit

**R-XVIII-059** Advance waiver and exit recovery MUST use the same mechanisms as
loans (R-XVIII-042, R-XVIII-046). No separate code path exists.

### XVIII.4.6 Payslip visibility

**R-XVIII-060** The advance's presence on the payslip MUST be governed by the
type's `payslip_visibility_code`, and where it is shown, the line's label MUST be
the component's own name (R-XVIII-014). Where `line_with_balance`, the payslip
MUST print the closing balance from the ledger, not from a narrative note.
*Test:* the printed balance equals `agg.balance('obligation:<id>', period.end)`.
*Rationale — SmartPRS 2 prints Paid Days and LOP by running a **regex over a
free-text English calculation note** (`payslipViewData():1278–1282`,
BR-PAY-031), and carries three different hard-coded spellings of the salary
advance recovery label (`:1726`, `:2203`, `:2663`). Neither pattern is repeated.*

**R-XVIII-061** Every figure this part contributes to a payslip MUST come from
**structured stored data**, never from parsing prose. *Test:* the payslip renderer
receives no free-text field it must parse.

**R-XVIII-062** An advance MUST be visible in ESS with its sanction, its schedule
(if any), its movements and its balance, subject to spine S13 scoping. *Test:*
the ESS view for employee E shows only E's obligations, resolved from the
session, never from a request parameter. *Rationale — SmartPRS 2's
`RequestController::list()` has **no role guard**, so any authenticated user reads
every colleague's loan, advance, increment and exit record for the whole tenant
(`E-…` SEC-01, CRITICAL).*

**R-XVIII-063** Raising an advance, a loan or any obligation **for another
employee** MUST require an explicit permission and a data-scope check; the
subject of a self-service request MUST be resolved from the session.
*Test:* `POST /api/v3/obligations {employment: <someone else>}` from an ordinary
employee returns 403. *Rationale — SmartPRS 2 accepts an arbitrary employee
string on eight of nine request modules with no role check and no relationship
check, so any employee can file a ₹5,00,000 loan for a colleague or an exit for
the CEO (`E-…` SEC-02, CRITICAL).*

---

## XVIII.5 Recovery orchestration

One service, `RecoveryOrchestrator`, resolves every competing claim on an
employee's net pay in one place, deterministically, with a trace. It is called
once per employee per run, at the pipeline stage spine S11 names *loans and
advances → other recoveries*.

### XVIII.5.1 The contract

```
apply( available_net      : Money,      -- after statutory deductions
       obligations        : Obligation[],
       order_policy       : RecoveryOrderVersion,
       floor_policy       : NetFloorVersion,
       period             : Period )
  → RecoveryPlan {
        applications : [ { obligation, amount, schedule_lines[], shortfall,
                           shortfall_disposition } ],
        residual_net : Money,
        trace        : DecisionTrace
    }
```

The orchestrator is **pure**: it computes a plan and mutates nothing. Part XIV's
payroll service applies the plan — writing payslip lines and ledger entries
together — inside the run transaction (R-XVIII-003). This mirrors spine S6's rule
engine contract exactly, and is what makes the plan simulable before a run.

**R-XVIII-064** `RecoveryOrchestrator::apply()` MUST be side-effect free and MUST
return a plan plus a trace. *Test:* calling it twice with identical inputs
produces byte-identical plans and writes nothing.

### XVIII.5.2 Recovery order is configuration

`cfg_recovery_orders` is a K3 artefact (Part VI section S6 already lists
`recovery_order` as an artefact type), scoped and effective-dated.

```
recovery_order
  rows: [ { sequence_no, obligation_type_code | obligation_class_code,
            tie_break_code, cap_expr, condition_expr } ]
  where obligation_class_code ∈ { statutory, court_order, loan, advance,
                                  clawback, other_recovery, voluntary }
  tie_break_code ∈ { oldest_first, largest_first, smallest_first,
                     highest_priority_no, schedule_due_date }
```

**R-XVIII-065** Recovery order MUST be a published, scoped, effective-dated
artefact, and the run MUST resolve the version **effective in the run's period**,
never "today". *Test:* a recovery order published in October does not change a
re-run of the August payroll. *Rationale — BR-PAY-009: the order is the fixed
literal sequence statutory → loan → advance → clawback, and the analysis records
"priority order is a policy decision" (`A-payroll.md` §3.8).*

**R-XVIII-066** Where two obligations occupy the same sequence position, the
declared `tie_break_code` MUST decide, and where it cannot, publication MUST be
**blocked** by the configuration validator naming both types — never resolved by
row order or primary-key order. *Test:* publishing an order with two ambiguous
rows is refused. *Rationale — spine S3.2; SmartPRS 2 orders loans by `loans.id`
and clawbacks by `clawbacks.id` (`:1356`, `:1377`), which is arrival order
masquerading as policy.*

**R-XVIII-067** A statutory or court-ordered deduction MUST be capable of being
placed above every voluntary recovery, and the validator MUST **warn** when a
published order places a voluntary recovery above a statutory class.
*Test:* the warning fires and is acknowledged before publication.

**PRODUCT OWNER DECISION REQUIRED — D-XVIII-02: is the recovery order tenant-
editable, or Ametecs-editable only?** *Recommendation:* **tenant-editable, but
`high_risk`** — requiring diff, impact preview and maker-checker approval before
publication (spine S4). *Trade-off:* a customer who reorders recoveries changes
who is paid first in a shortfall, which is a real business decision they own; but
a careless change silently alters every future shortfall month. The `high_risk`
classification is the compensating control, and the impact preview names the
affected employees.

### XVIII.5.3 The shortfall policy, per type

| Policy | Meaning | Effect on the obligation | Effect on the schedule |
|---|---|---|---|
| `skip` | take nothing unless the whole due amount fits | unchanged, due again next period | line stays `scheduled` |
| `partial` | take whatever fits | reduced by the amount taken | line becomes `part_recovered` with a residue |
| `carry_forward` | take whatever fits; the residue is explicitly carried and stamped with the period it came from | reduced; carry recorded | line `part_recovered`, `carried_from_period` set |
| `spread` | take whatever fits; the residue is **re-scheduled** across the type's remaining permitted periods | reduced; schedule superseded by a new version | new schedule version generated |

**R-XVIII-068** Each obligation type MUST declare a shortfall policy, and an
individual obligation MAY override it where the type permits. *Test:* the four
policies produce four exactly asserted outcomes on the same inputs (§XVIII.4.4).
*Rationale — three hard-coded and mutually inconsistent behaviours today: loans
full-EMI-only, advances one-shot-and-lost, clawbacks one-month-only
(`A-payroll.md` §10 requirement 33).*

**R-XVIII-069** Every shortfall MUST be recorded as **structured data** on the
payslip and on the obligation — amount due, amount taken, residue, policy applied,
reason code — and MUST be reportable as a "recoveries not fully taken" exception
list for the run. *Test:* the exception list for a run names every employee with a
shortfall and the rupee amount. *Rationale — the clawback shortfall today "stays
open on the row for manual handling" with no report, and the advance shortfall is
a sentence of English prose (BR-PAY-023, DEF-P19).*

### XVIII.5.4 The minimum net-pay floor

**R-XVIII-070** A **minimum net-pay floor** MUST be configurable, scoped and
effective-dated, expressed as either an absolute `Money` or a percentage of a
declared basis, or the greater of both. *Test:* "net may never fall below the
greater of ₹5,000 and 30% of gross" is expressible and enforced, and changing it
changes only future runs.

```
net_floor_policy
  floor_kind_code   absolute | percent_of_basis | greater_of | lesser_of
  absolute_amount   Money
  percent_value     Number
  percent_basis_code  gross | net_before_recovery | wage_gross | statutory_minimum_wage
  applies_to_classes[]   which recovery classes the floor constrains
  exempt_classes[]       classes that may breach it (e.g. court_order)
  on_floor_breach_code   stop_recovering | flag_for_review | block_run
```

**R-XVIII-071** The floor MUST be applied by the orchestrator as a **stopping
condition across all recoveries**, not per recovery, and the trace MUST name the
floor that stopped it. *Test:* with three recoveries due and a floor reached
after the first, the trace names the floor, the remaining two show a shortfall
with reason `net_floor`, and the shortfall dispositions follow each type's policy.
*Rationale — SmartPRS 2 enforces only `net >= 0` (`:1350`, `:1393`), which is not
a policy, and the analysis lists a configurable floor as requirement 35.*

**R-XVIII-072** Certain recovery classes MUST be declarable as **exempt** from the
floor, because some statutory or court-ordered deductions are not the employer's
to withhold. Whether a given class is exempt is **configuration referencing the
statutory pack**, never asserted in this document. *Test:* an exempt class
recovers below the floor and the payslip states that it did.

### XVIII.5.5 Everything writes to the run-keyed ledger

**R-XVIII-073** Every application in the plan — full, partial or zero — MUST
produce, inside the run transaction, exactly: one payslip line (where the amount
is non-zero), one ledger entry keyed to the run, the payslip and the line, and
one schedule-line update where a schedule exists. A zero application produces a
shortfall record and no ledger entry. *Test:* M2 holds for every payslip in the
run.

**R-XVIII-074** An **advance** recovery MUST write a ledger entry exactly as a
loan recovery does. *Test:* after a run, the advance's ledger shows the recovery
keyed to the run, and superseding the run reverses it. *Rationale — this is the
single most consequential missing artefact in SmartPRS 2's recovery handling:
"Advances have **no recovery ledger** — nothing records which run recovered how
much, so a regenerate cannot reverse an advance recovery" (BR-PAY-033;
`M-…` C-12).*

### XVIII.5.6 Reversal on run regeneration

**R-XVIII-075** Superseding a payroll run MUST reverse **every** ledger entry
keyed to that run, by compensating entry, **inside the transaction that creates
the superseding run version** — never before it, never after it, never
best-effort. *Test:* an injected failure during regeneration leaves the prior run,
its payslips and its ledger entries wholly intact. *Rationale — SmartPRS 2
hard-deletes the prior run's payslips **outside any transaction**, minutes before
the replacement transaction opens, and runs four reversal blocks each in its own
swallowing `try/catch`; a crash in between leaves the month with no payslips at
all and no way back (`M-…` C-3, CRITICAL; `A-payroll.md` §5 Q7).*

**R-XVIII-076** Regeneration MUST **version, not destroy**. The prior run, its
payslips, its line statuses, its approval stamps and its rejection remarks
survive. *Test:* after regeneration, the prior run version is fully retrievable,
including who rejected it and why. *Rationale — `A-payroll.md` §10 requirement 41
and DEF-P18: today the run and its payslips are hard-deleted and only an
`activity_logs` row survives, recording the *replaced* run id and not the
rejection.*

**R-XVIII-077** Reversal MUST restore the obligation's derived state exactly:
schedule lines return to their prior status, a closed obligation re-opens, and a
converted receivable reverts — all as consequences of the ledger, not as separate
counter arithmetic. *Test:* generate → close a loan on its final EMI → regenerate
→ the loan is `recovering` with exactly one instalment outstanding, and the ledger
shows the recovery and its reversal. Regenerate twice: the loan is still
`recovering`, not `sanctioned`. *Rationale — `M-…` C-3: two concurrent
regenerates decrement `installments_paid` twice and re-open a correctly closed
loan.*

**R-XVIII-078** Reversal MUST NOT touch any entry that the run did not create.
*Test:* a manual repayment recorded between the two runs survives regeneration
untouched. *Rationale — SmartPRS 2's reversal deletes any `commission_payments`
row whose free-text `reference` matches `LIKE 'run #<id> %'`, including a manual
passbook entry that happens to match (`M-…` C-13).*

### XVIII.5.7 Worked orchestration

Tenant order: `statutory(1) → court_order(2) → loan(3) → advance(4) →
clawback(5) → other_recovery(6)`, tie-break `schedule_due_date`.
Floor: `greater_of(₹5,000.00, gross % 30)`.
Employee: gross ₹45,000.00, statutory deductions ₹4,200.00, net before recovery
₹40,800.00. Floor = greater of ₹5,000.00 and ₹13,500.00 = **₹13,500.00**.
Recoverable headroom = ₹40,800.00 − ₹13,500.00 = **₹27,300.00**.

Due: loan instalment ₹7,061.02 (`shortfall = partial`); advance ₹20,000.00
(`shortfall = carry_forward`); clawback ₹9,500.00 (`shortfall = carry_forward`).

```
headroom 27,300.00
loan      due 7,061.02  → take 7,061.02   headroom 20,238.98   schedule line 13 recovered
advance   due 20,000.00 → take 20,000.00  headroom    238.98   advance closed
clawback  due 9,500.00  → take   238.98   headroom      0.00   partial; residue 9,261.02
                                                              carried, reason net_floor
net       40,800.00 − 27,300.00 = 13,500.00   ← exactly the floor
```

Trace rendered by `Explain(payslip, net)`:

```
Recovery plan            order "Standard Recovery Order" v3 (eff. 01-Apr-2026 →)
Net before recovery      ₹40,800.00
Floor                    greater_of( ₹5,000.00 , gross 45,000.00 × 30% = ₹13,500.00 )
                         = ₹13,500.00   [policy "Net Floor — Collections" v2]
Headroom                 ₹27,300.00
1 Loan SALARY-LOAN #L-4412   due ₹7,061.02  taken ₹7,061.02  (instalment 13 of 24)
2 Advance SALARY-ADVANCE #A-901 due ₹20,000.00 taken ₹20,000.00  (closed)
3 Clawback CB-2210          due ₹9,500.00  taken ₹238.98
                            stopped by: net floor
                            residue ₹9,261.02 carried to 2026-10 [policy carry_forward]
Net pay                  ₹13,500.00
```

Every line of that trace is derived from stored fields. None of it is parsed from
prose.

---
## XVIII.6 The Commission Scheme Builder — master-brief Section 52

### XVIII.6.1 What a scheme is

A **commission scheme** is a published configuration artefact that turns
*achievement* into *money*, for a defined audience, over a defined period, with
declared caps, accelerators, clawback rules and approval. It is the money side of
the collections vertical, and it is the artefact SmartPRS 2 comes closest to
having and furthest from delivering: the scheme engine supports only
`base × rate / 100`, `fixed` or `open` (BR-PAY-034), while the *unsaved,
request-scoped* bulk calculator supports slabs and per-portfolio rates and throws
them away after every batch (`IncentiveController::calculate():38–69`).

**R-XVIII-079** A commission scheme MUST be a first-class, named, versioned,
scoped, effective-dated configuration artefact — authored once and reusable every
period — never a set of parameters posted with a calculation request.
*Test:* running last month's scheme again for this month requires selecting the
scheme and supplying achievement data, and nothing else.
*Rationale — `A-payroll.md` §9: the bulk calculator is "a good shape but is
request-scoped and unsaved — no reusable named rule".*

**R-XVIII-080** Scheme money MUST be computed **server-side from the published
scheme version**, never accepted from the client, for every basis and every rate
type. *Test:* posting an `amount` on a claim is rejected as an unknown field
(R-XVIII-006). *Rationale — this is the one thing SmartPRS 2 gets right
(BR-PAY-034, `SchemeController::validateClaim():243–295`) and it is preserved
absolutely.*

### XVIII.6.2 The scheme definition

```
cfg_commission_schemes  (payload of a config_versions row, artefact_type = commission_scheme)

  scheme_code, name, description
  scope, priority, effective_from, effective_to        (spine S4)
  audience_expr            SPX Boolean over employee.*, org.*, service.*
  audience_kind_code       individual | team | both
  period_kind_code         payroll_period | calendar_month | quarter |
                           financial_year | scheme_window | rolling(n,unit)

  basis:
    basis_code             recovery | sales | revenue | collection | target |
                           achievement | cases | units | custom_kpi
    basis_measure_key      which measure feeds it (a declared KPI)
    basis_expr             SPX Money|Number — how the figure is derived
    basis_source_code      bulk_upload | claim | connector | derived_from_module
    qualification_expr     SPX Boolean — which rows count at all

  threshold:
    threshold_kind_code    none | absolute | percent_of_target | rank
    threshold_expr         SPX
    threshold_effect_code  pay_nothing | pay_from_threshold | pay_on_full

  components: ordered list, each
    component_kind_code    percentage | fixed_per_unit | fixed_amount |
                           slab_percentage | slab_fixed | formula
    slab_table_ref         → cfg_slab_tables (§XVIII.6.3)
    rate_expr / amount_expr  SPX
    applies_when_expr      SPX Boolean
    salary_component_code  the payslip earning it becomes

  accelerators: ordered list, each
    trigger_expr           SPX Boolean (e.g. achievement >= 110)
    effect_code            multiply | add_percentage_points | add_fixed | replace_rate
    effect_expr            SPX
    stacking_code          first_match | all_match | highest_only

  caps: ordered list, each
    cap_scope_code         per_transaction | per_claim | per_employee_period |
                           per_team_period | per_scheme_period
    cap_expr               SPX Money
    on_cap_code            truncate | truncate_and_flag | block

  clawback:
    enabled                bool
    trigger_event_keys[]   e.g. recovery.reversed, invoice.credit_noted
    window_expr            SPX Days
    attribution_code       recompute | effective_rate | marginal_top_band | stated
    obligation_type_code   the clawback obligation created

  team:
    split_basis_code       equal | weighted_by_measure | weighted_by_role |
                           declared_weights
    weights_ref            → cfg_commission_team_weights
    leader_share_expr      SPX

  payout:
    settlement_channel_code  payroll | payout_batch
    payout_lag_periods_expr  SPX Number     (the retention guard)
    payout_calendar_ref
    approval_workflow_key
    tds_head_code            → statutory pack head (e.g. 194H)
    gate_profile_code        → §XVIII.9

  gst:
    is_vendor_billable       bool           (off-roll / vendor payees)
    sac_code, place_of_supply_source_code
```

**R-XVIII-081** Every one of the nine `basis_code` values MUST be supported, and
the set MUST be extensible by adding a declared KPI, not by code. *Test:* a
tenant adds a `custom_kpi` "verified field visits" through configuration and
builds a scheme on it. *Rationale — SmartPRS 2's bulk calculator supports exactly
three bases (`collected | target | manual`) and three formulas
(`flat | slab | portfolio`), all six as literal strings (NEW-PAY-40).*

**R-XVIII-082** A scheme's audience MUST be an SPX expression plus scope, and the
**employee list MUST be a relation**, never a delimited string.
*Test:* an audience of 400 named employees is stored as 400 rows and is
queryable. *Rationale — `incentive_schemes.employee_ids` is a comma-separated
list of ids in a `TEXT` column (`A-payroll.md` §2.10; Part XXVIII R-XXVIII-008).*

**R-XVIII-083** A scheme MUST NOT be stored in a table that carries a second,
incompatible schema for the same entity. *Test:* `migrate:fresh` produces one
scheme table with one shape. *Rationale — `incentive_schemes` has two disjoint
shapes in one table, and `SchemeController::ensure():29–131` creates it at
request time, heals each column individually, relaxes legacy `NOT NULL` columns
and widens an ENUM to VARCHAR — and `save()` writes both `title` and `name` with
the same value purely to satisfy a legacy column (`A-payroll.md` §2.10). Law 2
forbids every part of this.*

### XVIII.6.3 Slabs

Slab tables are their own artefact (`cfg_slab_tables`), reusable across schemes,
formulas and the statutory pack's `SLAB()` function (spine S5.4).

```
cfg_slab_tables
  slab_table_code, name, measure_type (Money | Number | Percent)
  computation_code   marginal | whole_amount_at_matched_rate | stepped
  bands: [ { band_no, from_value, to_value (NULL = open),
             rate_percent | fixed_amount | formula_ref } ]
```

**R-XVIII-084** A slab table MUST declare whether it is **marginal** (each band
prices only the portion falling in it) or **whole-amount** (the matched band's
rate applies to the entire figure). There is no default. *Test:* the same figure
against the same bands produces two different, exactly asserted results under the
two settings.

**R-XVIII-085** Slab bands MUST be validated at publish: ascending, contiguous,
non-overlapping, exactly one open-ended top band, and no gap. Publication is
blocked otherwise. *Test:* a table with a gap between ₹5,00,000 and ₹5,00,001 is
refused with both bands named. *Rationale — SmartPRS 2's slab walk picks the
first band with `upto > 0 && figure <= upto` and, failing that, **silently falls
back to the last band's rate** because of the loop's pre-initialisation. A figure
above every band is therefore priced at the top rate by accident, and nothing
validates band ordering anywhere (`payoutFor():299–344`; `A-payroll.md` §4.12,
DEF-P22).*

### XVIII.6.4 Thresholds, accelerators and caps

**R-XVIII-086** A threshold MUST declare its effect: pay nothing below it, pay
only on the excess above it, or pay on the full figure once it is crossed.
*Test:* the three effects are exactly asserted on the same inputs.
*Rationale — SmartPRS 2's only threshold is `achievement >= threshold` with a
default of 100, and its only effect is "payout 0" (NEW-PAY-40).*

**R-XVIII-087** Accelerators MUST be an ordered list with a declared stacking
rule, and the applied accelerator MUST appear in the trace by name.
*Test:* two accelerators both true under `highest_only` apply one, named.

**R-XVIII-088** Caps MUST be declarable at five scopes (per transaction, per
claim, per employee-period, per team-period, per scheme-period) and MUST declare
their behaviour on breach. *Test:* a per-scheme-period budget cap of ₹5,00,000
across 40 agents truncates the last-processed agents deterministically under a
declared allocation rule, and the trace names the cap.
*Rationale — SmartPRS 2 has only per-person `max_claims` and `max_amount`, and
checks them against **gross** and against **all non-rejected claims including
still-pending ones** (BR-PAY-035, `capLeft():218–236`) — a defensible choice that
must nonetheless be configurable, since it means a pending claim blocks a
colleague's approved one.*

### XVIII.6.5 Team versus individual, and branch rules

**R-XVIII-089** A scheme MUST be able to compute at team level and split to
individuals by a declared basis (equal, weighted by the individual's own measure,
weighted by role, or declared weights), with the split using `ALLOCATE` so the
parts sum exactly to the team amount. *Test:* a ₹1,00,000.01 team pool split
three ways sums to ₹1,00,000.01 exactly, with the residue on the last member by
the declared ordering.

**R-XVIII-090** A scheme MUST be scopeable by any dimension in spine S3.1 —
branch, zone, state, client process, cost centre, grade, designation — and where
two schemes both match an employee, spine S3.2 precedence MUST decide, with
publication blocked on an unresolvable tie. *Test:* an employee matching a
branch-scoped and a company-scoped scheme resolves to the branch-scoped one, and
the trace shows the specificity arithmetic. *Rationale — SmartPRS 2's scheme
audience is `applies_to` plus `team` plus a CSV of employee ids, with no
precedence model at all.*

**R-XVIII-091** The **client / bank / portfolio MUST be a real entity** with an
id, not a free-text string, and a scheme's per-portfolio rates MUST reference it
by id. *Test:* renaming a portfolio does not change any historical commission's
attribution. *Rationale — "There is no bank master. The bank is the free-text
`portfolio` string carried on each commission entry, and `BankPackController`
groups by it" (`N-…` §1.8.1). Portfolio-rate matching today is a
case-insensitive exact string match, else a rate of 0 (NEW-PAY-40).*

### XVIII.6.6 Achievement data: bulk upload, validation and preview

**R-XVIII-092** Achievement data MUST enter through a **defined import** with a
declared column mapping, per-row validation, a preview showing computed amounts
before commitment, an explicit commit, and a per-row outcome report.
*Test:* a 1,000-row file with 3 bad rows previews 997 payable rows and 3 named
errors, and committing writes 997 obligations and no partial rows.

**R-XVIII-093** No row may be **silently dropped** at any stage of the import or
the calculation. Every excluded row MUST appear in the outcome report with a
reason code. *Test:* a row whose computed amount is zero, whose employee is
ineligible, whose gate blocks, or whose points fall below the threshold appears in
the report with its reason. *Rationale — SmartPRS 2 drops a row whose net after
TDS is ≤ 0 with a bare `continue` (`commit():125–132`), skips a DRA-blocked row,
and skips a below-points row (`:185–216`), all silently from the operator's
point of view except for a summary count.*

**R-XVIII-094** The preview MUST show, per row: the basis figure, the matched
band(s), the component amounts, the accelerator applied, the cap applied, the
gross, the statutory deduction at the applicable head, the net, the settlement
period, and every gate verdict. *Test:* the preview's per-row gross equals the
committed obligation's gross, exactly, for every row.

**R-XVIII-095** Bulk-created obligations MUST route through the **same approval
workflow** as singly created ones. *Test:* a 1,000-row bulk commit of large
claims produces instances that escalate identically to single claims.
*Rationale — SmartPRS 2's bulk upload passes `threshold = 0` explicitly
(`RequestController.php:687`), so a bulk-loaded commission **never escalates,
even in principle** — carrying that forward would mean a 1,000-row file of large
claims bypasses every amount control (`E-…` §4.2; Part IX §IX.19.2).*

### XVIII.6.7 Gross, TDS and the payslip

**R-XVIII-096** A commission obligation MUST store its **gross** as the principal
amount. Statutory deduction at the applicable head is a **separate deduction
line** computed by the statutory engine from the resolved rule pack — never
netted into the stored amount. *Test:* the payslip shows the gross as an earning
and the deduction as a deduction, and the taxable base includes the gross.
*Rationale — `commissions.amount` today holds the **NET after TDS**, and that net
is what payroll, Live Salary and the approver threshold all consume, while
`gross_amount` is a runtime-added column that may be absent (`E-…` §4.1;
`A-payroll.md` §2.9, §4.12). The consequence is that the approval threshold sees
a post-tax figure and the taxable base sees nothing at all.*

**R-XVIII-097** The rate, the head, the no-PAN treatment and the threshold for
any withholding on a commission payout MUST come from the **signed statutory rule
pack** (spine S12), resolved for the period, with the pack version on the trace.
No rate may be a default in code. *Test:* changing the pack changes new
computations only, with no deployment. *Rationale — the 194H rate is the literal
`5.0` in three places, the per-tenant `comm_tds_rate` setting is not read on the
claim path, and there is **no no-PAN higher rate on this path** although the 24Q
register has one (`IncentiveController.php:217–221`; `SchemeController.php:818`,
`:848`; `A-payroll.md` §5 Q8 item 23). **No rate is asserted in this document.**
The value SmartPRS 2's code currently uses is recorded as 5.0 and must be
verified against its statutory source before a pack is signed.*

**R-XVIII-098** Whether a commission enters the PF, ESI, PT, bonus, gratuity,
minimum-wage and taxable bases MUST be declared per obligation type as flags
resolved against the pack (R-XVIII-016). *Test:* a scheme declared ESI-bearing
increases the ESI contribution. *Rationale — BR-STAT-006: today commission,
incentive, OT, night allowance and arrears are added after the slip is computed,
so **none of them attracts PF, ESI, PT or TDS** — which the analysis flags as a
live compliance exposure.*

**R-XVIII-099** Which period pays a commission MUST be decided by an explicit,
stored `settlement_period_code`, set at approval from the scheme's payout lag and
calendar. An obligation with no settlement period is a **validation error at
approval**, not a silent orphan. *Test:* approving without a resolvable
settlement period is refused. *Rationale — BR-PAY-018: with no `payout_date` and
no `cycle_month`, **no payslip ever pays it** — the money is simply lost in the
table.*

### XVIII.6.8 Lock, reopen and the payment ledger

**R-XVIII-100** Including a commission obligation in a run MUST, in the run's own
transaction, write a `payout` ledger entry keyed to the run, the payslip and the
line, and set the obligation to `paid`. There is no separate "lock" flag: the
ledger entry **is** the lock, and the uniqueness constraint of R-XVIII-009 makes
double payment impossible at the database. *Test:* two concurrent generates
produce exactly one payout entry. *Rationale — SmartPRS 2 sets `locked_at` and
inserts a passbook debit **after** commit; two concurrent generates both read
`locked_at IS NULL`, both lock and **both insert a debit**, so the passbook shows
the commission paid twice (`M-…` C-13).*

**R-XVIII-101** A partially paid commission's balance MUST be derived from its
ledger, and any payment against it MUST be applied under a **row lock**, with the
over-payment refusal enforced by the balance read inside that lock.
*Test:* two simultaneous ₹10,000 payments against a ₹10,000 balance result in one
accepted and one refused. *Rationale — `M-…` C-7: today both read
`paid = 40,000`, both compute a ₹10,000 balance, both insert, and ₹60,000 is
recorded against a ₹50,000 commission, with the same `sum()` as the only balance
source so nothing ever detects it.*

**R-XVIII-102** There MUST be **no tolerance constant** in a money comparison. A
payment is refused when it exceeds the balance by any amount. *Test:* a payment
of balance + 1 paise is refused. *Rationale — the literal `0.005` appears twice
in the payment path, once as an over-payment allowance and once as the auto-lock
trigger, so a payment of ₹500.004 against a ₹500.00 balance is accepted and locks
the commission forever with a balance of −₹0.004 (`E-…` §4.6).*

**R-XVIII-103** Reopening a paid obligation MUST create a **new, linked
obligation or a compensating entry**, and MUST NOT erase the decision or the
movement. *Test:* no surface sets `status = 'pending'`, `decided_by = NULL`,
`decided_at = NULL` on a decided record. *Rationale — `RequestController.php:
1403–1410` does exactly that, and Part IX §IX.19.2 already refuses to carry it.*

**R-XVIII-104** Correcting a scheme's rate after claims exist MUST be an explicit,
previewed, approved **recalculation run** producing a diff and a set of
adjustment obligations — never an in-place re-pricing of existing records.
*Test:* editing a live scheme's rate shows an impact preview naming the affected
claims and the rupee delta, and commits adjustments, leaving originals intact.
*Rationale — today editing a live scheme **retroactively re-prices every unlocked,
non-rejected claim and sends approved ones back to Pending** (BR-PAY-036,
`recalcClaims():793–862`) — a strong policy choice made silently, and one that
cannot be reconciled afterwards.*

**R-XVIII-105** Withdrawing or reinstating a scheme MUST act on stored state and
identifiers, never on a substring of human-typed text. *Test:* reinstating a
withdrawn scheme restores exactly the claims it rejected, identified by id.
*Rationale — BR-PAY-711: reinstatement today matches
`remarks LIKE '%withdrawn%'` on a free-text field a human may edit.*

### XVIII.6.9 Clawback

**R-XVIII-106** A scheme MUST be able to declare that a named domain event within
a declared window **claws back** commission already computed or paid, creating a
clawback obligation of a configured type, routed through the recovery
orchestrator like any other. *Test:* a reversed recovery within the window
produces a clawback obligation of the exact attributed amount.

**R-XVIII-107** The clawback **attribution method** MUST be declared:
`recompute` (re-evaluate the whole scheme run excluding the reversed measure and
claw back the difference), `effective_rate`, `marginal_top_band`, or `stated`.
*Recommendation: `recompute` as the seeded default*, because it is exact and
defensible to the employee, and because it correctly withdraws an accelerator the
reversed amount had earned. *Test:* the four methods produce four exactly
asserted amounts on the same inputs.

**R-XVIII-108** A clawback MUST carry the **settlement period** it is to be
recovered in, and that period MUST be stored, not inferred. *Test:* a clawback
created after the current run is finalised targets the next open period, and the
stored period proves it. *Rationale — `E-…` DEF-REQ-04, HIGH: the bounce-generated
clawback's `cycle_month` is **dropped by `safeRow()`** because the column is
`month`, and its `fin_year` is computed at `:1784` and never used, so the payroll
engine must find an approved clawback that has **no target month and no financial
year at all**.*

### XVIII.6.10 Worked example — a debt-recovery agency

**Scheme** `COLL-RECOVERY-2026`, effective 01-Apr-2026 →, scope
`business_unit = Collections`, audience `designation IN [Recovery Officer,
Senior Recovery Officer]`, period `calendar_month`, basis `recovery` measured by
`amount_recovered_verified`, qualification `recovery.status = 'realised'`.

**Slab table** `COLL-SLAB-A`, `computation = marginal`:

| Band | From | To | Rate |
|---|---|---|---|
| 1 | ₹0 | ₹5,00,000 | 1.00% |
| 2 | ₹5,00,001 | ₹10,00,000 | 1.50% |
| 3 | ₹10,00,001 | open | 2.00% |

**Accelerator** `trigger: achievement >= policy.accel_threshold_percent (110)`,
`effect: multiply by policy.accel_factor (1.25)`, `stacking: highest_only`.
**Cap** `per_employee_period: salary.gross % policy.commission_cap_percent (50)`,
`on_cap: truncate_and_flag`.
**Clawback** `trigger: recovery.reversed`, `window: 90 days`,
`attribution: recompute`.

Agent R. Krishnan, September 2026. Monthly gross ₹40,000.00. Target
₹10,00,000.00. Verified realised recoveries ₹12,40,000.00.

```
basis            ₹12,40,000.00                    [measure amount_recovered_verified]
achievement      12,40,000 / 10,00,000 × 100 = 124.00 %
threshold        none configured                  → proceed
slab COLL-SLAB-A marginal
   band 1        ₹5,00,000.00 × 1.00%  = ₹5,000.00
   band 2        ₹5,00,000.00 × 1.50%  = ₹7,500.00
   band 3        ₹2,40,000.00 × 2.00%  = ₹4,800.00
   base                                  ₹17,300.00
accelerator      124.00 >= 110 → multiply 1.25
                 ₹17,300.00 × 1.25     = ₹21,625.00
cap              salary.gross ₹40,000.00 × 50% = ₹20,000.00
                 ₹21,625.00 > ₹20,000.00 → truncate_and_flag
GROSS COMMISSION                          ₹20,000.00   (capped, flagged)
```

The obligation is created with `principal_amount = ₹20,000.00` (the **gross**),
`settlement_period = 2026-10` (scheme payout lag 1), gate profile
`COLLECTIONS-PAYOUT`. On the October payslip:

```
Earning  Recovery Commission                     ₹20,000.00
Deduction  Withholding, head 194H                 ₹1,000.00
           rate  statutory.tds_194h_rate = 5.0 %
                 [pack IN-DIRECT-TAX v2026.3; recorded as the value SmartPRS 2's
                  code currently uses — pending compliance-owner verification]
```

The gross ₹20,000.00 enters the taxable base and every base the type declares.
Nothing is netted into the stored amount.

**Clawback.** On 12-Nov-2026, a ₹2,00,000.00 recovery inside that month's set is
reversed — 47 days after realisation, inside the 90-day window. Attribution
`recompute`:

```
recompute with basis ₹10,40,000.00
   band 1  ₹5,00,000.00 × 1.00% = ₹5,000.00
   band 2  ₹5,00,000.00 × 1.50% = ₹7,500.00
   band 3  ₹40,000.00   × 2.00% =   ₹800.00
   base                            ₹13,300.00
   achievement 104.00 % < 110     → accelerator NOT earned
   cap ₹20,000.00                 → not binding
   commission that should have been paid  ₹13,300.00
   commission actually paid               ₹20,000.00
   CLAWBACK                               ₹6,700.00
```

A clawback obligation of ₹6,700.00 is created, type `COMMISSION-CLAWBACK`,
settlement period 2026-12 (November's run being already finalised), shortfall
policy `carry_forward`, and it enters the recovery orchestration at its
configured priority. Note that `recompute` correctly withdraws the accelerator
the reversed recovery had earned — an outcome no per-transaction attribution
method reaches.

The employee's ESS and the explain trace show the original computation, the
reversal event, the recomputation and the difference, as one timeline.

---
## XVIII.7 The Incentive Engine — master-brief Section 53

### XVIII.7.1 Incentive versus commission

They share the model and differ in one declared property: a **commission** is
priced from a *quantity* (money recovered, units sold, cases closed) by a rate
structure; an **incentive** is priced from the *occurrence of a qualifying
condition* (perfect attendance, a passed assessment, a tenure milestone, a
quality score above a bar), usually as a fixed or formula amount.

They are therefore **the same artefact type with a different amount source**, and
both produce obligations of direction `pay_to_employee`. SmartPRS 3 does not build
two engines. The distinction survives only as a `scheme_kind_code`
(`commission | incentive`) that drives labelling, reporting and default
configuration — never behaviour.

**R-XVIII-109** Commission and incentive MUST share one engine, one artefact
shape, one obligation model and one ledger, distinguished only by a declared kind
and by which amount sources they use. *Test:* a single service computes both; no
`if (kind === 'incentive')` exists in a domain path.
*Rationale — SmartPRS 2's only distinction between the two is the literal purpose
label written into `commissions.purpose`: `'Incentive'` when a request's type
selector says incentive, else `'Commission'` (NEW-PAY-43,
`IncentiveController.php:87–90`). The distinction is a string, and the string is
what the payslip groups on.*

### XVIII.7.2 Trigger types

An incentive policy declares a **trigger**: what makes the incentive arise.

| `trigger_kind_code` | Source | Typical condition |
|---|---|---|
| `attendance` | attendance settlement | zero LOP, zero late instances, present days ≥ N |
| `performance` | performance module | rating ≥ band, goal achievement ≥ % |
| `sales` | sales measure | units, value, new accounts |
| `recovery` | collections measure | amount recovered, cases closed, PTP kept |
| `quality` | quality module | audit score, error rate, CSAT |
| `target` | any declared KPI | achievement against a target |
| `tenure` | service | completed service milestone |
| `custom_event` | any published domain event | an event key plus an SPX predicate |

**R-XVIII-110** The trigger set MUST be extensible by registering a **declared
KPI or a published domain event**, not by adding a code branch. *Test:* a tenant
creates an incentive triggered by the event `training.certification.passed` with
no deployment.

**R-XVIII-111** An incentive policy MUST be expressed as an ordinary rule-engine
rule (spine S6): `when` (SPX Boolean), `then` (typed actions), `except`,
`on_conflict`, scope, priority, effective range, version. The action that pays is
`CREATE_OBLIGATION(type, amount_expr, settlement_ref)` — the money-movement
member of the closed action catalogue. *Test:* the rule simulator runs an
incentive policy against a named employee and a date and shows the full trace
before publication.

**R-XVIII-112** An incentive amount MUST be a formula (spine S7), not a stored
constant, where the customer could want it to vary — which is almost always.
*Test:* "₹1,000 flat, but ₹1,500 for night-shift staff" is one formula, not two
policies.

### XVIII.7.3 Why the keyword-bucketing defect is structurally impossible

SmartPRS 2's points module classifies a rule by **substring-matching its
free-text `event` name**, first match wins, in the fixed order
`late → absent → award|reward → test|pass|exam|assessment →
perfect|present|attendance` (`PointsController.php:56–69`; `H-…` BR-REC-036).
The analysis states the consequence directly:

> a rule named **"Perfect attendance — no lates"** is classified as a **LATE
> PENALTY**

— because the token `late` appears in the word `lates` and is tested first. The
rule's point value is then multiplied by the employee's *late count*
(`PointsController.php:94–124`), so a reward configured by an administrator
becomes a penalty scaled by exactly the behaviour it was meant to reward.

In SmartPRS 3 this cannot occur, for four independent structural reasons:

1. **A rule's name is a label, never a selector.** What a rule *does* is its
   typed `then` action list, authored explicitly. Nothing anywhere reads a name
   to decide behaviour (Law 1, and R-XVIII-002's targeted grep).
2. **The condition is an expression over a typed symbol table**, validated at
   publish against declared namespaces. `attendance.late_minutes` and
   `agg.count('late_arrival', …)` are symbols; the letters `l-a-t-e` inside a
   human label are not addressable by the engine at all.
3. **The action is typed and directional.** `CREATE_OBLIGATION` with a
   `pay_to_employee` type cannot become a deduction. A penalty is a different
   action against a different type; misclassification cannot flip the sign.
4. **Publication runs the rule's stored test cases** (spine S6). A policy
   asserting "employee with 3 lates → ₹0; employee with 0 lates → ₹1,000" fails
   to publish if the engine computes the inverse.

**R-XVIII-113** No engine in SmartPRS 3 may derive behaviour from the text of a
name, label, description, remark, purpose or reason field. *Test:* a CI rule
fails the build on `str_contains`, `LIKE '%…%'` or a regex applied to any
user-authored name or free-text column inside `Domain\*`. *Rationale — this
single pattern produces the points misclassification (`H-…` BR-REC-036), the
reimbursement statutory carve-out (NEW-PAY-05), the payslip earning grouping
(NEW-PAY-30), the PF-base test `str_contains(name,'basic')`, the wage register's
substring column mapping (`ReportController:172–192`), the scheme reinstatement
match on `remarks LIKE '%withdrawn%'` (BR-PAY-711), and the commission reversal
match on `reference LIKE 'run #<id> %'` (`M-…` C-13).*

### XVIII.7.4 Worked example — perfect attendance

Policy `INC-PERFECT-ATTENDANCE`, scope `business_unit = Collections`, effective
01-Apr-2026 →, priority 100, `on_conflict = all_match`.

```spx
-- when
period.payable_days = period.working_days
AND agg.count('late_arrival', payroll_period,
              attendance.late_minutes > policy.late_grace_minutes) = 0
AND agg.count('absence', payroll_period, leave.is_paid = FALSE) = 0
AND service.months >= policy.incentive_min_service_months

-- except
employee.stage IN policy.incentive_excluded_stages

-- then
CREATE_OBLIGATION(
    type        : 'INC-ATTENDANCE',
    amount      : IF( shift.is_night ,
                      policy.perfect_attendance_night_amount ,
                      policy.perfect_attendance_amount ),
    settlement  : period_plus(policy.incentive_payout_lag_periods) )
```

Stored regression cases carried on the rule, executed at publish:

| Case | Inputs | Expected |
|---|---|---|
| 1 | payable 26 of 26, 0 lates over grace, 0 unpaid absences, 14 months' service, day shift | one obligation, ₹1,000.00, settlement 2026-10 |
| 2 | payable 26 of 26, **3 lates over grace** | **no obligation** |
| 3 | payable 25 of 26 | no obligation |
| 4 | perfect, but `stage = probation` (excluded) | no obligation |
| 5 | perfect, night shift | one obligation, ₹1,500.00 |

Case 2 is the exact scenario SmartPRS 2 inverts. Publication of a policy that
pays on case 2, or that produces a *deduction* on case 1, is refused because the
stored case fails.

The trace on the payslip line:

```
Perfect Attendance Incentive              ₹1,000.00
  rule       INC-PERFECT-ATTENDANCE v3 (effective 01-Apr-2026 →), priority 100
  condition  payable_days (26) = working_days (26)                       true
             late_arrivals over 15 min grace, this period = 0            true
             unpaid absences, this period = 0                            true
             service.months (14) >= 6                                    true
  except     stage 'confirmed' NOT IN ['probation','intern']              not applied
  amount     IF(shift.is_night = false) → policy.perfect_attendance_amount
             = ₹1,000.00  [policy "Collections Incentives" v5]
  settlement 2026-10  (earned 2026-09 + payout lag 1)
  statutory  taxable: yes · PF base: no · ESI base: yes   [type INC-ATTENDANCE]
```

### XVIII.7.5 Proof requirements and eligibility gates

**R-XVIII-114** An incentive policy MUST be able to require **proof** — a
document of a declared type, a verified compliance item, an approval from a named
role — before the obligation may be approved, and the requirement MUST be
enforced server-side. *Test:* approving without the required document is refused
with the document type named.

**R-XVIII-115** A **points or score threshold** MUST remain expressible as an
eligibility gate that never converts to money. *Test:* an incentive gated at
"≥ 60 points earned in the incentive month" excludes an employee at 59 and
reports the exclusion. *Rationale — BR-PAY-037: points never convert to money and
at most gate eligibility via `points_gate_min`. The analysis calls this "clean,
idempotent, and correctly never converts points to money" (`A-payroll.md` §9) —
it is preserved deliberately.*

**R-XVIII-116** The scoring inputs an incentive depends on — attendance, late
counts, working days — MUST come from the **attendance settlement of the period**
(spine S10 layer 4), not from a second attendance calculation.
*Test:* the incentive's late count equals the payslip's late count, always.
*Rationale — the points module carries a third, incompatible attendance
definition: a hard-coded 09:30 late cut-off with no grace, no shift and no late
policy (`PointsController.php:25`, `:164–168`), and a Sunday-only working-day
count regardless of the tenant's weekly-off configuration (`:259`), so approved
leave counts as an absence (`H-…` §4.5).*

### XVIII.7.6 Payout cycle and payroll integration

**R-XVIII-117** The payout lag MUST be a policy value in periods, expressible per
scheme and per scope, and MUST be applied by adding periods to the **earned
period**, resolved through the pay-cycle definition — never by setting a literal
calendar date. *Test:* a lag of 1 on a cut-off cycle settles into the correct
period, and a change of cut-off day does not retroactively move settled
obligations. *Rationale — SmartPRS 2 auto-fills a `payout_date` of "the 1st of
(earned month + N)" with N clamped 0..12 (NEW-PAY-42), which is a calendar date
that ignores the tenant's pay cycle entirely.*

**R-XVIII-118** An incentive settled through a **separate payout batch** rather
than payroll MUST still create the same obligation and the same ledger entries,
and MUST still be included in statutory bases and the employee's year-to-date
figures where the type declares it. *Test:* the annual taxable total is identical
whether an incentive is paid through payroll or a separate batch.
*Rationale — SmartPRS 2's `payout_method = 'separate'` simply excludes the entry
from the payslip, and nothing else accounts for it.*

**R-XVIII-119** An incentive or commission MUST be **prorated or not according to
its type's declared policy**, and the policy MUST be configurable per type.
*Test:* a joiner mid-period receives the configured treatment, asserted exactly,
under both settings. *Rationale — today commission and incentive are "added
after, never prorated" and the analysis marks this "No — by design" for
commission but leaves it non-configurable (`A-payroll.md` §4.2).*

**PRODUCT OWNER DECISION REQUIRED — D-XVIII-03: are commissions and incentives
prorated for mid-period joiners and leavers by default?**
*Recommendation:* **no proration by default** — a commission is earned on
performed work, not on elapsed time — with proration available as a per-type
setting for tenure-based and flat monthly incentives, where it is genuinely
expected. *Trade-off:* leaving it non-prorated matches current behaviour and
avoids re-pricing at cutover; making it prorated by default would silently reduce
every mid-month joiner's first commission and would surprise every existing
customer.

---

## XVIII.8 Reimbursements and expenses — master-brief Section 54

### XVIII.8.1 Position in the model

A reimbursement claim is an obligation of direction `pay_to_employee` whose
amount arises from a **claim against a category limit**, whose approval is a
workflow, and whose settlement channel is payroll or a dedicated reimbursement
run. It is the same object as everything else in this part.

What makes it distinct is that a reimbursement is frequently **not wages**, so its
statutory base flags typically differ from every other earning — and that
distinction must be a **declared flag**, never a substring.

**R-XVIII-120** Whether a reimbursement category is part of the wage gross, and
which statutory bases it enters, MUST be a declared flag set on the category,
resolved against the rule pack. *Test:* two categories with identical names and
opposite flags produce opposite statutory outcomes. *Rationale — NEW-PAY-05: a
component is treated as a reimbursement, and therefore carved out of the wage
gross and excluded from PF, ESI and TDS, when its **code or name contains the
substring `reimburs`**. A category named "Travel" is taxed; one named "Travel
Reimbursement" is not — the same money, decided by nine letters.*

**R-XVIII-121** "Gross" MUST have exactly **one** definition on a payslip, and
whichever definition is chosen MUST be used by the stored figure, the printed
figure, every register and every export. *Test:* `payslips.gross` equals the
printed gross, byte for byte, for every payslip in a run. *Rationale —
NEW-PAY-31: the printed "Gross Earnings (A+B)" **excludes** reimbursements while
the stored `payslips.gross` **includes** them, and both are labelled gross
(blade `:153` vs `computeSlipFromComponents():2329`).*

**R-XVIII-122** Payslip grouping (fixed / variable / reimbursement / statutory /
arrear) MUST come from the component definition's declared category. There is no
keyword fallback. *Test:* a component with no category cannot be published.
*Rationale — NEW-PAY-30: nine keywords decide the grouping when `category` is
unset (`payslipGroupEarnings():1591–1636`).*

### XVIII.8.2 Claim categories are dynamic

`cfg_expense_categories` — a K3 artefact, scoped, effective-dated, versioned.

| Setting | Type | Notes |
|---|---|---|
| `category_code`, `label`, `parent_category_code` | code, i18n, ref | categories nest (Travel → Air → Domestic) |
| `is_active`, `display_order` | | |
| `claim_form_fields_json` | field definitions | which fields this category captures, via the entity schema engine (spine S9) — **not** a fixed form |
| `amount_entry_code` | `actual` \| `per_unit` \| `slab_by_grade` \| `formula` | e.g. mileage per km, per-diem per night |
| `unit_rate_expr` | SPX Money | for `per_unit` |
| `limits[]` | see below | |
| `proof_requirement_code` | `none` \| `optional` \| `required` \| `required_above_expr` | |
| `proof_document_types[]` | refs | |
| `gst_invoice_required_code` | `none` \| `optional` \| `required` \| `required_above_expr` | |
| `gst_fields_required_json` | which GST fields must be captured | supplier GSTIN, invoice no, invoice date, taxable value, rate, CGST/SGST/IGST, place of supply, HSN/SAC |
| `is_input_credit_eligible` | bool | drives the tenant's own GST reporting |
| `taxability_code` | `not_taxable` \| `taxable` \| `taxable_above_expr` \| `per_rule_pack` | §XVIII.8.5 |
| `statutory_base_flags` | flag set | PF / ESI / PT / bonus / gratuity / minimum-wage / taxable |
| `cost_centre_source_code` | `employee_default` \| `claim_selected` \| `project` \| `client_process` \| `expression` | §XVIII.8.7 |
| `approval_workflow_key` | ref | amount-band routed |
| `settlement_channel_code` | `payroll` \| `reimbursement_run` \| `payout_batch` | |
| `advance_settlement_allowed` | bool | a travel advance may be settled by claims |
| `duplicate_detection_json` | which fields form the duplicate key | e.g. (supplier GSTIN, invoice no) |
| `claim_window_days_expr` | SPX Days | how late a claim may be submitted |
| `component_code` | ref | the payslip earning |

**R-XVIII-123** A tenant MUST be able to add a claim category, with its own
fields, limits, proof rules, taxability flags and approval path, entirely through
configuration. *Test:* Section 127 conformance — "Client entertainment, ₹2,500
per instance, ₹10,000 per month, GST invoice mandatory above ₹5,000, approved by
the Branch Head, taxable, cost centre from the client process" is created and
used with no deployment. *Rationale — SmartPRS 2's `expenses` table is
`id, tenant_id, employee_id, type, amount, date, status, approver_id, timestamps`
with `type` as free text and **no category master of any kind**
(`E-…` §2, `…000300…:131–141`).*

**R-XVIII-124** The claim's descriptive fields — reason, purpose, dates, place,
counterparty — MUST be declared per category by the entity schema engine and MUST
be **stored**. *Test:* a claim submitted with a reason has that reason retrievable
from the API and printed on the voucher. *Rationale — `E-…` DEF-REQ-01: today
`expenses.reason` is one of nine fields `safeRow()` silently drops. In the
analysis's own words, "An expense claim's reason — the whole point of the claim —
is never stored", and the user is told the request succeeded.*

### XVIII.8.3 Limits

A category may carry any number of limit rows. Each declares its **basis**, its
**window**, its **scope** and its **effect**.

```
limit
  limit_basis_code    per_claim | per_instance | per_day | per_week |
                      per_month | per_quarter | per_financial_year |
                      per_trip | per_project
  amount_expr         SPX Money
  count_expr          SPX Number        -- a cap on occurrences, not money
  scope               spine S3.1 scope  -- grade, designation, branch, location,
                                           employee_category, cost centre, employee
  on_breach_code      block | warn_and_allow | truncate_to_limit |
                      route_to_exception_approval
  exception_workflow_key
```

**R-XVIII-125** Limits MUST be resolvable **per grade, per designation, per
branch, per location, per employee category and per employee**, using spine S3.2
precedence, with an unresolvable tie blocking publication. *Test:* a Grade A2
employee in Hyderabad resolves the Hyderabad-specific limit over the grade limit,
and the trace shows the specificity arithmetic.

**R-XVIII-126** Limits MUST be enforced **at submission, at approval and at
settlement**, each time against the state at that moment, with the result traced.
*Test:* a claim within limit at submission but taking the employee over the
monthly limit at approval is caught at approval, with the earlier approved claims
named. *Rationale — SmartPRS 2 enforces no limit of any kind on an expense claim;
the only threshold in the path is a ₹10,000 approval escalation
(`ApprovalService.php:30`).*

**R-XVIII-127** Limit consumption MUST be computed from **approved and settled
claims within the window**, under a row lock at approval, so two simultaneous
approvals cannot both consume the last of a limit. *Test:* two concurrent
approvals of ₹6,000 claims against a ₹10,000 monthly limit result in one approved
and one routed by `on_breach_code`. *Rationale — `M-…` C-7's read-then-write
shape, which occurs three times in the money paths today.*

### XVIII.8.4 Proof and GST invoice requirements

**R-XVIII-128** A category MUST be able to require proof unconditionally or above
a stated amount, MUST declare the acceptable document types, and MUST refuse
submission without it. *Test:* a ₹6,000 claim in a category requiring proof above
₹5,000 cannot be submitted without an attachment of an accepted type.

**R-XVIII-129** Where a category requires a **GST invoice**, the declared GST
fields MUST be captured as structured, validated data — supplier GSTIN
(checksum-validated), invoice number, invoice date, taxable value, rate, tax
split, place of supply, HSN/SAC — and MUST be exportable for the tenant's own
input-credit reporting. *Test:* the input-credit register for a month reconciles
to the sum of the eligible claims' tax amounts, exactly.

**R-XVIII-130** The CGST/SGST versus IGST determination MUST come from **declared
place-of-supply data and the rule pack**, not from string-slicing a GSTIN, and
the rates MUST come from the pack. *Test:* changing the pack changes new
computations only. *Rationale — SmartPRS 2 decides the split by comparing the
first two characters of two GSTINs and hard-codes `0.09`/`0.09` and `0.18`
(`BankPackController::gstMode():116–127`, `invoiceSave():318–322`, NEW-PAY-44).
**No rate is asserted here**; the values SmartPRS 2's code currently uses are
recorded as 9/9 and 18.*

**R-XVIII-131** Uploaded proof MUST be stored on a private disk and served only
through authorised, signed, expiring URLs. *Test:* the document is unreachable by
direct path. *Rationale — off-roll agent documents — PAN, ID proof, address
proof, DRA and PCC scans — are stored on the **public** disk today and are
reachable at `/storage/offroll-docs/…` with no authentication wherever
`storage:link` has been run (`N-…` V-09).*

**R-XVIII-132** Duplicate claims MUST be detected on the category's declared
duplicate key and surfaced to the approver, never silently accepted or silently
rejected. *Test:* a second claim carrying the same supplier GSTIN and invoice
number is flagged, with the earlier claim linked.

### XVIII.8.5 Taxability

**R-XVIII-133** Each category MUST declare its taxability, and where the answer
depends on statute (a per-diem above a prescribed limit, a fuel allowance against
declared usage), the category MUST reference the **rule pack head** rather than
carry a number. *Test:* the taxable portion of a reimbursement changes when the
pack changes, with no configuration edit and no deployment.
**No taxability threshold or exemption limit is asserted in this document**
(brief hard rule 1).

**R-XVIII-134** Where a category is `taxable_above_expr`, the split into taxable
and non-taxable portions MUST produce **two payslip lines against two components**
with the split traced, not one line with a hidden adjustment. *Test:* the two
lines sum exactly to the claim, and the taxable line alone enters the taxable
base.

### XVIII.8.6 Settlement

**R-XVIII-135** A reimbursement MUST be settleable through payroll, through a
**separate reimbursement run**, or through a payout batch, chosen per category and
overridable per claim where the category permits — and all three MUST produce the
same obligation and the same ledger entries. *Test:* the employee's annual
reimbursement total is identical across the three channels.

**R-XVIII-136** A **reimbursement run** MUST be a first-class run type with its
own period, its own approval, its own reconciliation assertion and its own payout
batch, sharing the payroll run machinery of Part XIV. It MUST NOT require a
payroll run to exist. *Test:* a reimbursement run executes in a month with no
payroll run and reconciles exactly.

**R-XVIII-137** A travel or expense **advance** MUST be settleable by claims: the
claims reduce the advance obligation, and any unspent balance becomes a recovery
under the normal orchestration. *Test:* a ₹20,000 travel advance settled by
₹17,400 of approved claims leaves a ₹2,600 recovery, and the ledger shows the
whole story on one obligation.

### XVIII.8.7 Cost-centre attribution

**R-XVIII-138** Every claim MUST carry a **cost-centre attribution** resolved by
the category's declared source — the employee's default, a claim-selected value,
a project, a client process, or an expression — and the attribution MUST be
stored on the obligation and on every ledger entry, so cost reporting does not
depend on re-deriving the employee's org assignment later.
*Test:* an employee transferred between branches after the claim was approved
still reports against the cost centre attributed at approval.
*Rationale — spine S3: every employee-to-org assignment is effective-dated
precisely so "what was this employee's branch on 15 May?" is answerable;
attribution stores the answer at the moment it mattered.*

**R-XVIII-139** A claim MUST be splittable across multiple cost centres by
declared weights, with `ALLOCATE` guaranteeing the parts sum exactly to the whole.
*Test:* a ₹10,000.01 claim split 1/3 : 1/3 : 1/3 sums to ₹10,000.01.

**R-XVIII-140** Cost-centre attribution MUST flow to the general-ledger export
and to the payroll cost report as a stored dimension of each ledger entry.
*Test:* the GL export for a period reconciles to the sum of the period's ledger
entries by cost centre, exactly.

---
## XVIII.9 The compliance gate on money movement — the DRA gate, generalised

### XVIII.9.1 What is being preserved, and what is being generalised

Spine S19.1 names the DRA gate as the first thing the rebuild must not lose. The
analysis is unambiguous about why: it is *"the most sophisticated vertical rule in
the product"* and *"the product's only implemented compliance-to-money rule"*
(`N-…` §1.8.1, DISC-06).

Its shape is exactly right. Three of its variables are hard-coded.

| Variable | SmartPRS 2 | SmartPRS 3 |
|---|---|---|
| **Severity** | `off` \| `warn` \| `block`, per tenant, in Settings → Statutory Rates (`SettingsController.php:57`, `:154`) | preserved exactly, per gate policy, per scope |
| **Subject** | **DRA only.** The gate cannot look at PCC, BGV, agent authorisation or anything else (`N-…` §5 item 42) | any compliance item type, as a declared list |
| **Points of application** | **three literal call sites**: commission approval (`RequestController.php:1373`), bulk incentive commit (`IncentiveController.php:135`), off-roll earning approval (`OffrollAgentController.php:249–262`). Payroll checks nothing (`N-…` §1.4) | a declared set of gate points, any of which any policy may bind to |
| **Population** | employees only. `scan()`, `scoreFor()` and `draValid()` all key on `employees`; `offroll_agents` is read by none of them (`N-…` V-01) | any subject kind: employment, worker, vendor, team, org unit |
| **Validity** | for off-roll agents the gate can only detect a **blank** field, because `offroll_agents.dra` is free text with **no expiry column at all** (`N-…` V-02) | validity is a property of the compliance item (Part XXI), with `valid_from`/`valid_to` |

**R-XVIII-141** SmartPRS 3 MUST provide a **general compliance gate on money
movement**: a published, scoped, effective-dated policy declaring *which
compliance items* must be in *which states* for *which subjects* at *which money
events*, with a per-policy severity of `off` | `warn` | `block`.
*Test:* a tenant configures "block a commission payout when the agent's DRA
certificate is expired, and warn when the police clearance is pending" without a
deployment, and both verdicts appear on the payout preview.

### XVIII.9.2 The gate policy

`cfg_gate_policies` — already registered in Part XXVIII as a configuration
payload; this part specifies its money-movement use.

```
cfg_gate_policies
  gate_policy_code, name
  scope, priority, effective_from, effective_to      (spine S4)
  subject_kind_codes[]      employment | worker | vendor | team | org_unit
  gate_point_codes[]        §XVIII.9.3
  requirements: [ {
      compliance_item_type_code            → cfg_compliance_item_types
      required_state_expr                  SPX Boolean over the item
                                            (the item type owns "valid";
                                             this may narrow it)
      severity_code                        off | warn | block
      applies_when_expr                    SPX Boolean over the subject and the event
      grace_days_expr                      SPX Days  — block only after N days expired
      message_template_key
  } ]
  on_evaluation_error_code   block            -- fixed; see R-XVIII-146
  override_permission        verb.resource     -- who may override a warn or a block
  override_workflow_key      ref               -- when an override needs approval
  override_reason_required   bool (always true)
```

**R-XVIII-142** The definition of "valid" for a compliance item MUST live in
**one place** — the compliance item type — and the gate, the compliance score and
every compliance report MUST use it. *Test:* changing the valid-state set changes
all three together. *Rationale — SmartPRS 2 has three separate definitions of
"valid" behind the score, the gate and the report, and DRA requires `verified`
while PCC accepts `submitted`, asymmetrically and undocumented (`F-…` req. 34,
36; Part XXVIII `cfg_compliance_item_types.valid_state_codes_json`).*

**R-XVIII-143** A missing compliance record MUST NEVER evaluate as compliant.
*Test:* a subject with no DRA record at all is blocked by a `block`-severity DRA
requirement, exactly as an expired one is. *Rationale — Part XXVIII's
`missing_record_scores_zero`; and `N-…` V-05, where "Disciplinary actions" is
hard-coded to value `'0'`, state `'ok'` and therefore always passes.*

### XVIII.9.3 Gate points

The closed, versioned set of money events a gate may bind to. Extending it is a
platform release; binding to one is configuration.

| `gate_point_code` | Fires at | Blocked outcome |
|---|---|---|
| `obligation_sanction` | sanctioning a loan, advance or any obligation | sanction refused |
| `obligation_disbursement` | disbursing a loan or advance | disbursement refused |
| `commission_claim_approval` | approving a commission or incentive obligation | approval refused |
| `commission_bulk_commit` | committing a bulk achievement batch | the row is excluded **and reported** |
| `incentive_award` | a rule creating an incentive obligation | obligation not created; reason recorded |
| `reimbursement_approval` | approving a claim | approval refused |
| `payroll_run_approval` | approving a payroll run | run approval refused, per employee or per run |
| `payslip_inclusion` | including an obligation in a payslip | the line is omitted and the exception is listed |
| `payout_batch_release` | releasing a bank file or payout batch | release refused |
| `worker_earning_approval` | approving a non-employee worker's earning | approval refused |
| `expense_settlement` | settling a reimbursement run | settlement refused |

**R-XVIII-144** `payroll_run_approval`, `payslip_inclusion` and
`payout_batch_release` MUST be available as gate points. *Test:* a tenant gates
payroll approval on a compliance item and the run cannot be approved while any
in-scope employee fails at `block` severity. *Rationale — the business assumption
"a non-compliant agent cannot be paid" is stated by the product and **payroll does
not check DRA or PCC at all** (`N-…` §1.4). This is the single largest gap
between what the product claims and what it does.*

**R-XVIII-145** Every gate evaluation MUST write a `compliance_gate_decisions`
row recording the gate point, the subject, the mode in force, the verdict, **every
item evaluated and its state**, the policy version, and any override with its
actor and reason — so the decision is explainable months later.
*Test:* the decision for a payout six months ago is reproducible from the stored
row without re-reading current compliance data.

**R-XVIII-146** A gate MUST **never fail open**. An error inside evaluation
produces the verdict `error_blocked`, blocks, and raises an operator alert.
*Test:* fault injection inside the gate blocks the money event.
*Rationale — Law 5, and `F-…` req. 39. SmartPRS 2's `denyUnlessRole()` fails
open by design comment across 71 call sites (`A-payroll.md` SEC-P01).*

**R-XVIII-147** A `warn` verdict MUST be **surfaced and recorded**, not merely
logged: it appears on the approval screen, on the run preview, on the payout
batch, and on a standing exception report. *Test:* a warned payout appears on the
"paid despite a compliance warning" report for its period.
*Rationale — today `warn` "pays and lists it" (NEW-PAY-41), which is the right
behaviour and must be preserved with a durable record rather than a screen.*

**R-XVIII-148** An override of a `block` MUST require an explicit permission, a
mandatory reason, and — where the policy declares one — an approval workflow, and
MUST be recorded as an override on the gate decision. *Test:* an override without
a reason is refused; every override is on the exception report.

**R-XVIII-149** The gate MUST apply to **non-employee workers** — off-roll agents,
vendors — on the same terms as employees. *Test:* an off-roll agent with an
expired DRA is blocked at `worker_earning_approval` by the same policy that
blocks an employee. *Rationale — `N-…` V-01: off-roll agents are "the population
RBI norms most directly target" and are outside every compliance mechanism in
SmartPRS 2; `ComplianceController.php` contains no reference to `offroll_agents`
anywhere in the file.*

### XVIII.9.4 Worked gate

Policy `COLLECTIONS-PAYOUT`, scope `business_unit = Collections`, effective
01-Apr-2026 →.

| Requirement | Item type | Required state | Severity | Grace |
|---|---|---|---|---|
| 1 | `DRA-CERT` | valid on the event date | **block** | 0 days |
| 2 | `POLICE-CLEARANCE` | valid on the event date | **warn** | 30 days |
| 3 | `AGENT-AUTHORISATION` | valid for the claim's portfolio on the recovery date | **block** | 0 days |
| 4 | `CODE-OF-CONDUCT-ACK` | acknowledged at the current policy version | **warn** | — |

Bound to: `commission_claim_approval`, `commission_bulk_commit`,
`payslip_inclusion`, `payout_batch_release`, `worker_earning_approval`.

Evaluation for R. Krishnan's ₹20,000.00 October commission:

```
Gate COLLECTIONS-PAYOUT v2 · point payslip_inclusion · mode from policy
  DRA-CERT              IIBF/2024/114552  valid to 31-Mar-2027   PASS
  POLICE-CLEARANCE      expired 12-Sep-2026, 21 days             WARN
                        (grace 30 days; severity warn)
  AGENT-AUTHORISATION   portfolio "HDFC Retail" valid to 31-Dec-2026  PASS
  CODE-OF-CONDUCT-ACK   acknowledged v4; current version is v5   WARN
  VERDICT  warn — included, listed on the compliance-warning exception report
           decision id GD-2026-10-004417
```

Requirement 3 is the case SmartPRS 2 cannot express at all: agent authorisation
today appears only inside the audit PDF, has no alert on `valid_to`, and has no
link to the commission's portfolio field (`N-…` V-03, §1.8.1). Because the
portfolio is now a real entity (R-XVIII-091) and the gate can name any compliance
item type, the rule is ordinary configuration.

---

## XVIII.10 Configuration surface

Every item below is changeable by a tenant administrator or consultant through
the Configuration Studio (Part VI), with no code, no deployment and no schema
change. Each is a versioned, scoped, effective-dated artefact.

### XVIII.10.1 The list

| # | What | Artefact type | Risk | Notes |
|---|---|---|---|---|
| 1 | Obligation types and their direction, amount source, channels, statutory flags, shortfall policy, priority | `obligation_type` | high | §XVIII.2.4 |
| 2 | Loan types: eligibility, ceilings, interest method and rate, tenure, moratorium, concurrency, cooling period, prepayment, foreclosure, restructure, pause, exit policy, guarantors, documents | `loan_type` | high | §XVIII.3.1 |
| 3 | Advance types: eligibility, maximum, service duration, frequency window and counts, recovery plan, offsets, visibility | `advance_type` | high | §XVIII.4.1 |
| 4 | Recovery order and tie-breaks | `recovery_order` | high | §XVIII.5.2 |
| 5 | Per-type shortfall policy | on the type | high | §XVIII.5.3 |
| 6 | Minimum net-pay floor: kind, amount, percentage, basis, exempt classes, breach behaviour | `net_floor_policy` | high | §XVIII.5.4 |
| 7 | Commission and incentive schemes in full | `commission_scheme` | high | §XVIII.6.2 |
| 8 | Slab tables, marginal or whole-amount | `slab_table` | high | §XVIII.6.3 |
| 9 | Accelerators, thresholds, caps at five scopes | on the scheme | high | §XVIII.6.4 |
| 10 | Team split bases and weights | `commission_team_weights` | medium | §XVIII.6.5 |
| 11 | Clawback triggers, windows and attribution method | on the scheme | high | §XVIII.6.9 |
| 12 | Achievement import definitions: columns, aliases, validations, row caps | `import_definition` | medium | §XVIII.6.6 |
| 13 | Incentive policies as rules and formulas | `rule`, `formula` | high | §XVIII.7 |
| 14 | Points/score eligibility gates | on the policy | medium | §XVIII.7.5 |
| 15 | Payout lag, payout calendars, settlement channels | on the scheme/type | medium | §XVIII.7.6 |
| 16 | Expense categories: fields, limits, proof, GST, taxability, cost centre, workflow, channel, duplicate key, claim window | `expense_category` | high | §XVIII.8.2 |
| 17 | Per-category limits by grade, designation, branch, location, category, employee | on the category | high | §XVIII.8.3 |
| 18 | Gate policies: items, states, severities, gate points, grace, override rules | `gate_policy` | high | §XVIII.9.2 |
| 19 | Rounding profiles for every component in this part | `rounding_profile` | medium | spine S7 |
| 20 | Payslip components and labels for every movement | `salary_component` | high | R-XVIII-014 |
| 21 | Approval workflows and amount bands for every operation here | `workflow` | high | Part IX |
| 22 | Status vocabularies and permitted transitions for every lifecycle here | `status_values` | high | spine S16 |
| 23 | Notification audiences and templates for every event here | `template`, subscriptions | low | Part XIX |
| 24 | Report definitions: loan register, advance register, commission register, payout register, clawback register, limit-consumption report, exception reports | `report_definition` | low | |

### XVIII.10.2 The Section 127 test, applied

The brief's standard is *"3 late arrivals over 10 minutes = half day must not
require code."* The equivalent statements for this part, each of which must be
pure configuration:

| Statement | How it is expressed | Code required |
|---|---|---|
| "Salary advance: up to 50% of net, max ₹40,000, once a quarter, after 6 months' service, recovered over up to 3 months" | advance type + two SPX expressions + frequency window | none |
| "Loans over ₹2,00,000 need the MD's approval" | a workflow amount band | none |
| "Recover the advance before the loan" | reorder two rows of `recovery_order` | none |
| "Never let net fall below ₹8,000" | a net floor policy | none |
| "If the EMI does not fit, take what fits and carry the rest" | shortfall policy `carry_forward` on the loan type | none |
| "1% up to ₹5 lakh, 1.5% to ₹10 lakh, 2% above, marginal; ×1.25 above 110% of target; capped at 50% of gross" | a slab table + an accelerator + a cap | none |
| "Claw back commission when a recovery bounces within 90 days" | clawback block on the scheme | none |
| "₹1,000 for a month with no LOP and no lates over the grace" | an incentive rule + a formula | none |
| "Client entertainment: ₹2,500 per instance, ₹10,000 a month, GST invoice above ₹5,000, Branch Head approves" | an expense category with two limits | none |
| "Block a commission payout when the DRA certificate is expired; warn on a lapsed PCC for 30 days" | a gate policy with two requirements | none |
| "Grade D gets a ₹15/km mileage rate; everyone else ₹12" | two scoped limits on one category | none |
| "Add a Festival Advance type recovered in one instalment in December" | a new obligation type | none |

---
## XVIII.11 Data model

Specified to the Section 123 standard, in Part XXVIII's conventions:
`snake_case`; `BIGINT UNSIGNED` primary keys; `BINARY(16)` UUIDv7 public ids;
`DECIMAL(18,4)` money at rest with **integer paise in memory**; **no database
`ENUM`** — every status column is `VARCHAR(40)` with a foreign key to
`status_values`; `tenant_id` first in every unique index; `ON DELETE RESTRICT` by
default; `row_version` on every mutable row.

Notation: `PK` · `FK→` · `U` unique participation · `IX` indexed · `NN` not null.
Archetypes are Part XXVIII's: **A1** aggregate root · **A3** child rows ·
**A4** append-only ledger/event · **A5** effective-dated.

### XVIII.11.0 Reconciliation with Part XXVIII, cluster C9

Part XXVIII registers C9 — Money Movement with `loans`, `loan_schedules`,
`loan_ledger_entries`, `advances`, `recoveries`, `payout_batches`,
`payout_lines`, `payout_returns`, plus derivable `advance_ledger_entries` and
`recovery_instalments`. This part specifies C9 in full and makes **three
deliberate structural changes**, each of which realises the one-model claim of
§XVIII.2 and each of which must be accepted into Part XXVIII:

| Part XXVIII | This part | Why |
|---|---|---|
| `recoveries` — "the general case" | renamed **`obligations`** and made the **root of every money movement**, in both directions | Part XXVIII's own description of `recoveries` — `employment_id`, `cause_code`, `total_amount`, `recovered_amount`, `max_percent_of_net`, `priority_no` — is already the general obligation. Naming it for one direction obscures that commission, incentive and reimbursement are the same shape |
| `loans`, `advances` as independent A1 roots | retained as **1:1 extension tables** on `obligations`, carrying only type-specific columns | Identical to the way Part XXVIII models `cfg_workflows` as a payload keyed to `config_versions`. Preserves both names and every column XXVIII lists |
| `loan_ledger_entries` + `advance_ledger_entries` (two tables, "shares the shape"; `loan_ledger_entries` already carrying two nullable parents with a `CHECK`) | unified into **`obligation_ledger_entries`** with one parent | One ledger, one reversal, one reconciliation, one uniqueness constraint. The two-parents-plus-`CHECK` construction XXVIII already resorts to is the symptom this removes |
| `loan_schedules` | **`obligation_schedules`** (version header) + **`obligation_schedule_lines`** | Restructure, prepayment, pause and spread-recovery all need a versioned schedule; a flat line table cannot express supersession |

Net effect on the count: C9 goes from 8 specified + 2 derivable to **11 specified
+ 4 derivable**, and the configuration payload tables below join C14.

**R-XVIII-150** There MUST be exactly one ledger table for money movements in
this domain, with exactly one parent column. *Test:* a schema assertion counts
one `*_ledger_entries` table in C9.

---

### XVIII.11.1 `obligations` — A1, the root of every money movement

**Purpose.** One row per stated sum owed by a party to a party. Every loan,
advance, clawback, other recovery, commission payout, incentive payout and
reimbursement payout is a row here.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** · UUIDv7; the only id on any external surface |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK→ `tenants(id)` RESTRICT (Law 6) |
| `obligation_no` | VARCHAR(40) | NN | — | **U(`tenant_id`,`obligation_no`)** · from `cfg_code_sequences`, DB-allocated under `FOR UPDATE` |
| `obligation_type_code` | VARCHAR(40) | NN | — | FK→ `cfg_obligation_types(obligation_type_code)` RESTRICT |
| `obligation_type_config_version_id` | BIGINT UNSIGNED | NN | — | FK→ `config_versions(id)` RESTRICT — **the version that governed creation** |
| `obligation_class_code` | VARCHAR(40) | NN | — | FK→ `status_values` domain `obligation_class`: `loan\|advance\|clawback\|other_recovery\|commission\|incentive\|reimbursement\|arrear\|charge\|receivable` |
| `direction_code` | VARCHAR(30) | NN | — | `recover_from_employee\|pay_to_employee` |
| `subject_kind_code` | VARCHAR(30) | NN | `employment` | `employment\|worker\|vendor` |
| `subject_id` | BIGINT UNSIGNED | NN | — | FK→ `employments(id)` / `workers(id)` RESTRICT, per kind (declared polymorphic; both FKs present as generated-column constraints) |
| `counterparty_kind_code` | VARCHAR(30) | NN | `employer` | `employer\|client\|third_party` |
| `counterparty_id` | BIGINT UNSIGNED | Y | NULL | FK→ `org_units(id)` / `clients(id)` RESTRICT |
| `currency_code` | CHAR(3) | NN | — | ISO 4217; carried explicitly (spine S16) |
| `principal_amount` | DECIMAL(18,4) | NN | — | the **gross** stated sum; never a net-of-tax figure (R-XVIII-096) |
| `outstanding_amount` | DECIMAL(18,4) | NN | — | **derived** = SUM(ledger); asserted by M1 (R-XVIII-007) |
| `interest_accrued_amount` | DECIMAL(18,4) | NN | 0 | |
| `waived_amount` | DECIMAL(18,4) | NN | 0 | |
| `origin_kind_code` | VARCHAR(40) | NN | — | `request\|scheme_run\|claim\|rule_action\|exit_case\|bounce_event\|migration\|manual` |
| `origin_id` | BIGINT UNSIGNED | Y | NULL | the producing record |
| `origin_event_key` | VARCHAR(120) | Y | NULL | for `rule_action` and `bounce_event` |
| `parent_obligation_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT — a clawback names the payout it reverses; a receivable names the loan it came from |
| `sanction_workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK→ `workflow_instances(id)` RESTRICT |
| `sanctioned_by_user_id`, `sanctioned_at` | BIGINT UNSIGNED, DATETIME | Y | NULL | denormalised from the instance for reporting |
| `sanction_terms_json` | JSON | Y | NULL | the frozen terms (R-XVIII-027), hashed into `terms_checksum` |
| `terms_checksum` | CHAR(64) | Y | NULL | |
| `first_settlement_period_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payroll_periods(id)` RESTRICT — the first period it may move in |
| `settlement_period_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payroll_periods(id)` RESTRICT — for a single-movement payout; **NOT NULL before approval** (R-XVIII-099, enforced by the approval guard) |
| `settlement_channel_code` | VARCHAR(40) | NN | — | `payroll\|payout_batch\|reimbursement_run\|manual\|external` |
| `priority_no` | SMALLINT UNSIGNED | NN | — | recovery competition order; seeded from the type |
| `shortfall_policy_code` | VARCHAR(30) | NN | — | seeded from the type; overridable where the type permits |
| `gate_policy_code` | VARCHAR(40) | Y | NULL | FK→ `cfg_gate_policies` RESTRICT |
| `cost_centre_org_unit_id` | BIGINT UNSIGNED | Y | NULL | FK→ `org_units(id)` RESTRICT — attributed at approval (R-XVIII-138) |
| `lifecycle_state_code` | VARCHAR(40) | NN | — | FK→ `status_values` domain `obligation_state`; vocabulary per class |
| `closed_on` | DATE | Y | NULL | |
| `migration_confidence_code` | VARCHAR(30) | Y | NULL | `reconciled\|inferred\|disputed` (R-XVIII-048) |
| `legacy_source_ref` | VARCHAR(120) | Y | NULL | `smartprs2:loans:4412` |
| `trace_id` | BINARY(16) | Y | NULL | the S15 trace that produced the amount |
| `row_version` | INT UNSIGNED | NN | 1 | optimistic concurrency |
| `created_by_user_id`, `created_at`, `updated_at` | | NN | | |

**Keys.** PK `id` · U `(tenant_id, uuid)`, `(tenant_id, obligation_no)`.
**Indexes.** `IX(tenant_id, subject_kind_code, subject_id, lifecycle_state_code, priority_no)` ← the recovery fetch, one query per employee ·
`IX(tenant_id, settlement_period_id, direction_code, lifecycle_state_code)` ← the earnings fetch ·
`IX(tenant_id, obligation_class_code, lifecycle_state_code)` ← registers ·
`IX(tenant_id, parent_obligation_id)` ·
`IX(tenant_id, origin_kind_code, origin_id)` ·
`IX(tenant_id, cost_centre_org_unit_id, settlement_period_id)` ← cost reporting.
**Tenant scope.** `tenant_id` NN, in every unique index, applied by the
persistence layer.
**History.** The ledger. Terms are frozen and versioned; the schedule is
versioned. **Soft delete:** none — never deleted (spine S16).
**Audit:** `full`, field-level before/after on every column.
**PII:** `sensitive` — a loan balance is financial data about a person.
**Retention:** `R-PAY-8Y`.

*Why `settlement_period_id` and not a date:* SmartPRS 2 routes a commission by a
`payout_date`, and where neither `payout_date` nor `cycle_month` is present, **no
payslip ever pays it** (BR-PAY-018). A period id resolved through the pay cycle
cannot be orphaned, and cannot drift when the cut-off day changes.

*Why `obligation_type_config_version_id`:* spine S4 — every derived record stores
the version ids it was produced from, so the obligation is defensible three years
later.

---

### XVIII.11.2 `loans` — A1 extension, 1:1 on `obligations`

**Purpose.** Loan-specific terms. Every general column lives on `obligations`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `obligation_id` | BIGINT UNSIGNED | NN | — | **U(`tenant_id`,`obligation_id`)** · FK→ `obligations(id)` RESTRICT |
| `loan_type_code` | VARCHAR(40) | NN | — | FK→ `cfg_loan_types` RESTRICT |
| `interest_method_code` | VARCHAR(30) | NN | `none` | `none\|flat\|reducing_balance` |
| `interest_rate_percent` | DECIMAL(9,6) | Y | NULL | NULL iff method `none` |
| `interest_rate_basis_code` | VARCHAR(20) | Y | NULL | `per_annum\|per_period` |
| `instalment_count` | SMALLINT UNSIGNED | NN | — | **the column SmartPRS 2 reads and does not have** |
| `instalment_amount` | DECIMAL(18,4) | NN | — | the level instalment; the final line may differ |
| `moratorium_periods` | TINYINT UNSIGNED | NN | 0 | |
| `moratorium_interest_code` | VARCHAR(40) | NN | `none` | `none\|accrue_and_capitalise\|accrue_and_collect_first` |
| `interest_total_amount` | DECIMAL(18,4) | NN | 0 | from the active schedule version |
| `principal_recovered_amount`, `interest_recovered_amount` | DECIMAL(18,4) | NN | 0 | derived from the ledger; asserted by M3 |
| `max_recovery_percent_of_net` | DECIMAL(9,6) | Y | NULL | the loan's own ceiling |
| `disbursed_on` | DATE | Y | NULL | |
| `disbursement_payout_line_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payout_lines(id)` RESTRICT |
| `active_schedule_id` | BIGINT UNSIGNED | Y | NULL | FK→ `obligation_schedules(id)` RESTRICT |
| `guarantor_employment_ids_json` | JSON | NN | `[]` | resolved ids, never names |
| `paused_from_period_id`, `paused_to_period_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payroll_periods(id)` RESTRICT |
| `foreclosed_on`, `foreclosure_charge_amount` | DATE, DECIMAL(18,4) | Y | NULL | |
| `row_version` | INT UNSIGNED | NN | 1 | |

**Keys.** PK `id` · U `(tenant_id, obligation_id)`.
**Indexes.** `IX(tenant_id, loan_type_code)`, `IX(tenant_id, active_schedule_id)`.
**Policy.** Tenant-owned · history: ledger + schedule versions · delete: never ·
audit `full` · PII `sensitive` · retention `R-PAY-8Y`.

*Why every column is present:* `installment_amount`, `installments_total`, `type`
and `start_month` are read by SmartPRS 2's payroll engine at `:682`, `:687`,
`:691` and `:702` and **exist in no migration and are created by no code at all**,
so `safeRow()` drops them silently and the engine produces a wrong number rather
than an error (`A-payroll.md` §2.6, DEF-P02). `tenure` is migrated and never
read. There is **no `outstanding` column here**: the balance lives on
`obligations` and is the ledger sum.

---

### XVIII.11.3 `advances` — A1 extension, 1:1 on `obligations`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `obligation_id` | BIGINT UNSIGNED | NN | — | **U(`tenant_id`,`obligation_id`)** · FK RESTRICT |
| `advance_type_code` | VARCHAR(40) | NN | — | FK→ `cfg_advance_types` RESTRICT |
| `recovery_plan_code` | VARCHAR(20) | NN | `single` | `single\|spread` |
| `instalment_count` | SMALLINT UNSIGNED | NN | 1 | |
| `instalment_amount` | DECIMAL(18,4) | Y | NULL | NULL iff `single` |
| `active_schedule_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `purpose_text` | VARCHAR(500) | Y | NULL | **the field `safeRow()` drops today** |
| `is_settled_by_claims` | TINYINT(1) | NN | 0 | a travel advance settled by expense claims (R-XVIII-137) |
| `row_version` | INT UNSIGNED | NN | 1 | |

**Keys/Policy.** As `loans`.
*Why no `per_month` / `balance`:* both are migrated in SmartPRS 2 and **read by
nothing**; they modelled a spread recovery the code never implemented
(`A-payroll.md` §2.7). Here the spread is a real schedule and the balance is the
ledger.

---

### XVIII.11.4 `obligation_schedules` — A3, the schedule version header

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `obligation_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `version_no` | SMALLINT UNSIGNED | NN | 1 | **U(`tenant_id`,`obligation_id`,`version_no`)** |
| `status_code` | VARCHAR(30) | NN | `active` | `active\|superseded\|cancelled` |
| `generated_reason_code` | VARCHAR(40) | NN | `sanction` | `sanction\|restructure\|prepayment\|foreclosure\|pause\|spread_shortfall\|migration` |
| `generated_by_user_id`, `generated_at` | | NN | | |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the approval, where one is required |
| `principal_total`, `interest_total`, `grand_total` | DECIMAL(18,4) | NN | — | asserted equal to the line sums |
| `superseded_by_schedule_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |
| `trace_id` | BINARY(16) | NN | — | the derivation trace |

**Keys.** PK `id` · U `(tenant_id, obligation_id, version_no)` ·
a **partial-unique guarantee** that at most one version per obligation is
`active`, enforced by a generated column `active_marker` participating in
U `(tenant_id, obligation_id, active_marker)`.
**Policy.** Tenant-owned · superseded, never deleted · audit `full` ·
PII `none` · retention `R-PAY-8Y`.

### XVIII.11.5 `obligation_schedule_lines` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `schedule_id` | BIGINT UNSIGNED | NN | — | FK→ `obligation_schedules(id)` RESTRICT |
| `instalment_no` | SMALLINT UNSIGNED | NN | — | **U(`tenant_id`,`schedule_id`,`instalment_no`)** |
| `due_period_id` | BIGINT UNSIGNED | NN | — | FK→ `payroll_periods(id)` RESTRICT |
| `due_on` | DATE | NN | — | |
| `opening_balance` | DECIMAL(18,4) | NN | — | |
| `principal_component`, `interest_component`, `total_amount` | DECIMAL(18,4) | NN | — | `principal + interest = total`, DB `CHECK` |
| `closing_balance` | DECIMAL(18,4) | NN | — | |
| `recovered_amount` | DECIMAL(18,4) | NN | 0 | derived from the ledger |
| `carried_from_period_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — set by `carry_forward` |
| `status_code` | VARCHAR(30) | NN | `scheduled` | `scheduled\|part_recovered\|recovered\|waived\|deferred\|cancelled\|superseded` |

**Indexes.** `IX(tenant_id, due_period_id, status_code)` ← the due-instalment
fetch for a run · `IX(tenant_id, schedule_id, status_code)`.
**Policy.** Tenant-owned · audit `transitional` · PII `none` ·
retention `R-PAY-8Y`.

---

### XVIII.11.6 `obligation_ledger_entries` — A4, **the balance**

**Purpose.** Every movement of money against every obligation. Append-only. The
application database user holds `INSERT` and `SELECT` only.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK (with `occurred_on` where partitioned) |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `obligation_id` | BIGINT UNSIGNED | NN | — | FK→ `obligations(id)` RESTRICT — **one parent** |
| `entry_type_code` | VARCHAR(40) | NN | — | FK→ `status_values` domain `ledger_entry_type`: `opening_balance\|disbursement\|recovery\|payout\|interest_accrual\|waiver\|adjustment\|reversal\|write_off\|charge` |
| `amount_delta` | DECIMAL(18,4) | NN | — | **signed**; the sign convention is declared per entry type in `status_values.semantics_json` |
| `currency_code` | CHAR(3) | NN | — | |
| `schedule_line_id` | BIGINT UNSIGNED | Y | NULL | FK→ `obligation_schedule_lines(id)` RESTRICT |
| `payroll_run_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payroll_runs(id)` RESTRICT |
| `payslip_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payslips(id)` RESTRICT; `CHECK (payroll_run_id IS NULL OR payslip_id IS NOT NULL)` |
| `payslip_line_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payslip_lines(id)` RESTRICT — **the counterpart of this movement** |
| `payout_line_id` | BIGINT UNSIGNED | Y | NULL | FK→ `payout_lines(id)` RESTRICT |
| `reversal_of_entry_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT; **U(`tenant_id`,`reversal_of_entry_id`)** — an entry may be reversed at most once (R-XVIII-012) |
| `cost_centre_org_unit_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — attribution travels with the money |
| `reason_code` | VARCHAR(40) | Y | NULL | FK→ `status_values` |
| `reason_text` | VARCHAR(500) | Y | NULL | **never parsed by any code** (R-XVIII-113) |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — manual movements |
| `document_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — a receipt |
| `trace_id` | BINARY(16) | NN | — | the S15 trace |
| `idempotency_key` | VARCHAR(120) | Y | NULL | **U(`tenant_id`,`idempotency_key`)** |
| `occurred_at` | DATETIME | NN | — | UTC |
| `occurred_on` | DATE | NN | — | business date, for partitioning |
| `recorded_at` | DATETIME(6) | NN | — | |

**Keys.** PK `(id, occurred_on)` (partitioned by year) ·
**U `(tenant_id, obligation_id, payslip_id, entry_type_code)`** — one movement of
a type per obligation per payslip, enforced by the database (R-XVIII-009) ·
U `(tenant_id, reversal_of_entry_id)` · U `(tenant_id, idempotency_key)`.
**Indexes.** `IX(tenant_id, obligation_id, occurred_at)` ← balance derivation ·
**`IX(tenant_id, payroll_run_id)`** ← run reversal, the query R-XVIII-011 depends
on · `IX(tenant_id, entry_type_code, occurred_on)` ·
`IX(tenant_id, cost_centre_org_unit_id, occurred_on)`.
**Tenant scope.** structural. **History:** the table *is* the history.
**Soft delete:** none; `UPDATE` and `DELETE` are denied at the database.
**Audit:** `event-only` (the row is its own audit).
**PII:** `none` (amounts, not identities). **Retention:** `R-PAY-8Y`.

*Why one ledger and not a counter:* SmartPRS 2 keeps `installments_paid` on the
loan and adjusts it in a best-effort `try/catch` **after** the payroll transaction
commits, so a payslip can say "Loan EMI ₹5,000 deducted" while the counter never
advances; two concurrent regenerates decrement it twice and re-open a closed loan
(`M-…` C-2, C-3). Advances have **no ledger at all**, so an advance recovery
cannot be reversed (BR-PAY-033). `loan_recoveries` — the one ledger that does
exist — is created by `generate():2589–2603` **at request time**, has **no index
on any column** (`J-…` §Q5) and is **hard-deleted** on regeneration.

*Why `IX(tenant_id, payroll_run_id)` is load-bearing:* it is the whole of
reversal. SmartPRS 2 reverses commission passbook debits with
`reference LIKE 'run #<id> %'` — a free-text match safe from matching run #12
only because of one trailing space (`A-payroll.md` §5 Q7; `M-…` C-13).

---

### XVIII.11.7 `obligation_shortfalls` — A4

**Purpose.** A structured record of every recovery that could not be taken in
full, so the shortfall is reportable rather than a sentence of English prose.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `obligation_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `payroll_run_id`, `payslip_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `period_id` | BIGINT UNSIGNED | NN | — | FK→ `payroll_periods(id)` RESTRICT |
| `amount_due`, `amount_taken`, `amount_short` | DECIMAL(18,4) | NN | — | `due − taken = short`, DB `CHECK` |
| `policy_applied_code` | VARCHAR(30) | NN | — | `skip\|partial\|carry_forward\|spread` |
| `reason_code` | VARCHAR(40) | NN | — | `insufficient_net\|net_floor\|loan_cap\|gate_blocked\|obligation_paused` |
| `carried_to_period_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `trace_id` | BINARY(16) | NN | — | |
| `occurred_at`, `occurred_on` | | NN | | |

**Keys.** U `(tenant_id, obligation_id, payslip_id)`.
**Indexes.** `IX(tenant_id, period_id, reason_code)` ← the run exception report.
**Policy.** Append-only · audit `event-only` · PII `none` · retention `R-PAY-8Y`.
Reversed with the run, like a ledger entry.

---

### XVIII.11.8 `commission_scheme_runs` — A1

**Purpose.** One evaluation of one scheme version for one period: the unit that
turns achievement data into obligations, with a preview stage.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid`, `tenant_id` | | NN | | PK, U, FK |
| `run_no` | VARCHAR(40) | NN | — | **U(`tenant_id`,`run_no`)** · sequence-allocated |
| `scheme_code` | VARCHAR(40) | NN | — | FK→ `cfg_commission_schemes` RESTRICT |
| `scheme_config_version_id` | BIGINT UNSIGNED | NN | — | FK→ `config_versions(id)` RESTRICT — the version that priced it |
| `period_id` | BIGINT UNSIGNED | NN | — | FK→ `payroll_periods(id)` RESTRICT — the **earned** period |
| `settlement_period_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — earned + payout lag |
| `lot_no` | SMALLINT UNSIGNED | NN | 1 | **U(`tenant_id`,`scheme_code`,`period_id`,`lot_no`)** — several lots per scheme-period are legitimate |
| `lifecycle_state_code` | VARCHAR(40) | NN | `draft` | `draft\|previewed\|pending_approval\|committed\|superseded\|cancelled` |
| `source_import_id` | BIGINT UNSIGNED | Y | NULL | FK→ `imports(id)` RESTRICT |
| `subject_count`, `payable_count`, `excluded_count` | INT UNSIGNED | NN | 0 | |
| `gross_total`, `withholding_total`, `net_total` | DECIMAL(18,4) | NN | 0 | asserted equal to the line sums before commit |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `superseded_by_run_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |
| `committed_by_user_id`, `committed_at` | | Y | NULL | |
| `trace_id`, `row_version` | | | | |

**Indexes.** `IX(tenant_id, period_id, lifecycle_state_code)`,
`IX(tenant_id, scheme_code, period_id)`.
**Policy.** Tenant-owned · versioned by supersession, never deleted ·
audit `full` · PII `none` · retention `R-PAY-8Y`.

*Why this table exists at all:* SmartPRS 2's bulk calculator holds its basis,
formula, slab bands, portfolio rates, TDS override, payout method and purpose
**in the HTTP request**, computes, and discards the configuration
(`IncentiveController::calculate():38–69`, `commit():72–282`). There is no record
of *how* a batch was priced, so a disputed commission cannot be defended.

### XVIII.11.9 `commission_achievements` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `scheme_run_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `line_no` | INT UNSIGNED | NN | — | **U(`tenant_id`,`scheme_run_id`,`line_no`)** |
| `subject_kind_code`, `subject_id` | | NN | — | FK RESTRICT |
| `team_id` | BIGINT UNSIGNED | Y | NULL | FK→ `org_units(id)` RESTRICT — team schemes |
| `client_portfolio_id` | BIGINT UNSIGNED | Y | NULL | FK→ `client_portfolios(id)` RESTRICT — **an entity, not a string** (R-XVIII-091) |
| `basis_measure_key` | VARCHAR(80) | NN | — | |
| `basis_value` | DECIMAL(18,4) | NN | — | the achievement figure |
| `target_value` | DECIMAL(18,4) | Y | NULL | |
| `achievement_percent` | DECIMAL(9,4) | Y | NULL | derived |
| `computed_gross` | DECIMAL(18,4) | NN | 0 | |
| `accelerator_applied_code` | VARCHAR(60) | Y | NULL | |
| `cap_applied_code` | VARCHAR(60) | Y | NULL | |
| `capped_amount` | DECIMAL(18,4) | Y | NULL | the amount removed by a cap |
| `outcome_code` | VARCHAR(40) | NN | — | `payable\|below_threshold\|zero_amount\|ineligible\|gate_blocked\|points_gate\|duplicate\|invalid` — **every row has an outcome; none is dropped** (R-XVIII-093) |
| `outcome_detail` | VARCHAR(500) | Y | NULL | |
| `gate_decision_id` | BIGINT UNSIGNED | Y | NULL | FK→ `compliance_gate_decisions(id)` RESTRICT |
| `obligation_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — set on commit for payable rows |
| `trace_id` | BINARY(16) | NN | — | the full pricing trace for this row |

**Indexes.** `IX(tenant_id, scheme_run_id, outcome_code)`,
`IX(tenant_id, subject_kind_code, subject_id)`, `IX(tenant_id, obligation_id)`.
**Policy.** Tenant-owned · immutable after commit · audit `transitional` ·
PII `none` · retention `R-PAY-8Y`.

---

### XVIII.11.10 `expense_claims` — A1

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid`, `tenant_id` | | NN | | PK, U, FK |
| `claim_no` | VARCHAR(40) | NN | — | **U(`tenant_id`,`claim_no`)** · sequence-allocated |
| `subject_kind_code`, `subject_id` | | NN | — | FK RESTRICT |
| `claim_period_from`, `claim_period_to` | DATE | NN | — | |
| `submitted_at` | DATETIME | Y | NULL | |
| `currency_code` | CHAR(3) | NN | — | |
| `claimed_total`, `approved_total`, `taxable_total`, `non_taxable_total` | DECIMAL(18,4) | NN | 0 | `taxable + non_taxable = approved`, DB `CHECK` |
| `input_credit_total` | DECIMAL(18,4) | NN | 0 | |
| `advance_obligation_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — settled against a travel advance |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `settlement_channel_code` | VARCHAR(40) | NN | — | |
| `settlement_period_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `payout_obligation_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the obligation created on approval |
| `lifecycle_state_code` | VARCHAR(40) | NN | `draft` | |
| `trace_id`, `row_version`, `created_at`, `updated_at` | | | | |

**Indexes.** `IX(tenant_id, subject_kind_code, subject_id, lifecycle_state_code)`,
`IX(tenant_id, settlement_period_id)`, `IX(tenant_id, lifecycle_state_code)`.
**Policy.** Tenant-owned · delete: never · audit `full` · PII `sensitive` ·
retention `R-PAY-8Y`.

### XVIII.11.11 `expense_claim_lines` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `expense_claim_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `line_no` | INT UNSIGNED | NN | — | **U(`tenant_id`,`expense_claim_id`,`line_no`)** |
| `expense_category_code` | VARCHAR(40) | NN | — | FK→ `cfg_expense_categories` RESTRICT |
| `category_config_version_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — the limits that governed it |
| `incurred_on` | DATE | NN | — | |
| `quantity`, `unit_rate` | DECIMAL(18,4) | Y | NULL | for `per_unit` categories |
| `claimed_amount`, `approved_amount` | DECIMAL(18,4) | NN | — | |
| `limit_breach_code` | VARCHAR(40) | Y | NULL | `none\|truncated\|warned\|exception_approved` |
| `limit_applied_json` | JSON | Y | NULL | which limit rows were evaluated, and their consumption |
| `taxable_amount`, `non_taxable_amount` | DECIMAL(18,4) | NN | 0 | R-XVIII-134 |
| `cost_centre_org_unit_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `project_id`, `client_portfolio_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `proof_document_id` | BIGINT UNSIGNED | Y | NULL | FK→ `documents(id)` RESTRICT — private disk, signed URLs only |
| `duplicate_of_line_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |
| `entity_field_values` | *(via the entity schema engine)* | | | the category's declared fields, including **the reason** (R-XVIII-124) |
| `trace_id` | BINARY(16) | NN | — | |

**Indexes.** `IX(tenant_id, expense_category_code, incurred_on)`,
`IX(tenant_id, cost_centre_org_unit_id)`, `IX(tenant_id, duplicate_of_line_id)`.
**Policy.** Tenant-owned · audit `full` · PII `sensitive` (a claim reveals
movements and health-adjacent categories) · retention `R-PAY-8Y`.

### XVIII.11.12 `expense_claim_tax_details` — A3

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | NN | | PK, FK |
| `expense_claim_line_id` | BIGINT UNSIGNED | NN | — | **U(`tenant_id`,`expense_claim_line_id`)** · FK RESTRICT |
| `supplier_gstin` | VARCHAR(15) | Y | NULL | checksum-validated on write |
| `supplier_name`, `supplier_state_code` | VARCHAR(200), VARCHAR(4) | Y | NULL | |
| `invoice_no`, `invoice_on` | VARCHAR(60), DATE | Y | NULL | |
| `hsn_sac_code` | VARCHAR(12) | Y | NULL | |
| `taxable_value` | DECIMAL(18,4) | NN | 0 | |
| `cgst_amount`, `sgst_amount`, `igst_amount`, `cess_amount` | DECIMAL(18,4) | NN | 0 | |
| `place_of_supply_state_code` | VARCHAR(4) | Y | NULL | **declared, not inferred from a GSTIN prefix** (R-XVIII-130) |
| `is_input_credit_eligible` | TINYINT(1) | NN | 0 | |
| `rule_pack_version_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the pack that supplied the rates |

**Keys.** U `(tenant_id, expense_claim_line_id)` ·
**U `(tenant_id, supplier_gstin, invoice_no)`** where both are present —
the duplicate-invoice constraint, enforced by the database (R-XVIII-132).
**Policy.** Tenant-owned · audit `full` · PII `none` · retention `R-STAT-8Y`.

---

### XVIII.11.13 Configuration payload tables

Each is the typed payload of a `config_versions` row (spine S4), keyed
`U(config_version_id)`, and each therefore inherits scope, priority, effective
dating, status, approval, checksum and diff from the shared model. All are
tenant-owned, audited `full` with an old→new payload diff, `PII none`, retention
`R-PERM`.

| Table | Artefact type | Payload | Child rows |
|---|---|---|---|
| `cfg_obligation_types` | `obligation_type` | §XVIII.2.4's declaration set | — |
| `cfg_loan_types` | `loan_type` | §XVIII.3.1 | `cfg_loan_type_documents` |
| `cfg_advance_types` | `advance_type` | §XVIII.4.1 | — |
| `cfg_recovery_orders` | `recovery_order` | tie-breaks, validator severity | `cfg_recovery_order_lines(sequence_no, class_or_type, tie_break_code, cap_expr, condition_expr)`, U `(cfg_recovery_order_id, sequence_no)` |
| `cfg_net_floor_policies` | `net_floor_policy` | §XVIII.5.4 | `cfg_net_floor_exempt_classes` |
| `cfg_commission_schemes` | `commission_scheme` | §XVIII.6.2 header, basis, threshold, team, payout, clawback, GST | `cfg_commission_scheme_components(line_no)`, `cfg_commission_scheme_accelerators(line_no)`, `cfg_commission_scheme_caps(line_no)`, `cfg_commission_scheme_audience(subject_kind, subject_id)` — **a relation, never a CSV** (R-XVIII-082), `cfg_commission_team_weights` |
| `cfg_slab_tables` | `slab_table` | measure type, `computation_code` | `cfg_slab_bands(band_no, from_value, to_value, rate_percent, fixed_amount, formula_ref)`, U `(cfg_slab_table_id, band_no)`, validated ascending/contiguous/one-open at publish (R-XVIII-085) |
| `cfg_expense_categories` | `expense_category` | §XVIII.8.2 | `cfg_expense_category_limits(limit_no, basis, amount_expr, count_expr, scope_json, on_breach_code)`, `cfg_expense_category_documents` |
| `cfg_gate_policies` *(XXVIII)* | `gate_policy` | subject kinds, gate points, override rules | `cfg_gate_policy_requirements(line_no, compliance_item_type_code, required_state_expr, severity_code, applies_when_expr, grace_days_expr, message_template_key)` |

**R-XVIII-151** Every SPX expression stored in any of these payloads MUST be
persisted as **both source text and compiled AST**, so a published artefact
cannot be re-parsed differently by a later parser version (spine S5).
*Test:* the stored checksum covers the AST.

**R-XVIII-152** Every one of these tables MUST be created by a migration and by
nothing else. *Test:* Law 2's CI grep; `migrate:fresh` reproduces production
structure byte-identically. *Rationale — SmartPRS 2 creates `loan_recoveries`,
`commission_logs`, `commission_payments`, `bank_invoices` and the entire
`incentive_schemes` live shape **at request time**, so ~60 columns and 7 tables
exist only if the right screen was opened (`A-payroll.md` §2.11, §2.13, DEF-P29).*

### XVIII.11.14 Derivable register (Part XXVIII conventions)

| Table | Archetype | Parent | Natural key |
|---|---|---|---|
| `obligation_state_events` | A4 | `obligations` | `(obligation_id, occurred_at, to_state_code)` |
| `commission_scheme_run_reconciliations` | A4 | `commission_scheme_runs` | `(scheme_run_id, check_code)` |
| `obligation_cost_allocations` | A3 | `obligations` | `(obligation_id, cost_centre_org_unit_id)` — multi-centre splits (R-XVIII-139) |
| `expense_claim_limit_consumption` | A4 | `expense_claims` | `(tenant_id, subject_id, category_code, window_key)` — the locked consumption row (R-XVIII-127) |

---
## XVIII.12 API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated on every collection,
**idempotency keys mandatory on every unsafe method**, a consistent error
envelope with machine-readable codes (spine S17). Every route carries an explicit
policy binding, asserted by the CI sweep (Law 5). **UUIDs only** — no sequential
id appears in any path.

### XVIII.12.1 Obligations

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/obligations` | `money.obligation.view` | Filter by class, type, subject, state, period, cost centre. Data-scoped |
| `GET` | `/obligations/{uuid}` | `money.obligation.view` | |
| `POST` | `/obligations` | `money.obligation.request` (self) / `.request_for_other` | Subject resolved from the session unless `.request_for_other` is held (R-XVIII-063) |
| `PATCH` | `/obligations/{uuid}` | `money.obligation.author` | Draft only; PATCH semantics — only supplied fields change; unknown fields 422 |
| `POST` | `/obligations/{uuid}/submit` | `money.obligation.request` | Opens the sanction workflow |
| `POST` | `/obligations/{uuid}/cancel` | `money.obligation.request` | Before first movement only |
| `GET` | `/obligations/{uuid}/ledger` | `money.obligation.view` | The movements, newest first |
| `GET` | `/obligations/{uuid}/schedule` | `money.obligation.view` | Active version; `?version=` for any |
| `GET` | `/obligations/{uuid}/explain` | `money.obligation.view` | The S15 trace (§XVIII.14) |
| `POST` | `/obligations/{uuid}/payments` | `money.obligation.record_payment` | Manual repayment (R-XVIII-038) |
| `POST` | `/obligations/{uuid}/waiver` | `money.obligation.waive` | Opens the waiver workflow |
| `POST` | `/obligations/{uuid}/write-off` | `money.obligation.write_off` | Workflow-gated |
| `GET` | `/obligations/balances` | `money.obligation.view` | As-at-date balances for a subject or a scope |

### XVIII.12.2 Loans and advances

| Method | Path | Permission | Notes |
|---|---|---|---|
| `POST` | `/loans/{uuid}/disburse` | `loan.disburse` | Explicit command; never a file download (R-XVIII-033) |
| `POST` | `/loans/{uuid}/prepay` | `loan.prepay` | Body: amount, effect override where permitted |
| `GET` | `/loans/{uuid}/foreclosure-quote` | `loan.view` | Read-only, dated, reproducible (R-XVIII-041) |
| `POST` | `/loans/{uuid}/foreclose` | `loan.foreclose` | |
| `POST` | `/loans/{uuid}/restructure` | `loan.restructure` | Opens the restructure workflow |
| `POST` | `/loans/{uuid}/pause` · `/resume` | `loan.pause` | Reason mandatory |
| `GET` | `/loan-types` · `/advance-types` | `config.view` | Resolved for a subject and date, with the specificity arithmetic |
| `GET` | `/advances/{uuid}/eligibility` | `advance.view` | Returns the SPX predicates and each one's truth value |

### XVIII.12.3 Commission and incentive

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET`/`POST` | `/commission-schemes` | `commission.scheme.view` / `.author` | Versioned like any artefact; publish through Part VI |
| `POST` | `/commission-schemes/{uuid}/versions/{v}/simulate` | `commission.scheme.author` | Price a named subject and figure without writing |
| `POST` | `/commission-scheme-runs` | `commission.run.create` | Creates a draft run for a scheme and period |
| `POST` | `/commission-scheme-runs/{uuid}/achievements` | `commission.run.create` | Bulk upload; returns per-row validation |
| `POST` | `/commission-scheme-runs/{uuid}/preview` | `commission.run.create` | Computes; writes no obligation (R-XVIII-094) |
| `GET` | `/commission-scheme-runs/{uuid}/outcomes` | `commission.run.view` | **Every** row with its outcome code (R-XVIII-093) |
| `POST` | `/commission-scheme-runs/{uuid}/submit` | `commission.run.create` | Opens the approval workflow |
| `POST` | `/commission-scheme-runs/{uuid}/commit` | `commission.run.commit` | Creates obligations; idempotent on the run uuid |
| `POST` | `/commission-scheme-runs/{uuid}/supersede` | `commission.run.commit` | Creates a corrective run; never edits the prior one |
| `POST` | `/obligations/{uuid}/clawback` | `commission.clawback.raise` | Body: trigger event ref; attribution from the scheme |
| `GET` | `/incentive-policies` | `rules.view` | A filtered view of `/rules` — no second engine |

### XVIII.12.4 Reimbursements

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET`/`POST` | `/expense-claims` | `expense.claim.view` / `.submit` | Subject from the session unless `.submit_for_other` |
| `POST` | `/expense-claims/{uuid}/lines` | `expense.claim.submit` | Validates against the category's limits, live |
| `POST` | `/expense-claims/{uuid}/lines/{lineUuid}/proof` | `expense.claim.submit` | Multipart; private disk; signed retrieval only |
| `GET` | `/expense-claims/{uuid}/limit-check` | `expense.claim.view` | Consumption per limit, per window, before submission |
| `POST` | `/expense-claims/{uuid}/submit` | `expense.claim.submit` | |
| `GET` | `/expense-categories` | `config.view` | Resolved for a subject and date, with limits |
| `POST` | `/reimbursement-runs` | `reimbursement.run.create` | A first-class run type (R-XVIII-136) |
| `GET` | `/reimbursement-runs/{uuid}/reconciliation` | `reimbursement.run.view` | M1/M2/M3 results |

### XVIII.12.5 Recovery, gates and reporting

| Method | Path | Permission | Notes |
|---|---|---|---|
| `POST` | `/payroll-runs/{uuid}/recovery-preview` | `payroll.run.view` | The plan for every employee, before generation, with traces |
| `GET` | `/payroll-runs/{uuid}/shortfalls` | `payroll.run.view` | The structured exception list (R-XVIII-069) |
| `GET` | `/gate-decisions` | `compliance.gate.view` | Filter by gate point, subject, verdict, period |
| `POST` | `/gate-decisions/{uuid}/override` | `compliance.gate.override` | Reason mandatory; workflow where the policy declares one |
| `GET` | `/reports/{loan-register\|advance-register\|commission-register\|payout-register\|clawback-register\|limit-consumption\|compliance-warned-payouts}` | `reporting.view` + the report's own permission | Generated from stored facts, never recomputed |

**R-XVIII-153** There MUST be **no `DELETE`** on any obligation, schedule, ledger
entry, scheme run, achievement row or claim. Cancellation, supersession,
reversal and dating are the only removals. *Test:* the OpenAPI document contains
no `DELETE` under any path in this part. *Rationale — spine S16, and SmartPRS 2's
`cleanOrphanCommissions` deletes rows from three tables with no record at all
(`E-…` SEC-22).*

**R-XVIII-154** No state-changing operation in this part may be reachable by
`GET`. *Test:* a route sweep asserts it. *Rationale — the bank file mutates state
on a `GET`, disbursing salaries as a side effect of a download
(`A-payroll.md` SEC-P04; `M-…` C-14).*

**R-XVIII-155** Every unsafe method MUST accept an idempotency key and return the
original result on replay. *Test:* double-posting a commit, a payment, a
disbursement or a claim submission produces one effect.
*Rationale — `M-…` C-1: only 2 of ~180 mutating buttons in the SPA disable
themselves in flight.*

**R-XVIII-156** Every list endpoint MUST be **data-scoped** (spine S13) in
addition to permission-checked. *Test:* the generated cross-tenant and
cross-scope isolation tests exist for every endpoint above.
*Rationale — `E-…` SEC-01, CRITICAL: `RequestController::list()` has no role
guard and applies row-level scoping only when the module happens to be
`commissions`, so any authenticated user reads every colleague's loan, advance,
increment, expense claim and exit record for the whole tenant.*

---

## XVIII.13 Events

### XVIII.13.1 Emitted

| Event | When | Key payload |
|---|---|---|
| `money.obligation.requested` | request submitted | obligation uuid, class, type, subject, amount, origin |
| `money.obligation.sanctioned` | sanction workflow completes | frozen terms, terms checksum, workflow instance |
| `money.obligation.rejected` | sanction refused | reason, actor |
| `money.obligation.disbursed` | disbursement entry written | amount, channel, payout line |
| `money.obligation.movement_recorded` | any ledger entry | entry type, signed amount, run, payslip, new balance |
| `money.obligation.shortfall` | a recovery could not be taken in full | due, taken, short, policy, reason, carried-to period |
| `money.obligation.closed` | balance reaches zero | closing entry, closing date |
| `money.obligation.reopened` | reversal re-opens a closed obligation | reversing run |
| `money.obligation.waived` | waiver approved | amount, reason, approver |
| `money.obligation.written_off` | write-off approved | amount, reason, approver |
| `money.obligation.restructured` | new schedule version active | old/new version numbers, delta |
| `money.obligation.paused` / `.resumed` | pause applied/lifted | periods affected |
| `money.obligation.converted_to_receivable` | exit conversion | exit case, residual |
| `money.schedule.version_created` | any regeneration | reason code, totals |
| `commission.scheme_run.previewed` | preview computed | counts, totals, exclusions by reason |
| `commission.scheme_run.committed` | obligations created | run uuid, payable count, gross total |
| `commission.scheme_run.superseded` | corrective run committed | prior run, delta |
| `commission.clawback.raised` | clawback obligation created | trigger event, attribution method, amount |
| `incentive.awarded` | rule created an incentive obligation | rule + version, amount, settlement period |
| `incentive.declined` | rule matched but a gate or eligibility refused | reason code |
| `expense.claim.submitted` / `.approved` / `.rejected` / `.settled` | | claim uuid, totals, taxable split |
| `expense.limit.breached` | a limit was hit | category, limit row, consumption, disposition |
| `money.gate.evaluated` | any gate evaluation | gate point, verdict, items, decision id |
| `money.gate.overridden` | an override used | actor, reason, verdict overridden |
| `money.reconciliation.failed` | M1, M2 or M3 fails | check code, obligation, expected, actual |

**R-XVIII-157** Every event MUST carry the tenant, the obligation uuid where one
exists, the correlation id and the trace id, so a consumer can join to the trace
without a second query.

**R-XVIII-158** `money.obligation.movement_recorded` MUST be emitted **from the
committed transaction's outbox**, not from the request handler, so an event is
never emitted for a movement that rolled back and never lost for one that
committed. *Test:* an injected rollback emits nothing; an injected crash after
commit still delivers the event on recovery.

### XVIII.13.2 Consumed

| Event | Source | Effect |
|---|---|---|
| `payroll.run.superseded` | Payroll (Part XIV) | Reverse every ledger entry keyed to the run, inside that transaction (R-XVIII-075) |
| `payroll.period.locked` / `.reopened` | Payroll | Freeze/unfreeze settlement into that period |
| `employment.exited` | Employee | Surface every open obligation on the F&F; apply the exit policy (R-XVIII-046) |
| `employment.reactivated` | Employee | Re-open receivables where the type permits |
| `recovery.reversed`, `invoice.credit_noted`, and any scheme-declared trigger | Collections / Billing | Evaluate clawback (R-XVIII-106) |
| `compliance.item.expired` / `.renewed` / `.revoked` | Compliance (Part XXI) | Re-evaluate standing gate verdicts; raise exceptions |
| `attendance.settlement.published` | Attendance (Part XI) | Make the period's figures available to incentive triggers (R-XVIII-116) |
| `config.version.published` (types, schemes, orders, floors, categories, gates) | Configuration | Invalidate resolution caches; recompute impact previews; **never** re-price a settled obligation |
| `org.assignment_changed` | Org | Affects future attribution only; settled attributions are immutable (R-XVIII-138) |
| `client_portfolio.merged` | Collections | Re-point future scheme rates; historical attribution unchanged |

---

## XVIII.14 Explainability

Spine S15's `Explain(entity, field)` is extended by this part with
`Explain(obligation)`, `Explain(obligation_movement)` and
`Explain(payslip_line)` for any line this part produces. Every one is available
to the employee for their own records, and to any actor within data scope.

### XVIII.14.1 What an obligation's trace shows

```
Loan  L-2026-0441 · Salary Loan · sanctioned 14-Aug-2026
──────────────────────────────────────────────────────────────────────
Terms (frozen at sanction, checksum 9f2c…)
  principal            ₹1,50,000.00
  interest             reducing balance, 12.00% per annum
                       [loan type SALARY-LOAN v6, effective 01-Apr-2026 →]
  tenure               24 periods      moratorium 0
  first recovery       2026-09         eligibility trace ↗
  sanctioned by        workflow LOAN-SANCTION v4, instance WI-88213
                       S. Meera 15-Aug 09:41 · A. Rao 18-Aug 16:12

Schedule  version 1 (sanction) · active
  instalment 13 of 24   due 2026-09   ₹7,061.02
                        principal ₹6,246.11   interest ₹814.91

Movements
  18-Aug-2026  disbursement   −₹1,50,000.00   payout batch PB-2026-08-19
  30-Sep-2026  recovery         +₹7,061.02    run PR-2026-09 · payslip PS-…-4412
                                              line "Loan Repayment"
  …
Balance      ₹86,240.15   = the signed sum of 14 entries   [M1 asserted 03-Oct 02:14]
```

### XVIII.14.2 What a payslip line's trace shows

For a recovery, the trace names the orchestration that produced it, in full — the
order version, the floor policy, the headroom, the competing obligations, and the
reason any shortfall occurred (§XVIII.5.7).

For a commission or incentive earning, it names the scheme version, the basis
figure and its source, the slab bands walked with each band's contribution, the
accelerator, the cap, the gate verdict, the settlement period and the statutory
base flags (§XVIII.6.10, §XVIII.7.4).

For a reimbursement, it names the claim, the category version, every limit
evaluated with its consumption, the taxable split and the rule-pack head that
decided it.

**R-XVIII-159** Every derived figure this part contributes to a payslip, a
register or an export MUST be explainable to the paise, naming every artefact
version that produced it. *Test:* the engine contract test — no value without a
trace (Law 4).

**R-XVIII-160** The trace MUST name the **rule-pack version** for any statutory
element and MUST state where a value is recorded as "the value SmartPRS 2's code
currently uses, pending verification". *Test:* the 194H trace renders the pack
version and, until verified, the provenance note.

**R-XVIII-161** An employee MUST be able to see, in ESS, the full trace of every
recovery taken from their pay and every payout made to them, including why a
recovery was skipped or partial. *Test:* the ESS trace for a skipped EMI names
the floor policy and the rupee headroom. *Rationale — today the employee sees a
line item and, at best, a sentence of English prose in a calculation note; a
skipped EMI produces nothing at all.*

---
## XVIII.15 Migration

### XVIII.15.1 Principle

Nothing in this domain is migrated by inference. Every balance that will be
recovered from an employee's pay after cutover must be **reconstructed from
evidence, cross-checked against a second independent source, classified by
confidence, and confirmed by a named person** before the first run. Where the
evidence does not support a balance, the obligation is created but **frozen**,
and it appears on a blocking cutover report.

This is not caution for its own sake. `loans.outstanding` is migrated
`default(0)` and **written by nothing** (grep-verified, `A-payroll.md` §2.6), so
for practically every loan the stored balance is a lie in one direction or the
other. The analysis's migration verdict is explicit: *"Audit every existing loan
for un-recovered or over-recovered instalments before cutover"* (`A-payroll.md`
§9), and Part XXVIII's T8 states that *"reconciliation exceptions are expected and
must be listed, not absorbed."*

**R-XVIII-162** No obligation migrated from SmartPRS 2 may be recovered from, or
paid out, until its balance carries confidence `reconciled` **or** a named person
has confirmed it against the tenant's own records. *Test:* the cutover gate fails
while any obligation is `pending_reconciliation`; the first payroll run refuses
to include one.

### XVIII.15.2 Loans — the reconstruction

**Sources available.**

| Source | Reliability | Notes |
|---|---|---|
| `loans.amount` | the sanctioned principal | the one field consistently populated |
| `loans.emi` | the instalment | populated; the engine reads it |
| `loans.tenure` | **migrated and never read by anything** | a candidate instalment count, unvalidated |
| `loans.outstanding` | **unusable** | `default(0)`, written by nothing |
| `loans.installments_paid` | **runtime column**, present only if a run ever executed; adjusted post-commit in a swallowing catch, decremented twice under concurrent regenerates | corroborating only |
| `loan_recoveries` | **runtime table** created by `generate():2589–2603`; one row per EMI recovered per run; **hard-deleted on regeneration** | the best evidence, where it exists |
| `payslips` stored JSON | the `Loan EMI` deduction key written at `:2657` | the **second, independent source** |
| `loans.installment_amount`, `installments_total`, `type`, `start_month` | **do not exist** | nothing to migrate |
| `loans.tenure_months`, `loans.reason` | **never stored** — dropped by `safeRow()` | permanently lost (`E-…` DEF-REQ-01, PO-13) |

**Algorithm, per loan.**

```
1  principal        := loans.amount
2  recovered_ledger := SUM(loan_recoveries.amount)                  -- may be absent
3  recovered_slips  := SUM(payslip deduction 'Loan EMI' attributable to this loan)
4  paid_counter     := loans.installments_paid                       -- may be absent
5  instalment       := loans.emi
6  count_candidate  := loans.tenure  (if > 0)  else  CEIL(principal / emi)

7  IF recovered_ledger and recovered_slips both exist AND are equal
        AND paid_counter × instalment reconciles to them within one instalment
        AND 0 <= recovered <= principal
     → confidence = reconciled ;  outstanding = principal − recovered
8  ELSE IF exactly one of the two exists AND 0 <= it <= principal
     → confidence = inferred   ;  outstanding = principal − it
9  ELSE IF recovered > principal
     → confidence = disputed   ; OVER-RECOVERY (see D-XVIII-05)
10 ELSE
     → confidence = disputed   ; outstanding = NULL ; state = pending_reconciliation
```

**Expected distribution.** Because the `outstanding <= 0` guard fires for every
loan on a freshly migrated schema (`:696`), the dominant case is
`recovered = 0`, `outstanding = principal`, and step 7 lands on
`reconciled` **only because nothing was ever recovered**. The migration report
must say so in those words, so no one mistakes a clean reconciliation for a
healthy loan book.

**Schedule reconstruction.** A schedule version 1 is generated with
`generated_reason_code = migration`, `interest_method = none` unless the tenant
supplies terms, `instalment_amount = loans.emi`, `instalment_count =
count_candidate`, and `first_recovery_period` set to the first open period after
cutover. Every already-recovered instalment is marked `recovered` and backed by
an `opening_balance` plus reconstructed `recovery` entries carrying
`legacy_source_ref`.

**R-XVIII-163** Every reconstructed recovery entry MUST carry
`legacy_source_ref` naming the SmartPRS 2 row it came from, and MUST be
distinguishable from a SmartPRS 3 movement in every report. *Test:* the loan
register shows migrated and native movements distinctly.

**R-XVIII-164** The migration MUST produce a **loan reconciliation report** per
tenant listing, for every loan: principal, both recovered figures, their
difference, the counter, the confidence, and the proposed outstanding. It MUST be
signed off before cutover. *Test:* cutover is gated on the sign-off record.

### XVIII.15.3 Advances — the harder case

There is **no advance ledger of any kind** and **nothing is ever stamped on the
`advances` row**: no `recovered_run_id`, no `recovered_amount`, no `recovered_at`
(BR-PAY-033; `M-…` C-12). The only evidence that an advance was ever recovered is
the `Salary Advance Recovery` deduction on a stored payslip — and because
`advanceRecovery()` sums **all** approved advances created in the month into one
figure, that deduction cannot be attributed to a particular advance where an
employee had more than one in a month.

**R-XVIII-165** Advance balances MUST be migrated as follows: where an employee
had exactly one approved advance in a month and a matching payslip deduction
exists, the advance is `inferred` and closed or reduced accordingly. In **every
other case** the advance is created `pending_reconciliation` with
`outstanding = NULL` and appears on the blocking report. *Test:* an employee with
two advances in one month produces two `pending_reconciliation` rows and no
guessed split. *Rationale — the analysis's own instruction: "Rebuild with a
schedule and a ledger; **reconcile outstanding balances manually**"
(`A-payroll.md` §9).*

**R-XVIII-166** `advances.per_month` and `advances.balance` MUST NOT be trusted
as balances. They are migrated columns that **no code has ever read or written**
(`A-payroll.md` §2.7). They may be presented on the reconciliation report as an
operator hint, labelled as never-maintained. *Test:* no migration path assigns
`outstanding` from `balance`.

### XVIII.15.4 Commissions, incentives and arrears

**R-XVIII-167** Every `commissions` row's true state MUST be reconstructed from
**evidence, not from `status`**, because on non-strict MySQL the column was
silently blanked on every insert and on strict MySQL the insert failed
(DEF-P03). Evidence, in precedence order: a `commission_payments` sum equal to
the amount and a `locked_at` → `paid`; `locked_at` set → `paid`;
`decided_at` + `decided_by` with a non-rejection remark → `approved`;
a rejection remark → `rejected`; otherwise `pending`. Rows that cannot be
classified are quarantined and reported. *Test:* the sum of reconstructed states
equals the row count; no row is dropped.

**R-XVIII-168** Whether a historical `commissions.amount` is a **gross or a net**
MUST be determined per row before migration, using the presence and value of the
runtime `gross_amount`, `tds_rate` and `tds_amount` columns, and MUST be recorded
on the migrated obligation. Rows where it cannot be determined are quarantined.
*Test:* no obligation is created with an ambiguous amount semantics.
*Rationale — `commissions.amount` holds the NET after TDS on the bulk path
(`IncentiveController.php:217–221`) and whatever the client posted on the request
path, while `gross_amount` is a runtime column that may be absent entirely
(`A-payroll.md` §2.9; Part XXVIII open question A5, D-XXVIII-04).*

**R-XVIII-169** `commission_payments` MUST migrate as `payout` ledger entries
against the reconstructed obligation, and `commission_logs` as
`obligation_state_events`, with `commission_logs.by` re-keyed from **name strings
to user ids** where resolvable and flagged where not. *Test:* the migrated
passbook sums to the migrated payments, exactly.

**R-XVIII-170** `incentive_schemes` MUST be migrated by **detecting which of the
two disjoint shapes a row carries** and transforming accordingly; the legacy
`name`, `basis` and `clawback` columns are dropped; `employee_ids` is normalised
from a CSV `TEXT` column into `cfg_commission_scheme_audience` rows, with every
unresolvable id reported. *Test:* the audience row count equals the sum of the
parsed CSV lengths, minus reported failures.

**R-XVIII-171** Arrears riding the `commissions` table with
`purpose = 'Salary Arrears'` and `tds_rate = 0` MUST migrate as obligations of
class `arrear`, and the tenant MUST be given a report of **backdated salary
decreases that produced no recovery**, because `IncrementController::apply()`
creates an arrear only when `newCTC > oldCTC` — a decrease produces nothing and
the overpayment is never recovered. *Test:* the report exists and lists every
increment row whose new CTC is below its old CTC. *Rationale — `A-payroll.md` §9:
"no decrease path exists — audit for unrecovered overpayments".*

**R-XVIII-172** Historical commission and incentive amounts MUST NOT be
recomputed at migration. They are migrated as **immutable facts** with their
original figures and a provenance note. *Test:* no migrated obligation's amount
differs from its source row's. *Rationale — spine S4 and the analysis's payslip
verdict: "re-render historical payslips from stored JSON, never recompute".*

### XVIII.15.5 Expenses, clawbacks and what does not come across

| SmartPRS 2 | Disposition |
|---|---|
| `expenses` rows | Migrate as `expense_claims` with a single line each, category `LEGACY-UNCATEGORISED` (tenant re-maps at leisure), amount, date, state reconstructed from `status`/`decided_at`. **The `reason` is not there to migrate** (`safeRow()` dropped it) — see D-XVIII-15 |
| `clawbacks` rows | Migrate as obligations of class `clawback`. `month` (the migrated column) is the recovery period where present; **`cycle_month` never existed as a column**, so bounce-generated clawbacks have **no target month and no financial year** (`E-…` DEF-REQ-04) and are created `pending_reconciliation` |
| `clawbacks.recovered_run_id` / `-amount` / `-at` (runtime columns) | Migrate as reconstructed `recovery` ledger entries keyed to the migrated run |
| `loan_recoveries` | Source evidence only; the table itself is not carried forward |
| `commission_payments`, `commission_logs` | Migrated (R-XVIII-169), then retired |
| `loans.installment_amount`, `installments_total`, `type`, `start_month` | **Do not exist.** Nothing to migrate |
| `loans.tenure_months`, `loans.reason`, `advances.reason`, `expenses.reason`, `bonus.kind`, `entered_by`, `company_id` on five modules | **Never stored.** Permanently lost; cannot be recovered from the database (`E-…` DEF-REQ-01, PO-13) |
| `advances.per_month`, `advances.balance` | Presented as hints; never trusted (R-XVIII-166) |
| The request-scoped bulk-calculator configuration | **Nothing to migrate** — it lived in the HTTP request |
| `points_ledger`, `point_rules` | Migrated as-is; the **keyword bucketing is not carried** — each legacy rule is presented to the tenant for explicit re-classification, with its current (possibly inverted) behaviour shown alongside |
| `SalaryRun`, `SalarySlip`, `Loan` models, `SalaryRunController`, `PayslipController`, `LoanController` | **Removed.** Unrouted, target tables that do not exist, Blade views absent from the repository (`A-payroll.md` §9) |
| `App\Models\Loan::dueInstallment()` | Removed. Dead code |
| The float EMI comparison, the `0.005` money tolerances, the `LIKE 'run #<id> %'` reversal, the `remarks LIKE '%withdrawn%'` reinstatement, the 50-row limits, the substring reimbursement test, the nine payslip grouping keywords, the points keyword buckets | **Not carried.** Each is a defect; §XVIII.1 states why |

**R-XVIII-173** The migration MUST NOT create any obligation whose type,
component, workflow, gate policy or recovery order is not published in the
tenant's configuration at cutover. *Test:* a dry run that names every missing
artefact before any row is written.

**R-XVIII-174** Every migrated obligation MUST satisfy M1 and, where it has a
schedule, M3 on day one, or be quarantined. *Test:* the cutover report's
reconciliation section is empty or the cutover is blocked.

---

## XVIII.16 Edge cases and failure states

| # | Situation | Specified behaviour |
|---|---|---|
| 1 | Net after statutory deductions is zero or negative | No recovery is taken. Every due obligation records a shortfall with reason `insufficient_net` and its policy's disposition. Net is never negative |
| 2 | The net floor is above the available net before any recovery | Headroom is zero; every recovery is a shortfall; the payslip states the floor. No recovery is "half applied" |
| 3 | Two obligations of equal priority, equal age, equal amount | The order's `tie_break_code` decides; if it cannot, the order **failed publication** and cannot be in force |
| 4 | An employee has 60 open obligations | All 60 are evaluated. There is no row limit (R-XVIII-037) |
| 5 | An EMI exactly equals the available headroom | It is recovered. Integer-paise comparison, not float (R-XVIII-019) |
| 6 | An EMI exceeds the headroom by one paise | Governed by the shortfall policy; under `skip` nothing is taken and the trace names the one-paise gap |
| 7 | The final instalment is smaller than the level instalment | The schedule's final line carries the exact residue; M3 asserts the closure |
| 8 | A loan is fully recovered mid-run and a second loan awaits | The first closes inside the transaction; the second competes for the remaining headroom in the same plan |
| 9 | A run is superseded after a loan closed on its final EMI | The recovery is reversed, the schedule line returns to `scheduled`, the loan returns to `recovering`. Superseding twice does not double-reverse (R-XVIII-012) |
| 10 | Two operators regenerate the same run simultaneously | One transaction wins; the other fails on the run's uniqueness constraint. Neither produces a double reversal |
| 11 | A manual repayment is recorded between two runs and the run is regenerated | The manual entry is untouched — reversal is keyed to `payroll_run_id`, not to text (R-XVIII-078) |
| 12 | An obligation's settlement period is locked | Settlement moves to the next open period, recorded with a reason; it is never silently dropped |
| 13 | An obligation is approved with no resolvable settlement period | Approval is refused (R-XVIII-099). It cannot become an orphan |
| 14 | An employee exits with an open loan and an insufficient F&F | The exit policy applies; the residue converts to a receivable and the exit cannot settle until every obligation is resolved (R-XVIII-046) |
| 15 | An employee exits with an over-recovered obligation | A `pay_to_employee` obligation is raised for the excess and appears on the F&F |
| 16 | An employee is rehired with an open receivable | The receivable is re-linked to the new employment where the type permits; the ledger is continuous |
| 17 | A commission's scheme version is superseded after the obligation was created | The obligation keeps its pricing version. A correction is an adjustment run, never a re-price (R-XVIII-104) |
| 18 | A commission is clawed back after the employee has left | The clawback becomes a receivable; recovery follows the tenant's receivable process |
| 19 | A clawback exceeds everything the employee will ever be paid | It carries forward indefinitely and appears on the ageing receivable report. It is never silently written off |
| 20 | A bulk achievement file contains an employee not in the scheme's audience | The row's outcome is `ineligible`, reported, not dropped (R-XVIII-093) |
| 21 | A bulk file contains a duplicate subject in the same run | The second row's outcome is `duplicate`; the operator chooses aggregate or reject at preview |
| 22 | An achievement figure is below the threshold | Outcome `below_threshold`, reported with the figure and the threshold |
| 23 | A computed commission is zero after a cap | Outcome `zero_amount`, reported. **Not silently dropped** — the SmartPRS 2 behaviour at `commit():125–132` |
| 24 | The gate blocks a row in a bulk run | Outcome `gate_blocked` with the gate decision id; the row is excluded and listed |
| 25 | Gate evaluation throws | Verdict `error_blocked`; the money event is blocked; an operator alert is raised (R-XVIII-146) |
| 26 | A compliance item expires between approval and payslip inclusion | The gate re-evaluates at `payslip_inclusion`; a `block` excludes the line and lists the exception |
| 27 | An expense claim is within limit at submission and over it at approval | Caught at approval against the state at that moment, with the intervening claims named (R-XVIII-126) |
| 28 | Two claims are approved simultaneously against the last of a limit | The consumption row lock serialises them; one is approved, one is routed by `on_breach_code` (R-XVIII-127) |
| 29 | A GST invoice number is presented twice by two employees | The database unique constraint refuses the second; both claims are shown to the approver |
| 30 | A category's taxability changes mid-year | Resolution is by the claim's date, so settled claims are untouched; the impact preview names the affected pending claims |
| 31 | A reimbursement run and a payroll run both settle the same claim | Impossible: the obligation's uniqueness constraint on `(obligation, payslip, entry_type)` plus a single `settlement_channel` |
| 32 | A payout batch is released and the bank returns a line | A `payout_returns` row plus a compensating ledger entry; the obligation re-opens. The employee is not marked paid |
| 33 | An interest-bearing loan is paused | Whether interest accrues during the pause is the type's declared moratorium treatment; the schedule shows it |
| 34 | A restructure is approved after the period it was meant to affect closed | It takes effect from the next open period; the trace states the deferral |
| 35 | A scheme's currency differs from the employee's payroll currency | Blocked at publish. Cross-currency obligations are out of scope for v3.0 and the validator says so |
| 36 | A migrated loan's confidence is `disputed` | It is `pending_reconciliation`: visible, reportable, never recovered from (R-XVIII-162) |
| 37 | The scheduler or queue worker is stopped | Nothing in this part settles by background job alone; run-driven movements are synchronous within the run. Clawback evaluation queues and the operations dashboard shows the backlog (spine S18) |
| 38 | Two tenants' obligations share a sequence value | Impossible: every sequence is `(tenant_id, sequence_code, scope_key, period_key)` allocated under `FOR UPDATE` (Part XXVIII) |
| 39 | An obligation type is retired while obligations are open | Retirement is blocked while any obligation of the type is open, exactly as a workflow cannot be retired with instances in flight |
| 40 | Someone attempts to correct a balance directly | There is no such surface, no such API and no such permission. The only path is a ledger entry (R-XVIII-042) |

---
## XVIII.17 Tests

Every test below asserts **exact rupee-and-paise values**, not tolerances, and
every money assertion is in integer paise. `P0` tests block a release.

### XVIII.17.1 Loan schedule and arithmetic — exact values

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Interest-free, uneven division.** ₹50,000.00 over 7 instalments, 2dp half-up, residue to last | instalments 1–6 = ₹7,142.85; instalment 7 = ₹7,142.90; **Σ = ₹50,000.00 exactly**; `principal_total = grand_total` |
| P0 | **Flat interest.** ₹1,20,000.00, 10% p.a. flat, 12 instalments | interest total ₹12,000.00; each instalment ₹11,000.00 with principal ₹10,000.00 and interest ₹1,000.00; Σ instalments = ₹1,32,000.00 exactly |
| P0 | **Reducing balance.** ₹1,50,000.00, 12% p.a., 24 instalments | level instalment ₹7,061.02; instalment 1 interest ₹1,500.00 / principal ₹5,561.02 / closing ₹1,44,438.98; instalment 2 interest ₹1,444.39; **instalment 24 closes at exactly ₹0.00**; Σ principal components = ₹1,50,000.00 exactly |
| P0 | **Property test, all three methods.** 10,000 random (principal, rate, tenure) triples | Σ principal components = principal, and Σ totals = principal + interest_total, **exactly**, every time |
| P0 | **The schedule exists at sanction.** Immediately after sanction, before any disbursement | `instalment_count` rows exist; M3 holds. *(SmartPRS 2 has no schedule at all)* |
| P0 | **Moratorium.** 2-period moratorium, all three interest treatments | three exactly asserted schedules; the first recovery period differs from the disbursement period by the moratorium |
| P1 | **Prepayment, reduce tenure.** ₹30,000.00 prepaid on the reducing-balance loan after instalment 6 | new schedule version; trailing instalments dropped; level instalment unchanged; closing balance exactly ₹0.00; version 1 still retrievable |
| P1 | **Prepayment, reduce instalment.** Same prepayment, other setting | same instalment count; new level instalment asserted exactly |
| P1 | **Foreclosure quote reproducibility.** Quote on day D, executed on day D | identical amount; executed on D+5, recomputed and the delta shown |

### XVIII.17.2 Recovery orchestration — exact values

| P | Scenario | Must assert |
|---|---|---|
| P0 | **The legacy-equivalent configuration.** Net ₹20,000.00; loan EMI ₹15,000.00 (`skip`), advance ₹10,000.00 (`partial`), clawback ₹5,000.00 (`partial`); order loan → advance → clawback; floor ₹0 | loan ₹15,000.00; advance ₹5,000.00; clawback ₹0.00; net ₹0.00; the advance shows a ₹5,000.00 shortfall and the clawback a ₹5,000.00 shortfall, **both as structured rows**. This reproduces SmartPRS 2's arithmetic on purpose, to prove the migration is behaviour-preserving where it should be |
| P0 | **The same inputs, order reversed by configuration.** clawback → advance → loan | clawback ₹5,000.00; advance ₹10,000.00; loan ₹5,000.00 **partial** (policy `partial`) or ₹0.00 (policy `skip`); each asserted exactly. **No code change between the two runs** |
| P0 | **All four shortfall policies.** Advance ₹20,000.00 due, headroom ₹12,000.00 | the four outcomes of §XVIII.4.4, each asserted to the paise, including the resulting obligation balance and schedule state |
| P0 | **Net floor, `greater_of`.** Gross ₹45,000.00, statutory ₹4,200.00, floor `greater_of(₹5,000.00, gross % 30)`; loan ₹7,061.02, advance ₹20,000.00, clawback ₹9,500.00 | floor ₹13,500.00; headroom ₹27,300.00; loan ₹7,061.02; advance ₹20,000.00; clawback ₹238.98; **net exactly ₹13,500.00**; clawback shortfall ₹9,261.02 with reason `net_floor` and `carried_to_period` = the next period |
| P0 | **Floor exemption.** The same inputs with the clawback's class declared floor-exempt | the clawback recovers below the floor; the payslip states that it did |
| P0 | **Exact-fit EMI.** Headroom exactly ₹7,061.02, EMI ₹7,061.02 | **recovered**. In integer paise. *(The float comparison at `:1357` is the reason this test exists)* |
| P0 | **One paise short.** Headroom ₹7,061.01, EMI ₹7,061.02, policy `skip` | nothing recovered; the trace states a ₹0.01 gap |
| P0 | **Net never negative, and the floor is not a floor of zero** | with recoveries exceeding gross, net = the floor, not ₹0.00, unless the floor is zero |
| P0 | **No row limit.** 60 open obligations | all 60 evaluated; the 51st is not silently skipped. *(SmartPRS 2 carries `->limit(50)` on two queries)* |
| P0 | **M1, M2, M3 hold for the whole run** | `SUM(ledger) = outstanding` for every touched obligation; `SUM(payslip money-movement lines) = SUM(ledger entries for that payslip)`; every scheduled obligation reconciles. A deliberately corrupted row **aborts the run** |

### XVIII.17.3 The reversal tests SmartPRS 2 cannot pass

Each of these fails on SmartPRS 2 today, for the reason cited. They are the
acceptance criteria for R-XVIII-003 and R-XVIII-075.

| P | Scenario | Must assert | Why SmartPRS 2 fails |
|---|---|---|---|
| P0 | **Atomicity of a recovery.** Inject a failure immediately after the loan ledger insert and before commit | **no payslip and no ledger entry** exist; the loan's balance is unchanged | side effects run *after* `DB::commit()` in a swallowing catch (`M-…` C-2) |
| P0 | **The payslip and the ledger never disagree.** Inject a failure in the loan counter update | the run fails; there is no payslip stating an EMI that the loan does not record | *"a payslip can say Loan EMI ₹5,000 deducted while `installments_paid` never advances"* (`M-…` C-2) |
| P0 | **Advance recovery is reversible.** Generate → regenerate | the advance's recovery entry is reversed; its balance returns to the pre-run figure | **advances have no ledger at all** (BR-PAY-033) |
| P0 | **Loan counter decremented exactly once.** Two concurrent supersessions of the same run | exactly one reversal entry per original entry; the loan is `recovering` with one instalment outstanding, **not** `sanctioned` | both regenerates run the reversal; `decrement()` fires twice and re-opens a closed loan (`M-…` C-3) |
| P0 | **A closed loan re-opens correctly.** Close a loan on its final EMI, then supersede the run | loan `recovering`; schedule line 24 `scheduled`; balance = the final instalment; regenerating a second time changes nothing further | the auto-close guard can never fire, so the case does not arise — and where it does, the double decrement corrupts it (DEF-P02, C-3) |
| P0 | **Commission is paid exactly once under concurrency.** Two simultaneous generates of the same run | exactly one `payout` entry; the second transaction fails on `U(tenant, obligation, payslip, entry_type)` | both read `locked_at IS NULL`, both lock, **both insert a passbook debit** (`M-…` C-13) |
| P0 | **Reversal is identity-based, not text-based.** Create a manual ledger entry whose `reason_text` contains "run #1"; supersede run #1 | the manual entry survives; every entry keyed to run #1 is reversed | reversal is `reference LIKE 'run #<id> %'`, safe by one space, and deletes matching manual entries (`M-…` C-13) |
| P0 | **A failed regeneration leaves the prior run intact.** Force a failure after the supersession begins | the previous run, its payslips, its line statuses, its approval stamps and its rejection remark all still exist | the prior run's payslips are hard-deleted **outside any transaction** before the replacement transaction opens (`M-…` C-3) |
| P0 | **Byte-identical re-run.** Generate, supersede, regenerate with unchanged inputs | the second run's figures are identical to the first's, field by field, and the input-snapshot diff is empty | a regenerate re-prices at today's rates, today's CTC and today's structure with no warning (`A-payroll.md` §5 Q5) |
| P0 | **Over-payment is impossible.** Two simultaneous ₹10,000.00 payments against a ₹10,000.00 balance | one accepted, one refused | both read the same balance and both insert; ₹60,000 lands against a ₹50,000 commission (`M-…` C-7) |
| P0 | **No money tolerance.** A payment of balance + ₹0.01 | refused | the literal `0.005` accepts it and locks the record forever (`E-…` §4.6) |
| P0 | **Idempotent commit.** Double-post a scheme-run commit, a disbursement and a claim submission | one effect each | only 2 of ~180 mutating buttons disable in flight (`M-…` C-1) |

### XVIII.17.4 Commission, incentive and reimbursement — exact values

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Marginal slab.** ₹12,40,000.00 against `COLL-SLAB-A` marginal | band contributions ₹5,000.00 + ₹7,500.00 + ₹4,800.00; base ₹17,300.00 |
| P0 | **Whole-amount slab.** The same figure, `computation = whole_amount_at_matched_rate` | ₹24,800.00. **The two settings must differ and both must be asserted** |
| P0 | **Above every band.** A figure above the top band's floor | priced at the open band's rate **by definition**, not by loop initialisation; and a slab table with a gap or an unordered band **fails to publish** |
| P0 | **Accelerator and cap.** Achievement 124%, accelerator ×1.25 above 110%, cap 50% of ₹40,000.00 gross | ₹17,300.00 → ₹21,625.00 → capped to **₹20,000.00**, with `cap_applied_code` and `capped_amount = ₹1,625.00` recorded |
| P0 | **Gross is stored, not net.** The same commission with a 194H head | obligation `principal_amount = ₹20,000.00`; the withholding is a **separate deduction line**; the taxable base includes ₹20,000.00 |
| P0 | **Clawback by `recompute`.** A ₹2,00,000.00 recovery reversed inside the window | recomputed base ₹13,300.00 (₹5,000.00 + ₹7,500.00 + ₹800.00); achievement 104% so **no accelerator**; clawback exactly **₹6,700.00** |
| P0 | **The other three attribution methods** on the same reversal | three different, exactly asserted amounts, proving the choice is configuration |
| P0 | **No row is silently dropped.** A bulk file whose rows include a zero-amount, a below-threshold, an ineligible, a gate-blocked and a points-gated subject | every row appears in `/outcomes` with its reason code; the payable count plus the excluded count equals the row count |
| P0 | **Preview equals commit.** A 1,000-row preview then commit | every committed obligation's gross equals its preview figure, exactly; and `gross_total` equals the sum of the payable rows |
| P0 | **Perfect attendance, the inverted case.** 26 of 26 payable days with **3 lates over grace** | **no incentive obligation**, and the stored regression case for the rule asserts it at publish. This is BR-REC-036's exact failure |
| P0 | **Perfect attendance, the paying case.** 26 of 26, 0 lates over grace, 14 months' service, day shift | one obligation, ₹1,000.00, settlement period = earned + lag; night shift → ₹1,500.00 |
| P0 | **Behaviour does not follow a name.** Rename the rule to "Perfect attendance — no lates" and re-run both cases | identical results. The CI rule of R-XVIII-113 additionally fails the build on any name-derived branch |
| P0 | **Statutory bases apply to extras.** A commission type declaring `is_esi_base` and `is_taxable` | the payslip's ESI and taxable base both include the commission. *(Today none of PF, ESI, PT or TDS applies to any extra — BR-STAT-006)* |
| P0 | **One definition of gross.** Any payslip with a reimbursement | `payslips.gross` equals the printed gross, exactly. *(Today they differ and both are labelled gross — NEW-PAY-31)* |
| P0 | **Reimbursement statutory treatment follows the flag, not the name.** Two categories, identical names, opposite flags | opposite statutory outcomes; and a category named "Travel" with `is_reimbursement` set behaves identically to one named "Travel Reimbursement" |
| P0 | **Per-category limits by scope.** Grade A2 in Hyderabad against a grade limit and a branch limit | the branch limit wins by specificity, and the trace shows the arithmetic; an ambiguous pair **fails to publish** |
| P0 | **Limit consumption under concurrency.** Two ₹6,000.00 claims against a ₹10,000.00 monthly limit, approved simultaneously | one approved; one routed by `on_breach_code` |
| P0 | **Duplicate GST invoice.** The same supplier GSTIN and invoice number twice | the database refuses the second |
| P0 | **The claim's reason is stored.** Submit a claim with a reason and read it back through the API and the voucher | present and identical. *(Today it is silently discarded and the user is told the claim succeeded — `E-…` DEF-REQ-01)* |
| P0 | **Unknown field fails loudly.** Post `tenure_months` to a type declaring `instalment_count` | HTTP 422, `code = field.unknown`, `field = tenure_months`, **no row written** |
| P1 | **Team split is penny-safe.** A ₹1,00,000.01 pool split three ways | ₹33,333.34 / ₹33,333.34 / ₹33,333.33 by the declared ordering, **Σ = ₹1,00,000.01** |
| P1 | **Cost-centre split.** A ₹10,000.01 claim split three ways | Σ exactly ₹10,000.01; the GL export reconciles |
| P1 | **Attribution is frozen.** Approve a claim, transfer the employee, then report | the claim reports against the cost centre attributed at approval |

### XVIII.17.5 Gate tests

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Block.** An expired DRA certificate at `payslip_inclusion`, severity `block` | the line is excluded; the exception is listed; a `compliance_gate_decisions` row records every item evaluated |
| P0 | **Warn.** A lapsed PCC within grace | the payout proceeds **and** appears on the compliance-warning exception report, durably |
| P0 | **Off.** Severity `off` | no gate effect, and a decision row is still written |
| P0 | **Missing record never passes.** A subject with no DRA record at all | blocked, exactly as an expired one |
| P0 | **Fail closed.** Fault injection inside gate evaluation | verdict `error_blocked`; the money event is blocked; an alert is raised |
| P0 | **Payroll is gated.** A tenant gates `payroll_run_approval` | the run cannot be approved while an in-scope employee fails at `block`. *(Payroll checks nothing today — `N-…` §1.4)* |
| P0 | **Non-employee workers are gated.** An off-roll agent with an expired DRA | blocked at `worker_earning_approval` by the same policy. *(`ComplianceController` contains no reference to `offroll_agents` — `N-…` V-01)* |
| P0 | **Override is recorded.** An override of a block without a reason | refused; with a reason, recorded on the decision and on the exception report |
| P1 | **Item-type-agnostic.** A gate configured on agent authorisation for a named portfolio | blocks a commission on that portfolio when the authorisation has lapsed. *(Impossible today — the gate's subject is hard-coded to DRA, and there is no bank entity — `N-…` §5 item 42, §1.8.1)* |

### XVIII.17.6 Migration tests

| P | Scenario | Must assert |
|---|---|---|
| P0 | **A migrated loan with no recovery evidence** | `outstanding = principal`; confidence `reconciled`; **and the report explicitly says nothing was ever recovered** |
| P0 | **A migrated loan whose ledger and payslip evidence disagree** | confidence `disputed`; state `pending_reconciliation`; excluded from the first run; on the blocking report |
| P0 | **An over-recovered loan** (`recovered > principal`) | flagged; the excess is quantified; no obligation is silently closed |
| P0 | **An employee with two advances in one month** | both `pending_reconciliation`; **no guessed split** |
| P0 | **A commission with a blanked `status`** | its state is reconstructed from evidence, not from the column, and every row is classified or quarantined |
| P0 | **A bounce-generated clawback with no month and no financial year** | created `pending_reconciliation`, not silently assigned to the current period |
| P0 | **Every migrated obligation satisfies M1 (and M3 where scheduled)** | or the cutover is blocked |
| P0 | **The cutover gate.** Any obligation left `pending_reconciliation` | cutover fails with the count and the list |
| P1 | **A legacy points rule named "Perfect attendance — no lates"** | presented for explicit re-classification, with its **current inverted behaviour shown**, and never migrated as a late penalty by default |
| P1 | **`incentive_schemes` in both shapes** | both detected and transformed; `employee_ids` normalised to rows; unresolvable ids reported |

### XVIII.17.7 Platform conformance

| P | Assertion |
|---|---|
| P0 | No float arithmetic in `Domain\MoneyMovement` (type-level ban) |
| P0 | No DDL outside `database/migrations`; `migrate:fresh` reproduces production structure byte-identically |
| P0 | No `ENUM` column in any table of this part; every status is `VARCHAR(40)` with an FK to `status_values` |
| P0 | Every route in §XVIII.12 has an explicit policy binding; fault injection inside authorisation **denies** |
| P0 | Cross-tenant isolation tests exist for every aggregate and every endpoint in this part |
| P0 | `UPDATE` and `DELETE` on `obligation_ledger_entries` are refused at the database for the application user |
| P0 | No `DELETE` verb appears under any path of this part in the OpenAPI document |
| P0 | No engine derives behaviour from a name, label, remark, purpose or reason field (R-XVIII-113) |
| P0 | Every engine call returns a trace or throws |

---
## XVIII.18 Compliance with the seven laws

| Law | How this part satisfies it | Enforcing test |
|---|---|---|
| **1 — No business literal in code** | Obligation types, loan types, advance types, interest methods and rates, tenure limits, recovery order, shortfall policies, net floors, slab bands, accelerators, caps, TDS heads, incentive triggers, expense categories, limits, gate severities, gate points bound, approval thresholds and every payslip label are configuration rows | R-XVIII-002's targeted grep for the seven hard-coded labels; the Law 1 static-analysis rule; R-XVIII-113's ban on name-derived behaviour |
| **2 — No schema change at runtime** | Every table in §XVIII.11 is created by a migration. `loan_recoveries`, `commission_logs`, `commission_payments`, `bank_invoices` and the live `incentive_schemes` shape — all created at request time today — are replaced by migrated tables | R-XVIII-152; `migrate:fresh` byte-identity |
| **3 — Nothing is overwritten** | Ledger entries are append-only; schedules are versioned and superseded; scheme runs are superseded, never edited; sanctioned terms are frozen with a checksum; obligations are never deleted; corrections are compensating entries | R-XVIII-010, R-XVIII-040, R-XVIII-076, R-XVIII-104, R-XVIII-153 |
| **4 — Every number explains itself** | Every obligation, movement, schedule line, commission price, incentive award, limit evaluation and gate verdict carries a trace naming inputs, artefact versions, conditions matched and arithmetic performed | R-XVIII-017, R-XVIII-159, R-XVIII-160, R-XVIII-161; §XVIII.14 |
| **5 — Authorisation is server-side and fails closed** | Every route carries a policy binding; self-service subjects resolve from the session; the compliance gate returns `error_blocked` on any evaluation error | R-XVIII-063, R-XVIII-146, R-XVIII-156; the CI route sweep |
| **6 — Tenancy is structural** | `tenant_id` is `NOT NULL` on every table here, first in every unique index, and applied by the persistence layer | §XVIII.11; the generated cross-tenant isolation tests |
| **7 — Money is exact** | Integer paise end to end; `ALLOCATE` residue-to-last on every split (schedules, team pools, cost-centre allocations); rounding declared per component with no default; three blocking reconciliation assertions | R-XVIII-004, R-XVIII-018, R-XVIII-019, R-XVIII-020, R-XVIII-028; §XVIII.17.1's property test |

---

## XVIII.19 Open items — PRODUCT OWNER DECISION REQUIRED

Consolidated. Each carries a recommendation and its trade-off.

| Ref | Decision | Recommendation | Trade-off |
|---|---|---|---|
| **D-XVIII-01** | Are part-disbursed (tranche) loans in scope for v3.0? | **No.** One disbursement per loan; a facility is several loans under a group | Tranche financing becomes clerical; supporting it means regenerating the schedule per tranche and widening the reconciliation surface for a case no analysed customer has |
| **D-XVIII-02** | Is the recovery order tenant-editable, or Ametecs-only? | **Tenant-editable, classified `high_risk`** — diff, impact preview and maker-checker approval before publication | A careless reorder silently changes who is paid first in every future shortfall; the `high_risk` classification and the impact preview are the compensating controls |
| **D-XVIII-03** | Are commissions and incentives prorated for mid-period joiners and leavers? | **Not by default** — earned on performed work, not elapsed time — with proration available per type for tenure-based and flat monthly incentives | Matches current behaviour and avoids re-pricing at cutover; prorating by default would silently reduce every joiner's first commission |
| **D-XVIII-04** | **Blocking.** What happens to SmartPRS 2 loan and advance balances that cannot be reconciled? | Create every obligation, but **frozen** (`pending_reconciliation`), excluded from payroll, on a blocking cutover report that a named person per tenant must clear against the tenant's own records. Do **not** import a computed guess | Cutover cannot complete until someone does the work, which will delay some tenants. The alternative — importing a guess — means the first SmartPRS 3 payslip deducts a number nobody can defend, on a book where `outstanding` is `default(0)` and written by nothing. This is the single largest cutover risk in this part |
| **D-XVIII-05** | Loans found **over-recovered** at migration (the "recovered forever" branch of DEF-P02) | Quantify the excess per employee, present it, and **raise a `pay_to_employee` obligation for the excess**, settled in the first run after confirmation | Refunding is the defensible outcome and may be a material cash event for a tenant that ran the defect for years; not refunding leaves a known over-collection on the books |
| **D-XVIII-06** | Is a historical `commissions.amount` a gross or a net, and who confirms? | Determine per row from the runtime `gross_amount` / `tds_amount` columns; **quarantine every ambiguous row** and require tenant confirmation | Some tenants will have thousands of ambiguous rows; treating them all as gross overstates taxable income, treating them all as net understates it. Neither may be assumed |
| **D-XVIII-07** | **Material.** Do commission, incentive, overtime, night allowance and arrears now enter the PF, ESI, PT and income-tax bases? | **Yes, governed by per-type flags resolved against the rule pack** — because BR-STAT-006 means none of them attracts any statutory deduction today, which the analysis identifies as a live compliance exposure (ESI on overtime; all of it taxable salary) | This **increases employer and employee cost** the month it takes effect, and every affected customer must be told before cutover, with a quantified impact preview per tenant. Continuing the current behaviour is cheaper and is a compliance risk Ametecs would be knowingly carrying forward. **The correct flag values are a compliance-owner determination (spine D-03), not a decision this document makes** |
| **D-XVIII-08** | At cutover, do loans keep the **full-EMI-only** rule or move to `partial`? | Seed **`skip`** (exactly today's behaviour) and present `partial` as a one-click change with an impact preview | Seeding `partial` silently changes what employees see on their first SmartPRS 3 payslip; seeding `skip` preserves a rule the analysis records as one "most clients expect" to be different |
| **D-XVIII-09** | The seeded recovery order and net floor at cutover | Seed the order **exactly as today** (statutory → loan → advance → clawback) and the floor at **zero**, then raise a Configuration Studio task per tenant to choose | Zero is not a real policy and leaves employees exposed to a zero-net month; but any non-zero floor Ametecs picks would be Ametecs setting a customer's employment policy |
| **D-XVIII-10** | Is retroactive re-pricing of scheme claims retained? | **No.** Replace it with an explicit, previewed, approved **adjustment run** | Today an edit to a live scheme re-prices every unlocked claim and returns approved ones to Pending, silently. Removing it means correcting a mis-set rate takes an extra step; keeping it means a rate typo can silently rewrite a month of agent earnings |
| **D-XVIII-11** | Backdated salary **decreases**, which produce no arrear and no recovery today | Implement negative arrears as ordinary `recover_from_employee` obligations, **and** deliver a migration report of every historical decrease that produced nothing | Recovering historical overpayments may be commercially or legally sensitive per tenant; not reporting them means Ametecs knows about an unrecovered overpayment and says nothing |
| **D-XVIII-12** | Do commission schemes, clawbacks, the DRA gate, portfolios and the bank billing pack ship in core, or in a collections module pack? | **Core** = obligations, ledger, recovery orchestration, schemes, slabs, incentives, reimbursements, the general gate. **Pack** = the DRA/PCC/agent-authorisation item set, portfolio/bank billing, off-roll worker payouts, the RBI audit pack | Aligns with `N-…` §1.8.2 and Part IX D-IX-10. Putting schemes in the pack would make the core unsellable to staffing, security and logistics; putting the RBI pack in core burdens every customer with a vertical they do not have |
| **D-XVIII-13** | The 194H rate, the no-PAN rate, the threshold and the head for commission withholding | Ship as **rule-pack content** signed by the named compliance owner (spine D-03). Seed the value SmartPRS 2's code currently uses (5.0) **marked unverified**, and refuse to activate the pack until verified | Recorded here strictly per the brief's legal-values discipline. **No rate in this document is asserted as legally correct.** The no-PAN higher rate does not exist on this path today although it does on the 24Q register |
| **D-XVIII-14** | Gate severity per tenant at migration | Seed each tenant's **existing `dra_gate` value exactly**, extend it to the new gate points at **`warn`**, and raise a task to choose per point | Seeding `block` on the new points would stop payments on day one for every subject with a lapsed credential; `warn` preserves visibility without a cash-flow shock, and the exception report makes the exposure visible |
| **D-XVIII-15** | The nine fields `safeRow()` silently dropped — loan tenure and purpose, advance reason, expense reason, clawback recovery month | **Accept the historical loss** (the data was never written and cannot be recovered) and **re-collect prospectively**; present affected historical records with an explicit "not recorded in the previous system" marker rather than a blank | Re-collecting historical expense reasons across years is not realistic; showing a blank invites the assumption that nobody entered one, when in fact the system discarded it and told the user it had succeeded |
| **D-XVIII-16** | Should the SPX function library gain a scalar `PMT(principal, rate, n)` for eligibility expressions? | **Optional.** The schedule generator is a domain service (iteration is outside SPX by design), so nothing requires it; a scalar `PMT` would let a tenant express "the EMI must not exceed 40% of net" as an eligibility predicate | Adding it extends spine S5.4's closed function set, which should not be done casually. Without it, that predicate is expressed on the derived instalment after the schedule is generated, which is one step later but equally enforceable |

---

## XVIII.20 Requirements index

| Range | Subject | Section |
|---|---|---|
| R-XVIII-001 – 004 | One model; no literals; side effects inside the transaction; the balance is the ledger sum | XVIII.0 |
| R-XVIII-005 – 020 | The obligation, the ledger, type declarations, pipeline position, reconciliation, money discipline | XVIII.2 |
| R-XVIII-021 – 034 | Loan types, lifecycle, sanction, interest methods, schedule generation, disbursement | XVIII.3.1 – XVIII.3.6 |
| R-XVIII-035 – 047 | Payroll recovery, manual payment, prepayment, foreclosure, waiver, restructure, pause, exit | XVIII.3.7 – XVIII.3.13 |
| R-XVIII-048 – 050 | Migrated loan opening balances and their provenance | XVIII.3.14 |
| R-XVIII-051 – 063 | Advance types, limits, approval, effective period, spread recovery, shortfall, waiver, payslip visibility, self-service scoping | XVIII.4 |
| R-XVIII-064 – 074 | The orchestrator contract, configurable order, shortfall policies, the net floor, the run-keyed ledger | XVIII.5.1 – XVIII.5.5 |
| R-XVIII-075 – 078 | Reversal on regeneration: transactional, versioning, state restoration, non-interference | XVIII.5.6 |
| R-XVIII-079 – 085 | Schemes as artefacts, server-side money, audience as a relation, one schema, slabs and their validation | XVIII.6.1 – XVIII.6.3 |
| R-XVIII-086 – 091 | Thresholds, accelerators, caps, team splits, scope precedence, the portfolio as an entity | XVIII.6.4 – XVIII.6.5 |
| R-XVIII-092 – 095 | Bulk upload, no silent drops, preview fidelity, bulk rows route like single rows | XVIII.6.6 |
| R-XVIII-096 – 099 | Gross not net, statutory heads from the pack, base flags, the mandatory settlement period | XVIII.6.7 |
| R-XVIII-100 – 105 | Payment as a ledger entry, locked balances, no tolerance, reopening, adjustment runs, no text matching | XVIII.6.8 |
| R-XVIII-106 – 108 | Clawback triggers, attribution methods, stored settlement period | XVIII.6.9 |
| R-XVIII-109 – 113 | One engine for commission and incentive, trigger extensibility, policies as rules and formulas, **no behaviour from a name** | XVIII.7.1 – XVIII.7.3 |
| R-XVIII-114 – 119 | Proof, points gates, attendance source, payout lag, separate-channel parity, proration policy | XVIII.7.5 – XVIII.7.6 |
| R-XVIII-120 – 124 | Reimbursement flags not substrings, one gross, grouping from definitions, dynamic categories, stored claim fields | XVIII.8.1 – XVIII.8.2 |
| R-XVIII-125 – 132 | Limits by scope, enforcement points, locked consumption, proof, GST invoices, place of supply, private documents, duplicates | XVIII.8.3 – XVIII.8.4 |
| R-XVIII-133 – 140 | Taxability from the pack, the taxable split, settlement channels, the reimbursement run, advance settlement, cost-centre attribution and splits | XVIII.8.5 – XVIII.8.7 |
| R-XVIII-141 – 149 | The general compliance gate: policy, one definition of valid, missing records, gate points, decision records, fail closed, warn durability, overrides, non-employee workers | XVIII.9 |
| R-XVIII-150 – 152 | One ledger table; AST persistence; migrations only | XVIII.11 |
| R-XVIII-153 – 156 | No `DELETE`, no state change on `GET`, idempotency, data scoping | XVIII.12 |
| R-XVIII-157 – 158 | Event payloads; transactional outbox | XVIII.13 |
| R-XVIII-159 – 161 | Explainability to the paise, pack versions, employee-facing traces | XVIII.14 |
| R-XVIII-162 – 174 | Migration: no recovery without confidence, loan reconstruction and reporting, advance manual reconciliation, commission state and gross/net reconstruction, scheme normalisation, arrears, immutable historical facts, artefact prerequisites, day-one reconciliation | XVIII.15 |

**Count.** 174 numbered, testable requirements; 13 tables specified in full plus
9 configuration payload tables and 4 derivable tables; 16 Product Owner
decisions.
