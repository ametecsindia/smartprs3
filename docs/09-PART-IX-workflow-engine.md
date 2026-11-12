# PART IX — WORKFLOW ENGINE

Conforms to: SPINE.md — S1 (the seven laws), S3.1–S3.2 (scope and precedence),
S4 (effective dating, draft/publish), S5 (SPX), S6 (rule-engine boundary),
**S8 (the workflow engine — this part is its full specification)**, S13
(security), S14 (audit ledger), S15 (explainability), S16 (data conventions),
S17 (API), S18 (deployment parity).

Evidence base: `analysis/E-leave-requests-notifications.md` (§1, §3.1, §5, §7A
Q1/Q2/Q8, §10.2), `analysis/A-payroll.md` (§1.4, §3.7, §3.8, DEF-P10, DEF-P18),
`analysis/H-recruitment-hrops-reports.md` (§1.7, §1.8, §3.4).

---

## IX.0 Purpose

This part specifies **the single workflow engine** that carries every approval,
review, sign-off, escalation and maker-checker control in SmartPRS 3. It fixes
the definition model, the node catalogue, participant resolution, instance
semantics, SLA and escalation, the unified inbox, and the audit contract.

It decides three things:

1. **That there is exactly one engine.** No module owns approval code. A module
   owns a *subject entity*, a *binding*, and the *effects* of a completed
   workflow — never a routing rule, never an approver lookup, never a state
   guard.
2. **That an approval path is configuration**, resolved by scope, effective
   date and version like every other artefact (spine S4), authored and published
   through the Configuration Studio (Part VI), and expressed in the same
   expression language as rules and formulas (spine S5).
3. **That a decision, once made, is a fact.** A decided step cannot be
   re-decided. This is a structural property of the data model, not a
   convention — because SmartPRS 2's absence of that guard is what re-fires
   transfer orders and increment letters (`RequestController.php:1330–1525`).

**R-IX-001** SmartPRS 3 MUST contain exactly one workflow engine. No module may
implement approver resolution, approval state transition, SLA, escalation or
delegation. *Test:* a Deptrac dependency rule forbids any `Domain\*` namespace
other than `Domain\Workflow` from declaring a class whose name matches
`*Approv*`, `*Escalat*` or `*Delegat*`; a CI grep asserts no module writes an
approval status column outside the workflow engine's own tables.

**R-IX-002** No approval path, approver identity, threshold, role name, stage
name or SLA duration may appear as a literal in application code (Law 1). Every
one of them is a column in a published workflow version.

---

## IX.1 What SmartPRS 2 does today, and why it must change

### IX.1.1 The engine that is not one

SmartPRS 2 presents itself as having a generic approval engine. It has a generic
**table router**. `ApprovalService::modules():27–43` is a static PHP array of
nine request types (Expense Claim, Salary Advance, Loan, Commission, Clawback,
Increment/Appraisal, Exit & FnF, Bonus/Encashment, Employee Transfer), each
giving a table name, a label, an amount column and a single numeric threshold.
Everything the router shares across those nine types is clerical: the table
lookup, the employee resolution, the `status='pending'` birth
(`RequestController.php:538`), the financial-year stamp, the audit row and two
emails. Everything *behavioural* is a hard-coded `if` on the module name —
`decide()` alone contains nine of them (`:1342`, `:1353`, `:1359`, `:1371`,
`:1418`, `:1438`, `:1456`, `:1470`, `:1486`).

Leave does not use the engine at all. `LeaveController` carries its own
`currentEmployee()`, its own `resolveApprover():96–117`, its own `ensureCols()`
and its own inbox, and is stitched back into the shared inbox only by
`RequestController::inbox():1542–1559` reading the `leaves` table directly.

Payroll has a third, unrelated approval implementation:
`SalaryApprovalController`, with a two-stage run state machine
(`nextStep():159–169`, `applyDecision():795–836`) and a separate seven-state
per-line lifecycle (`applyLineAction():554–606`).

Recruitment has a fourth: a public tokenised acceptance page
(`OfferAcceptController.php:64–88`) and a public NDA signature page
(`NdaSignController.php:85–102`), neither of which is an approval at all in the
engine's terms and neither of which is routed, audited or state-guarded.

**Four approval implementations, none of them configurable.** That is the
single fact this part exists to end.

### IX.1.2 The complete approval-hierarchy configurability that exists today

None. The entire hierarchy is forty-two lines
(`ApprovalService::resolveApprover():310–352`):

```
base := employees.reporting_manager_id → that employee row   (FK, not tenant-scoped)
      | employees.reporting_manager (a NAME string) → matched employee in tenant
      | NULL, with the label 'Reporting Manager / HR'

if (threshold > 0 && amount !== null && amount >= threshold):
      base's own reporting_manager_id → [id, "<name> (higher approval — amount ₹n)"]
      else                            → [NULL, "Branch / Company Admin (amount ₹n)"]
else: → [base id, base name]
```

One hop, optionally two, where the second "level" can degrade into a *label
string that is not an approver at all*. The capability matrix, verified against
source in `E-…` §7A Q2:

| Capability | Present in SmartPRS 2? | Evidence |
|---|---|---|
| Multi-step / sequential (level 1 → 2 → 3) | **No** — one approver for the item's whole life | `approver_id` written once, `RequestController.php:539` |
| Parallel approval | **No** | a single `approver_id` column |
| Any-one-of (approver pool) | **No** | single column, single id |
| All-must (unanimity) | **No** | single column |
| Quorum (n of m) | **No** | single column |
| Conditional routing (department, grade, branch, cost centre, sub-type) | **No** — the only condition is `amount >= threshold` | `:332` |
| Role-based approver ("any Finance Manager") | **No** — the approver is an employee id | `:1398` |
| Per-tenant hierarchy configuration | **No** | the registry and resolver are static PHP |
| Per-request-type hierarchy | **No** — every type uses the same resolver | `:531` |
| Threshold **bands** (more than one boundary) | **No** — one threshold, one hop | `:332` |
| Configurable threshold amounts | **No** — three literals | `ApprovalService.php:30–32` |
| SLA / due date | **No** — no `due_at`, `sla_*` or `escalate_at` column exists anywhere | schema-wide absence |
| Reminders | **No** | same |
| Timeout escalation, auto-approve or auto-reject on expiry | **No** — `routes/console.php:140–262` registers twelve schedules, **none** approval-related | same |
| Delegation / out-of-office / alternate approver | **No** — no table, no column, no code | absence |
| Reassignment of a pending item | **No** — `approver_id` is never rewritten | absence of an endpoint |
| Re-evaluation when the routing input changes | **No** — escalation is computed once, at creation | `:531` is the only call site |
| Skip-level, matrix or dotted-line reporting | **No** — one `reporting_manager_id` per employee | schema |

Two consequences deserve to be stated plainly, because they are the failure
modes customers actually experience. First, a **`NULL` approver is a valid
outcome**: the request is created, no email is sent (`:571` skips it), and the
item is invisible in every non-manager inbox because the filter is
`approver_id = $myId ?? -1` (`:1573`). It sits Pending forever, silently, with
no SLA to surface it. Second, **bulk commission uploads pass `threshold = 0`
explicitly** (`:687`), so a thousand-row file of large claims never escalates at
all.

The service's own docblock (`ApprovalService.php:19–21`) describes the chain as
*Reporting Manager → Team Leader → Branch / Company Admin*. **There is no Team
Leader lookup anywhere in the codebase.** The documentation of the hierarchy is
wrong about the hierarchy.

### IX.1.3 Decisions are not immutable

`RequestController::decide():1330–1525` performs **no check on the current
status**. Neither does `LeaveController::decide():392–466`. Consequences,
verified:

| Consequence | Mechanism | Evidence |
|---|---|---|
| A transfer order is re-sent every time an approved transfer is re-approved | `if ($module === 'transfers')` → `TransferService::applyIfDue()` + `TransferController::sendOrder()`, each in its own `try/catch` writing only `Log::warning`; the approval stands regardless | `RequestController.php:1465–1481` |
| An increment letter is re-sent on every re-approval | `if ($module === 'increments')` → `IncrementController::sendLetter()` | `:1486–1492` |
| The prior decision's stated reason is destroyed | `'remarks' => $v['remarks'] ?? null` written unconditionally; a blank re-decide erases it | `:1408`; identically `LeaveController.php:422` |
| Leave consumption inflates or deflates | an approved leave can be flipped to rejected and back; `used` and `pending` recompute each time | `E-…` §7A Q5 failure mode 3 |
| A decision may be made by anyone holding one of three role names, not only the named approver | `hasAnyRole(super_admin, admin, hr_manager)` **OR** `approver_id === my employee id` | `:1396–1411`; role set at `:52–55` |

**R-IX-003** A decided workflow step MUST be immutable. Re-deciding MUST be
impossible at the data layer, not merely refused by application logic. *This
requirement exists because SmartPRS 2 re-fires transfer orders and increment
letters on re-approval.* Specification: §IX.6.3.

### IX.1.4 Four-eyes on payroll is satisfied by one person

Payroll's two-stage approval is presented as a four-eyes control. It is not one.

```
isHR()      := hr_manager | admin | super_admin      SalaryApprovalController:59–62
isFinance() := admin | super_admin                   SalaryApprovalController:65–68
```

`admin` and `super_admin` satisfy **both** predicates. A single user holding
`admin` can move a run `draft → hr_approved → approved` unaided. The identical
defect exists on the commission Accounts gate: `isManager()` is
`super_admin|admin|hr_manager` and `isAccounts()` is
`super_admin|admin|accountant` (`RequestController.php:52–61`), so one `admin`
confirms the money and approves the payment.

Three further payroll findings compound it:

- **The bank file bypasses the control entirely.** `bankFile():499–503` updates
  `payslips.line_status` to `disbursed` **directly**, bypassing
  `applyLineAction()` and therefore bypassing BR-PAY-028's guard *"a line may be
  disbursed only after its parent run has cleared HR approval"* (`:583–588`).
  `bankFileQuery():342–367` never checks the run's status. A Finance user can
  approve lines on a still-draft run, download the file, and every line flips to
  `disbursed` with the run never having passed HR approval. The whole operation
  is a **GET that mutates state** (DEF-P10, SEC-P04).
- **There is no reopen path.** `PayrollLock::denyUnlessSuperAdmin():82–97` has
  **zero callers**; the documented "Super Admin can reopen a finalised run"
  decision (`PayrollLock:22–25`) is unimplemented.
- **Regeneration destroys the approval record.** A draft regeneration deletes
  the old payslips and the old run row, losing the rejection remark, the
  approval stamps and every line status (BR-PAY-405, DEF-P18).

**R-IX-004** The engine MUST be able to enforce, as configuration, that the
actor of step N is not the actor of any earlier declared step, and that no
single role satisfies two declared segregated steps. *Test:* a publish-time
validator rejects a workflow declaring segregation between two steps whose
resolved participant sets can intersect for any user in the tenant, naming the
users. *This requirement exists because `admin` currently satisfies both sides
of every four-eyes control in SmartPRS 2.*

### IX.1.5 What else the absence of an engine costs

| Cost | Evidence |
|---|---|
| Two-stage "confirm then approve" exists for exactly one module and cannot be switched off or reused | Accounts gate, `RequestController.php:1359–1364` |
| Pre-conditions on a transition are three bespoke `if` blocks with inconsistent policy: the DRA gate is tri-state `off\|warn\|block`, while compliance score and open grievance are hard-coded warn-and-allow | `:1371–1394` vs `:1416–1454` |
| Post-transition effects are two hard-coded module-name conditionals | `:1465–1492` |
| Cancel and withdraw do not exist; `decide()` accepts only `approve\|reject` (plus `reopen`, commissions only) and `leaves.status` is a three-value `ENUM` that `ensureCols()` never widens | `:1330–1525`; `…000200…` |
| The inbox has no pagination, no limit, no filter, no SLA column, no delegation view | `inbox():1532–1591` |
| Loading the inbox executes runtime DDL against nine tables | `ApprovalService::ensureAuditCols():46–260` |
| Approval-path authorisation fails **open** — `denyUnlessRole()` returns `null` (= allowed) on any exception, deliberately, across 71 call sites in 30 files | `ApprovalService.php:365–380` |
| Recruitment's offer "acceptance" shows the signer only name, position, company and offer date — never the body, CTC, incentive, bonus or joining date — and the NDA signature endpoint performs **no** server-side validation of the typed name or the consent tick and is CSRF-exempt | `resources/views/offer-accept.blade.php:38–44`; `NdaSignController.php:96`; `routes/web.php:63–64` |
| The public offer route accepts **any** letter type — there is no `letter_type` filter | `OfferAcceptController.php:36` |
| Runtime DDL executes on every hit of a public, unauthenticated token page | `OfferAcceptController::ensureCols():18–30`; `NdaSignController::ensureCols():19–31`; `LetterController::sendAcceptLink():395–401` |
| Scheme approval carries a fifth hard-coded hierarchy: Admin/HR schemes go live instantly, a team leader's scheme waits for their own reporting manager, fallback being the *label* `'Admin / HR'` | `SchemeController::save():443–500` |

**We are not replicating any of this.** Each design choice in §IX.3 onwards
names the failure it answers.

---

## IX.2 Scope — what the engine owns, and what it does not

### IX.2.1 The division of labour

Part VII fixed the three-engine boundary. Restated from the workflow side:

| Question | Owner | Returns |
|---|---|---|
| *What outcome applies?* | Rule engine (Part VII, spine S6) | `AppliedAction[]` + `DecisionTrace` — **never mutates** |
| *What amount?* | Formula engine (Part VIII, spine S7) | one typed value + `CalculationTrace` |
| *Who must agree, in what order, by when?* | **Workflow engine (this part)** | a workflow instance and its transitions — **owns its own state** |

**R-IX-005** The workflow engine MUST NOT decide a business outcome. It routes a
decision, records it, and emits an event. It never computes a leave balance, a
payslip line, an attendance status or a statutory value. *Rationale — SmartPRS 2
puts TDS arithmetic, evidence validation and clawback creation inside
`decide()`, which is why the decide path is 195 lines long and why bouncing a
commission silently creates an approved clawback (`:1786–1802`).*

**R-IX-006** The workflow engine MUST NOT own the effect of a completed
workflow. On completion it emits `workflow.instance.completed`; the owning
domain service subscribes and applies the effect inside its own transaction,
idempotently, keyed on the instance uuid. *This is the replacement for
`if ($module === 'transfers')` at `RequestController.php:1470–1481`.*

**R-IX-007** A workflow node MAY evaluate an SPX Boolean (spine S5) as a branch
condition, a guard, an auto-approve test or a participant expression. It uses
the **same** language, the same symbol table discipline and the same publish-time
type checking as the rule engine. There is no second expression language.

### IX.2.2 What is in scope

Approval, review, sign-off, verification, clearance, publication gating,
maker-checker, escalation and delegation — for **any** subject entity, in any
module, including configuration artefacts themselves (spine S4: approval before
publish for `high_risk` artefacts uses this engine).

### IX.2.3 What is explicitly not a workflow

| Not a workflow | Where it lives | Why |
|---|---|---|
| A record's lifecycle vocabulary (`draft`, `active`, `closed`) and its permitted transitions | `status_domains` / `status_values` / `status_transitions` (Part XXVIII XXVIII.4) | A status machine is a property of the entity. A workflow is *attached to* a transition via `status_transitions.workflow_artefact_key` |
| A recruitment pipeline's stage list | the ATS's configurable stage set (Part on recruitment) | Moving a card is not an approval; an *offer approval* is |
| A checklist whose items have owners and due dates but no decision | the onboarding/exit checklist model | It is a task list; where an item requires a decision, the item **binds a workflow** |
| Notification audience, template and channel | the notification engine (spine, Part on notifications) | The workflow emits an event; it does not compose messages |
| Who may see an item | the permission and data-scope model (spine S13) | Participation is not authorisation. Both are enforced; neither substitutes for the other |
| Whether an operation is *permitted at all* | policy (spine S13) | An approval routes a permitted operation. An unauthorised operation is refused before any instance is created |

**R-IX-008** Being a participant in a workflow MUST NOT grant any permission.
The engine checks (a) the actor holds the permission the node declares, **and**
(b) the actor is a resolved participant of the open task. Both, server-side, both
failing closed (Law 5). *Rationale — SmartPRS 2 lets any holder of three role
names decide any item regardless of who the named approver is
(`RequestController.php:1396–1411`), and its authorisation helper fails open
(`ApprovalService.php:365–380`).*

---

## IX.3 The workflow definition model

### IX.3.1 A workflow is an ordinary configuration artefact

A workflow definition is a `config_artefacts` row of `artefact_type = 'workflow'`
with `config_versions` rows exactly as spine S4 requires. It therefore inherits,
without inventing anything: draft → testing → pending_approval → published →
superseded → archived; scope and priority (spine S3.1/S3.2); `effective_from` /
`effective_to`; overlap rejection at publish; the mandatory diff and impact
preview for `high_risk`; the checksum; and `resolve(type, key, scope, as_of)`.

**R-IX-009** A workflow definition MUST be an effective-dated, scoped, versioned
configuration artefact resolved by the standard resolver. There is no separate
workflow publication mechanism. *Test:* `resolve('workflow', key, scope, date)`
returns the same shape as for any other artefact type; publishing a workflow
walks the same validator pipeline as publishing a rule.

**R-IX-010** `artefact_type = 'workflow'` MUST be registered `is_high_risk = 1`
in `config_artefact_types`, so publishing a workflow itself requires an approval
workflow (spine S8; §IX.8.2). *Rationale — a workflow is the control; changing
a control without a control is the definition of an unaudited system.*

### IX.3.2 The definition, in full

```
workflow_definition                                   → cfg_workflows
  artefact_key            stable code, e.g. LEAVE-APPROVE
  name, business_description                          (description mandatory)
  subject_entity_key      what it approves; must exist in entity_definitions (S9)
  subject_amount_path     optional; the typed Money field used by amount routing
  start_node_key
  allows_self_approval    0 | 1                       (§IX.5.6)
  self_approval_override  none | permission | named_role | workflow
  segregation_sets        [ { set_key, node_keys[], rule } ]   (§IX.5.7)
  on_definition_superseded  complete_on_start_version           (fixed; §IX.6.6)
  return_policy           restart | resume_at_return_node       (§IX.4.10)
  cancel_policy           requester_until_first_decision
                        | requester_always
                        | permission_only
                        | never                       (§IX.4.12)
  comment_policy          optional | required_on_reject
                        | required_on_all | per_node
  attachment_policy       per_node
  instance_key_expr       optional SPX Text → the idempotency key (§IX.6.4)
  sla_calendar_key        FK → cfg_sla_calendars                (§IX.7.2)
  timezone_source         tenant | org_unit | employee | fixed(<tz>)
  trace_retention_class   FK → cfg_retention_classes

  nodes[]                                             → cfg_workflow_nodes
    node_key              unique within the definition
    node_type             one of the thirteen (§IX.4)
    label                 shown in the inbox and the trace
    display_order
    params_json           typed per node_type; publish-time schema-validated
    participants[]        → cfg_workflow_node_participants   (approval/notify/escalate)
    guard_expr            SPX Boolean; false ⇒ the node is skipped (§IX.6.7)
    entry_actions[]       declarative, from the closed catalogue (§IX.3.5)
    exit_actions[]        same
    requires_comment      never | on_reject | always
    requires_attachment   never | on_approve | on_reject | always
    required_permission   verb.resource the actor must hold (S13)
    sla_minutes           NULL = no SLA
    sla_calendar_key      NULL = inherit the definition's
    reminder_schedule     [ offsets in minutes, relative to due_at ]
    escalation_policy     none | notify_only | reassign | route_to_node
                        | auto_approve | auto_reject
    escalation_target     participant spec or node_key
    reassignment_rights   none | assignee | assignee_manager | permission
    delegation_allowed    0 | 1
    is_terminal           derived; true for reject / cancel / finalise
    on_no_participant     fail_instance | route_to_node | assign_to_fallback
                        | auto_approve_with_reason           (§IX.5.4)

  transitions[]                                       → cfg_workflow_transitions
    from_node_key, outcome_code, to_node_key
    condition_expr        SPX Boolean; first matching transition wins,
                          evaluated in declared order
    label                 rendered in the trace and the visual editor
```

**R-IX-011** `params_json` MUST be validated at publish time against the JSON
schema declared for its `node_type`. An unknown key is a publish error, never
silently discarded. *Rationale — SmartPRS 2's `safeRow()`
(`ApprovalService.php:262–266`) drops any field the table does not have, with no
error, no log and no user signal, losing nine known fields including the expense
reason and the loan tenure.*

**R-IX-012** Every node's `label` and the definition's `business_description`
MUST be non-empty. They are what the inbox, the trace and the audit ledger
render; an unnamed step is an unexplainable step (Law 4).

### IX.3.3 Binding: how a subject reaches a workflow

Three binding mechanisms exist, evaluated in this order. All three are
configuration.

| # | Mechanism | Where declared | Typical use |
|---|---|---|---|
| B1 | **Rule action.** `ROUTE_TO_WORKFLOW(code)` or `REQUIRE_APPROVAL(code)` emitted at a decision point | a rule version (Part VII §6.2) | Conditional routing that depends on employee or period data — "sick leave over three days routes to the HR workflow" |
| B2 | **Status transition binding.** `status_transitions.workflow_artefact_key` | Part XXVIII XXVIII.4 | "Moving a payroll run from `draft` to `hr_approved` requires workflow PAYROLL-APPROVE" |
| B3 | **Entity binding.** `cfg_workflow_bindings` — subject entity + operation + scope + priority | this part | The default path for an operation, per scope: "expense claims in Telangana use EXPENSE-TG" |

**R-IX-013** Binding resolution MUST be deterministic: B1 (if any rule emitted a
routing action) wins over B2, which wins over B3. Two rules emitting
*different* `ROUTE_TO_WORKFLOW` codes at the same decision point is a **conflict
the rule engine fails on** (Part VII §7.3), not something the workflow engine
arbitrates. Two B3 bindings tying on specificity **and** priority block
publication (spine S3.2). There is no third tie-break and no row-order fallback.
*Rationale — SmartPRS 2 resolves its three-level configuration scope by row
order.*

**R-IX-014** The set of entity types a workflow may be bound to MUST be data:
`entity_definitions` (spine S9) plus a per-entity declaration of which operations
are workflow-gatable. Adding "asset issue request" as an approvable entity is a
seeded row and a domain subscriber, never an edit to the engine.

Entity types bindable at v3.0, with their gatable operations:

| `subject_entity_key` | Gatable operations | Owning part |
|---|---|---|
| `leave_request` | `submit`, `cancel`, `amend` | Leave |
| `attendance_regularisation` | `submit` | Attendance |
| `comp_off_claim` | `submit`, `encash` | Attendance |
| `overtime_claim` | `submit` | Attendance |
| `payroll_run` | `approve_hr`, `approve_finance`, `lock`, `reopen`, `release_payment` | Payroll |
| `payslip_line` | `hold`, `release`, `disburse` | Payroll |
| `salary_structure_assignment` | `change`, `revise` | Payroll |
| `loan` | `sanction`, `restructure`, `waive`, `foreclose` | Payroll |
| `advance` | `sanction` | Payroll |
| `expense_claim` | `submit` | Requests |
| `commission_claim` | `submit`, `edit_after_decision`, `bounce`, `pay` | Requests |
| `clawback` | `raise`, `waive` | Requests |
| `employee` | `create`, `delete_request`, `bank_change`, `pii_change`, `role_change`, `transfer`, `increment`, `confirm_probation` | Employee |
| `employee_exit` | `initiate`, `clearance`, `settle` | Employee |
| `candidate_offer` | `approve`, `revise`, `withdraw` | Recruitment |
| `document` | `verify`, `waive`, `renew` | Compliance |
| `config_version` | `publish` | Configuration (spine S4) |
| `statutory_override` | `publish` | Statutory |
| `rule_pack_acceptance` | `accept` | Statutory |
| `period_reopen` | `request` | Payroll / Attendance |
| `data_export` | `approve` | Security / DPDP |

**R-IX-015** An operation not present in this registry MUST NOT be workflow-
gatable at runtime, and an operation present in it MUST refuse to execute
without either a completed instance or an explicit configured "no workflow
required" binding. There is no third state in which an operation silently
proceeds unapproved.

### IX.3.4 Participants — the specification shape

A participant spec is used by `approval`, `notify` and `escalate` nodes and by
escalation targets. It is one row per participant, several rows per node.

```
participant
  seq                     evaluation order within the node
  participant_kind        role_in_scope | named_user | reporting_line
                        | group | spx_expression | requester
                        | previous_node_actor | initiator_manager
  role_key                for role_in_scope           FK → roles
  data_scope_code         for role_in_scope: self | direct_reports | team
                          | department | branch | company | legal_entity
                          | tenant | subject_org_unit          (S13)
  scope_anchor            subject | requester | subject_employee
                          — whose org position anchors the data scope
  user_id                 for named_user               FK → users
  level_n                 for reporting_line: 1..N hops up the reporting line
  level_stop_condition    optional SPX Boolean evaluated at each hop
                          ("stop at the first manager whose grade >= 'M3'")
  group_id                for group                    FK → workflow_groups
  expression              for spx_expression: SPX returning List<Code> of
                          employee codes or user ids
  is_optional             0 | 1 — an optional participant that resolves to
                          nobody does not trigger on_no_participant
  exclude_requester       0 | 1  (default 1)
  exclude_prior_actors    0 | 1  (default 1 where a segregation set applies)
```

**R-IX-016** A participant MUST be resolvable to zero or more **user ids**. An
approver is never stored as a display name. *Rationale — SmartPRS 2 stores the
actor as a name string across eleven columns, producing an unjoinable,
non-repudiable trail (`J-database.md` SEC-DB-04), and its second escalation
"level" is a label string that is not an approver at all
(`ApprovalService.php:337`).*

### IX.3.5 Entry and exit actions — the closed catalogue

Node actions are declarative and typed, exactly as the rule engine's are. The
engine never executes arbitrary behaviour.

| Action | Parameters | Notes |
|---|---|---|
| `EMIT_EVENT` | `event_key`, `payload_map` | The only way a workflow reaches another module |
| `NOTIFY` | `event_key`, `participant_spec` | Hands the audience to the notification engine; the engine composes nothing |
| `SET_INSTANCE_VARIABLE` | `key`, SPX expression | Writes to `routing_context_json`; readable by later guards. Never writes to the subject |
| `SNAPSHOT_SUBJECT` | — | Re-hashes the subject; used by `return_for_edit` (§IX.4.10) |
| `WRITE_AUDIT` | `reason` | An explicit ledger row beyond the automatic transition row |
| `REQUIRE_ACKNOWLEDGEMENT` | `text_key` | Renders a declaration the actor must tick; the tick is stored with the decision |

**R-IX-017** A node action MUST NOT mutate the subject entity. The engine writes
only to its own tables and the audit ledger. Subject mutation happens in the
domain subscriber, in its own transaction, on the emitted event. *Rationale — the
absence of this boundary is why `decide()` writes commission TDS, creates
clawbacks and sends transfer orders (`RequestController.php:1465–1492`,
`:1786–1802`).*

### IX.3.6 Publish-time validation

Every check below **blocks publication** and names the offending nodes.

| # | Check | Why |
|---|---|---|
| W01 | Exactly one `submit` node, and it is `start_node_key` | A workflow with two entry points has no defined start |
| W02 | Every node is reachable from the start node | Unreachable nodes are dead configuration |
| W03 | Every non-terminal node has at least one outgoing transition, and its outcome codes cover the node type's declared outcome set | Otherwise an instance can strand |
| W04 | Every transition's `to_node_key` exists | — |
| W05 | No cycle that does not pass through a `return_for_edit` or an `escalate` node | An approval loop with no human-visible return is an infinite loop |
| W06 | Every `guard_expr`, `condition_expr`, `expression` and auto-approve expression parses, type-checks `Boolean` (or `List<Code>` for participants) against the declared symbol table, and is within the node budget (spine S5.6) | — |
| W07 | Amount routing is declared only where `subject_amount_path` is set and typed `Money` | `Money` never silently coerces (spine S5.1) |
| W08 | Amount bands are contiguous and non-overlapping and cover `(-∞, +∞)` | A gap is an unroutable amount |
| W09 | Every `role_key`, `user_id`, `group_id`, `permission` and `sla_calendar_key` exists and is active in this tenant | — |
| W10 | Every segregation set names ≥ 2 existing nodes, and the resolved participant sets of those nodes **cannot intersect** for any current user | §IX.1.4 — this is the check `admin`-satisfies-both would fail |
| W11 | Every `approval` node has ≥ 1 non-optional participant, or an explicit `on_no_participant` policy other than the default | §IX.5.4 |
| W12 | `quorum` nodes satisfy `1 <= n <= m` where `m` is the minimum resolvable participant count declared, and declare a `tie_policy` | §IX.4.6 |
| W13 | Every `finalise`, `reject` and `cancel` node has no outgoing transitions | Terminal means terminal |
| W14 | At least one path from start reaches a `finalise` node and at least one reaches a `reject` node | A workflow that cannot be refused is not a control |
| W15 | The definition's declared `subject_entity_key` and every `EMIT_EVENT` key are registered | Law 1 |
| W16 | Where `escalation_policy` is `auto_approve` or `auto_reject`, the node declares `sla_minutes` and a `stale_timer_policy` | §IX.7.6 |
| W17 | No two workflows bound to the same (entity, operation) tie on specificity **and** priority for any employee | Spine S3.2 |
| W18 | Stored test cases attached to the definition all pass | §IX.21.2 |

**R-IX-018** Publication MUST run W01–W18 and MUST block on any failure, naming
the nodes and, for W10 and W17, the affected users or employees. Ambiguity is a
configuration error, never a runtime coin-toss.

### IX.3.7 Simulation before publish

**R-IX-019** The Configuration Studio MUST offer a workflow simulator that, given
a real or synthetic subject and a date, resolves the binding, resolves every
participant, evaluates every guard and branch, and renders the complete path with
the resolved names, the amounts that drove each branch, and the SLA due dates
that would be set — **without creating an instance**. *Rationale — today the only
way to discover who will approve a ₹1,50,000 loan is to raise one.*

---

## IX.4 The node catalogue

Thirteen node types, exactly as spine S8 declares. The catalogue is **closed**:
adding a node type is an Ametecs release activity requiring a migration, a
schema for its `params_json`, an executor, a trace renderer and a conformance
test. It is never a tenant action.

**R-IX-020** The node catalogue MUST be closed and versioned. A definition
referencing an unknown `node_type` fails publication and, if one is somehow
persisted, fails instance start — never degrades to a default behaviour.

Common vocabulary used below:

- **Entry condition** — what must hold for the engine to activate the node.
  If `guard_expr` evaluates false the node is *skipped* (§IX.6.7), not failed.
- **Outcome codes** — the values a node can produce; transitions are declared
  per outcome. An outcome with no declared transition fails W03 at publish.
- **Failure behaviour** — what happens when the node cannot proceed. There are
  exactly three dispositions: **park** (instance state `blocked`, an operator
  alert, no data change), **fail** (instance state `failed`, alert, no data
  change), and **route** (follow a declared transition). Nothing is ever
  silently skipped, and nothing ever auto-approves as a *consequence of an
  error*.

**R-IX-021** No node may resolve an error into an approval. An evaluation
failure, a resolution failure or a timeout during evaluation MUST park or fail
the instance and raise an operator alert. *Rationale — SmartPRS 2 destroys 50.5 %
of its caught exceptions and its authorisation helper returns "allowed" on any
exception (`ApprovalService.php:365–380`).*

---

### IX.4.1 `submit`

The entry node. Records who raised the item, snapshots the subject, and evaluates
the submission guards.

| Parameter | Type | Notes |
|---|---|---|
| `requires_comment` | enum | `never \| always` |
| `requires_attachment` | enum | `never \| always` |
| `attachment_types` | List\<Code\> | from the document-type value set |
| `precondition_expr` | SPX Boolean | evaluated against the subject; false ⇒ outcome `blocked` |
| `precondition_severity` | enum | `block \| warn \| off` — resolved from configuration at the point of enforcement |
| `snapshot_fields` | List\<Text\> | the subject fields hashed into `entity_snapshot_hash`; default: all |

**Entry condition.** The instance is being created and the actor holds the
operation's permission and the operation's data scope on the subject.

**Outcomes.** `submitted` · `blocked` (precondition failed at severity `block`).

**Failure behaviour.** If `precondition_expr` errors: **fail** — no instance
persists, the caller receives a typed error naming the expression and the rule
version. If the subject cannot be snapshot-hashed: **fail**.

*Why `precondition_severity` is a declared tri-state on every gate:* SmartPRS 2
has one tri-state gate (`dra_gate`, `off|warn|block`, `RequestController.php:1373–1378`)
and two hard-coded warn-and-allow gates for compliance score and open grievances
(`:1416–1454`), with no way to make either of the latter block. Every gate in
SmartPRS 3 carries the same tri-state.

---

### IX.4.2 `approval`

The workhorse. Creates one `workflow_task` per resolved participant, waits for a
decision, and produces one outcome.

| Parameter | Type | Notes |
|---|---|---|
| `completion_rule` | enum | `any_one \| all_must` — for multi-participant single nodes. (`quorum` is a separate node type when *n of m* is needed) |
| `on_reject` | enum | `terminate \| return_to_node \| continue` — `continue` is permitted only where a later node re-decides; default `terminate` |
| `allow_return_for_edit` | 0/1 | exposes a `return` outcome to the approver |
| `allow_reassign` | 0/1 | governed by `reassignment_rights` |
| `decision_options` | List\<Code\> | subset of `approve \| reject \| return`; at minimum `approve` and `reject` |
| `amount_bands` | List | see §IX.9; when present, participants are selected per band |
| `visible_fields` | List\<Text\> | the subject fields the approver is shown — with the masking rules of spine S13 applied on top |

**Entry condition.** `guard_expr` true (or absent) **and** participant resolution
yields ≥ 1 assignable user, or `on_no_participant` provides a disposition.

**Exit condition.** Determined by `completion_rule`:

| `completion_rule` | Approved when | Rejected when |
|---|---|---|
| `any_one` | the first participant approves | the first participant rejects |
| `all_must` | every open task is approved | any one task is rejected (remaining tasks are closed as `superseded`, never deleted) |

**Outcomes.** `approved` · `rejected` · `returned` · `escalated` (from the SLA
path, §IX.7) · `auto_decided` (from `escalation_policy`).

**Failure behaviour.** Resolution yielding nobody: per `on_no_participant`
(§IX.5.4) — default **park** with an alert, never silent. A decision arriving for
a task that is already decided: rejected with `409 workflow.task_already_decided`
and the existing decision returned (§IX.6.4). A decision arriving from a user who
is not a participant or lacks `required_permission`: `403`, fails closed, audited
as an attempted transition.

**R-IX-022** `visible_fields` MUST be a subset of what spine S13 permits the
actor to see; the workflow never widens visibility. An approver who may not see
salary approves a salary change without seeing the figure only if the tenant
configured it that way, and the configuration validator warns that the approval
is then evidentially weak.

---

### IX.4.3 `condition_branch`

Pure routing. No task, no participant, no wait.

| Parameter | Type | Notes |
|---|---|---|
| `branches` | ordered List | each `{ label, condition_expr (SPX Boolean), to_node_key }` |
| `default_to_node_key` | Text | mandatory — the else branch |
| `evaluation_context` | enum | `at_entry \| at_instance_start` — `at_instance_start` re-uses the values snapshotted at submit |

**Entry condition.** Reached by a transition.

**Exit condition.** The first branch whose condition is true; otherwise the
default. Evaluation is ordered and total.

**Outcomes.** The `label` of the matched branch, recorded in the trace with the
substituted values.

**Failure behaviour.** Any expression error: **fail** the instance with an alert
naming the branch and the expression. There is no "treat an error as false" —
that is how a mis-typed condition would silently route every high-value item to
the low-value path.

**R-IX-023** A branch evaluation MUST record, in the trace, the branch labels
tried, the condition source, the substituted values and the branch taken (Law 4).

---

### IX.4.4 `parallel_all`

Fan-out to several branches; the instance proceeds only when **every** branch
reaches its declared join point.

| Parameter | Type | Notes |
|---|---|---|
| `branch_start_nodes` | List\<Text\> | ≥ 2 |
| `join_node_key` | Text | where the branches converge |
| `on_branch_reject` | enum | `terminate_instance \| cancel_siblings_and_route \| continue_others` |
| `branch_sla_independent` | 0/1 | whether each branch carries its own SLA clock |

**Entry condition.** Reached. Every branch's own `guard_expr` is evaluated at
fan-out; a branch whose guard is false is marked `skipped` and does not block the
join.

**Exit condition.** All non-skipped branches have reached the join node.

**Outcomes.** `joined` · `rejected` (per `on_branch_reject`).

**Failure behaviour.** If *every* branch is skipped, the node **parks** — a
parallel node that fans out to nothing is a configuration error visible at
runtime, and W02/W03 cannot catch it because the guards are data-dependent. A
branch that fails parks the instance; sibling branches are frozen, not
cancelled, so no work is lost.

---

### IX.4.5 `parallel_any`

Fan-out where the **first** branch to complete decides the outcome and the
siblings are withdrawn.

| Parameter | Type | Notes |
|---|---|---|
| `branch_start_nodes` | List\<Text\> | ≥ 2 |
| `to_node_key` | Text | where the winner proceeds |
| `sibling_disposition` | enum | `withdraw \| leave_open_informational` |

**Entry condition.** As `parallel_all`.

**Exit condition.** The first branch reaching a completion outcome.

**Outcomes.** `first_completed` carrying the winning branch label.

**Failure behaviour.** Withdrawn sibling tasks are closed with status
`withdrawn`, an actor of `system`, and a transition row each — **never deleted**
(spine S16: business records are not hard-deleted). If two branches complete in
the same instant, the tie is broken by the earlier `decided_at`, then by the
lower `workflow_tasks.id`; the loser is `withdrawn` and both facts are in the
ledger.

---

### IX.4.6 `quorum(n of m)`

| Parameter | Type | Notes |
|---|---|---|
| `n` | Number | required approvals |
| `m_source` | enum | `resolved_participants \| fixed` |
| `m_fixed` | Number | when `m_source = fixed` |
| `reject_threshold` | Number | rejections at which the node fails; default `m − n + 1` — the point at which `n` approvals become arithmetically impossible |
| `tie_policy` | enum | `reject \| escalate \| park` — applied when neither threshold can be reached because participants have shrunk |
| `close_on_decision` | 0/1 | whether remaining tasks are withdrawn once the outcome is decided (default 1) |

**Entry condition.** Resolution yields ≥ `n` assignable users; otherwise
`on_no_participant` applies with the added disposition `reduce_n_to_available`
(explicitly opt-in, always audited, never a default).

**Exit condition.** `n` approvals ⇒ `approved`; `reject_threshold` rejections ⇒
`rejected`.

**Outcomes.** `approved` · `rejected` · `escalated` · `parked`.

**Failure behaviour.** If the participant set shrinks below what either threshold
needs (a leaver, a revoked role), the node applies `tie_policy`. It never
silently lowers `n`.

---

### IX.4.7 `auto_approve(SPX)`

A decision taken by the system, recorded as a decision.

| Parameter | Type | Notes |
|---|---|---|
| `condition_expr` | SPX Boolean | mandatory |
| `reason_text_key` | Code | the stated reason, from a value set — never a free literal |
| `applies_to_node_key` | Text | the approval node it stands in for, so the trace reads "step 2 auto-approved" |
| `record_as_actor` | enum | `system` (fixed) — an auto-approval is never attributed to a person |

**Entry condition.** Reached, and `condition_expr` true.

**Exit condition.** Immediate.

**Outcomes.** `auto_approved` · `not_applicable` (condition false ⇒ route on).

**Failure behaviour.** Expression error ⇒ **fail**, never auto-approve.

**R-IX-024** An auto-approval MUST write a `workflow_transitions` row and an
audit-ledger row with `actor_system_code = 'workflow_engine'`, the condition
source, the substituted values and the reason code. An auto-approval that cannot
be explained is a defect. *Rationale — Law 4 applies to decisions, not only to
numbers.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-01: may a maker-checker workflow
contain an `auto_approve` node?**
*Recommendation: no.* The validator should refuse `auto_approve` on any node
inside a segregation set, because an automatic second pair of eyes is not a
second pair of eyes. *Trade-off:* some customers want "amounts under ₹1,000
auto-approve" on expense claims, which is legitimate — that is expressible as a
`condition_branch` that routes *around* the approval entirely, and the trace then
says "no approval required", which is honest, rather than "approved by the
system", which is not.

---

### IX.4.8 `notify`

Sends without waiting.

| Parameter | Type | Notes |
|---|---|---|
| `event_key` | Code | registered notification event |
| `participants` | participant spec | the audience — resolved by the same resolver as approvers |
| `payload_map` | map | subject fields exposed to the template, subject to masking |
| `blocking` | 0/1 | `1` only for legally required acknowledgements; default `0` |

**Entry condition.** Reached; `guard_expr` true.

**Exit condition.** The notification engine has **accepted** the request
(enqueued and persisted), not delivered it.

**Outcomes.** `notified`.

**Failure behaviour.** A notification failure never blocks the workflow: the node
proceeds, the failure is recorded on the instance as a warning and is visible in
the ops dashboard. *This is deliberate and is the one place where best-effort is
correct — but unlike SmartPRS 2 the failure is recorded and surfaced, not
swallowed (`E-…` DEF-NOT-03, where `Mail::raw` leaves no delivery record at
all).*

---

### IX.4.9 `escalate`

An explicit escalation step, distinct from the SLA-driven escalation configured
on an approval node (§IX.7.5). Used where escalation is a *designed step* — for
example "if Finance rejects twice, route to the MD".

| Parameter | Type | Notes |
|---|---|---|
| `escalation_target` | participant spec | who now decides |
| `preserve_original_task` | 0/1 | keep the original task open in parallel (informational) |
| `reason_code` | Code | from a value set |
| `to_node_key` | Text | the approval node the escalated task belongs to |

**Entry condition.** Reached.

**Exit condition.** The escalation task has been created.

**Outcomes.** `escalated`.

**Failure behaviour.** Target resolves to nobody ⇒ **park** with an alert. An
escalation that escalates to nobody is the exact failure that produces SmartPRS
2's `NULL` approver, and it must be loud.

---

### IX.4.10 `return_for_edit`

Sends the item back to the requester (or a named earlier participant) for
correction, without rejecting it.

| Parameter | Type | Notes |
|---|---|---|
| `return_to` | enum | `requester \| node_actor(<node_key>) \| participant_spec` |
| `editable_fields` | List\<Text\> | which subject fields may change; enforced by the domain service, declared here |
| `resume_policy` | enum | `restart \| resume_at_return_node` — overrides the definition default |
| `max_returns` | Number | after which the outcome is `exhausted`; prevents an infinite edit loop (W05's runtime counterpart) |
| `requires_comment` | enum | `always` (fixed — a return with no reason is useless) |

**Entry condition.** An approver chose `return` on a node whose
`allow_return_for_edit` is 1, or a transition routed here.

**Exit condition.** The requester resubmits.

**Outcomes.** `returned` · `resubmitted` · `exhausted` · `abandoned` (the
requester cancels instead).

**Failure behaviour.** On resubmission the engine **re-snapshots the subject** and
compares the hash. If a field outside `editable_fields` changed, the resubmission
is refused with a typed error naming the fields. If `resume_policy` is
`resume_at_return_node` and any already-decided upstream node's inputs changed
materially (§IX.6.8), the engine **restarts** the affected steps regardless of
policy and says so in the trace.

**R-IX-025** A return-and-resubmit MUST NOT preserve an approval that was given
on different content. Where the material inputs changed, prior approvals on the
changed path are invalidated and re-sought, and the trace records which
approvals were invalidated and why. *Rationale — SmartPRS 2 permits editing a
commission after approval, with the approval standing
(`RequestController.php:763–877`), and leaves the CTC of an approved offer freely
editable (`H-…` BR-REC-003).*

---

### IX.4.11 `reject`

A terminal node meaning "refused".

| Parameter | Type | Notes |
|---|---|---|
| `reason_required` | 0/1 | default 1 |
| `reason_value_set` | Code | optional structured reason list alongside the free comment |
| `allow_resubmission` | enum | `never \| new_instance \| same_instance_within(<days>)` |
| `on_reject_event` | Code | emitted for the domain subscriber |

**Entry condition.** Reached from a rejection outcome.

**Exit condition.** Immediate and final.

**Outcomes.** `rejected` (terminal).

**Failure behaviour.** None available — the node cannot fail. If the emitted
event's subscriber fails, the instance stays rejected and the subscriber retries
(§IX.6.5); the workflow's own state is never contingent on a subscriber.

---

### IX.4.12 `cancel`

Terminal, meaning "withdrawn before conclusion". Distinct from `reject` because
the audit story differs: a cancel is the requester's act (or an administrator's),
a reject is a decider's.

| Parameter | Type | Notes |
|---|---|---|
| `who_may_cancel` | enum | `requester_until_first_decision \| requester_always \| permission_only \| never` (inherits `cancel_policy`) |
| `required_permission` | Text | for `permission_only` |
| `reason_required` | 0/1 | default 1 |
| `compensating_event` | Code | e.g. release a reserved leave balance |

**Entry condition.** A cancellation request from an actor permitted by
`who_may_cancel`, or an administrative cancellation.

**Exit condition.** Immediate. All open tasks are closed as `withdrawn`.

**Outcomes.** `cancelled` (terminal).

**Failure behaviour.** A cancellation attempt that the policy forbids returns
`403` and is audited as an attempted transition — **including the attempt**,
because who tried to withdraw a payroll approval mid-flight is exactly the kind
of fact an auditor asks for.

*Cancel and withdraw do not exist at all in SmartPRS 2 —* `decide()` accepts only
`approve|reject` (plus `reopen` for commissions) and `leaves.status` is a
three-value `ENUM` that `ensureCols()` never widens.

---

### IX.4.13 `finalise`

Terminal, meaning "approved and concluded". The **only** node that emits the
completion event the domain subscribes to.

| Parameter | Type | Notes |
|---|---|---|
| `completion_event_key` | Code | mandatory |
| `effect_payload_map` | map | what the subscriber receives |
| `requires_all_parallel_joined` | 0/1 | default 1 |
| `seal_snapshot` | 0/1 | default 1 — stores the final `entity_snapshot_hash` for non-repudiation |

**Entry condition.** Every declared required node on the taken path has an
`approved`, `auto_approved` or `skipped` outcome.

**Exit condition.** Immediate; the instance becomes `completed`.

**Outcomes.** `completed` (terminal).

**Failure behaviour.** If `seal_snapshot` finds the subject's current hash
differs from the hash approved at the last decision node, the node **parks** the
instance with `subject_drift_detected` and an operator alert. It does not
finalise. *This is the structural answer to SmartPRS 2's payroll behaviour where a
draft is re-priced by anything that changes after approval, because there is no
attendance freeze (DEF-P04).*

**R-IX-026** `finalise` MUST emit exactly one completion event per instance, ever
— enforced by a unique constraint on `(tenant_id, workflow_instance_id,
completion_event_key)` in the outbox. Re-entry cannot re-emit. *This is the
structural fix for the transfer order and the increment letter being re-sent on
every re-approval (`RequestController.php:1470–1492`).*

---

### IX.4.14 Node catalogue summary

| Node | Waits for a human? | Creates tasks? | Terminal? | Outcomes |
|---|---|---|---|---|
| `submit` | no | no | no | `submitted`, `blocked` |
| `approval` | **yes** | yes | no | `approved`, `rejected`, `returned`, `escalated`, `auto_decided` |
| `condition_branch` | no | no | no | branch label |
| `parallel_all` | via children | no | no | `joined`, `rejected` |
| `parallel_any` | via children | no | no | `first_completed` |
| `quorum` | **yes** | yes | no | `approved`, `rejected`, `escalated`, `parked` |
| `auto_approve` | no | no | no | `auto_approved`, `not_applicable` |
| `notify` | no | no | no | `notified` |
| `escalate` | no | yes | no | `escalated` |
| `return_for_edit` | **yes** | yes (to the requester) | no | `returned`, `resubmitted`, `exhausted`, `abandoned` |
| `reject` | no | no | **yes** | `rejected` |
| `cancel` | no | no | **yes** | `cancelled` |
| `finalise` | no | no | **yes** | `completed` |

---

## IX.5 Participant resolution

### IX.5.1 The resolution algorithm

Resolution runs **at node activation**, not at instance creation. This single
choice removes SmartPRS 2's most consequential limitation: escalation is computed
once, at creation (`RequestController.php:531`), so a request never re-routes
when the amount is edited or the employee transfers.

```
resolve_participants(node, instance, as_of) :=
  1. context := prepared context
       subject          the subject entity, at its snapshot
       requester        the initiating user and their employment
       subject_employee the employee the subject concerns (may differ)
       org              subject_employee's org assignments AS AT as_of  (S3)
       amount           subject_amount_path, typed Money
       instance vars    routing_context_json
  2. for each participant spec in seq order:
       candidates := resolve_kind(spec, context, as_of)
  3. union the candidate sets, preserving first-seen order
  4. apply delegation substitution                       (§IX.5.5)
  5. apply out-of-office backup substitution              (§IX.5.5)
  6. drop users whose account is inactive, locked or whose
     role assignment has lapsed as at as_of
  7. drop users lacking node.required_permission          (S13, fails closed)
  8. if spec.exclude_requester    drop the requester
     if spec.exclude_prior_actors drop actors of nodes in the same
                                  segregation set
  9. apply the self-approval prohibition                  (§IX.5.6)
 10. if the resulting set is empty → on_no_participant    (§IX.5.4)
```

**R-IX-027** Participant resolution MUST be evaluated as at the node activation
date against **effective-dated** org assignments (spine S3), never against a
current-state column on the employee. *Test:* an employee who transferred
departments on 1 August, whose leave request activated its department-head node on
31 July, routes to the July head; the same request activating on 2 August routes
to the August head; both are answerable by a single service call.

**R-IX-028** Resolution MUST be recorded. Every activation writes the resolved
user ids, the spec that produced each, and the substitutions applied
(delegation, backup, exclusion) into the instance trace. *Rationale — "why did
this come to me?" is unanswerable in SmartPRS 2, and "why did this go to nobody?"
is the more expensive version of the same question.*

### IX.5.2 The five participant kinds, specified

| Kind | Resolves by | Parameters | Notes and failure |
|---|---|---|---|
| **`role_in_scope`** | every user holding `role_key` whose **data scope** covers the anchor | `role_key`, `data_scope_code`, `scope_anchor` | The scope is spine S13's, not a free string. `data_scope_code = subject_org_unit` means "holders of this role whose scope includes the subject employee's org unit as at the date". Failure: role does not exist ⇒ publish-time W09; role exists with no holders ⇒ `on_no_participant` |
| **`named_user`** | one user id | `user_id` | The most brittle kind; the validator **warns** at publish that a named user is a single point of failure and suggests a role. A named user who leaves triggers §IX.5.9 |
| **`reporting_line`** | walk the employee's reporting line `level_n` hops from `scope_anchor` | `level_n`, `level_stop_condition`, `on_short_line` | Replaces `reporting_manager_id` → `reporting_manager` name-string matching. `on_short_line`: `use_topmost \| fail \| fallback_participant`. A cycle in the reporting line is detected and **fails** the node with an alert naming the cycle — it is a data defect, not a routing decision |
| **`group`** | membership of a named approver group | `group_id` | Groups are tenant configuration (`workflow_groups`), effective-dated, with an optional org scope. Used for panels, committees and duty rosters |
| **`spx_expression`** | evaluate an SPX expression typed `List<Code>` | `expression` | The escape hatch, and deliberately the narrowest one: it returns identifiers, never side effects, and is subject to the same node/time budget as any other SPX evaluation (spine S5.6). Failure: any evaluation error ⇒ **fail** the node, never an empty set |

Plus three convenience kinds that are shorthand for the above and exist because
they are what customers actually say:

| Kind | Equivalent to |
|---|---|
| `requester` | the initiating user — used for `return_for_edit` and acknowledgement nodes |
| `previous_node_actor(node_key)` | the user who decided the named node |
| `initiator_manager` | `reporting_line(level_n = 1, anchor = requester)` |

### IX.5.3 Reporting line to level N, precisely

```
line(anchor, n, stop_condition) :=
  cur := anchor's employment as at as_of
  for i in 1..n:
     next := reporting relationship of cur, effective as at as_of
     if next is NULL:
        apply on_short_line
     if next has already been visited:
        FAIL (reporting cycle)
     cur := next
     if stop_condition is present and evaluates true against cur:
        return cur
  return cur
```

**R-IX-029** The reporting relationship MUST be an effective-dated, tenant-scoped
row referencing an employment, supporting a **primary** line and optional named
secondary lines (functional, dotted, project). *Rationale — SmartPRS 2 has one
`reporting_manager_id` column plus a `reporting_manager` name string, the FK
lookup is **not tenant-scoped** (`ApprovalService.php:321`, `:344`), and matrix
reporting is impossible.*

**R-IX-030** `level_stop_condition` MUST be available so a customer can express
"escalate upwards until you reach someone at grade M3 or above" without knowing
how many hops that is. *Rationale — the number of hops differs per employee, which
is why a fixed second hop degrades to a label string today.*

### IX.5.4 When resolution yields nobody

This is the single most damaging silent failure in SmartPRS 2: a `NULL`
`approver_id` is a legal outcome, no email is sent (`:571`), and the item is
invisible in every non-manager inbox (`:1573`).

**R-IX-031** Resolution yielding nobody MUST NEVER produce a pending item with no
assignee. The node's declared `on_no_participant` policy applies, and the default
is `fail_instance`.

| `on_no_participant` | Behaviour | When to use |
|---|---|---|
| `fail_instance` (default) | Instance → `blocked`; operator alert; the requester is told the request could not be routed and why; nothing is approved | Anything money-moving |
| `route_to_node` | Follow a declared transition, typically to an HR fallback approval node | Where a designed fallback exists |
| `assign_to_fallback` | Assign to the tenant's configured fallback participant spec for this entity type | The general safety net |
| `auto_approve_with_reason` | Auto-approve with a stated reason code | **Never permitted** on a node in a segregation set (W10) or on any Section 56 maker-checker workflow; the validator refuses it there |

**R-IX-032** Every tenant MUST have a configured **fallback approver spec** per
subject entity type, and the installer/configuration validator MUST refuse to
mark the tenant's configuration complete without one. *This is the direct answer
to `E-…` PO-16: "who is the fallback approver when an employee has no reporting
manager?" Today: nobody.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-02: the default tenant fallback
approver.**
*Recommendation: a `role_in_scope` spec naming the HR Operations role at company
scope, not a named user.* A named user creates a single point of failure and a
leaver problem; a role survives staff changes. *Trade-off:* a role-based fallback
can resolve to several people, so the fallback node should use `any_one`
completion to avoid manufacturing an all-must approval nobody asked for.

### IX.5.5 Delegation and out-of-office backup

Two distinct mechanisms, deliberately kept apart.

| | **Delegation** | **Out-of-office backup** |
|---|---|---|
| Declared by | the approver, or an administrator on their behalf | the approver, or HR |
| Date-bounded | yes, `from`/`to` mandatory | yes |
| Scope | optional: per entity type, per workflow, per amount ceiling | all workflows |
| Effect on **new** tasks | the delegate is assigned instead | the backup is assigned **in addition**, both may act |
| Effect on **open** tasks | optional `apply_to_open_tasks` (default 0) | yes, the backup is added to open tasks |
| Attribution | task records `assignee_user_id` = delegate, `delegated_from_user_id` = principal; the ledger names both | same shape, `substitution_kind = 'backup'` |
| Chaining | **prohibited** — a delegate may not re-delegate the same authority | n/a |
| Amount ceiling | supported; above the ceiling the task stays with the principal | supported |

**R-IX-033** Delegation MUST record both identities on the task and in the audit
ledger, and the inbox and the trace MUST render "decided by B on behalf of A".
A decision whose accountability is ambiguous is not an auditable decision.

**R-IX-034** Delegation MUST NOT chain. If A delegates to B and B delegates to C,
A's tasks go to B, not to C. *Rationale — a chain makes accountability
untraceable and permits an authority to be laundered to someone the principal
never chose.*

**R-IX-035** A delegation MUST NOT confer permissions the delegate does not hold.
If the delegate lacks the node's `required_permission`, the delegation does not
apply to that node and the task remains with the principal, with the reason
recorded. *Law 5: authorisation is not transferable by a workflow setting.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-03: may delegation apply to
maker-checker (Section 56) workflows at all?**
*Recommendation: yes, but only to a delegate who independently holds the
segregated role, and never such that the delegate ends up holding both sides of a
segregation set.* W10's runtime counterpart re-checks the segregation constraint
**after** delegation substitution. *Trade-off:* forbidding delegation entirely on
these workflows is safer but produces month-end deadlock when the single Finance
approver is on leave; the segregation re-check gives the same safety without the
deadlock.

### IX.5.6 The self-approval prohibition

**R-IX-036** By default, the requester MUST NOT be an assignee of any approval,
quorum or escalation task in their own instance. The engine drops them at
resolution step 8 and, if that empties the set, applies `on_no_participant` — it
does **not** quietly leave them assigned.

The prohibition covers three identities, all of which must be excluded:

1. the **initiating user**;
2. the **subject employee**, where the subject concerns an employee and that
   employee has a user account (an employee must not approve their own salary
   change even if someone else raised it);
3. any user acting under a **delegation from** either of the above.

Override policy — the only permitted overrides, in order of preference:

| Override | Mechanism | Permitted where |
|---|---|---|
| **None** | `allows_self_approval = 0` (default) | everywhere |
| **Permission** | `self_approval_override = permission`; the actor must hold a distinct, separately grantable permission (`workflow.self_approve.<entity>`), and every use writes a `SELF_APPROVAL_OVERRIDE` audit row with a mandatory reason | small-value operational workflows only |
| **Named role** | `self_approval_override = named_role`; e.g. a proprietor who genuinely is the only approver in a five-person tenant | single-approver tenants |
| **Workflow** | `self_approval_override = workflow`; the self-approval itself routes through a second workflow to a different person | never actually a self-approval; the honest option |

**R-IX-037** `self_approval_override` MUST be refused by the publish validator on
any workflow bound to a Section 56 operation (§IX.8). There is no configuration
by which one person alone completes a maker-checker control. *Rationale — this is
precisely what `admin` satisfying both `isHR()` and `isFinance()` achieves today
(`SalaryApprovalController:59–68`).*

**R-IX-038** Every self-approval override use MUST appear on a standing exception
report visible to the tenant's administrators and to Ametecs support, with the
actor, the entity, the amount and the reason.

### IX.5.7 Segregation sets

A segregation set names two or more nodes and a rule:

| Rule | Meaning |
|---|---|
| `distinct_actor` | no user may decide two nodes in the set |
| `distinct_role` | the deciding users must hold different declared roles |
| `distinct_actor_and_no_common_role` | both — the strict maker-checker form |

Enforced twice: at publish (W10, the participant sets cannot intersect) and at
decision time (an actor who decided an earlier node in the set is excluded from
the later node's participants, and a decision attempt is refused `403` and
audited).

**R-IX-039** Segregation MUST be enforced at decision time and not only at
publish, because role membership changes between publication and decision.

### IX.5.8 Reassignment rights

| `reassignment_rights` | Who may reassign an open task |
|---|---|
| `none` | nobody; the task moves only by delegation, backup or escalation |
| `assignee` | the current assignee, to another resolvable participant of the same node |
| `assignee_manager` | the assignee's manager as well |
| `permission` | any holder of `workflow.task.reassign` within data scope |

**R-IX-040** Reassignment MUST require a reason, MUST write a transition row and
an audit row naming both users, and MUST NOT reset the SLA clock unless the node
declares `reassignment_resets_sla = 1`. *Rationale — a resettable clock makes SLA
reporting meaningless, and reassignment is the obvious lever.*

**R-IX-041** Reassignment MUST NOT be able to place a task with a user excluded
by the self-approval prohibition or a segregation set. The same filters apply.

### IX.5.9 Leavers, revoked roles and dissolved groups

**R-IX-042** When a user is deactivated, every open task assigned to them MUST be
surfaced on an **orphaned-tasks** queue and re-resolved according to the node's
policy: re-resolve participants; if that yields nobody, apply
`on_no_participant`. Deactivation MUST NOT leave a task addressed to an account
nobody can log into. *Test:* deactivate a user holding twelve open tasks; assert
twelve re-resolutions or twelve alerts, and zero silently pending items.

---

## IX.6 Instance semantics

### IX.6.1 The instance state model

Instance states are rows in `status_values` under the domain
`workflow_instance_state` (Part XXVIII XXVIII.4), never a database `ENUM`.

| State | Meaning | Entered from | Terminal |
|---|---|---|---|
| `running` | at least one node is active | `submit` succeeded | no |
| `awaiting_edit` | returned to the requester | `return_for_edit` | no |
| `blocked` | cannot proceed without operator or configuration intervention (no participant, subject drift, stale timer) | any node's park disposition | no |
| `failed` | an evaluation or engine error; no business decision was taken | any node's fail disposition | no |
| `completed` | `finalise` reached | `finalise` | **yes** |
| `rejected` | `reject` reached | `reject` | **yes** |
| `cancelled` | `cancel` reached | `cancel` | **yes** |
| `expired` | an SLA policy of `auto_reject` fired at the instance level | SLA sweep | **yes** |

Permitted transitions are `status_transitions` rows. `blocked → running` and
`failed → running` require the permission `workflow.instance.resume` and a
reason. **No transition out of a terminal state exists.** Reopening is a new
instance with a link to its predecessor, never a resurrection.

**R-IX-043** A terminal instance MUST NOT be reopened. A correction is a **new
instance** carrying `supersedes_instance_id`, so both the original decision and
the correction are in the ledger. *Rationale — SmartPRS 2's commission `reopen`
sets `status='pending'`, `decided_by=NULL`, `decided_at=NULL`
(`RequestController.php:1403–1410`), erasing the fact that a decision was ever
made.*

### IX.6.2 The task state model

Domain `workflow_task_state`: `pending` · `approved` · `rejected` · `returned` ·
`reassigned` · `withdrawn` · `expired` · `superseded`.

`pending` is the only non-terminal task state. Every other state is terminal for
that task row; a task that must change hands produces a **new row**
(`reassigned` closes the old, opens the new), so the history is a chain of rows
rather than a mutated column.

**R-IX-044** A task row MUST NOT move from a terminal state to any state. Enforced
by a `status_transitions` set with no outgoing edges from terminal codes, and by
the database constraint in §IX.6.3.

### IX.6.3 Immutability of decided steps — the mechanism

Stating that decisions are immutable is not enough; SmartPRS 2's problem is that
nothing stops the write. Four mechanisms, all of them structural:

1. **The conditional update.** The only statement that records a decision is
   ```sql
   UPDATE workflow_tasks
      SET status_code = :outcome, decided_by_user_id = :actor,
          decided_at = :now, decision_comment = :comment,
          row_version = row_version + 1
    WHERE id = :task_id AND tenant_id = :tenant
      AND status_code = 'pending' AND row_version = :expected_version
   ```
   Zero affected rows ⇒ the decision is refused with
   `409 workflow.task_already_decided`, and the caller is returned the existing
   decision. There is no code path that writes a decision without the
   `status_code = 'pending'` predicate. *Test:* a static-analysis rule fails the
   build on any `UPDATE` of `workflow_tasks.status_code` outside the single
   decision repository method.
2. **The database privilege.** The application user has no `DELETE` on
   `workflow_tasks`, `workflow_transitions` or `audit_events`.
3. **The transition ledger.** `workflow_transitions` is append-only and carries a
   unique constraint on `(tenant_id, workflow_instance_id, seq)`, allocated under
   a row lock on the instance. A replayed decision cannot obtain a second `seq`
   for the same task decision because of (1).
4. **The completion outbox.** `finalise` emits through an outbox with a unique
   constraint on `(tenant_id, workflow_instance_id, completion_event_key)`
   (R-IX-026), so even a hypothetical double-finalise cannot produce two transfer
   orders.

**R-IX-045** Re-deciding a decided task MUST be **impossible**, not discouraged.
The four mechanisms above are each independently sufficient; all four are
required. *Test (mandatory, exact-value):* submit a decision twice concurrently
from two connections; assert exactly one `workflow_transitions` row, exactly one
audit row, exactly one emitted event, and a `409` on the loser.

**R-IX-046** Decision comments MUST be **append-only per transition**, stored on
the transition row, never as a single overwritable column on the item.
*Rationale — SmartPRS 2 writes `'remarks' => $v['remarks'] ?? null`
unconditionally on every decide (`RequestController.php:1408`;
`LeaveController.php:422`), so a blank re-decide erases the prior decision's
stated reason.*

### IX.6.4 Idempotency

Three distinct idempotency concerns, three mechanisms:

| Concern | Mechanism |
|---|---|
| **Duplicate instance creation** (double-click, retried POST, replayed webhook) | `instance_key_expr` produces a key; `UNIQUE (tenant_id, workflow_artefact_key, instance_key)` over non-terminal instances. A second start returns the existing instance uuid with `200`, not a second instance. Where no `instance_key_expr` is declared, the caller's `Idempotency-Key` header (spine S17) serves the same purpose |
| **Duplicate decision** | §IX.6.3 mechanism (1), plus the client-supplied `Idempotency-Key`, which returns the original response rather than a `409` when the key matches |
| **Duplicate effect** | the completion outbox (R-IX-026) plus a subscriber-side requirement: every domain subscriber MUST be idempotent keyed on `workflow_instance_uuid` |

**R-IX-047** Every unsafe workflow endpoint MUST accept an idempotency key and
MUST return the original response for a repeated key within the configured window
(spine S17).

**R-IX-048** A domain subscriber to a workflow completion event MUST be
idempotent on the instance uuid, and its idempotency MUST be asserted by a test
that delivers the same event twice. *Rationale — SmartPRS 2's transfer-order and
increment-letter side effects are unguarded and re-fire
(`RequestController.php:1470–1492`).*

### IX.6.5 Concurrent decisions on a parallel node

| Situation | Resolution |
|---|---|
| Two participants of an `all_must` node approve simultaneously | Both succeed; the node's completion is evaluated under a row lock on the **instance**, so exactly one activation of the next node occurs |
| One approves and one rejects simultaneously on `all_must` | The **rejection wins** deterministically: completion evaluation applies rejection precedence regardless of arrival order. The approval is still recorded as a fact and appears in the trace |
| Two participants of an `any_one` node decide simultaneously | The first to obtain the instance lock decides; the other receives `409 workflow.node_already_decided` and its task is closed `superseded`, with its attempted decision recorded in the ledger as an attempt |
| Two branches of `parallel_any` complete simultaneously | Tie broken by `decided_at`, then by the lower task id (§IX.4.5) |
| A quorum node reaches `n` approvals while a rejection is in flight | The rejection lands on a node whose outcome is already decided ⇒ `409`, task `superseded`, attempt recorded. The quorum outcome does not change |

**R-IX-049** Node completion evaluation MUST occur under a row lock on the
workflow instance, and MUST be the only place node outcomes are computed.
*Rationale — SmartPRS 2's leave balance check has no transaction, no lock and no
re-check at approval, so two simultaneous applications both pass
(`E-…` §7A Q5 failure mode 1). The same class of race would apply to quorum
tallies computed in application memory.*

**R-IX-050** A rejected decision that arrives after a node has been decided MUST
still be written as an **attempted transition** row, with the actor, timestamp,
intended outcome and comment. The evidence that a second approver disagreed is
material even when it does not change the outcome.

### IX.6.6 Versioning of in-flight instances

**R-IX-051** An in-flight instance MUST complete on the `config_version_id` it
started on. Publishing a new version of a workflow MUST NOT alter, re-route,
re-participant or re-SLA any running instance.

`workflow_instances.config_version_id` is `NOT NULL` with `ON DELETE RESTRICT`,
and a `config_versions` row can never be deleted (spine S4). Superseding a
version closes its `effective_to`; it does not archive it out of reach.

What happens when the definition is superseded mid-flight:

| Case | Behaviour |
|---|---|
| A new version is published while instances are running | Running instances continue on their own version. New instances resolve the new version. The publish screen states, before confirmation, **how many instances are in flight on the version being superseded** and how long the oldest has been running |
| The new version removes a node an in-flight instance is sitting on | Irrelevant to that instance — it holds its own version's node set |
| An administrator wants in-flight items moved to the new version | Not possible in place. The supported operation is **cancel and restart**: an explicit, permissioned, audited bulk action that cancels the running instances with reason `superseded_by_version`, and starts new instances on the new version, linking `supersedes_instance_id`. Prior decisions are preserved as facts in the cancelled instances' ledgers and are **not** carried over as approvals |
| The definition is retired entirely | Retirement is blocked while instances are running (validator V05 in Part VI). The administrator must first complete or cancel them |
| The version is rolled back | A rollback publishes a new version whose payload equals an earlier one. It is a new version and behaves as one; it never revives the old version's identity |

**R-IX-052** Migrating an in-flight instance to a different definition version
MUST be impossible. Only cancel-and-restart exists, and it never inherits
approvals. *Rationale — carrying an approval across a definition change means a
person approved under one control and their approval counts under another.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-04: should publishing a workflow whose
change is *material* (nodes, participants or segregation) be blocked while
instances are in flight?**
*Recommendation: warn, do not block, but require an explicit acknowledgement
naming the in-flight count.* Blocking makes urgent corrections impossible in a
busy tenant; silent publication makes two populations run under different
controls without anyone noticing. The acknowledged warning gives the auditor a
dated record of who accepted the split. *Trade-off:* a careless administrator can
click through; the acknowledgement row is the compensating control.

### IX.6.7 Skipped nodes

A node whose `guard_expr` is false is recorded with outcome `skipped`, a
transition row, and the substituted values that made the guard false. It is
never omitted from the trace.

**R-IX-053** A skipped node MUST appear in the trace with its guard source and
substituted values. "This step did not apply, and here is why" is as much a part
of the explanation as "this step approved" (Law 4).

### IX.6.8 Subject drift and material change

The instance stores `entity_snapshot_hash` at `submit` and re-hashes at every
decision node entry and at `finalise`.

**R-IX-054** The definition MUST declare which subject fields are **material**.
A change to a material field while the instance is running:

| Policy (`on_material_change`) | Behaviour |
|---|---|
| `invalidate_decided_steps` (default for money-moving workflows) | Prior approvals on the changed path are invalidated, the affected nodes are re-activated, and the trace names each invalidated approval |
| `restart` | The instance is cancelled with reason `material_change` and a new instance starts |
| `block_edit` | The domain service refuses the edit while an instance is running — the strongest option and the right one for payroll runs |
| `warn_only` | The change is recorded and flagged to remaining approvers; permitted only where the validator confirms the workflow is not a Section 56 workflow |

*This exists because SmartPRS 2 permits editing a commission after approval with
the approval standing (`RequestController.php:763–877`, "Ejaz's rule"), leaves an
approved offer's CTC freely editable (`H-…` BR-REC-003), and re-prices an
approved payroll draft because there is no attendance freeze (DEF-P04).*

**PRODUCT OWNER DECISION REQUIRED — D-IX-05: is edit-after-approval retained, and
for which entity types?**
*Recommendation: retain it only for `commission_claim`, only until the entry is
locked, only under `invalidate_decided_steps`, and only with the field diff
written to the audit ledger with old→new values.* It is a deliberate, documented
business rule (`RequestController.php:758–762`) and the collections business
appears to depend on it. *Trade-off:* retaining it anywhere weakens the claim
that decisions are final; confining it to one entity type with re-approval makes
the weakening explicit, bounded and auditable — unlike today, where the rich
field diff goes to `commission_logs` as an English sentence and **never** to the
audit ledger (`E-…` §7A Q8).

### IX.6.9 Instance-level guards that are not approvals

Preconditions (compliance score, certification validity, open grievances,
evidence attached, a counterparty confirmation) are `precondition_expr` on nodes,
each with its own `block | warn | off` severity resolved from configuration at the
point of enforcement.

**R-IX-055** Every gate MUST expose the same `block | warn | off` tri-state. A
gate that can only warn is not a control, and a gate that can only block is not
deployable in a tenant still cleaning its data. *This generalises SmartPRS 2's one
good gate (the DRA gate, `off|warn|block`,
`RequestController.php:1373–1378`) to the two that are hard-coded warn-and-allow
(compliance score and open grievance, `:1416–1454`) and to every future gate.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-06: should the compliance-score and
open-grievance gates default to `warn` or `block` on migration?**
*Recommendation: seed them at `warn`, matching current behaviour exactly, and
surface a Configuration Studio task prompting each tenant to choose.* Migrating
straight to `block` would stop payments on day one for every agent whose score is
below the threshold. *Trade-off:* `warn` preserves the current weak control until
someone acts; the surfaced task and the standing exception report make the
inaction visible.

---

## IX.7 SLA, reminders and escalation

### IX.7.1 Why this is a scheduled job and not a human's memory

SmartPRS 2 has no `due_at`, no `sla_*`, no `reminder_sent_at` and no
`escalate_at` column anywhere, and `routes/console.php:140–262` registers twelve
scheduled commands, **none** of which touches a pending approval. An item
assigned to someone who never acts is pending forever, invisibly.

**R-IX-056** Every approval and quorum node MUST be capable of carrying an SLA, a
reminder schedule and an escalation policy, all as configuration. A node with no
SLA is a deliberate configuration choice, recorded as `sla_minutes IS NULL`, not
an absence of the capability.

### IX.7.2 Business-hours calendars

```
cfg_sla_calendars
  calendar_key, name
  timezone                       IANA zone
  working_days                   per weekday: is_working, start_time, end_time
  breaks[]                       optional non-working intervals within a day
  holiday_calendar_key           FK → the holiday calendar (attendance part)
  half_day_rule                  how a declared half working day is counted
  clock_mode                     business_hours | calendar_hours | calendar_days
```

**R-IX-057** SLA arithmetic MUST use absolute instants and the node's resolved
calendar, and MUST convert to the instance's business timezone for display.
Minutes-since-midnight arithmetic is prohibited (spine S10's discipline, applied
here). *Rationale — SmartPRS 2's greeting sweep uses the server's hour despite a
docblock claiming the tenant's (`E-…` DEF-NOT-05); the same class of bug in an
SLA would silently shift every due date by hours.*

**R-IX-058** `clock_mode = business_hours` MUST exclude weekly offs and holidays
from the elapsed calculation, using the same holiday calendar the attendance
engine uses. There is not a second holiday list.

**R-IX-059** The calendar MUST be resolvable per scope, so a Chennai branch and a
Hyderabad branch may have different working hours on the same workflow.

### IX.7.3 The timer model

Every timed obligation is a **durable row**, not an in-memory schedule.

```
workflow_timers
  instance_id, task_id (nullable for instance-level timers)
  timer_kind        due | reminder | escalation | expiry | stale_check
  fire_at           DATETIME(3) UTC, computed at activation from the calendar
  sequence_no       for the nth reminder
  status            scheduled | fired | cancelled | superseded
```

Timers are created at node activation, cancelled when the task or node is
decided, and recreated on reassignment only if the node declares
`reassignment_resets_sla`.

### IX.7.4 Reminders

`reminder_schedule` is a list of offsets in minutes relative to `due_at`;
negative offsets are pre-due nudges, positive offsets are overdue chasers.
Example configuration (illustrative, tenant-editable, **not** a platform
default): `[-1440, -120, +1440, +2880]` — a day before, two hours before, a day
after, two days after.

**R-IX-060** Reminders MUST be idempotent per `(task_id, timer_kind,
sequence_no)`, enforced by a unique constraint, so a scheduler catch-up cannot
send the same reminder twice.

**R-IX-061** Reminders MUST be **coalesced per recipient per sweep**: an approver
with fourteen overdue tasks receives one digest naming fourteen items, not
fourteen messages. *Rationale — SmartPRS 2's broadcast makes one 15-second-timeout
HTTP call per recipient inside the request (`E-…` DEF-NOT-09); reminder storms
are the same failure with a schedule attached.*

**R-IX-062** Reminder delivery MUST go through the notification engine, with per
recipient delivery tracking. No workflow code composes a message or calls a
mailer directly. *Rationale — the late-arrival email in SmartPRS 2 uses
`Mail::raw` on the global mailer, using the wrong SMTP and leaving no delivery
record (`E-…` DEF-NOT-03).*

### IX.7.5 Escalation

| `escalation_policy` | Behaviour at breach |
|---|---|
| `none` | The task stays; the breach is recorded and reportable. **The default** |
| `notify_only` | Notify the escalation target; the task stays with its assignee |
| `reassign` | Close the task `expired`, open a new task for the escalation target |
| `route_to_node` | Close open tasks `expired`, activate the named node |
| `auto_approve` | Record an `auto_decided` outcome with actor `system`, reason `sla_breach` |
| `auto_reject` | Record an `auto_decided` rejection with the same discipline |

**R-IX-063** `auto_approve` on SLA breach MUST be refused by the publish
validator on any Section 56 maker-checker workflow and on any node inside a
segregation set. An approval nobody gave is not a control. *Test:* attempting to
publish PAYROLL-APPROVE with `escalation_policy = auto_approve` on the Finance
node fails with a named error.

**R-IX-064** Escalation MUST be capable of repeating up the reporting line
(`escalate_levels_max`, with its own interval), because "escalate once and then
nothing" recreates the original problem one level higher.

**R-IX-065** Every breach MUST emit `workflow.sla.breached` carrying the instance,
node, task, assignee, due instant and breach duration, whether or not an
escalation policy acts on it. Reporting on breaches must not depend on having
configured an escalation.

### IX.7.6 The sweep, and what happens when the scheduler is not running

One scheduled command, `workflow:timers`, runs on the tenant-independent
platform schedule at a configurable interval (recommended: every minute; minimum
useful granularity: one minute).

```
workflow:timers
  1. SELECT ... FROM workflow_timers
       WHERE status = 'scheduled' AND fire_at <= now()
       ORDER BY fire_at
       LIMIT :batch  FOR UPDATE SKIP LOCKED
  2. group by recipient for coalescing
  3. for each timer: apply its kind, write the transition/notification,
     mark fired
  4. record the sweep's high-water mark and duration
```

**Deployment reality.** Spine S2 makes the queue and scheduler **mandatory**: the
installer refuses to complete without them and the health check fails loudly if
either stops. This is a direct response to SmartPRS 2's most damaging operational
failures coming from customers running neither.

Behaviour when the scheduler is nonetheless not running:

| Property | Behaviour |
|---|---|
| Data loss | **None.** Timers are durable rows. Nothing is lost; everything is late |
| On restart | The sweep processes the backlog in `fire_at` order |
| Reminder storms | Prevented by coalescing (R-IX-061) and by the **stale-timer policy** below |
| Breach timestamps | Computed from the stored `fire_at`, never from the sweep's wall clock, so reports are accurate about *when* the breach occurred even though the notification was late |
| Auto-approve / auto-reject on a stale timer | **Suppressed.** See R-IX-066 |
| Visibility | The ops dashboard shows the scheduler heartbeat, the sweep's last successful run, the count of timers overdue by more than the alert margin, and the oldest overdue timer. An overdue backlog raises an alert |

**R-IX-066** Every node declaring `escalation_policy` of `auto_approve` or
`auto_reject` MUST declare a `stale_timer_policy`. When a timer fires more than
`stale_threshold_minutes` after `fire_at` — that is, the scheduler was down — the
automatic decision MUST be **suppressed**, the instance flagged `blocked` with
reason `stale_timer`, and an operator alerted. *Rationale — a scheduler outage
followed by a catch-up sweep must never silently approve a backlog of payments.
This is the one place where "the job caught up" is the dangerous outcome.*

**R-IX-067** The health check MUST fail, visibly and loudly, when the scheduler
heartbeat is older than the configured margin, and the approvals inbox MUST
display a banner stating that SLA processing is degraded. Users must not be left
to infer it from missing reminders.

**R-IX-068** SLA state MUST be **derivable without the scheduler**: the inbox
computes overdue status from `due_at` versus now at read time. A stopped
scheduler delays reminders and escalations; it does not make an overdue item look
on time.

---

## IX.8 Maker-checker as a workflow shape

### IX.8.1 The shape

Maker-checker is not a feature. It is a workflow of a particular shape, and the
engine enforces it through three properties already specified:

```
submit  (the maker; the actor is recorded and excluded from every later node)
   → [optional] condition_branch        route by amount, entity or scope
   → approval (the checker)             segregation set {submit, approval}
                                        rule = distinct_actor_and_no_common_role
                                        allows_self_approval = 0, no override
                                        no auto_approve node in the set
                                        no auto_approve/auto_reject on SLA breach
   → finalise                           emits the completion event; the domain
                                        applies the effect idempotently
```

**R-IX-069** A workflow is a **maker-checker workflow** if and only if it
declares a segregation set covering its submit node and at least one approval
node with rule `distinct_actor_and_no_common_role`. The flag is derived from the
definition, never asserted separately, so a workflow cannot claim to be a control
it does not implement.

**R-IX-070** For a maker-checker workflow the publish validator MUST additionally
enforce: no `self_approval_override` (R-IX-037); no `auto_approve` node inside the
set (D-IX-01); no `auto_approve`/`auto_reject` SLA policy (R-IX-063); no
`on_no_participant = auto_approve_with_reason` (R-IX-031); and W10's
non-intersection check across the set's resolved participants for every current
user of the tenant.

**R-IX-071** W10 MUST be re-run as a **scheduled configuration health check**, not
only at publish, and MUST raise a compliance finding when a role change causes two
segregated participant sets to intersect. *Rationale — this is exactly the state
SmartPRS 2 is in permanently: `admin` satisfies `isHR()` and `isFinance()`
(`SalaryApprovalController:59–68`) and `isManager()` and `isAccounts()`
(`RequestController.php:52–61`). A control that was sound at publish and became
unsound at the next role grant must be detectable.*

### IX.8.2 The Section 56 list, applied

Each row is a seeded workflow definition delivered with the platform, published
per tenant during onboarding, and editable thereafter within the constraints
above.

| # | Operation | Seeded workflow | Subject entity / operation | Shape | Segregation | SmartPRS 2 today |
|---|---|---|---|---|---|---|
| 1 | **Payroll approval** | `PAYROLL-APPROVE` | `payroll_run` / `approve_hr`, `approve_finance`, `lock` | submit → HR approval → Finance approval → lock → finalise | `{submit, hr, finance}` distinct actor **and** no common role | Two stages both satisfied by `admin` (`SalaryApprovalController:59–68`); the bank file bypasses the guard entirely (DEF-P10) |
| 2 | **Bank account change** | `EMP-BANK-CHANGE` | `employee` / `bank_change` | submit → HR verification (document required) → Finance approval → finalise | `{submit, verify, approve}` | ESS self-edit is instant, unverified and **unaudited** (`E-…` DEF-ESS-02, SEC-08) |
| 3 | **Salary change** | `SALARY-CHANGE` | `salary_structure_assignment` / `change` | submit → amount-band branch → HR approval → [MD approval if the revision exceeds the configured percentage] → finalise | `{submit, approvals}` | An increment approval overwrites `employees.ctc` with no period guard; the increment letter re-fires on re-approval (`RequestController.php:1486–1492`) |
| 4 | **Statutory configuration** | `STATUTORY-PUBLISH` | `statutory_override` / `publish` | submit (diff + impact mandatory) → Compliance approval → Finance approval → finalise | `{submit, compliance, finance}` | `EffectiveDated::guardFinalisedPeriod()` fails **open** on any exception (`NEW-PAY-26`), and the tenant-wide `statutory_settings` blob has no period check at all |
| 5 | **Employee deletion** | `EMP-DELETE` | `employee` / `delete_request` | submit (reason mandatory) → HR approval → Data Protection approval → finalise (soft delete + retention class) | `{submit, hr, dpo}` | Orphan purge hard-deletes rows from three tables with **no audit record of any kind** (`RequestController.php:1254–1259`, SEC-22) |
| 6 | **Role changes** | `ROLE-CHANGE` | `employee` / `role_change` | submit → line manager approval → Security/Admin approval → finalise | `{submit, manager, security}` | Role membership is edited directly; no approval, and the role set that may decide anything is three PHP literals (`:52–55`) |
| 7 | **Financial approvals** (expense, advance, reimbursement) | `EXPENSE-CLAIM`, `ADVANCE-SANCTION` | `expense_claim`, `advance` / `submit`, `sanction` | submit → amount-band routing → 1..N approvals → finalise | `{submit, approvals}` | One hop, optionally two, thresholds ₹10,000 / ₹10,000 / ₹50,000 as PHP literals (`ApprovalService.php:30–32`) |
| 8 | **Loan sanction** | `LOAN-SANCTION` | `loan` / `sanction` | submit → eligibility gate → amount-band routing (1, 2 or 3 approvals) → Finance disbursement approval → finalise | `{submit, sanctions, disbursement}` | Single threshold; and loans created through the live UI are **never recovered** because the schema lacks the columns (DEF-P02) |
| 9 | **Full and final settlement** | `EXIT-FNF` | `employee_exit` / `clearance`, `settle` | submit → parallel departmental clearance → recovery calculation review → HR approval → Finance approval → finalise | `{submit, hr, finance}` | A ten-item clearance checklist self-healed onto the table at runtime (`ApprovalService.php:188–218`); two competing "last day" columns (`NEW-REC-105`) |
| 10 | **Rule publishing** | `CONFIG-PUBLISH-RULE` | `config_version` / `publish` | submit (diff + impact + regression results mandatory) → peer review → owner approval → finalise | `{submit, review, approve}` | No approval on configuration change of any kind; policies overwrite in place |
| 11 | **Workflow publishing** | `CONFIG-PUBLISH-WORKFLOW` | `config_version` / `publish` where artefact type is `workflow` | as above, plus the in-flight-instance acknowledgement (D-IX-04) | `{submit, review, approve}` | n/a — workflows are code |

**R-IX-072** Each of the eleven operations above MUST be marked
`requires_maker_checker = 1` in its entity-operation registry row. An operation so
marked MUST refuse to execute without a completed instance of a workflow that
satisfies R-IX-069, and MUST refuse to bind to a workflow that does not. *Test:*
for each of the eleven, attempt the operation with no instance, with a running
instance, with a rejected instance, and with a completed instance of a
non-maker-checker workflow; assert refusal in the first three cases and in the
fourth.

**R-IX-073** The maker-checker requirement MUST NOT be removable by tenant
configuration. A tenant may make a control **stricter** (more steps, more
segregation); it may not make it absent. *Rationale — the brief's Section 56 list
is a compliance commitment, and a control a customer can switch off is not one.*

**PRODUCT OWNER DECISION REQUIRED — D-IX-07: single-user and very small tenants.**
In a five-person tenant there may genuinely be only one person who can approve
anything. *Recommendation: no exemption from the eleven. Where a tenant cannot
staff both sides, the second side is filled by a named external checker (the
proprietor's accountant, an Ametecs-managed service role) or the tenant accepts
that those operations require two accounts.* *Trade-off:* this is friction for
micro-tenants; the alternative is shipping a documented control that a single
person routinely satisfies alone, which is the exact defect we are correcting.

### IX.8.3 Payroll, specifically

Because payroll is where the current control fails hardest, its seeded workflow is
specified in full in §IX.12.3, and three additional requirements bind the payroll
module:

**R-IX-074** Disbursement MUST NOT be a side effect of producing a file. Bank-file
generation MUST be a read operation; the state change to `disbursed` MUST be a
separate, permissioned, workflow-gated command. *Rationale — DEF-P10: downloading
the bank file **is** the disbursement, it is a GET that mutates state, and it
bypasses the four-eyes guard because `bankFile():499-503` writes `line_status`
directly instead of going through `applyLineAction()`.*

**R-IX-075** Period reopen MUST be a workflow (`period_reopen` / `request`), not a
role check. *Rationale — `PayrollLock::denyUnlessSuperAdmin():82-97` has zero
callers; the documented Super-Admin reopen is unimplemented.*

**R-IX-076** Run regeneration MUST create a new run version preserving prior
approvals, remarks and line statuses as historical facts of the superseded
version. *Rationale — BR-PAY-405 / DEF-P18: regeneration currently deletes the run
row and its payslips, destroying the rejection remark and every approval stamp.*

---

## IX.9 Amount-based and condition-based routing

### IX.9.1 The mechanism

Amount routing is a first-class property of an `approval` node, not a bespoke
comparison. Bands are contiguous, non-overlapping, exhaustive (W08), declared in
minor units, and each band names its own participant list and, optionally, its
own SLA.

```
amount_bands: [
  { from: NULL,      to: <cfg>,  participants: [...], sla_minutes: <cfg> },
  { from: <cfg>,     to: <cfg>,  participants: [...], sla_minutes: <cfg> },
  { from: <cfg>,     to: NULL,   participants: [...], sla_minutes: <cfg> }
]
```

`from` is inclusive, `to` is exclusive, `NULL` is unbounded. The band is selected
by `subject_amount_path` evaluated at node activation — **not** at instance
creation, so an amended amount re-routes (R-IX-027).

**R-IX-077** Amount comparison MUST use integer minor units through a `Money`
value object (Law 7). Floats never touch a routing threshold. *Rationale — in
SmartPRS 2 a float comparison decides whether a loan instalment is deducted
(`PayrollGenController:1357`), and `SchemeController` uses explicit `0.005`
epsilons, the code itself acknowledging float imprecision.*

**R-IX-078** Every band boundary MUST be tenant configuration with an effective
date, per workflow and per scope. The values below are illustrative and are
recorded as *the values a tenant may configure*, never as platform defaults or as
correct amounts. The three thresholds SmartPRS 2 hard-codes
(`ApprovalService.php:30–32`) are seeded as tenant defaults and flagged for
confirmation (`E-…` PO-17).

### IX.9.2 Worked example — loan sanction by amount

Definition `LOAN-SANCTION`, node `n_sanction`, `subject_amount_path =
loan.principal`. Configured bands (tenant values, illustrative):

| Band | Range | Participants | Completion | SLA |
|---|---|---|---|---|
| 1 | up to ₹50,000 | `reporting_line(level 1, anchor = subject_employee)` | `any_one` | 2 working days |
| 2 | ₹50,000 – ₹2,00,000 | step A `reporting_line(level 1)`; step B `role_in_scope(Finance Manager, company)` | sequential, both | A 2 days, B 3 days |
| 3 | ₹2,00,000 and above | step A `reporting_line(level 1)`; step B `role_in_scope(Finance Manager, company)`; step C `role_in_scope(Managing Director, legal_entity)` | sequential, all three | A 2, B 3, C 5 days |

Expressed as configuration rather than three separate workflows: a
`condition_branch` selects the sanction path.

```
branches:
  - label: "up to the single-approval ceiling"
    condition: loan.principal < policy.loan_band_1_ceiling
    to: n_sanction_1
  - label: "single to double approval band"
    condition: loan.principal < policy.loan_band_2_ceiling
    to: n_sanction_2
  default: n_sanction_3
```

**Worked instance.** Agent R. Krishnan (Hyderabad Collections, grade A2) requests
₹1,75,000 on 14-Aug-2026.

```
Binding      cfg_workflow_bindings → LOAN-SANCTION v4 (effective 01-Apr-2026 →)
             scope: legal_entity = Ametecs Collections; specificity 60
n_submit     submitted 14-Aug-2026 11:02 IST; snapshot hash 9f2c…; amount ₹1,75,000
n_route      branch "single to double approval band"
               loan.principal (₹1,75,000) < policy.loan_band_1_ceiling (₹50,000)   → false
               loan.principal (₹1,75,000) < policy.loan_band_2_ceiling (₹2,00,000) → true
             → n_sanction_2
n_sanction_2A participants: reporting_line(1, subject_employee)
               = S. Meera (Team Lead, Hyderabad Collections) as at 14-Aug-2026
             due 18-Aug-2026 18:00 IST (2 working days, calendar HYD-GEN)
             approved 15-Aug-2026 09:41 by S. Meera, comment "Verified tenure"
n_sanction_2B participants: role_in_scope(Finance Manager, company = Ametecs Collections)
               = { A. Rao, P. Nair } — 2 candidates, completion any_one
             due 20-Aug-2026 18:00 IST
             approved 18-Aug-2026 16:12 by A. Rao
             P. Nair's task closed 'superseded'
n_finalise   completed 18-Aug-2026 16:12; event loan.sanctioned emitted once
```

If the requester amends the principal to ₹2,40,000 while the instance is running,
`loan.principal` is declared material, `on_material_change =
invalidate_decided_steps`, so both approvals are invalidated, `n_route` re-runs,
the default branch is taken, and three approvals are re-sought. **The trace names
the two invalidated approvals.** In SmartPRS 2 the escalation is computed once at
creation and never revisited (`RequestController.php:531`).

### IX.9.3 Worked example — leave over five days routes to the department head

Definition `LEAVE-APPROVE`, node `n_route`. The routing input is not money, so
the branch reads the leave request directly.

```
branches:
  - label: "long leave"
    condition: leave.units > policy.leave_dept_head_threshold_days
    to: n_dept_head
  default: n_manager
```

`policy.leave_dept_head_threshold_days` is a resolved policy value (spine S5.3
`policy.*`), effective-dated and scoped — so Collections may set four days while
Support sets seven, without a second workflow.

```
Employee     K. Anitha, Support, Chennai
Request      21-Sep-2026 → 28-Sep-2026, Earned Leave
leave.units  6.0 days      (working days, per the leave type's day-count basis)
n_route      "long leave": 6.0 > 5.0 → true      → n_dept_head
n_dept_head  role_in_scope(Department Head, data_scope = department,
                           anchor = subject_employee)
             resolved as at 21-Sep-2026 (the leave start, per the node's as_of policy)
             = V. Subramanian
             SLA 24 business hours, calendar CHN-GEN
             reminder schedule [-120, +480]
             escalation reassign → reporting_line(1, anchor = V. Subramanian)
```

Note the two things SmartPRS 2 cannot do here at all: routing on anything but
amount, and a department-head approver who is not the reporting manager
(`E-…` B-47).

### IX.9.4 Worked example — salary revision over 20 % routes to the MD

`SALARY-CHANGE`, node `n_route`. The percentage is computed by the formula engine
(Part VIII) and exposed to the branch as an instance variable, because a workflow
does not do money arithmetic (R-IX-005).

```
n_submit
  entry_actions:
    SET_INSTANCE_VARIABLE revision_pct =
        FORMULA('SALARY-REVISION-PCT', subject)     -- returns Number
n_route
  branches:
    - label: "material revision"
      condition: vars.revision_pct > policy.salary_md_threshold_pct
      to: n_md_chain
    default: n_hr_only
```

Worked: current CTC ₹8,40,000, proposed ₹10,50,000.
`revision_pct = 25.0`; `policy.salary_md_threshold_pct = 20.0` (tenant value,
effective 01-Apr-2026) ⇒ `25.0 > 20.0` is true ⇒ `n_md_chain`
(HR approval → MD approval, segregated from the maker).

The trace renders:

```
Branch       "material revision"
Condition    vars.revision_pct > policy.salary_md_threshold_pct
Substituted  25.0 > 20.0  →  true
Sources      revision_pct: formula SALARY-REVISION-PCT v2
                           (CTC ₹8,40,000 → ₹10,50,000)
             policy.salary_md_threshold_pct: policy SALARY-THRESHOLDS v3
                           (effective 01-Apr-2026 →, scope: legal_entity)
Routed to    n_md_chain  (HR approval → MD approval)
```

### IX.9.5 Routing inputs beyond amount

Any SPX-addressable value is a routing input: `employee.*`, `org.*`, `service.*`,
`period.*`, `leave.*`, `salary.*`, `policy.*`, `statutory.*`, `agg.*` and the
instance's own `vars.*`. Representative conditions a tenant can author without
code:

| Requirement | Condition |
|---|---|
| Probationers' expenses need HR as well | `employee.stage = 'PROBATION'` |
| Night-shift OT above a threshold goes to Ops | `shift.is_night AND attendance.ot_minutes > policy.ot_ops_threshold_minutes` |
| Third regularisation in a payroll month escalates | `agg.occurrence_index('attendance_regularisation', 'payroll_period') >= 3` |
| Off-roll agents route to the vendor manager | `employee.employment_type = 'OFF_ROLL'` |
| Anything touching a locked period goes to Finance | `period.is_locked` |
| Claims from an employee with an open grievance carry a warning gate | `agg.count('grievance', 'rolling(90, day)', 'status IN open,pending') > 0` |

---

## IX.10 Integration with the rule engine

### IX.10.1 The two binding actions

| Action | Emitted by | Meaning | Parameters |
|---|---|---|---|
| `ROUTE_TO_WORKFLOW(workflow_code)` | `leave.request.validate` and other request-shaped decision points | **Selects which** workflow this subject uses, replacing the binding that would otherwise apply | `workflow_code: Code` |
| `REQUIRE_APPROVAL(workflow_code, reason)` | `payroll.run.gate`, `platform.config.guard`, `compliance.payment_gate` | **Adds** an approval requirement to an operation that would otherwise proceed | `workflow_code: Code`, `reason: Text` |

The difference matters: `ROUTE_TO_WORKFLOW` answers "which path?", so two rules
emitting different codes is a conflict the rule engine fails on (Part VII §7.3).
`REQUIRE_APPROVAL` answers "must this be approved at all?", so two rules emitting
different codes is **not** a conflict — both requirements stand and both
instances must complete before the operation proceeds.

**R-IX-079** `ROUTE_TO_WORKFLOW` MUST be idempotent by value; two identical
emissions are one routing. Two different emissions at the same decision point
fail the rule evaluation with a named conflict, and the operation is refused.
The workflow engine never arbitrates between rules.

**R-IX-080** Multiple `REQUIRE_APPROVAL` actions MUST produce multiple
instances, all of which must reach `completed` before the gated operation
executes. The operation's precondition is the conjunction. *Rationale — a
compliance gate and a financial gate are independent controls; satisfying one
must not satisfy the other.*

**R-IX-081** The workflow engine MUST record, on the instance,
`origin_rule_version_id` when the binding came from a rule action, so the trace
can answer "why is this being approved at all?" with the rule, its version and
the condition that matched. *This is spine S15 applied to routing rather than to
arithmetic.*

### IX.10.2 The composition boundary, restated

```
domain service
  → rule engine (decision point)  → AppliedAction[] + DecisionTrace
  → sees ROUTE_TO_WORKFLOW / REQUIRE_APPROVAL
  → asks the workflow engine to start an instance, inside its own transaction
  → the operation waits
  → workflow.instance.completed arrives
  → the domain service applies the effect, idempotently, on the instance uuid
```

The rule engine never calls the workflow engine, and the workflow engine never
calls the rule engine to *decide a business outcome*. The workflow engine calls
SPX to evaluate a guard, a branch, a participant expression or an auto-approve
condition — that is expression evaluation, not rule evaluation, and it uses the
same evaluator with the same budgets.

**R-IX-082** A workflow branch condition MUST be evaluated by the same SPX
evaluator, against a **prepared context** declaring exactly which namespaces are
readable at that node, validated at publish time. An expression referencing an
undeclared namespace is a publish error, not a runtime null. *Rationale — spine
S5.3; and SmartPRS 2's configuration payloads are accepted as arbitrary arrays
and merged (`E-…` NEW-NOT-113).*

### IX.10.3 Worked composition

Leave application, 6 days sick leave, employee on probation, Hyderabad.

```
1. LeaveRequestService calls decision point leave.request.validate
2. Rules fire (Part VII):
     RL-LV-011  when leave.type = 'SL' AND leave.units > policy.sl_document_days
                then REQUIRE_DOCUMENT('MEDICAL_CERTIFICATE')
     RL-LV-014  when employee.stage = 'PROBATION'
                then ROUTE_TO_WORKFLOW('LEAVE-APPROVE-PROBATION')
3. Trace records both, with versions and matched conditions
4. LeaveRequestService:
     - enforces the document requirement (its own concern)
     - starts workflow LEAVE-APPROVE-PROBATION, recording
       origin_rule_version_id = RL-LV-014 v3
5. The instance runs: manager → HR (probation confirmation authority) → finalise
6. On completion the leave ledger debits the balance, idempotently on the
   instance uuid
```

The employee's "How was this decided?" panel shows the rule that chose the path,
the workflow version, every participant resolution, every decision and every SLA
event — one continuous explanation across three engines.

---

## IX.11 The unified inbox

### IX.11.1 What it replaces

SmartPRS 2's inbox (`RequestController::inbox():1532–1591`) aggregates pending
leave plus nine request tables into one flat list. It has **no pagination, no
limit, no filter and no sort control**; the query is
`orderByDesc('r.id')->get()` over the whole tenant. Loading it executes runtime
DDL against nine tables (`ApprovalService::ensureAuditCols():46–260`). Non-manager
scoping is `approver_id = $myId ?? -1`, so an item with a null approver is
invisible to everyone. There is no SLA column because there is no SLA, no
delegation view because there is no delegation, and no bulk action of any kind.

### IX.11.2 The SmartPRS 3 inbox

One inbox, one endpoint, every module, driven entirely by `workflow_tasks` —
because every approval in the product is a workflow task, there is nothing to
aggregate.

**R-IX-083** The approvals inbox MUST be served by a single query over
`workflow_tasks` with no per-module branch. *Test:* adding a new approvable entity
type requires zero changes to the inbox code, asserted by a conformance test that
introduces a synthetic entity type and reads it from the inbox.

**What a manager sees**, per row:

| Column | Source |
|---|---|
| Subject summary (employee, entity type, human title) | the subject renderer registered for the entity type |
| Amount, where the entity has one | `subject_amount_path`, formatted in Indian numbering |
| Requested by, requested at | instance |
| Step | node label and position ("step 2 of 3") |
| Why me | the participant spec that resolved to this user, in words: "as Finance Manager for Ametecs Collections", "as R. Krishnan's reporting manager", "on behalf of A. Rao (delegation, 10–20 Sep)" |
| Due | `due_at` in the user's timezone, with an overdue badge computed at read time (R-IX-068) |
| SLA state | `on_time` / `due_soon` / `overdue`, with elapsed business hours |
| Escalation state | whether this task arrived by escalation, and from whom |
| Prior decisions on this instance | who approved what, when, with comments — visible before deciding |
| Attachments and required evidence | with the node's requirement flags |
| Available actions | approve / reject / return / reassign / delegate, filtered by node configuration **and** by the actor's permissions |
| Blocking warnings | gate results at `warn` severity, shown before the decision is taken |

**R-IX-084** The inbox MUST show, for every task, **why the actor is a
participant**, in words. *Rationale — "why is this in my queue?" is the single
most common approval-system support question, and today it is unanswerable
because the approver is a name string derived from one of two columns.*

**R-IX-085** The inbox MUST show prior decisions on the same instance before the
actor decides. An approver who cannot see what the previous approver said is not
performing a review.

### IX.11.3 Filters, sorting and views

| Filter | Notes |
|---|---|
| Module / entity type | multi-select |
| Workflow | multi-select |
| SLA state | on time / due soon / overdue / breached |
| Amount range | on entities with an amount |
| Requester, subject employee | typeahead within data scope |
| Org unit (company, branch, department, team) | as at today |
| Age band | |
| Assignment kind | mine / delegated to me / delegated by me / my team's (for a manager with an oversight scope) / escalated to me |
| Requires my attention first | tasks blocking others, e.g. the first step of a parallel-all |

Saved views are per user, shareable within a role. Default sort: overdue first,
then due soonest, then oldest.

**R-IX-086** The inbox MUST be cursor-paginated (spine S17) with a bounded page
size, and MUST expose a separate cheap **count** endpoint for the badge, served
by the covering index `(tenant_id, assignee_user_id, status_code)` (Part XXVIII
Q7). No screen loads an unbounded list.

### IX.11.4 Bulk actions

**R-IX-087** The inbox MUST support bulk approve, bulk reject and bulk reassign
over an explicit selection, executed as **individual, individually audited
transitions** — never as a single blanket update.

Rules governing bulk:

| Rule | Reason |
|---|---|
| Bulk excludes any task whose node requires a comment or attachment, unless one comment/attachment is supplied and applied to each, with the fact recorded | An approval with no stated reason on a node that requires one is not an approval |
| Bulk excludes any task on a Section 56 maker-checker workflow **unless** the tenant enables it explicitly, and never for `payroll_run`, `salary_structure_assignment`, `employee` bank/role changes, `loan` sanction or `config_version` publication | Bulk is convenience; these are controls |
| Each item is evaluated independently: permission, participation, segregation, self-approval, and the pending-state predicate | One failure does not fail the batch, and one success does not license the rest |
| The response reports per-item outcomes, with a typed reason for each failure | *Rationale — SmartPRS 2's bulk commission upload caps the error report at 30 rows and sends no notifications at all (`RequestController.php:750`, `:607`)* |
| Bulk is rate-limited and size-capped by configuration | |
| Every bulk operation writes one `bulk_operation` audit row plus one row per item | The intent and the effects are both on the record |

**R-IX-088** A bulk action MUST NOT be able to approve an item the actor could not
approve individually. The bulk path calls the same decision service, item by item.

### IX.11.5 Delegation view

**R-IX-089** The inbox MUST expose a delegation panel showing: delegations I have
granted (with date range, scope, amount ceiling and a revoke action), delegations
granted to me, out-of-office backups in both directions, and the tasks currently
being acted on under each. Revoking a delegation re-resolves open tasks that were
substituted (§IX.5.9's mechanism).

### IX.11.6 Oversight views

**R-IX-090** A user with an oversight data scope MUST be able to see, without
being a participant: their team's pending tasks, ageing and breaches; the
orphaned-task queue; and instances blocked for want of a participant. Seeing is
not deciding — the actions available on those rows are limited to reassign (where
permitted) and escalate.

**R-IX-091** Every inbox read of a row containing sensitive data (salary, bank,
PII) MUST be recorded by the read-audit mechanism (spine S13/S14). *Rationale —
SmartPRS 2 has no read auditing of any kind (`E-…` §7A Q8, SEC-18).*

---

## IX.12 Worked workflow library

Eleven complete definitions, seeded with the platform and published per tenant at
onboarding. Every threshold, SLA, role and calendar shown is **tenant
configuration**; the values are illustrative and are never platform defaults, and
none of them is asserted as a correct or required value.

Common notation: `RL(n)` = `reporting_line(level n)`; `RIS(role, scope)` =
`role_in_scope`; `[seg]` marks membership of the definition's segregation set.

---

### IX.12.1 `LEAVE-APPROVE` — leave approval with escalation

**Subject** `leave_request` / `submit`. **Binding** B3 entity binding, tenant
default; a rule may override with `ROUTE_TO_WORKFLOW` (§IX.10.3).

| Node | Type | Configuration |
|---|---|---|
| `n_submit` | submit | precondition: `leave.balance(leave.type) >= leave.units OR policy.allow_negative_balance`; severity from configuration; requires attachment when the rule engine emitted `REQUIRE_DOCUMENT` |
| `n_route` | condition_branch | `leave.units > policy.leave_dept_head_threshold_days` → `n_dept_head`; default → `n_manager` |
| `n_manager` | approval | participants `RL(1, subject_employee)`; `any_one`; SLA 24 business hours (calendar per branch); reminders `[-120, +480]`; escalation `reassign` → `RL(2)`, `escalate_levels_max = 2`, interval 24 h; `allow_return_for_edit = 1` |
| `n_dept_head` | approval | participants `RIS(DEPARTMENT_HEAD, department @ subject_employee)`; `any_one`; SLA 48 business hours; same escalation shape |
| `n_return` | return_for_edit | to requester; editable: dates, type, reason, attachment; `max_returns = 2`; comment mandatory |
| `n_reject` | reject | reason from value set `LEAVE_REJECT_REASON` + free comment |
| `n_notify_team` | notify | audience `group(TEAM_CALENDAR_WATCHERS)`; non-blocking |
| `n_finalise` | finalise | emits `leave.approved`; leave ledger debits on the event, idempotent on the instance uuid |

**Transitions.** `n_submit:submitted → n_route`; `n_route:*` per branch;
`n_manager:approved → n_notify_team`; `n_manager:rejected → n_reject`;
`n_manager:returned → n_return`; `n_return:resubmitted → n_route`;
`n_return:exhausted → n_reject`; `n_notify_team:notified → n_finalise`.

**Segregation.** `{n_submit, n_manager, n_dept_head}` `distinct_actor` — a
manager applying for their own leave routes to `RL(2)` by the self-approval
prohibition (§IX.5.6), which is exactly the case SmartPRS 2 gets wrong by leaving
the requester as their own approver whenever they are their own recorded manager.

**Against SmartPRS 2.** Leave has its own resolver, no approver notification at
all on submission (`LeaveController.php:381–385`), no SLA, no escalation, no
return, no cancellation, and a three-value `ENUM` status column.

---

### IX.12.2 `ATT-REGULARISE` — attendance regularisation

**Subject** `attendance_regularisation` / `submit`.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` | submit | precondition: the target date is within `policy.regularisation_backdating_days` **and** the attendance period is not locked; severity `block` for a locked period, configurable for backdating |
| `n_limit_check` | condition_branch | `agg.occurrence_index('attendance_regularisation', 'payroll_period') > policy.regularisation_monthly_limit` → `n_escalated_review`; default → `n_manager` |
| `n_manager` | approval | `RL(1)`; `any_one`; SLA 24 business hours; reminders `[-240, +480]`; escalation `route_to_node` → `n_hr_review` |
| `n_escalated_review` | approval | `RIS(HR_OPS, branch @ subject_employee)`; comment mandatory; SLA 48 business hours |
| `n_hr_review` | approval | `RIS(HR_OPS, branch)`; SLA 48 business hours |
| `n_reject` | reject | reason mandatory |
| `n_finalise` | finalise | emits `attendance.correction.approved`; the attendance engine writes a **correction overlay** (spine S10) and triggers recalculation for the affected date; the raw punch layer is untouched |

**Segregation.** `{n_submit, n_manager}` `distinct_actor`.

**Against SmartPRS 2.** Regularisation notifies a reviewer through hard-coded call
sites (`AttendanceCorrectionService.php:411–415`, `:440–445`) with no configurable
multi-level approval, no SLA, no delegation, no per-employee monthly limit and no
backdating window (consolidated requirement 31).

---

### IX.12.3 `PAYROLL-APPROVE` — HR → Finance → lock, maker-checker

**Subject** `payroll_run` / `approve_hr`, `approve_finance`, `lock`.
**Marked** `requires_maker_checker = 1`.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | precondition: **zero unresolved preview exceptions** (spine S11's list: missing bank data, PAN, UAN, attendance, structure; negative net; minimum-wage breach; unmapped statutory policy; unapproved variable input; duplicate run; loan inconsistency; invalid tax data) at severity `block`; and the reconciliation assertion `SUM(payslip lines) = run totals` holds exactly |
| `n_freeze` | approval | *system-verified gate rendered as a node so it appears in the trace*: attendance and leave settlement for the period are frozen; auto_approve on `period.is_settled`, otherwise routes to `n_block` |
| `n_hr` `[seg]` | approval | `RIS(PAYROLL_HR_APPROVER, company)`; `all_must` where the tenant configures more than one; comment mandatory; requires acknowledgement of the run totals; SLA 24 business hours; escalation `notify_only` (never auto-approve, R-IX-063) |
| `n_variance` | condition_branch | `vars.net_variance_pct > policy.payroll_variance_threshold_pct` → `n_finance_senior`; default → `n_finance` |
| `n_finance` `[seg]` | approval | `RIS(PAYROLL_FINANCE_APPROVER, legal_entity)`; comment mandatory; SLA 24 business hours |
| `n_finance_senior` `[seg]` | approval | `RIS(FINANCE_CONTROLLER, legal_entity)`; comment mandatory |
| `n_lock` `[seg]` | approval | `RIS(PAYROLL_LOCKER, legal_entity)`; acknowledgement text: locking freezes the period |
| `n_block` | reject | reason `preconditions_not_met` |
| `n_reject` | reject | reason from value set; emits `payroll.run.rejected`; the run returns to draft as a **new version**, preserving the rejection remark (R-IX-076) |
| `n_finalise` | finalise | emits `payroll.run.approved` exactly once; disbursement is a **separate** workflow-gated command, never a file download (R-IX-074) |

**Segregation.** `{n_submit, n_hr, n_finance, n_finance_senior, n_lock}` with rule
`distinct_actor_and_no_common_role`. W10 asserts, at publish and on the scheduled
health check, that no user can satisfy two of these. **This is the check that
`admin` fails today.**

**Prohibited by validator on this workflow.** `self_approval_override`;
`auto_approve` nodes inside the set; `auto_approve`/`auto_reject` SLA policies;
`on_no_participant = auto_approve_with_reason`; bulk decision (§IX.11.4).

**Against SmartPRS 2.** `draft → hr_approved → approved` with
`isHR() = hr_manager|admin|super_admin` and
`isFinance() = admin|super_admin` (`SalaryApprovalController:59–68`) — one `admin`
completes both stages; `paid` and `locked` are read but never written
(BR-PAY-024); there is no reopen path (`PayrollLock:82–97`, zero callers); and
downloading the bank file disburses the money outside the control entirely
(DEF-P10).

---

### IX.12.4 `LOAN-SANCTION` — amount-based routing

**Subject** `loan` / `sanction`. Bands and worked instance in §IX.9.2.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | preconditions, each with its own tri-state severity: service ≥ `policy.loan_min_service_months`; no active loan of the same type unless `policy.allow_concurrent_loans`; projected EMI ≤ `policy.loan_max_emi_pct_of_net` of net pay; compliance gate result |
| `n_route` | condition_branch | three bands (§IX.9.2) |
| `n_sanction_1` `[seg]` | approval | `RL(1)`; `any_one`; SLA 2 working days |
| `n_sanction_2A/2B` `[seg]` | approval | `RL(1)`, then `RIS(FINANCE_MANAGER, company)` |
| `n_sanction_3A/3B/3C` `[seg]` | approval | as 2, then `RIS(MANAGING_DIRECTOR, legal_entity)`; SLA 5 working days; escalation `notify_only` |
| `n_schedule_review` | approval | `RIS(PAYROLL_OPS, company)` confirms the repayment schedule, instalment count, start period and interest treatment before disbursement |
| `n_disburse_auth` `[seg]` | approval | `RIS(FINANCE_DISBURSER, legal_entity)`; segregated from every sanctioning node |
| `n_finalise` | finalise | emits `loan.sanctioned` with the schedule; the payroll module creates the recovery schedule idempotently |

**Segregation.** `{n_submit, all sanction nodes, n_disburse_auth}`
`distinct_actor`; additionally `{n_sanction_*, n_disburse_auth}`
`distinct_actor_and_no_common_role`.

**Against SmartPRS 2.** One `>=` against a single literal ₹50,000
(`ApprovalService.php:32`), no schedule review, no disbursement authorisation —
and loans created through the live UI are never recovered at all because the
schema lacks `emi`, `tenure`, `outstanding` and `moratorium` (DEF-P02).

---

### IX.12.5 `EXPENSE-CLAIM` — expense claim with proof verification

**Subject** `expense_claim` / `submit`. This definition also demonstrates the
**generalised Accounts gate**: the two-stage "someone confirms the evidence, then
someone approves the money" pattern that SmartPRS 2 implements once, in PHP, for
commissions only (`RequestController.php:1359–1364`).

| Node | Type | Configuration |
|---|---|---|
| `n_submit` | submit | requires attachment when `claim.amount > policy.expense_proof_threshold`; attachment types from the document value set; per-line evidence where the claim has lines |
| `n_proof_check` | approval | `RIS(ACCOUNTS_VERIFIER, company)`; decision options `approve \| return \| reject`; the verifier confirms the evidence, **not** the entitlement; comment mandatory on return; SLA 24 business hours |
| `n_policy_gate` | condition_branch | `claim.amount > policy.expense_per_diem_cap` → `n_exception_approval`; `claim.age_days > policy.expense_claim_window_days` → `n_late_claim_approval`; default → `n_route_amount` |
| `n_route_amount` | approval | amount bands: band 1 `RL(1)`; band 2 `RL(1)` then `RIS(FINANCE_MANAGER, company)`; band 3 adds `RIS(FINANCE_CONTROLLER, legal_entity)` |
| `n_exception_approval` | approval | `RIS(FINANCE_CONTROLLER, legal_entity)`; comment mandatory; then continues to `n_route_amount` |
| `n_late_claim_approval` | approval | `RIS(HR_OPS, company)`; comment mandatory |
| `n_return` | return_for_edit | editable: amount, lines, attachments, narrative; `max_returns = 3` |
| `n_finalise` | finalise | emits `expense.approved`; payroll or accounts payable picks it up |

**Segregation.** `{n_submit, n_proof_check, n_route_amount}` `distinct_actor`;
`{n_proof_check, n_route_amount}` `distinct_actor_and_no_common_role` — the
evidence checker and the money approver may not be the same person or hold the
same role. *This is the control `admin` defeats today by satisfying both
`isManager()` and `isAccounts()` (`RequestController.php:52–61`).*

**Against SmartPRS 2.** Expenses get a threshold and nothing else
(`ApprovalService.php:30`); the claim's `reason` field is silently discarded by
`safeRow()`; the eight non-commission modules accept an arbitrary `employee`
parameter with **no guard at all** (SEC-02), so any user can file a claim in a
colleague's name.

---

### IX.12.6 `SALARY-CHANGE` — salary structure change

**Subject** `salary_structure_assignment` / `change`, `revise`. **Marked**
`requires_maker_checker = 1`. Branch worked in §IX.9.4.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | preconditions: effective date not inside a locked payroll period (severity `block`; a locked period requires `PERIOD-REOPEN` first); the resulting structure passes the statutory floor validator (spine S12: a company policy may improve on a statutory minimum, never reduce it); minimum-wage comparison passes for the employee's state and category. `entry_action: SET_INSTANCE_VARIABLE revision_pct = FORMULA('SALARY-REVISION-PCT', subject)` |
| `n_route` | condition_branch | `vars.revision_pct > policy.salary_md_threshold_pct` → `n_md_chain`; `vars.revision_pct < 0` → `n_reduction_chain`; default → `n_hr` |
| `n_hr` `[seg]` | approval | `RIS(HR_COMPENSATION, company)`; comment mandatory; `visible_fields` includes the full old→new component breakdown |
| `n_md_chain` | parallel_all → join | branch A `n_hr`; branch B `RIS(MANAGING_DIRECTOR, legal_entity)`; both must approve; each carries its own SLA clock |
| `n_reduction_chain` | approval | a reduction routes additionally to `RIS(HR_HEAD, legal_entity)` and requires an acknowledgement that consent and statutory constraints have been considered |
| `n_payroll_impact` | notify | audience `RIS(PAYROLL_OPS, company)`; carries the arrears implication where the effective date is retrospective |
| `n_finalise` | finalise | emits `salary.structure.changed`; the assignment is written as a **new effective-dated row** (Law 3), never an update in place |

**Segregation.** `{n_submit, n_hr, n_md_chain.B, n_reduction_chain}`
`distinct_actor_and_no_common_role`.

**Material fields.** Every component amount, the effective date, the pay group.
`on_material_change = invalidate_decided_steps`.

**Against SmartPRS 2.** An approved increment overwrites `employees.ctc` with no
period guard, the letter re-fires on every re-approval
(`RequestController.php:1486–1492`), and the arrears divisor (`/12`) and
proration basis are hard-coded arithmetic rather than configurable formulas
(consolidated requirement 50).

---

### IX.12.7 `EMP-BANK-CHANGE` — bank detail change (maker-checker)

**Subject** `employee` / `bank_change`. **Marked** `requires_maker_checker = 1`.
The highest-value fraud target in the product.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | the maker may be the employee (ESS) or HR on their behalf; requires attachment `CANCELLED_CHEQUE` or `BANK_STATEMENT`; captures the **old** account masked and the **new** account in full, encrypted at the application layer (spine S13) |
| `n_identity_gate` | approval | auto_approve when the change was submitted through ESS **and** the session passed a step-up authentication challenge within `policy.bank_change_stepup_window_minutes`; otherwise routes to `n_hr_verify` |
| `n_hr_verify` `[seg]` | approval | `RIS(HR_OPS, branch @ subject_employee)`; the verifier confirms the document against the account and **must tick a declaration** (`REQUIRE_ACKNOWLEDGEMENT`); comment mandatory; SLA 24 business hours |
| `n_finance_approve` `[seg]` | approval | `RIS(PAYROLL_FINANCE_APPROVER, company)`; sees the masked old and new accounts and the verification declaration; SLA 24 business hours; escalation `notify_only` |
| `n_cooling` | condition_branch | `policy.bank_change_cooling_period_days > 0` AND the next payroll run for the employee is inside the cooling window → `n_defer_notice`; default → `n_finalise` |
| `n_defer_notice` | notify | tells the employee and payroll that the new account takes effect from the following cycle |
| `n_finalise` | finalise | emits `employee.bank_changed`; the employee's **old** address of record and the registered mobile are notified out-of-band that the account changed, and the notification is not suppressible |

**Segregation.** `{n_submit, n_hr_verify, n_finance_approve}`
`distinct_actor_and_no_common_role`. Where the employee is the maker, the
self-approval prohibition already excludes them from both checks
(§IX.5.6 identity 2).

**Against SmartPRS 2.** The ESS profile update writes ten fields immediately with
**no approval, no verification, no audit call of any kind**
(`EssController::updateProfile()`, `E-…` DEF-ESS-02), and the same screen can
change the email that payslips are delivered to (SEC-08).

---

### IX.12.8 `EXIT-FNF` — exit and full-and-final clearance, parallel across departments

**Subject** `employee_exit` / `initiate`, `clearance`, `settle`. **Marked**
`requires_maker_checker = 1`.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | resignation or termination; captures last working day, notice period served/waived, exit reason from a value set |
| `n_notice_route` | condition_branch | `exit.notice_shortfall_days > 0` → `n_notice_waiver`; default → `n_clearance` |
| `n_notice_waiver` | approval | `RIS(HR_HEAD, legal_entity)`; comment mandatory; decides recovery or waiver of the shortfall |
| `n_clearance` | **parallel_all** | branches, each a departmental approval, each with its own SLA and escalation, each skippable by its own guard when the department is not applicable to this employee: `b_it` (assets, accounts, access revocation), `b_admin` (ID card, keys, facilities), `b_finance` (advances, loans, expense settlement), `b_hr` (documents, exit interview, PF/UAN), `b_compliance` (DRA card, agent authorisation, code-of-conduct undertaking — the collections-vertical clearance), `b_manager` (handover, pending work, team property). `on_branch_reject = continue_others` — one department raising a dues item does not abort the others; the item is carried into settlement |
| `n_join` | (join of `n_clearance`) | all non-skipped branches complete |
| `n_fnf_calc` | notify | payroll computes the settlement; the workflow does not (R-IX-005) |
| `n_fnf_review` `[seg]` | approval | `RIS(PAYROLL_OPS, company)`; reviews earnings, recoveries, leave encashment, gratuity, notice recovery; comment mandatory |
| `n_fnf_finance` `[seg]` | approval | `RIS(PAYROLL_FINANCE_APPROVER, legal_entity)`; amount-banded — settlements above `policy.fnf_senior_threshold` add `RIS(FINANCE_CONTROLLER, legal_entity)` |
| `n_relieving` | approval | `RIS(HR_OPS, company)` authorises the relieving and experience letters; the letters issue on the completion event, once |
| `n_finalise` | finalise | emits `employee.exit.settled`; access revocation, asset ledger closure and statutory exit filings subscribe |

**Segregation.** `{n_submit, n_fnf_review, n_fnf_finance}`
`distinct_actor_and_no_common_role`. The departmental clearances are **not**
segregated from each other — they are different departments by construction.

**Escalation.** Each clearance branch escalates on breach to `RL(1)` of its
department owner, then to `RIS(HR_HEAD, legal_entity)`, `escalate_levels_max = 2`.
The exit dashboard shows which department is holding the settlement — the single
most-asked operational question in an exit.

**Against SmartPRS 2.** A ten-item clearance checklist is **self-healed onto the
table at runtime** (`ApprovalService::ensureAuditCols():188–218`), the exit
request is a single-approver row like any other, there is no parallelism, no per
department owner, no SLA, and the table carries two competing "last day" columns
(`NEW-REC-105`).

---

### IX.12.9 `OFFER-APPROVE` — recruitment offer approval

**Subject** `candidate_offer` / `approve`, `revise`, `withdraw`.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` | submit | preconditions with configurable severity: an approved requisition exists and has an open position; the offered CTC is within the band for the grade (`policy.offer_band_max_pct_over_grade_midpoint`); no interview round carries a `no_hire` or `strong_no_hire` recommendation without an override comment |
| `n_route` | condition_branch | `offer.ctc > policy.offer_md_threshold` OR `vars.band_breach` → `n_leadership`; default → `n_hiring_manager` |
| `n_hiring_manager` | approval | `named participant from the requisition` (`spx_expression` resolving the requisition's hiring manager); SLA 2 working days |
| `n_hr_comp` | approval | `RIS(HR_COMPENSATION, company)`; checks internal parity; comment mandatory |
| `n_leadership` | parallel_all | `n_hr_comp` and `RIS(MANAGING_DIRECTOR, legal_entity)` |
| `n_finalise` | finalise | emits `offer.approved` — **and only then** may the offer letter be generated and the tokenised acceptance link issued |
| `n_withdraw` | cancel | `permission_only`; reason mandatory; invalidates any outstanding acceptance token |

**Candidate acceptance is not a workflow node.** It is an external event: the
candidate's acceptance is captured by the recruitment module and raises
`offer.accepted`, which the workflow may consume as an instance variable for a
subsequent onboarding workflow. Acceptance is evidence, not an approval step.

**R-IX-092** The offer document presented for acceptance MUST contain the
complete approved terms — position, CTC, all components, joining bonus,
incentive, joining date and conditions — and the acceptance record MUST store the
hash of the exact document presented. *Rationale — SmartPRS 2's public acceptance
page shows only name, position, company and offer date
(`resources/views/offer-accept.blade.php:38–44`), which is what makes the
"acceptance" evidentially worthless; the NDA endpoint performs no server-side
validation of the typed name or the consent tick and is CSRF-exempt
(`NdaSignController.php:96`; `routes/web.php:63–64`); and the offer route accepts
**any** letter type because there is no `letter_type` filter
(`OfferAcceptController.php:36`).*

**R-IX-093** No public, unauthenticated route may execute DDL, and no signature
or acceptance route may be CSRF-exempt. *Rationale —
`OfferAcceptController::ensureCols():18–30` and
`NdaSignController::ensureCols():19–31` run `ALTER TABLE` on every hit of a
public token page.*

---

### IX.12.10 `CONFIG-PUBLISH` — configuration publication approval

**Subject** `config_version` / `publish`. **Marked** `requires_maker_checker = 1`.
This is spine S4's "approval before publish for `high_risk` artefacts", and it is
the same engine — which is the point.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` `[seg]` | submit | preconditions, all severity `block`: the rendered **diff** is attached; the **impact preview** is attached (affected employee count, affected modules, effective date); the artefact's **stored regression tests pass**; no overlap or gap violation; no unresolved conflict finding |
| `n_type_route` | condition_branch | by `artefact_type` and impact size: `artefact_type IN ('statutory_override','rule_pack_acceptance')` → `n_compliance_review`; `artefact_type = 'workflow'` → `n_workflow_review`; `vars.impacted_employees > policy.config_large_impact_threshold` → `n_senior_review`; default → `n_peer_review` |
| `n_peer_review` `[seg]` | approval | `RIS(CONFIG_REVIEWER, tenant)`; the reviewer is shown the diff, the impact and the test results, and must acknowledge having read the diff |
| `n_senior_review` `[seg]` | approval | `RIS(CONFIG_OWNER, tenant)` in addition to `n_peer_review` (sequential) |
| `n_compliance_review` `[seg]` | approval | `RIS(COMPLIANCE_OWNER, tenant)`; for statutory artefacts, records the `source_reference` sighted (spine S12) |
| `n_workflow_review` `[seg]` | approval | `RIS(CONFIG_OWNER, tenant)`; **requires acknowledgement of the in-flight instance count** on the version being superseded (D-IX-04) |
| `n_schedule` | condition_branch | `version.activation_at IS NOT NULL` → `n_scheduled_notice`; default → `n_finalise` |
| `n_finalise` | finalise | emits `config.version.approved`; the configuration engine publishes, supersedes the prior version and writes the checksum |

**Segregation.** `{n_submit, all review nodes}` `distinct_actor_and_no_common_role`
— **the author of a rule may not approve its publication.**

**R-IX-094** The workflow that governs publication of workflows MUST itself be
publishable only through this workflow, and the platform MUST seed
`CONFIG-PUBLISH` as an immutable **platform-owned** version that a tenant may
extend (add steps) but not weaken or remove. *Rationale — otherwise the first
change a careless administrator makes is to remove the control that would have
stopped them.*

**Against SmartPRS 2.** Configuration is edited in place with no approval, no
diff, no impact preview and no version, which is why editing a policy silently
rewrites last year's payroll.

---

### IX.12.11 `COMMISSION-CLAIM` — the generalised two-stage money gate

Included because it demonstrates that the ~1,100 lines of commission-specific PHP
in `RequestController` reduce to configuration.

| Node | Type | Configuration |
|---|---|---|
| `n_submit` | submit | the claim's field schema comes from the entity schema engine (spine S9), so the five collection-evidence fields are **configuration**, not the `stripos($claim_type,'simp')` substring test at `RequestController.php:465`; proof upload required per configuration |
| `n_accounts` | approval | `RIS(ACCOUNTS_VERIFIER, company)`; confirms the money was received; decision options `approve \| flag \| reject`; `flag` routes to `n_return` |
| `n_gates` | condition_branch | three independent gates, each `block \| warn \| off` from configuration: DRA certification validity, compliance score below `policy.incentive_min_compliance`, open grievance count. A `warn` result is attached to the instance and shown to the approver before they decide (§IX.11.2) |
| `n_approve` | approval | `RL(1)` with amount bands; `any_one` |
| `n_lock` | approval | auto_approve when the disbursed total reaches the claim net; otherwise a permissioned manual lock |
| `n_unlock` | approval | `RIS(FINANCE_CONTROLLER, legal_entity)`; **reason mandatory** — the unlock-with-reason path that does not exist anywhere in SmartPRS 2 (`E-…` B-21) |
| `n_finalise` | finalise | emits `commission.approved` |

**Against SmartPRS 2.** The Accounts gate is hard-coded for one module and cannot
be switched off or reused (`:1359–1364`); the DRA gate is tri-state while
compliance and grievance are hard-coded warn-and-allow (`:1371–1394` vs
`:1416–1454`); a locked entry can never be unlocked because no endpoint exists;
and `bounce()` auto-creates an **approved** clawback with no approval step at all
(`:1786–1802`).

---

## IX.13 Configuration surface

The explicit list of what a tenant administrator changes without code. Tested
against the brief's Section 127 standard: nothing here requires a deploy.

| # | What | Where | Effective-dated? | Approval to change? |
|---|---|---|---|---|
| WC-01 | Create, clone, edit, version, publish, retire a workflow definition | Configuration Studio → Workflows | Yes | Yes — `CONFIG-PUBLISH` |
| WC-02 | Add, remove, reorder nodes; change node type | as above | Yes | Yes |
| WC-03 | Participants per node: role + data scope, named user, reporting level N, group, SPX expression | as above | Yes | Yes |
| WC-04 | Completion rule per node: `any_one`, `all_must`, quorum `n of m`, and the reject threshold | as above | Yes | Yes |
| WC-05 | Amount bands: boundaries, currency, per-band participants and per-band SLA | as above | Yes | Yes |
| WC-06 | Branch conditions in SPX, with the visual condition builder | as above | Yes | Yes |
| WC-07 | SLA minutes per node; the business-hours calendar per node or per definition | as above | Yes | Yes |
| WC-08 | Reminder schedule: number of reminders and their offsets, pre- and post-due | as above | Yes | Yes |
| WC-09 | Escalation policy, target, repeat interval and maximum levels | as above | Yes | Yes |
| WC-10 | Required comment and required attachment per node and per outcome; permitted attachment types | as above | Yes | Yes |
| WC-11 | Decision options per node (`approve`/`reject`/`return`) | as above | Yes | Yes |
| WC-12 | Reassignment rights per node; whether reassignment resets the SLA | as above | Yes | Yes |
| WC-13 | Whether delegation is allowed per node | as above | Yes | Yes |
| WC-14 | Self-approval prohibition and its override mode (within R-IX-037's limits) | as above | Yes | Yes |
| WC-15 | Segregation sets and their rule | as above | Yes | Yes |
| WC-16 | `on_no_participant` policy per node, and the tenant fallback approver spec per entity type | as above / Settings | Yes | Yes |
| WC-17 | Gate severities (`block`/`warn`/`off`) on every precondition | as above | Yes | Yes |
| WC-18 | Cancel and withdraw policy per definition | as above | Yes | Yes |
| WC-19 | Return policy: restart or resume; maximum returns | as above | Yes | Yes |
| WC-20 | Material-field list and `on_material_change` policy | as above | Yes | Yes |
| WC-21 | Bindings: which workflow serves which (entity, operation) for which scope, with priority | Configuration Studio → Bindings | Yes | Yes |
| WC-22 | Business-hours calendars: working days, hours, breaks, timezone, holiday calendar reference | Configuration Studio → Calendars | Yes | Yes |
| WC-23 | Approver groups: membership, org scope, validity dates | Masters → Approver Groups | Yes | No (audited) |
| WC-24 | Delegations: create, edit, revoke; date range, entity-type scope, amount ceiling | Inbox → Delegation, or Admin → Delegations | Yes | No (audited) |
| WC-25 | Out-of-office backups | as above | Yes | No (audited) |
| WC-26 | Policy values referenced by branch conditions (`policy.*`), e.g. thresholds and day counts | Configuration Studio → Policies | Yes | Per artefact risk class |
| WC-27 | Saved inbox views and their default sort | Inbox | n/a | No |
| WC-28 | Bulk-action enablement per entity type, and the bulk size cap | Settings → Approvals | No | Yes |
| WC-29 | Notification events emitted per node and their audiences | Configuration Studio → Notifications | Yes | Per artefact risk class |
| WC-30 | Trace retention class per workflow | as above | No | Yes |

**Section 127 conformance for this part.** *"3 late arrivals over 10 minutes =
half day"* is a rule, not a workflow — but its workflow-side analogue is
*"the third attendance regularisation in a payroll month is approved by HR
instead of the line manager"*, which is WC-06 plus WC-03:

```
branch condition:
  agg.occurrence_index('attendance_regularisation', 'payroll_period') >= 3
participants on the true path:
  role_in_scope(HR_OPS, data_scope = branch, anchor = subject_employee)
```

No code, no deploy, effective-dated, scoped, versioned, simulated before publish.

**What a tenant administrator cannot change**, deliberately:

| Not changeable | Why |
|---|---|
| The node catalogue | Closed set; adding a type is an Ametecs release (R-IX-020) |
| The precedence weights of scope dimensions | Spine S3.2 — a tenant changing them silently changes every historical resolution |
| That a Section 56 operation requires maker-checker | R-IX-073 |
| That a decided step is immutable | R-IX-045 |
| That every transition is audited | R-IX-097 |
| That authorisation is checked independently of participation | R-IX-008 |

---

## IX.14 Data model

Specified to the Section 123 standard, in Part XXVIII's conventions:
`snake_case`; `BIGINT UNSIGNED` primary keys; `BINARY(16)` UUIDv7 public ids;
`DECIMAL(18,4)` money at rest with integer paise in memory; **no database
`ENUM`** — every status column is `VARCHAR(40)` with a foreign key to
`status_values`; `tenant_id` first in every unique index; `ON DELETE RESTRICT`
by default; `row_version` on every mutable row.

Notation: `PK` · `FK→` · `U` unique participation · `IX` indexed · `NN` not null.

Part XXVIII already registers `cfg_workflows`, `cfg_workflow_nodes`,
`cfg_workflow_transitions`, `workflow_instances`, `workflow_tasks`,
`workflow_transitions` and `workflow_delegations`. This part specifies them in
full and adds five tables that the engine requires and Part XXVIII lists by
reference only: `cfg_workflow_node_participants`, `cfg_workflow_bindings`,
`cfg_sla_calendars`, `workflow_node_activations`, `workflow_timers`,
`workflow_groups` and `workflow_outbox`. **No column specified here contradicts
Part XXVIII; where Part XXVIII names a column, that name is used.**

---

### IX.14.1 `cfg_workflows` — the typed payload of a workflow version

Purpose: the definition-level configuration of one published (or draft) workflow
version. One row per `config_versions` row.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK→ `tenants(id)` RESTRICT |
| `config_version_id` | BIGINT UNSIGNED | NN | — | **U(`config_version_id`)** · FK→ `config_versions(id)` RESTRICT |
| `subject_entity_key` | VARCHAR(60) | NN | — | FK→ `entity_definitions(entity_key)` RESTRICT |
| `subject_amount_path` | VARCHAR(120) | Y | NULL | SPX path typed `Money`; required when any node declares amount bands |
| `start_node_key` | VARCHAR(60) | NN | — | must exist in `cfg_workflow_nodes` (W01) |
| `allows_self_approval` | TINYINT(1) | NN | 0 | |
| `self_approval_override_mode` | VARCHAR(30) | NN | `none` | `status_values`: `none\|permission\|named_role\|workflow` |
| `self_approval_permission` | VARCHAR(80) | Y | NULL | when mode = `permission` |
| `is_maker_checker` | TINYINT(1) | NN | 0 | **derived at publish** from the segregation sets (R-IX-069); stored so the runtime need not recompute |
| `return_policy_code` | VARCHAR(30) | NN | `restart` | `restart\|resume_at_return_node` |
| `cancel_policy_code` | VARCHAR(40) | NN | `requester_until_first_decision` | |
| `comment_policy_code` | VARCHAR(30) | NN | `per_node` | |
| `on_material_change_code` | VARCHAR(40) | NN | `invalidate_decided_steps` | |
| `material_fields_json` | JSON | NN | `[]` | subject field paths |
| `instance_key_expr` | TEXT | Y | NULL | SPX Text → the idempotency key |
| `sla_calendar_id` | BIGINT UNSIGNED | Y | NULL | FK→ `cfg_sla_calendars(id)` RESTRICT |
| `timezone_source_code` | VARCHAR(30) | NN | `tenant` | `tenant\|org_unit\|employee\|fixed` |
| `timezone_fixed` | VARCHAR(64) | Y | NULL | IANA zone when source = `fixed` |
| `segregation_sets_json` | JSON | NN | `[]` | `[{set_key, node_keys[], rule_code}]` |
| `trace_retention_class_code` | VARCHAR(40) | NN | — | FK→ `cfg_retention_classes` RESTRICT |
| `row_version` | INT UNSIGNED | NN | 1 | |

Indexes: `IX(tenant_id, subject_entity_key)`; `IX(tenant_id, is_maker_checker)`.
Tenant scope: tenant · History: in `config_versions` · Soft delete: none · Audit:
`full` with old→new payload diff · PII: none.

---

### IX.14.2 `cfg_workflow_nodes`

Purpose: one node of one workflow version.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `cfg_workflow_id` | BIGINT UNSIGNED | NN | — | FK→ `cfg_workflows(id)` CASCADE (payload rows die with their version's payload) |
| `node_key` | VARCHAR(60) | NN | — | **U(`tenant_id`,`cfg_workflow_id`,`node_key`)** |
| `node_type_code` | VARCHAR(40) | NN | — | FK→ `status_values` domain `workflow_node_type`; the closed set of thirteen |
| `label` | VARCHAR(160) | NN | — | non-empty (R-IX-012) |
| `display_order` | INT UNSIGNED | NN | 0 | |
| `params_json` | JSON | NN | `{}` | schema-validated per node type (R-IX-011) |
| `guard_expr` | TEXT | Y | NULL | SPX Boolean |
| `guard_ast` | JSON | Y | NULL | compiled AST stored at publish (spine S5) |
| `entry_actions_json` | JSON | NN | `[]` | closed catalogue (§IX.3.5) |
| `exit_actions_json` | JSON | NN | `[]` | |
| `requires_comment_code` | VARCHAR(20) | NN | `never` | `never\|on_reject\|always` |
| `requires_attachment_code` | VARCHAR(20) | NN | `never` | `never\|on_approve\|on_reject\|always` |
| `attachment_types_json` | JSON | NN | `[]` | document-type codes |
| `required_permission` | VARCHAR(80) | Y | NULL | `verb.resource` (spine S13) |
| `completion_rule_code` | VARCHAR(20) | Y | NULL | `any_one\|all_must` |
| `quorum_n` | SMALLINT UNSIGNED | Y | NULL | quorum nodes |
| `quorum_m_source_code` | VARCHAR(30) | Y | NULL | `resolved_participants\|fixed` |
| `quorum_m_fixed` | SMALLINT UNSIGNED | Y | NULL | |
| `quorum_reject_threshold` | SMALLINT UNSIGNED | Y | NULL | default `m − n + 1` |
| `quorum_tie_policy_code` | VARCHAR(20) | Y | NULL | `reject\|escalate\|park` |
| `amount_bands_json` | JSON | Y | NULL | contiguous, exhaustive (W08); amounts in minor units |
| `sla_minutes` | INT UNSIGNED | Y | NULL | NULL = no SLA |
| `sla_calendar_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL inherits the definition's |
| `reminder_offsets_json` | JSON | NN | `[]` | minutes relative to `due_at`; negative = pre-due |
| `escalation_policy_code` | VARCHAR(30) | NN | `none` | `none\|notify_only\|reassign\|route_to_node\|auto_approve\|auto_reject` |
| `escalation_target_json` | JSON | Y | NULL | a participant spec, or `{node_key}` |
| `escalate_levels_max` | TINYINT UNSIGNED | NN | 1 | |
| `escalate_interval_minutes` | INT UNSIGNED | Y | NULL | |
| `stale_timer_policy_code` | VARCHAR(30) | Y | NULL | mandatory when the policy auto-decides (W16) |
| `stale_threshold_minutes` | INT UNSIGNED | Y | NULL | |
| `reassignment_rights_code` | VARCHAR(30) | NN | `none` | |
| `reassignment_resets_sla` | TINYINT(1) | NN | 0 | |
| `delegation_allowed` | TINYINT(1) | NN | 1 | |
| `on_no_participant_code` | VARCHAR(40) | NN | `fail_instance` | (§IX.5.4) |
| `on_no_participant_target_json` | JSON | Y | NULL | |
| `visible_fields_json` | JSON | Y | NULL | subset of what S13 permits |
| `is_terminal` | TINYINT(1) | NN | 0 | derived: reject/cancel/finalise |
| `row_version` | INT UNSIGNED | NN | 1 | |

Indexes: `IX(tenant_id, cfg_workflow_id, display_order)`;
`IX(tenant_id, node_type_code)`.
Tenant scope: tenant · History: in `config_versions` · Soft delete: none · Audit:
via the version diff · PII: none.

---

### IX.14.3 `cfg_workflow_node_participants`

Purpose: the ordered participant specification of a node. Several rows per node.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `cfg_workflow_node_id` | BIGINT UNSIGNED | NN | — | FK CASCADE |
| `seq` | SMALLINT UNSIGNED | NN | — | **U(`tenant_id`,`cfg_workflow_node_id`,`seq`)** |
| `band_index` | SMALLINT UNSIGNED | Y | NULL | non-NULL binds this spec to one amount band |
| `participant_kind_code` | VARCHAR(40) | NN | — | the eight kinds (§IX.3.4) |
| `role_id` | BIGINT UNSIGNED | Y | NULL | FK→ `roles(id)` RESTRICT |
| `data_scope_code` | VARCHAR(40) | Y | NULL | spine S13 scope vocabulary |
| `scope_anchor_code` | VARCHAR(30) | NN | `subject_employee` | `subject\|requester\|subject_employee` |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK→ `users(id)` RESTRICT |
| `level_n` | TINYINT UNSIGNED | Y | NULL | reporting_line |
| `level_stop_expr` | TEXT | Y | NULL | SPX Boolean |
| `on_short_line_code` | VARCHAR(30) | Y | NULL | `use_topmost\|fail\|fallback_participant` |
| `reporting_line_code` | VARCHAR(40) | NN | `primary` | primary / functional / dotted / project |
| `group_id` | BIGINT UNSIGNED | Y | NULL | FK→ `workflow_groups(id)` RESTRICT |
| `expression` | TEXT | Y | NULL | SPX → `List<Code>` |
| `expression_ast` | JSON | Y | NULL | compiled at publish |
| `is_optional` | TINYINT(1) | NN | 0 | |
| `exclude_requester` | TINYINT(1) | NN | 1 | |
| `exclude_prior_actors` | TINYINT(1) | NN | 1 | |

Constraint: a `CHECK`-equivalent validated at publish asserts exactly the columns
required by `participant_kind_code` are populated and the rest are NULL.
Indexes: `IX(tenant_id, cfg_workflow_node_id, band_index, seq)`;
`IX(tenant_id, role_id)`; `IX(tenant_id, user_id)` — the latter two serve the
"which workflows name this role/user?" impact query at publish and at role
deletion.
Tenant scope: tenant · Audit: via the version diff · PII: none.

---

### IX.14.4 `cfg_workflow_transitions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `cfg_workflow_id` | BIGINT UNSIGNED | NN | — | FK CASCADE |
| `from_node_key` | VARCHAR(60) | NN | — | |
| `outcome_code` | VARCHAR(40) | NN | — | from the node type's declared outcome set |
| `to_node_key` | VARCHAR(60) | NN | — | |
| `seq` | SMALLINT UNSIGNED | NN | 0 | evaluation order among transitions sharing (from, outcome) |
| `condition_expr` | TEXT | Y | NULL | SPX Boolean; NULL = unconditional |
| `condition_ast` | JSON | Y | NULL | |
| `label` | VARCHAR(160) | NN | — | rendered in the trace |

Unique: `(tenant_id, cfg_workflow_id, from_node_key, outcome_code, seq)`.
Index: `IX(tenant_id, cfg_workflow_id, from_node_key)`.
Tenant scope: tenant · Audit: via the version diff · PII: none.

---

### IX.14.5 `cfg_workflow_bindings`

Purpose: mechanism B3 — which workflow serves which (entity, operation) for which
scope. Itself a scoped, effective-dated artefact (its own `config_versions` row),
so a binding change is versioned like any other configuration.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `config_version_id` | BIGINT UNSIGNED | NN | — | U · FK RESTRICT |
| `subject_entity_key` | VARCHAR(60) | NN | — | FK RESTRICT |
| `operation_code` | VARCHAR(60) | NN | — | FK→ `entity_operations(operation_code)` RESTRICT |
| `workflow_artefact_id` | BIGINT UNSIGNED | Y | NULL | FK→ `config_artefacts(id)` RESTRICT; NULL means **explicitly no approval required** for this scope — never an accidental absence (R-IX-015) |
| `no_approval_reason` | VARCHAR(500) | Y | NULL | mandatory when `workflow_artefact_id` IS NULL |
| `specificity` | INT UNSIGNED | NN | 0 | computed at publish from the version's scope |
| `priority` | INT | NN | 0 | tie-break (spine S3.2) |

Resolution index:
`IX(tenant_id, subject_entity_key, operation_code, specificity DESC, priority DESC)`.
Tenant scope: tenant · History: in `config_versions` · Audit: `full` · PII: none.

---

### IX.14.6 `workflow_groups` and `workflow_group_members`

Purpose: named approver pools (panels, committees, duty rosters) that are not
roles.

`workflow_groups`: `id`, `uuid` U, `tenant_id`, `group_key`
**U(`tenant_id`,`group_key`)**, `name`, `description`, `org_scope_json`,
`is_active`, `row_version`, standard actor/timestamp columns.

`workflow_group_members`: `id`, `tenant_id`, `group_id` FK RESTRICT,
`user_id` FK RESTRICT, `effective_from` DATE NN, `effective_to` DATE NULL,
`row_version`. Unique `(tenant_id, group_id, user_id, effective_from)`;
`IX(tenant_id, group_id, effective_from, effective_to)`.

Tenant scope: tenant · History: membership is effective-dated (Law 3) · Soft
delete: none — membership ends by dating · Audit: `full` · PII: none.

---

### IX.14.7 `cfg_sla_calendars` and `cfg_sla_calendar_days`

`cfg_sla_calendars`: `id`, `uuid` U, `tenant_id`, `calendar_key`
**U(`tenant_id`,`calendar_key`)**, `name`, `timezone` VARCHAR(64) NN,
`clock_mode_code` VARCHAR(30) NN default `business_hours`,
`holiday_calendar_id` BIGINT UNSIGNED NULL FK RESTRICT (the attendance module's
holiday calendar — there is not a second holiday list, R-IX-058),
`half_day_rule_code`, `scope_json`, `specificity`, `priority`, `config_version_id`
U FK RESTRICT, `row_version`.

`cfg_sla_calendar_days`: `id`, `tenant_id`, `calendar_id` FK CASCADE,
`weekday` TINYINT NN (0–6), `is_working` TINYINT(1) NN,
`start_time` TIME NULL, `end_time` TIME NULL, `breaks_json` JSON NN default `[]`.
Unique `(tenant_id, calendar_id, weekday)`.

Tenant scope: tenant · History: in `config_versions` · Audit: `full` · PII: none.

---

### IX.14.8 `workflow_instances`

Purpose: one running or concluded approval of one subject.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** · UUIDv7; the only id on any external surface |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `config_version_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — **the version it started on; it completes on it** (R-IX-051) |
| `workflow_artefact_key` | VARCHAR(80) | NN | — | denormalised for query locality and for the idempotency key |
| `subject_entity_key` | VARCHAR(60) | NN | — | |
| `subject_id` | BIGINT UNSIGNED | NN | — | polymorphic by design; the pair is validated by the owning context |
| `subject_uuid` | BINARY(16) | NN | — | addressable without the internal id |
| `subject_employee_id` | BIGINT UNSIGNED | Y | NULL | FK→ `employees(id)` RESTRICT — the employee the subject concerns; drives participant anchoring and the self-approval prohibition |
| `operation_code` | VARCHAR(60) | NN | — | which gated operation this instance authorises |
| `initiated_by_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — **a user id, never a name** |
| `initiated_on_behalf_of_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — HR raising on an employee's behalf |
| `instance_key` | VARCHAR(190) | Y | NULL | from `instance_key_expr`; **U(`tenant_id`,`workflow_artefact_key`,`instance_key`,`open_marker`)** |
| `open_marker` | TINYINT UNSIGNED | NN | 1 | `1` while non-terminal, set to `0` on termination, so the unique index constrains only live instances |
| `current_node_key` | VARCHAR(60) | Y | NULL | NULL for parallel fan-out; activations carry the truth |
| `lifecycle_state_code` | VARCHAR(40) | NN | `running` | FK→ `status_values` domain `workflow_instance_state` |
| `blocked_reason_code` | VARCHAR(40) | Y | NULL | `no_participant\|subject_drift\|stale_timer\|evaluation_error\|…` |
| `amount_minor` | BIGINT | Y | NULL | the routing amount in minor units, captured at submit |
| `currency_code` | CHAR(3) | Y | NULL | |
| `entity_snapshot_hash` | BINARY(32) | NN | — | what was submitted, hashed |
| `entity_snapshot_json` | JSON | Y | NULL | the material fields only; PII-classified per the subject entity |
| `routing_context_json` | JSON | NN | `{}` | instance variables written by `SET_INSTANCE_VARIABLE` |
| `origin_binding_code` | VARCHAR(20) | NN | — | `rule\|status_transition\|entity_binding` |
| `origin_rule_version_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT (R-IX-081) |
| `supersedes_instance_id` | BIGINT UNSIGNED | Y | NULL | FK→ self RESTRICT — corrections and cancel-and-restart |
| `due_at` | DATETIME(3) | Y | NULL | instance-level SLA where declared |
| `started_at` | DATETIME(3) | NN | — | |
| `completed_at` | DATETIME(3) | Y | NULL | |
| `correlation_id` | BINARY(16) | Y | NULL | joins the trace to the request that started it |
| `row_version` | INT UNSIGNED | NN | 1 | |

Indexes: `IX(tenant_id, subject_entity_key, subject_id)` — "is there an approval
on this record?"; `IX(tenant_id, lifecycle_state_code, due_at)` — the SLA sweep;
`IX(tenant_id, workflow_artefact_key, lifecycle_state_code)` — the in-flight count
at publish (D-IX-04); `IX(tenant_id, initiated_by_user_id, started_at)`;
`IX(tenant_id, config_version_id)`.

Tenant scope: tenant · History: `workflow_transitions` **is** the history ·
Soft delete: **never deleted**, no `deleted_at` · Audit: `full` · PII: `none` at
the row level; `entity_snapshot_json` inherits the subject's classification and is
masked and retained accordingly · Retention: `R-PERM` unless the workflow's
`trace_retention_class_code` permits archival.

---

### IX.14.9 `workflow_node_activations`

Purpose: the record of a node being entered, its resolved participants and its
outcome. This is the table that makes quorum tallies, parallel joins and skipped
nodes explicit rather than inferred from tasks.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | U(`tenant_id`,`uuid`) |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `node_key` | VARCHAR(60) | NN | — | |
| `activation_seq` | SMALLINT UNSIGNED | NN | — | **U(`tenant_id`,`workflow_instance_id`,`node_key`,`activation_seq`)** — a node re-entered after a return gets seq 2 |
| `parent_activation_id` | BIGINT UNSIGNED | Y | NULL | FK→ self RESTRICT — the fan-out parent for parallel branches |
| `branch_label` | VARCHAR(160) | Y | NULL | |
| `status_code` | VARCHAR(40) | NN | `active` | `active\|approved\|rejected\|skipped\|withdrawn\|escalated\|parked` |
| `selected_band_index` | SMALLINT UNSIGNED | Y | NULL | which amount band selected the participants |
| `resolution_json` | JSON | NN | — | the resolved user ids, the spec that produced each, and every substitution applied (R-IX-028) |
| `resolved_count` | SMALLINT UNSIGNED | NN | 0 | |
| `approvals_count`, `rejections_count` | SMALLINT UNSIGNED | NN | 0 | quorum tallies, maintained under the instance lock |
| `guard_result_json` | JSON | Y | NULL | the guard source and substituted values when skipped |
| `due_at` | DATETIME(3) | Y | NULL | |
| `activated_at` | DATETIME(3) | NN | — | |
| `concluded_at` | DATETIME(3) | Y | NULL | |
| `row_version` | INT UNSIGNED | NN | 1 | |

Indexes: `IX(tenant_id, workflow_instance_id, activated_at)`;
`IX(tenant_id, status_code, due_at)`.
Tenant scope: tenant · Soft delete: never · Audit: `full` · PII: `none`
(`resolution_json` holds user ids, not names).

---

### IX.14.10 `workflow_tasks`

Purpose: one addressable unit of work for one assignee. The inbox is a query over
this table and nothing else.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | U(`tenant_id`,`uuid`) — the id the API uses |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `node_activation_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `node_key` | VARCHAR(60) | NN | — | denormalised for the inbox |
| `assignee_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL only for a pool task held against a role |
| `assignee_role_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — pool assignment |
| `delegated_from_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `substitution_kind_code` | VARCHAR(20) | Y | NULL | `delegation\|backup\|reassignment\|escalation` |
| `substituted_from_task_id` | BIGINT UNSIGNED | Y | NULL | FK→ self RESTRICT — the chain a reassignment forms |
| `origin_participant_seq` | SMALLINT UNSIGNED | Y | NULL | which participant spec produced this task — renders "why me" (R-IX-084) |
| `status_code` | VARCHAR(40) | NN | `pending` | FK→ `status_values` domain `workflow_task_state` |
| `decided_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — **a user id, never a name string** |
| `decided_at` | DATETIME(3) | Y | NULL | |
| `decision_comment` | VARCHAR(2000) | Y | NULL | written once, with the decision, never updated |
| `decision_attachment_document_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `acknowledgement_json` | JSON | Y | NULL | declarations ticked, with their text version |
| `due_at` | DATETIME(3) | Y | NULL | |
| `first_viewed_at` | DATETIME(3) | Y | NULL | supports "seen but not acted on" reporting |
| `reminded_at` | DATETIME(3) | Y | NULL | last reminder |
| `reminder_count` | TINYINT UNSIGNED | NN | 0 | |
| `escalated_at` | DATETIME(3) | Y | NULL | |
| `sla_breached_at` | DATETIME(3) | Y | NULL | set by the sweep; the inbox computes overdue independently (R-IX-068) |
| `decision_ip` | VARBINARY(16) | Y | NULL | |
| `decision_user_agent_hash` | BINARY(32) | Y | NULL | |
| `created_at` | DATETIME(3) | NN | — | |
| `row_version` | INT UNSIGNED | NN | 1 | the optimistic-lock column of the conditional update (§IX.6.3) |

Unique: `(tenant_id, uuid)`; `(tenant_id, node_activation_id, assignee_user_id)`
— one task per person per activation.
Indexes: **`IX(tenant_id, assignee_user_id, status_code, due_at)`** — the inbox
and its badge count, covering; `IX(tenant_id, status_code, due_at)` — the SLA
sweep; `IX(tenant_id, workflow_instance_id)`;
`IX(tenant_id, assignee_role_id, status_code)` — pool tasks;
`IX(tenant_id, delegated_from_user_id, status_code)` — the delegation view.

Tenant scope: tenant · History: `workflow_transitions` · Soft delete: **never** ·
Audit: `full` · PII: `identifying` (`decision_ip`), access-audited ·
Retention: `R-PERM`.

*Why the inbox index is specified here:* SmartPRS 2 has no index on `status` on
any of its approval tables (`J-database.md` §Q5), and its inbox reads nine tables
unbounded.

---

### IX.14.11 `workflow_transitions` — the instance ledger

Purpose: the append-only record of everything that happened to an instance,
including attempts that changed nothing (R-IX-050).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `seq` | INT UNSIGNED | NN | — | **U(`tenant_id`,`workflow_instance_id`,`seq`)** — allocated under the instance row lock |
| `transition_kind_code` | VARCHAR(40) | NN | — | `started\|node_activated\|node_skipped\|task_created\|decision\|attempted_decision\|reassigned\|delegated\|reminder_sent\|sla_breached\|escalated\|returned\|resubmitted\|auto_decided\|cancelled\|blocked\|resumed\|completed\|rejected\|withdrawn\|superseded` |
| `node_key` | VARCHAR(60) | Y | NULL | |
| `node_activation_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `workflow_task_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `from_state_code`, `to_state_code` | VARCHAR(40) | Y | NULL | |
| `outcome_code` | VARCHAR(40) | Y | NULL | |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL only for system actions |
| `actor_system_code` | VARCHAR(40) | Y | NULL | `workflow_engine\|scheduler\|migration` |
| `on_behalf_of_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — delegation |
| `comment` | VARCHAR(2000) | Y | NULL | **append-only: a new transition row, never an update** (R-IX-046) |
| `reason_code` | VARCHAR(40) | Y | NULL | |
| `evaluation_json` | JSON | Y | NULL | branch/guard source, substituted values, resolution detail |
| `entity_snapshot_hash` | BINARY(32) | Y | NULL | the subject as it stood at this transition |
| `ip_address` | VARBINARY(16) | Y | NULL | |
| `audit_event_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the ledger row this transition wrote (spine S14) |
| `occurred_at` | DATETIME(3) | NN | — | |
| `recorded_at` | DATETIME(3) | NN | — | |

Indexes: `IX(tenant_id, workflow_instance_id, occurred_at)`;
`IX(tenant_id, transition_kind_code, occurred_at)`;
`IX(tenant_id, actor_user_id, occurred_at)`.
Tenant scope: tenant · **Append-only, enforced by privilege**: the application
database user has `INSERT` and `SELECT` and no `UPDATE`/`DELETE` · Soft delete:
none · Audit: is part of the audit · PII: `identifying` (IP) · Retention:
`R-PERM`.

---

### IX.14.12 `workflow_delegations`

Purpose: delegation and out-of-office backup, in one table distinguished by kind.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | U(`tenant_id`,`uuid`) |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `delegation_kind_code` | VARCHAR(20) | NN | `delegation` | `delegation\|backup` |
| `from_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — the principal |
| `to_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — the delegate or backup |
| `effective_from` | DATETIME(3) | NN | — | |
| `effective_to` | DATETIME(3) | Y | NULL | NULL = open-ended, permitted only for `backup` |
| `subject_entity_keys_json` | JSON | Y | NULL | NULL = all entity types |
| `workflow_artefact_ids_json` | JSON | Y | NULL | NULL = all workflows |
| `amount_ceiling_minor` | BIGINT | Y | NULL | above which the task stays with the principal |
| `apply_to_open_tasks` | TINYINT(1) | NN | 0 | |
| `reason` | VARCHAR(500) | Y | NULL | |
| `created_by_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT — may differ from `from_user_id` when HR sets it |
| `revoked_at` | DATETIME(3) | Y | NULL | |
| `revoked_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `revocation_reason` | VARCHAR(500) | Y | NULL | |
| `row_version` | INT UNSIGNED | NN | 1 | |

Constraints: `CHECK (from_user_id <> to_user_id)`; a publish/save-time validator
refuses a delegation whose `to_user_id` already holds an active delegation from a
third party for an overlapping scope and period (**no chaining**, R-IX-034); and
refuses one that would place the delegate on both sides of a segregation set
(D-IX-03).
Indexes: `IX(tenant_id, from_user_id, effective_from, effective_to)`;
`IX(tenant_id, to_user_id, effective_from, effective_to)`;
`IX(tenant_id, delegation_kind_code, effective_to)`.
Tenant scope: tenant · History: rows are never edited in place; a change is a
revocation plus a new row · Soft delete: none · Audit: `full` · PII: none.

---

### IX.14.13 `workflow_timers`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `workflow_task_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT; NULL = instance-level timer |
| `node_activation_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `timer_kind_code` | VARCHAR(20) | NN | — | `due\|reminder\|escalation\|expiry\|stale_check` |
| `sequence_no` | SMALLINT UNSIGNED | NN | 0 | the nth reminder |
| `fire_at` | DATETIME(3) | NN | — | UTC, computed from the calendar at activation |
| `status_code` | VARCHAR(20) | NN | `scheduled` | `scheduled\|fired\|cancelled\|superseded` |
| `fired_at` | DATETIME(3) | Y | NULL | |
| `lateness_minutes` | INT UNSIGNED | Y | NULL | `fired_at − fire_at`; drives the stale-timer policy (R-IX-066) |
| `row_version` | INT UNSIGNED | NN | 1 | |

Unique: `(tenant_id, workflow_task_id, timer_kind_code, sequence_no)` — the
idempotency guarantee for reminders (R-IX-060); a partial equivalent keyed on
`workflow_instance_id` for instance-level timers.
Index: **`IX(status_code, fire_at)`** — deliberately *not* tenant-first, because
the sweep is cross-tenant and ordered by time; tenant isolation on this table is
enforced in the row handler, and the exception is documented here because Law 6
otherwise requires `tenant_id` first.
Tenant scope: tenant · Soft delete: none · Audit: `minimal` (fire events reach
the ledger through the transitions they cause) · PII: none · Retention: purged
with the instance's retention class.

**R-IX-095** The `workflow_timers` sweep index is the single declared exception to
the tenant-first index convention (spine S16). It MUST be accompanied by a
conformance test asserting that every read path other than the sweep filters by
`tenant_id`, and that the sweep's row handler re-establishes the tenant context
before acting.

---

### IX.14.14 `workflow_outbox`

Purpose: exactly-once emission of domain events (R-IX-026).

`id`, `tenant_id` FK RESTRICT, `workflow_instance_id` FK RESTRICT,
`event_key` VARCHAR(80) NN, `payload_json` JSON NN,
`status_code` (`pending|dispatched|failed|dead_lettered`),
`attempts` TINYINT UNSIGNED NN default 0, `last_error` VARCHAR(1000) NULL,
`available_at` DATETIME(3) NN, `dispatched_at` DATETIME(3) NULL,
`created_at` DATETIME(3) NN.

Unique: **`(tenant_id, workflow_instance_id, event_key)`** — the constraint that
makes a second transfer order structurally impossible.
Index: `IX(status_code, available_at)`.
Policy: written in the same transaction as the transition that produced it;
dispatched by a worker; failures retry with backoff and dead-letter with an alert.
Nothing is ever silently dropped.

---

### IX.14.15 Entity-operation registry

`entity_operations`: platform-seeded, tenant-extensible only by Ametecs release.
`operation_code` **U(`subject_entity_key`,`operation_code`)**,
`subject_entity_key` FK RESTRICT, `label`, `requires_maker_checker` TINYINT(1) NN
default 0, `default_workflow_artefact_key` VARCHAR(80) NULL,
`required_permission` VARCHAR(80) NN, `amount_path` VARCHAR(120) NULL,
`is_active` TINYINT(1) NN.

**R-IX-096** `requires_maker_checker` MUST be `1` for the eleven Section 56
operations (§IX.8.2), MUST be settable only by an Ametecs migration, and MUST NOT
be editable by a tenant (R-IX-073).

---

## IX.15 API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated on every collection,
idempotency keys on every unsafe method, a consistent error envelope with
machine-readable codes (spine S17). Every route carries an explicit policy
binding; the CI sweep asserts it (Law 5). UUIDs only — no sequential id appears
in any path.

### IX.15.1 Definitions and configuration

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/workflows` | `workflow.definition.view` | List definitions with their published version, in-flight count and last change |
| `GET` | `/workflows/{uuid}` | `workflow.definition.view` | |
| `GET` | `/workflows/{uuid}/versions` | `workflow.definition.view` | |
| `GET` | `/workflows/{uuid}/versions/{versionUuid}` | `workflow.definition.view` | Full graph, nodes, participants, transitions |
| `POST` | `/workflows` | `workflow.definition.author` | Creates the artefact and a draft version |
| `POST` | `/workflows/{uuid}/versions` | `workflow.definition.author` | New draft, optionally cloned from a version |
| `PATCH` | `/workflows/{uuid}/versions/{versionUuid}` | `workflow.definition.author` | Draft only; PATCH semantics — only supplied fields change |
| `POST` | `/workflows/{uuid}/versions/{versionUuid}/validate` | `workflow.definition.author` | Runs W01–W18, returns findings; never mutates |
| `POST` | `/workflows/{uuid}/versions/{versionUuid}/simulate` | `workflow.definition.author` | §IX.3.7; body names a subject and a date |
| `POST` | `/workflows/{uuid}/versions/{versionUuid}/submit` | `workflow.definition.submit` | To `pending_approval` **via `CONFIG-PUBLISH`** |
| `POST` | `/workflows/{uuid}/versions/{versionUuid}/publish` | `workflow.definition.publish` | Refused unless the governing instance completed |
| `POST` | `/workflows/{uuid}/retire` | `workflow.definition.publish` | Blocked while instances are in flight |
| `GET` | `/workflow-bindings` | `workflow.binding.view` | Filter by entity, operation, scope |
| `POST` | `/workflow-bindings` | `workflow.binding.author` | Versioned like any artefact |
| `GET` | `/workflow-bindings/resolve` | `workflow.binding.view` | Debug: given entity, operation, employee, date → the winning binding, the candidates and the specificity arithmetic |
| `GET`/`POST`/`PATCH` | `/workflow-groups`, `/workflow-groups/{uuid}/members` | `workflow.group.view` / `.manage` | |
| `GET`/`POST`/`PATCH` | `/sla-calendars` | `workflow.calendar.view` / `.manage` | |

**R-IX-097** There MUST be no `DELETE` on any workflow definition, version,
binding, instance, task or transition. Retirement, revocation and dating are the
only removals. *Rationale — spine S16, and SmartPRS 2's orphan purge deletes rows
from three tables with no record of any kind.*

### IX.15.2 Instances

| Method | Path | Permission | Notes |
|---|---|---|---|
| `POST` | `/workflow-instances` | the **gated operation's** permission, not a workflow permission | Started by the domain service, not by the client, in the ordinary case; direct use is limited to administrative re-drives |
| `GET` | `/workflow-instances/{uuid}` | `workflow.instance.view` + data scope on the subject | Full graph with resolved participants and outcomes |
| `GET` | `/workflow-instances/{uuid}/trace` | as above | §IX.17 |
| `GET` | `/workflow-instances` | `workflow.instance.view` | Filters: entity, operation, state, workflow, employee, org unit, date range, overdue |
| `POST` | `/workflow-instances/{uuid}/cancel` | per `cancel_policy` | Reason mandatory |
| `POST` | `/workflow-instances/{uuid}/resume` | `workflow.instance.resume` | For `blocked`/`failed`; reason mandatory; re-resolves participants |
| `POST` | `/workflow-instances/{uuid}/re-resolve` | `workflow.instance.resume` | Re-runs participant resolution on the active nodes (used after fixing a reporting line) |
| `GET` | `/subjects/{entityKey}/{subjectUuid}/workflow-instances` | the subject's view permission | "Is there an approval on this record, and where is it?" |

### IX.15.3 The inbox and decisions

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/approvals/inbox` | `workflow.task.act` | Cursor-paginated; the filters of §IX.11.3 |
| `GET` | `/approvals/inbox/count` | `workflow.task.act` | Index-only badge count |
| `GET` | `/approvals/tasks/{uuid}` | `workflow.task.act` + participation | Full decision context, prior decisions, warnings |
| `POST` | `/approvals/tasks/{uuid}/decide` | `workflow.task.act` + participation + the node's `required_permission` | Body: `outcome`, `comment`, `attachment_uuid`, `acknowledgements`. Idempotency key required. `409 workflow.task_already_decided` on a decided task, returning the existing decision |
| `POST` | `/approvals/tasks/bulk-decide` | as above, per item | Per-item outcomes; §IX.11.4's rules enforced server-side |
| `POST` | `/approvals/tasks/{uuid}/reassign` | per `reassignment_rights` | Reason mandatory |
| `POST` | `/approvals/tasks/{uuid}/claim` | `workflow.task.act` | Claims a pool task held against a role |
| `POST` | `/approvals/tasks/{uuid}/release` | `workflow.task.act` | Returns a claimed pool task to the pool |
| `GET` | `/approvals/oversight` | `workflow.oversight.view` + data scope | Team queue, ageing, breaches, orphaned tasks, blocked instances |
| `GET`/`POST`/`DELETE` | `/approvals/delegations` | `workflow.delegation.manage.self` or `.others` | `DELETE` is a revocation, not a row deletion |

**R-IX-098** `POST /approvals/tasks/{uuid}/decide` MUST evaluate, in this order and
all server-side: authentication → the actor holds `workflow.task.act` → the actor
holds the node's `required_permission` → the actor is a resolved participant (or
their delegate) → the self-approval prohibition → segregation → the task is
`pending` → the node's comment/attachment/acknowledgement requirements. Any
failure denies, is audited, and returns a typed code. **No step is skippable and
none may be enforced in the UI alone.**

### IX.15.4 Reporting

| Method | Path | Permission |
|---|---|---|
| `GET` | `/approvals/reports/sla` | `workflow.report.view` |
| `GET` | `/approvals/reports/ageing` | `workflow.report.view` |
| `GET` | `/approvals/reports/self-approval-overrides` | `workflow.report.view` |
| `GET` | `/approvals/reports/segregation-findings` | `workflow.report.view` |
| `GET` | `/approvals/reports/orphaned-tasks` | `workflow.report.view` |
| `GET` | `/approvals/reports/decision-throughput` | `workflow.report.view` |

---

## IX.16 Events

### IX.16.1 Emitted

| Event | When | Key payload |
|---|---|---|
| `workflow.instance.started` | after `submit` | instance uuid, workflow key + version, subject, initiator, amount, origin binding |
| `workflow.node.activated` | node entry | node key, activation uuid, resolved participants, due_at |
| `workflow.node.skipped` | guard false | node key, guard source, substituted values |
| `workflow.task.assigned` | task creation | task uuid, assignee, why-me spec, due_at, substitution kind |
| `workflow.task.decided` | a decision | task uuid, actor, on-behalf-of, outcome, comment presence, snapshot hash |
| `workflow.task.attempted` | a refused decision | actor, intended outcome, refusal code |
| `workflow.task.reassigned` | reassignment | from, to, actor, reason |
| `workflow.task.delegated` | delegation substitution applied | principal, delegate, delegation uuid |
| `workflow.sla.due_soon` | pre-due reminder | task, remaining business minutes |
| `workflow.sla.breached` | breach | task, due_at, breach minutes (R-IX-065) |
| `workflow.escalated` | escalation acted | from assignee, to target, level, policy |
| `workflow.instance.returned` | `return_for_edit` | node, editable fields, comment |
| `workflow.instance.resubmitted` | resubmission | invalidated approvals, if any |
| `workflow.instance.blocked` | park | reason code, node |
| `workflow.instance.failed` | fail | error class, expression, node |
| `workflow.instance.completed` | `finalise` | **the completion event key the definition declares**, plus the instance uuid; exactly once per instance (R-IX-026) |
| `workflow.instance.rejected` | `reject` | reason code, comment, deciding actor |
| `workflow.instance.cancelled` | `cancel` | reason, actor, compensating event key |
| `workflow.instance.expired` | instance-level SLA expiry | |
| `workflow.definition.published` | publication | artefact, version, in-flight count acknowledged |
| `workflow.segregation.finding` | scheduled health check | workflow, set, intersecting users |
| `workflow.self_approval.overridden` | an override used | actor, entity, amount, reason |

### IX.16.2 Consumed

| Event | Source | Effect |
|---|---|---|
| `employee.deactivated` | Employee | Orphaned-task re-resolution (R-IX-042) |
| `employee.reporting_line_changed` | Employee | Re-resolution offered on active nodes anchored to that line; never automatic, always audited |
| `role.assignment_changed` | Security | Re-runs the segregation health check for affected workflows |
| `org.assignment_changed` | Org | Same, for scope-anchored participants |
| `config.version.published` (artefact type `workflow`) | Configuration | Recomputes the in-flight count; never touches running instances |
| `period.locked` / `period.reopened` | Payroll | Blocks or permits instances whose preconditions reference the period |
| `subject.material_changed` | Any owning module | Applies `on_material_change` (R-IX-054) |
| `scheduler.heartbeat_lost` | Operations | Sets the degraded banner (R-IX-067) |

**R-IX-099** Every emitted event MUST carry the instance uuid, the workflow key
and version, the tenant and the correlation id, so a consumer can join back to
the trace without a second query.

---

## IX.17 Explainability

`Explain(entity, field)` (spine S15) is extended by this part with
`Explain(workflow_instance)`, exposed wherever an approval state is shown.

What the trace renders, for any instance, to any actor within data scope:

```
Request        Loan sanction — R. Krishnan (EMP-04182) — ₹1,75,000
Workflow       LOAN-SANCTION v4   (published 28-Mar-2026, effective 01-Apr-2026 →)
Chosen because binding: legal_entity = Ametecs Collections
                       specificity 60, priority 0; 2 candidates, 1 discarded
                       (tenant default, specificity 0)
Started        14-Aug-2026 11:02 IST by R. Krishnan (self)
Snapshot       9f2c4b… (principal, tenure, purpose, employee, effective date)

Step 1  Submit                              14-Aug 11:02   submitted
        Preconditions   service >= 12 months        18 months   pass
                        no active loan of this type             pass
                        EMI <= 40% of net           31.4%       pass
                        compliance gate             score 78    pass (warn: 78 < 80)

Step 2  Route by amount                     14-Aug 11:02   "single to double band"
        ₹1,75,000 < ₹50,000      → false
        ₹1,75,000 < ₹2,00,000    → true
        Sources  loan_band_1_ceiling, loan_band_2_ceiling
                 policy LOAN-BANDS v2 (effective 01-Apr-2026 →, scope: legal_entity)

Step 3  Manager approval                     due 18-Aug 18:00 IST
        Participants  S. Meera — as R. Krishnan's reporting manager (level 1),
                      resolved as at 14-Aug-2026
        Excluded      R. Krishnan — requester (self-approval prohibition)
        Decision      APPROVED  15-Aug 09:41 IST by S. Meera
                      "Verified tenure and repayment capacity"
                      snapshot at decision 9f2c4b… (unchanged)

Step 4  Finance approval                     due 20-Aug 18:00 IST
        Participants  A. Rao, P. Nair — as Finance Manager for Ametecs Collections
        Completion    any one of two
        Reminder      17-Aug 16:00 IST (2 hours before due) — sent to both
        Decision      APPROVED  18-Aug 16:12 IST by A. Rao
                      P. Nair's task closed: superseded

Step 5  Finalise                             18-Aug 16:12   completed
        Event         loan.sanctioned  (emitted once, outbox id 88421)

SLA      Step 3  20h 39m of 2 working days   on time
         Step 4  2d 6h  of 3 working days    on time
Calendar HYD-GEN (Mon–Sat 09:30–18:30 IST, Sunday off, holiday calendar TG-2026)
```

**R-IX-100** The trace MUST show, for every step: the participants resolved, the
spec that resolved each, everyone excluded and why, the decision, the actor, the
timestamp in the business timezone, the comment, the SLA arithmetic and the
snapshot hash at decision. *Rationale — Law 4 applies to decisions, and none of
this is recoverable from SmartPRS 2, where a decision is a status string, a name
string and an overwritable remark.*

**R-IX-101** The trace MUST be available to the requester for their own instance,
subject to field masking. An employee is entitled to know who is holding their
leave request and for how long.

**R-IX-102** Where a decision was auto-taken, the trace MUST say so in those
words, naming the condition, the substituted values and the reason code — never
render it as if a person decided.

---

## IX.18 Audit

### IX.18.1 The contract

**R-IX-103** Every workflow transition — instance start, node activation, node
skip, task creation, decision, **attempted** decision, reassignment, delegation
substitution, reminder, breach, escalation, return, resubmission, auto-decision,
cancellation, block, resume, completion, rejection — MUST write one row to the
audit ledger (spine S14) through the audit service, in the same transaction as
the transition.

Each row carries, per spine S14's schema:

| Ledger field | Workflow value |
|---|---|
| `actor_user_id` | the deciding user id — **never a name string** |
| `actor_system_code` | `workflow_engine` or `scheduler` for system actions |
| `actor_display_name` | denormalised for display only |
| `occurred_at` | the transition instant |
| `ip_address`, `user_agent_hash` | from the request, for human actions |
| `action_code` | `approve`, `reject`, `state_change`, `override`, … |
| `entity_table`, `entity_id`, `entity_uuid` | the **subject**, so an auditor querying the employee finds the approval |
| `workflow_instance_id` | the instance |
| `config_version_id` | the workflow version in force |
| `reason` | the decision comment or reason code |
| `old_value` / `new_value` | the field-level before/after where the transition changed the subject's approval state, and the node's outcome otherwise |
| `request_id`, `correlation_id` | joins to the originating request |
| `prev_hash`, `hash` | the per-tenant chain |

**R-IX-104** The **entity snapshot hash** MUST be recorded on every decision
transition and in the ledger row, so it is provable which content was approved.
A `finalise` whose hash differs from the last decision's parks the instance
(§IX.4.13).

**R-IX-105** Workflow code MUST NOT insert into the audit ledger directly. It
calls the audit service, which is the only writer. *Rationale — SmartPRS 2 has
four code paths inserting into `activity_logs` with no `prev_hash` and no
`row_hash` (`RequestController.php:1382–1387`, `:1424–1429`, `:1444–1449`,
`:1822–1827`), and because `verify()` resets `$prev` to `'GENESIS'` at any row
with a null hash (`Audit.php:118–121`), **each of them silently destroys the
tamper-evidence of everything before it** (SEC-15, CRITICAL).*

**R-IX-106** An audit write failure MUST fail the transition. Auditing is not
best-effort in this part. *Rationale — SmartPRS 2's audit is best-effort and
silently swallows its own failures (`Audit.php:97–99`), so the business action
succeeds without its audit row.*

**R-IX-107** Every refused decision attempt MUST be audited — wrong actor, wrong
permission, already decided, segregation violation, self-approval violation,
cancelled instance. Who *tried* to approve a payroll run they were not entitled
to approve is exactly the fact an auditor asks for, and it is unrecorded today.

### IX.18.2 What the ledger must be able to answer

| Question | Answered by |
|---|---|
| Who approved this payslip, when, from where, and what did they see? | ledger rows on the subject + the instance trace + `visible_fields` at that version |
| Was the four-eyes control satisfied on this run? | the segregation set evaluation recorded at each decision |
| Has any user ever satisfied both sides of a segregation set? | the scheduled segregation health check's findings (R-IX-071) |
| Who held this approval while it was overdue, and who was reminded? | reminder and breach transitions |
| Which approvals were taken by a delegate, and under whose authority? | `on_behalf_of_user_id` on the transition and the ledger row |
| Which decisions were taken automatically, under which condition? | `actor_system_code` + `evaluation_json` |
| Was this approval given before or after the amount changed? | snapshot hashes on each decision transition |
| Which self-approval overrides were used last quarter? | the standing exception report (R-IX-038) |

### IX.18.3 Retention

**R-IX-108** Workflow instances, tasks and transitions carry retention class
`R-PERM` by default and MUST NOT be purged while the subject entity is
retained. A workflow trace whose subject still exists but whose approval history
has been purged is worse than no trace. *Rationale — nothing prunes anything in
SmartPRS 2 today, contradicting a published 90-day commitment (`E-…` SEC-18); the
answer is a declared class per entity, not an unimplemented promise.*

---

## IX.19 Migration

### IX.19.1 Principle

SmartPRS 2's approval behaviour is **not** carried across as behaviour. It is
**read, transcribed into seeded workflow definitions, and shown to each tenant
for confirmation before cutover.** Nothing is inferred silently, because the
current behaviour is in several places defective and in several places different
from what its own documentation claims.

**R-IX-109** Every seeded definition derived from SmartPRS 2 MUST be presented to
the tenant during cutover as a **confirmable configuration**, with its
thresholds, roles and hops shown, and MUST record who confirmed it and when.
*Rationale — the three thresholds are literals whose status as policy is unknown
(`E-…` PO-17), and the documented escalation chain does not match the code
(`ApprovalService.php:19–21`).*

### IX.19.2 The mapping — hard-coded paths to seeded definitions

| SmartPRS 2 path | Evidence | Seeded SmartPRS 3 definition | Transformation |
|---|---|---|---|
| Nine request types, one hop to `reporting_manager_id` | `ApprovalService::modules():27–43`, `resolveApprover():310–352` | One definition per surviving type, each: `submit → n_manager(RL(1), any_one) → finalise` | The module registry becomes `entity_operations` rows plus bindings. The `reporting_manager` **name-string** fallback is not carried: migration resolves it once to an id and reports every row it could not resolve |
| Amount escalation on expenses (₹10,000), advances (₹10,000), loans (₹50,000) | `ApprovalService.php:30–32`, `:332` | Two-band `amount_bands` on `n_manager`: band 1 `RL(1)`, band 2 `RL(1) → RL(2)` | Values seeded **as the values the code currently uses**, flagged for confirmation. The degenerate `[NULL, 'Branch / Company Admin']` outcome becomes `on_short_line = fallback_participant` pointing at the tenant fallback (R-IX-032) |
| The six types with threshold 0 (commission, clawback, increment, exit, bonus, transfer) | same | Single-band definitions | No behaviour change at cutover; bands are addable afterwards |
| Bulk commission upload passing `threshold = 0` | `RequestController.php:687` | **Not carried.** Bulk-created instances route identically to singly-created ones | This is a defect, not a policy; carrying it would mean a 1,000-row file of large claims never escalates |
| The Accounts confirm-then-approve gate | `:1359–1364` | `COMMISSION-CLAIM` node `n_accounts` before `n_approve`, segregated | Becomes reusable on any entity type |
| DRA gate `off\|warn\|block` | `:1371–1394` | `n_gates` precondition with its tri-state preserved, per-tenant value migrated as-is | Preserved exactly — it is the one control in SmartPRS 2 worth keeping (spine S19.1) |
| Compliance-score and open-grievance warn-and-allow | `:1416–1454` | Two further preconditions on `n_gates`, seeded at `warn` | D-IX-06 |
| Transfer order and increment letter fired inside `decide()` | `:1465–1492` | `finalise` emits `transfer.approved` / `increment.approved`; the document modules subscribe idempotently | The re-fire defect disappears structurally |
| Leave's separate resolver and inbox | `LeaveController.php:96–117`, `:469–500` | `LEAVE-APPROVE` (§IX.12.1); leave rows become instances | Leave stops being a second engine |
| Payroll `draft → hr_approved → approved` with `isHR()`/`isFinance()` | `SalaryApprovalController:59–68`, `nextStep():159–169` | `PAYROLL-APPROVE` (§IX.12.3) with a true segregation set | **The defect is not replicated**: `admin` satisfying both sides is exactly what W10 refuses |
| Per-line lifecycle `pending → on_hold\|in_review → approved → disbursed → acknowledged` | `applyLineAction():554–606` | `payslip_line` operations (`hold`, `release`, `disburse`) bound to lightweight definitions; the state vocabulary becomes `status_values` rows with a `status_transitions` matrix | Reverse transitions become configurable, which they are not today |
| Bank-file download disbursing lines | `bankFile():499–503` | **Not carried.** File generation is a read; disbursement is a separate gated command (R-IX-074) | DEF-P10 is closed by construction |
| Scheme approval hierarchy (`Admin/HR instant; TL waits for their manager; fallback label 'Admin / HR'`) | `SchemeController::save():443–500` | `SCHEME-PUBLISH`, a two-node definition with a role-based fast path expressed as a guard, not a role literal | The label fallback becomes the tenant fallback spec |
| Offer acceptance and NDA signature token pages | `OfferAcceptController.php:64–88`, `NdaSignController.php:85–102` | **Not approvals.** They become e-signature evidence records consumed by `OFFER-APPROVE` and the document module; `OFFER-APPROVE` governs the *approval*, which does not exist today at all | R-IX-092, R-IX-093 |
| `reopen` on commissions setting `status='pending'`, `decided_by=NULL`, `decided_at=NULL` | `:1403–1410` | **Not carried.** A correction is a new instance with `supersedes_instance_id` (R-IX-043) | Erasing the fact of a decision is the opposite of the required behaviour |
| Edit-after-approval ("Ejaz's rule") | `:763–877` | Carried **only** for `commission_claim`, only until locked, under `invalidate_decided_steps`, with a structured field diff in the ledger | D-IX-05 |
| Terminal `locked_at` with no unlock endpoint | `:948–955`, `:1269–1295`; absence | `n_unlock` with a mandatory reason and a Finance Controller participant | The missing correction path is added |
| `denyUnlessRole()` failing open across 71 call sites | `ApprovalService.php:365–380` | **Not carried.** Every workflow decision fails closed (R-IX-098) | Law 5 |
| Runtime DDL on the approval path and on public token pages | `ensureAuditCols():46–260`; `OfferAcceptController::ensureCols():18–30` | **Not carried.** Law 2 | |

### IX.19.3 Data migration

| SmartPRS 2 data | Destination |
|---|---|
| Decided rows across the nine request tables and `leaves` | One **closed** `workflow_instances` row each, `lifecycle_state_code` = `completed` / `rejected`, with a single synthetic `workflow_transitions` row bearing `actor_system_code = 'migration'`, the original `decided_by` resolved to a user id where possible, `decided_at`, and the original remark as the comment. Marked `origin = 'smartprs2_import'` |
| Rows whose `decided_by` cannot be resolved to a user | Imported with `actor_user_id = NULL`, `actor_display_name` = the original string, and an explicit `unresolved_actor` marker. **Not guessed.** The count is reported per tenant |
| `payroll_runs` approval stamps (`hr_by`, `fin_by`, `remarks`, `locked_at`) — themselves runtime-created columns (`SalaryApprovalController::ensureCols():49–56`) | Two synthetic transitions per run, same discipline |
| `commission_logs` English-sentence field diffs | Imported as **opaque historical notes** attached to the instance, explicitly not parsed into structured old→new values. *They are unparseable by construction, capped at 1,000 characters, and outside the hash chain* |
| `activity_logs` | Imported as pre-migration audit rows behind an explicit **"pre-migration, unverifiable"** marker. The chain is already broken at every commission-warning row and every bounce, so it MUST NOT be presented as verified history (`E-…` PO-14) |
| `transfers` — the only request table with no migration, created by a runtime `Schema::create` | Migrated where it exists; its absence on a given install is reported, not assumed (`E-…` PO-20) |

**R-IX-110** No imported instance may be `running`. Everything imported is
terminal or is handled by §IX.19.4. *Rationale — an imported instance cannot have
a valid `config_version_id` for a version it never ran on, and pretending
otherwise corrupts the "completes on the version it started on" guarantee.*

**R-IX-111** Imported instances MUST be visually and queryably distinguishable
from native ones, and their traces MUST state that the detail available is
limited to what SmartPRS 2 recorded — which is a status, an actor string, a
timestamp and an overwritable remark.

### IX.19.4 In-flight approvals at cutover

The disposition question: at cutover there are pending rows in nine request
tables, `leaves`, `payroll_runs` and `payslips`.

**R-IX-112** In-flight items MUST be dispositioned by an explicit, per-tenant,
per-entity-type decision made before cutover, from exactly three options, and the
decision MUST be recorded:

| Option | Mechanism | When appropriate |
|---|---|---|
| **A. Drain before cutover** (recommended default) | A cutover-readiness report lists every pending item, its age and its approver. The tenant clears them in SmartPRS 2. Cutover proceeds with zero in-flight items | Leave, requests, regularisations — items with a natural short life |
| **B. Re-raise in SmartPRS 3** | The pending item is imported as a **cancelled** instance with reason `superseded_by_migration`, and a **new** instance is started on the seeded definition, linked by `supersedes_instance_id`, with the original submission date preserved and the SLA clock started at cutover | Long-lived items a tenant cannot drain: open loans awaiting sanction, exits mid-clearance |
| **C. Carry the decision, not the workflow** | For an item already decided in SmartPRS 2 but not yet effected, import as a completed instance and let the domain subscriber apply the effect — **once**, guarded by the outbox constraint | Approved-but-unpaid commissions, approved-but-unissued letters |

**R-IX-113** Option B MUST NOT inherit any approval given in SmartPRS 2. The
approvals are visible in the cancelled predecessor's trace as historical facts;
they do not satisfy any step of the new instance. *Rationale — the SmartPRS 2
approval was given under a control we have just documented as defective;
inheriting it would import the defect into the new system's evidence base.*

**R-IX-114** A payroll run MUST NOT be in flight across cutover. Cutover happens
between periods, with every run either locked or abandoned. *Rationale — a run
half-approved under `isHR()`/`isFinance()` and half-approved under a segregated
workflow has no coherent control story, and `paid`/`locked` are never written by
SmartPRS 2 anyway (BR-PAY-024), so "is this run finished?" is not reliably
answerable from the source data.*

**R-IX-115** The cutover-readiness report MUST include: pending count by entity
type and age; pending items with a **null approver** (which are invisible in
SmartPRS 2 and will otherwise be discovered only in SmartPRS 3); items whose
approver string cannot be resolved to a user; leave rows with a `type_name` that
matches no configured type; and every request table that does not exist on this
install.

---

## IX.20 Edge cases and failure states

| # | Case | Behaviour |
|---|---|---|
| E-01 | The requester is their own reporting manager | Self-approval prohibition drops them; `RL(1)` short-circuits to `RL(2)` if declared, else `on_no_participant`. Never assigned to self |
| E-02 | The employee has no reporting manager | `on_short_line` → tenant fallback spec; if none configured, instance `blocked` with an alert. **Never a null approver** |
| E-03 | The reporting line contains a cycle | Detected during the walk; node **fails** with the cycle named. It is a data defect and must be fixed, not routed around |
| E-04 | The approver leaves the organisation mid-flight | `employee.deactivated` → orphaned-task queue → re-resolution (R-IX-042) |
| E-05 | The approver's role is revoked mid-flight | The task remains but the decision is refused at `required_permission`; the oversight view surfaces it; re-resolution is available |
| E-06 | The approver is on leave with no backup | The SLA breaches and escalates per policy. The system does not silently wait |
| E-07 | A delegate is themselves delegated to | Chaining is refused at creation (R-IX-034) |
| E-08 | A delegation expires with the task still open | The task reverts to the principal at the next resolution touch; open tasks are re-evaluated by the timer sweep |
| E-09 | Two approvers decide simultaneously | §IX.6.5 |
| E-10 | The same decision is submitted twice (double-click, retry) | Idempotency key returns the original response; without a key, `409` with the existing decision |
| E-11 | The subject is deleted while an instance runs | Impossible: subjects are soft-deleted, and a soft-delete of a subject with a running instance is refused by the owning module, which must cancel the instance first |
| E-12 | The subject amount is edited mid-flight | `on_material_change`; re-routing and invalidation per §IX.6.8 |
| E-13 | The workflow version is superseded mid-flight | The instance completes on its own version (R-IX-051); the publish screen states the in-flight count |
| E-14 | The workflow definition is retired mid-flight | Retirement is blocked while instances run |
| E-15 | A branch condition references a namespace that is empty for this subject | Publish-time symbol-table validation prevents it; at runtime a null-typed value propagates as a typed error and the node **fails**, never routes to the default silently |
| E-16 | An SPX participant expression returns an unknown employee code | Unknown codes are dropped, **counted**, and reported on the activation; if the set empties, `on_no_participant` |
| E-17 | An SPX expression exceeds the node or time budget | Node fails with `evaluation_budget_exceeded`; instance parks |
| E-18 | The scheduler is down for a day | §IX.7.6: nothing lost, reminders coalesced, auto-decisions suppressed by the stale-timer policy, banner and alert raised |
| E-19 | The queue worker is down | Notifications and outbox dispatch back up; decisions still work; the ops dashboard shows depth and oldest pending |
| E-20 | The outbox dispatch fails permanently | Dead-lettered with an alert; the instance stays `completed` and the effect is visibly un-applied rather than silently lost |
| E-21 | A parallel branch's guard is false for every branch | The node parks (§IX.4.4) |
| E-22 | A quorum node's participant set shrinks below `n` | `tie_policy` applies; `n` is never silently reduced |
| E-23 | A bulk action includes an item the actor may not decide | That item fails with a typed reason; the rest proceed; per-item outcomes are returned |
| E-24 | A task is decided while the instance is being cancelled | The instance lock serialises them; the loser receives `409` and the attempt is recorded |
| E-25 | An instance is started for an operation with no binding and no explicit "no approval required" | The operation is **refused** (R-IX-015). There is no implicit auto-approval |
| E-26 | Two bindings tie on specificity and priority | Publication was blocked (W17); at runtime, if a legacy tie is somehow present, the operation is refused with the tie named. Never a coin-toss |
| E-27 | The tenant's clock and the calendar timezone disagree | SLA arithmetic is in UTC instants; display converts. The calendar's timezone is authoritative for business-hours boundaries |
| E-28 | A leap-second or DST transition falls inside an SLA window | Business-hours arithmetic operates on the calendar's local wall-clock windows converted to instants at computation time; a DST-shortened day yields fewer business minutes, which is the correct answer |
| E-29 | A decision arrives with a comment exceeding the column length | Rejected with a typed validation error naming the limit — never truncated. *SmartPRS 2 silently truncates audit detail with `mb_substr` at 1,000 characters* |
| E-30 | An attachment is required and the upload fails | The decision is not recorded; the actor is told. A decision and its evidence are written in one transaction |
| E-31 | The subject entity type is disabled by feature entitlement mid-flight | Running instances complete; new ones are refused with an entitlement error |
| E-32 | A tenant is suspended with instances running | Instances freeze; timers are suspended and their `fire_at` is shifted by the suspension duration on resume, so a suspension does not manufacture breaches |
| E-33 | An imported (SmartPRS 2) instance is queried for a trace | Renders the limited detail available, with the explicit limitation notice (R-IX-111) |
| E-34 | An approver views a task containing salary they may not see | Masked per spine S13; the trace records that the decision was taken on masked content, so the evidential weight is knowable |
| E-35 | A workflow is bound to an entity whose subject employee has no user account | Participation anchored to `subject_employee` still resolves (it anchors an org position, not a login); the self-approval prohibition's identity 2 simply finds no user to exclude |

---

## IX.21 Tests

### IX.21.1 Exact-value regression tests (mandatory)

These must exist, must assert exact values, and must run in CI.

| # | Scenario | Exact assertion |
|---|---|---|
| T-01 | **Double decision.** Two connections decide the same task concurrently | Exactly 1 `workflow_transitions` decision row, 1 audit row, 1 outbox row; loser gets `409 workflow.task_already_decided` |
| T-02 | **Re-decide a decided task** | 0 rows updated; `409`; the original decision returned unchanged; a `attempted_decision` transition written |
| T-03 | **Completion event emitted once.** Replay the finalise path five times | Exactly 1 outbox row; the transfer-order subscriber sends exactly 1 letter |
| T-04 | **Subscriber idempotency.** Deliver `workflow.instance.completed` twice | Exactly 1 effect applied |
| T-05 | **Amount band boundaries.** Loan at ₹49,999.99, ₹50,000.00, ₹1,99,999.99, ₹2,00,000.00 with bands `[<50,000)`, `[50,000–2,00,000)`, `[2,00,000–)` | 1, 2, 2, 3 approval steps respectively; comparison in integer paise |
| T-06 | **Amount amended mid-flight.** ₹1,75,000 approved twice, amended to ₹2,40,000 | Both approvals invalidated; 3 steps re-sought; trace names both invalidated approvals |
| T-07 | **Effective-dated participant resolution.** Employee transfers department on 1-Aug; node activates 31-Jul and 2-Aug | July head and August head respectively |
| T-08 | **Self-approval.** Requester is their own recorded manager | Requester never assigned; `RL(2)` or fallback used; if neither, instance `blocked`, 0 tasks assigned to the requester |
| T-09 | **Segregation at publish.** Publish `PAYROLL-APPROVE` in a tenant where one user holds both the HR and Finance approver roles | Publication blocked, error names the user |
| T-10 | **Segregation at decision.** The same user is granted both roles after publication and attempts the second step | `403`; attempt audited; the scheduled health check raises a finding |
| T-11 | **Quorum 2 of 3.** Approve, approve | `approved`; the third task `superseded`; a late rejection returns `409` and is recorded as an attempt |
| T-12 | **Quorum reject threshold.** 2 of 3 with `reject_threshold = 2`; reject, reject | `rejected`; remaining task `superseded` |
| T-13 | **parallel_all with one branch rejecting** under `continue_others` | Siblings complete; the join records the rejection; the outcome follows the declared transition |
| T-14 | **parallel_any tie.** Two branches complete in the same millisecond | Exactly one winner by `decided_at` then task id; the loser `withdrawn`; both facts in the ledger |
| T-15 | **SLA business hours.** 24-hour SLA set Friday 17:00 IST on a Mon–Fri 09:30–18:30 calendar with Saturday and Sunday off | Due Tuesday 16:00 IST — asserted to the minute |
| T-16 | **SLA across a public holiday** | The holiday is excluded; due date asserted exactly |
| T-17 | **Reminder idempotency.** Run the sweep three times over the same due window | Exactly one reminder per `(task, kind, sequence_no)` |
| T-18 | **Reminder coalescing.** One approver, 14 overdue tasks | Exactly 1 notification naming 14 items |
| T-19 | **Scheduler down 26 hours, then catch-up** on a node with `escalation_policy = auto_approve` and `stale_threshold_minutes = 120` | 0 auto-approvals; instance `blocked` with `stale_timer`; alert raised |
| T-20 | **Scheduler down, overdue visibility** | The inbox shows the task overdue with the correct elapsed business hours, computed at read time |
| T-21 | **Delegation.** A delegates to B for 10–20 Sep; a task arrives 15 Sep | Assigned to B; `delegated_from_user_id = A`; trace and ledger both name both |
| T-22 | **Delegation chaining refused.** B, holding A's delegation, delegates to C | Refused at creation with a typed error |
| T-23 | **Delegation without permission.** B lacks the node's `required_permission` | Task stays with A; the reason is recorded |
| T-24 | **Delegation amount ceiling.** Ceiling ₹1,00,000; a ₹1,50,000 item arrives | Stays with the principal |
| T-25 | **Leaver.** Deactivate a user holding 12 open tasks | 12 re-resolutions or 12 alerts; 0 tasks left addressed to the deactivated account |
| T-26 | **No participant.** A role with zero holders on a node with `on_no_participant = fail_instance` | Instance `blocked`; alert; requester informed; 0 tasks; **0 auto-approvals** |
| T-27 | **In-flight version isolation.** Start an instance on v3; publish v4 changing participants and SLA | The instance still resolves v3's participants and v3's SLA to the minute |
| T-28 | **Definition retirement blocked** while instances run | Refused, naming the count |
| T-29 | **Material change under `block_edit`** (payroll run) | The edit is refused while the instance runs |
| T-30 | **Bulk decide with one ineligible item** among 20 | 19 succeed, 1 typed failure, 20 per-item outcomes, 1 bulk audit row + 19 item rows |
| T-31 | **Bulk decide on a maker-checker workflow** with bulk disabled | Refused for those items with a typed reason |
| T-32 | **Return for edit.** Return, edit a non-editable field, resubmit | Refused, naming the field |
| T-33 | **Return exhaustion.** `max_returns = 2`; third return | Outcome `exhausted`; routes to reject |
| T-34 | **Finalise with subject drift.** Alter a material field between the last decision and finalise | Instance parks with `subject_drift_detected`; **no completion event** |
| T-35 | **Cross-tenant isolation.** Every workflow endpoint, with a token from tenant A against an object of tenant B | 404/403, never data; generated for every endpoint (Law 6) |
| T-36 | **Authorisation fault injection.** Force an exception inside the permission check | Denies. *The inverse of `denyUnlessRole()` returning "allowed" on any exception* |
| T-37 | **Every route has a policy binding** | CI sweep asserts it |
| T-38 | **Audit completeness.** For each of the 20 transition kinds | Exactly one ledger row with a valid `prev_hash`/`hash`, chained per tenant |
| T-39 | **Audit failure fails the transition.** Force an audit write failure | The decision does not persist |
| T-40 | **Refused attempts are audited.** Wrong actor, wrong permission, already decided, segregation, self-approval | 5 attempts, 5 ledger rows, 0 state changes |
| T-41 | **Trace completeness.** Every instance in the worked library | The trace names every participant resolved, every exclusion, every decision, every SLA computation, and the version of everything |
| T-42 | **Publish validation.** One negative case per validator W01–W18 | Each blocked with the offending nodes named |
| T-43 | **Section 56 conformance.** For each of the 11 operations: no instance / running / rejected / completed-on-a-non-maker-checker-workflow | Refused in all four |
| T-44 | **Section 127 conformance.** Author "the third regularisation this month routes to HR" through the UI | Published and effective with zero code changes; the third instance routes to HR and the second does not |
| T-45 | **Migration disposition.** Import a tenant with pending items under option B | Predecessor instances `cancelled`; new instances carry **zero** inherited approvals |
| T-46 | **Inbox performance.** 50,000 open tasks, one approver with 200 | Inbox page and badge count served from the covering index within the declared budget; no unbounded scan |
| T-47 | **No DDL at runtime.** Exercise every workflow path | 0 DDL statements observed (Law 2) |
| T-48 | **No float in routing.** Static analysis over the routing path | 0 float operations on `Money` (Law 7) |

### IX.21.2 Stored test cases on a definition

**R-IX-116** A workflow definition MAY carry stored test cases — a named subject
fixture, a date, and the expected path, participants and outcome — and
publication MUST run them and block on failure (W18). This mirrors the rule
engine's regression harness (spine S6) and uses the same fixture library.

### IX.21.3 Conformance tests owned by other modules

**R-IX-117** Every module owning a workflow-gated operation MUST carry a test
asserting the operation is refused without a completed instance, and a test
asserting its completion subscriber is idempotent (R-IX-048). The workflow engine
cannot assert this on the module's behalf.

---

## IX.22 Open items — PRODUCT OWNER DECISION REQUIRED

Consolidated. Each carries a recommendation.

| Ref | Decision | Recommendation | Trade-off |
|---|---|---|---|
| **D-IX-01** | May a maker-checker workflow contain an `auto_approve` node? | **No.** Express "below ₹X needs no approval" as a `condition_branch` that routes around the approval, so the trace says "no approval required" rather than "approved by the system" | Slightly more configuration for a common case; the gain is an honest audit trail |
| **D-IX-02** | The default tenant fallback approver spec | A **role** (HR Operations at company scope) with `any_one` completion, never a named user | A role can resolve to several people; a named user is a single point of failure and a leaver problem |
| **D-IX-03** | May delegation apply to Section 56 workflows? | **Yes**, but only to a delegate who independently holds the segregated role, with the segregation constraint re-checked **after** substitution | Forbidding it entirely is safer but produces month-end deadlock when the sole Finance approver is on leave |
| **D-IX-04** | Block or warn when publishing a materially changed workflow with instances in flight? | **Warn**, with a mandatory acknowledgement naming the in-flight count and the oldest instance | A careless administrator can click through; the acknowledgement row is the compensating control and the auditor's evidence |
| **D-IX-05** | Is edit-after-approval ("Ejaz's rule", `RequestController.php:758–762`) retained, and for which entity types? | Retain for `commission_claim` only, until locked, under `invalidate_decided_steps`, with a structured old→new diff in the audit ledger | Retaining it anywhere weakens the finality claim; confining it makes the weakening explicit, bounded and — unlike today — auditable |
| **D-IX-06** | Default severity for the compliance-score and open-grievance gates at migration | Seed at **`warn`** (matching current behaviour exactly) and raise a Configuration Studio task per tenant to choose | `block` on day one stops payments for every agent below the threshold; `warn` preserves a weak control until someone acts, which the standing exception report makes visible |
| **D-IX-07** | Exemptions from maker-checker for very small tenants | **No exemption.** Fill the second side with a named external checker or accept that those operations need two accounts | Friction for micro-tenants; the alternative is shipping a control one person routinely satisfies alone — the exact defect being corrected |
| **D-IX-08** | Are the seeded thresholds (₹10,000 / ₹10,000 / ₹50,000, `ApprovalService.php:30–32`) real policy or placeholders? | Seed **as the values the code currently uses**, mark unconfirmed, and require tenant confirmation at cutover | Recorded here per the brief's legal-values discipline; they are never asserted as correct |
| **D-IX-09** | Should `super_admin`/`admin` continue to satisfy both sides of every gate? (`E-…` PO-15) | **No.** Split into distinct approver roles at migration and let the segregation health check surface every tenant where one person holds both | Some tenants genuinely operate with one administrator; D-IX-07 governs that case |
| **D-IX-10** | Does the request-type catalogue survive as nine types, or is it re-designed? (`E-…` PO-03) | Seed the nine as bindings so nothing breaks, then let tenants disable what they do not use; treat Commission/Clawback/DRA-gated flows as a **collections-industry pack** rather than platform defaults | Nine types shown to every tenant is current behaviour and is wrong for most customers |
| **D-IX-11** | Who owns the platform-seeded workflow library, and who may change a seeded definition? | Ametecs owns the seed; a tenant **forks** on first edit (Part VI's `pristine`/`forked` origin states), so upgrades can still improve unforked definitions | A forked tenant stops receiving improvements to that definition; the origin state makes that visible |
| **D-IX-12** | Should a workflow be able to require a **legally weightier** signature (OTP-confirmed, or a digital signature) on named nodes? | Model it now as an acknowledgement with a declared strength (`tick`, `typed_name`, `otp`, `digital_signature`), implement `tick` and `typed_name` in v3.0 and leave the stronger two as declared-but-unimplemented strengths that fail closed if configured | Payroll e-signature today is a typed name plus IP (`SalaryApprovalController::acknowledge():731–792`), which is weak; declaring the ladder now avoids a schema change later |

---

## IX.23 Requirements index

| Range | Subject | Section |
|---|---|---|
| R-IX-001 – 004 | One engine; no literals; immutability; segregation enforceability | IX.0, IX.1 |
| R-IX-005 – 008 | Engine boundaries; participation is not authorisation | IX.2 |
| R-IX-009 – 019 | The definition model, binding, actions, publish validation, simulation | IX.3 |
| R-IX-020 – 026 | The node catalogue and its guarantees | IX.4 |
| R-IX-027 – 042 | Participant resolution, delegation, self-approval, segregation, reassignment, leavers | IX.5 |
| R-IX-043 – 055 | Instance semantics: states, immutability, idempotency, concurrency, versioning, drift, gates | IX.6 |
| R-IX-056 – 068 | SLA, calendars, reminders, escalation, the sweep, scheduler-down behaviour | IX.7 |
| R-IX-069 – 076 | Maker-checker, the Section 56 list, payroll specifics | IX.8 |
| R-IX-077 – 078 | Amount routing discipline | IX.9 |
| R-IX-079 – 082 | Rule-engine integration | IX.10 |
| R-IX-083 – 091 | The unified inbox, bulk actions, delegation and oversight views | IX.11 |
| R-IX-092 – 094 | Offer/NDA evidence; public-route prohibitions; the self-governing publish workflow | IX.12 |
| R-IX-095 – 096 | Data-model exceptions and the maker-checker registry flag | IX.14 |
| R-IX-097 – 099 | API prohibitions, the decision check order, event payloads | IX.15, IX.16 |
| R-IX-100 – 102 | Explainability of decisions | IX.17 |
| R-IX-103 – 108 | Audit contract and retention | IX.18 |
| R-IX-109 – 115 | Migration, imported instances, in-flight disposition | IX.19 |
| R-IX-116 – 117 | Stored test cases and module conformance | IX.21 |

**Total: 117 numbered, testable requirements; 12 Product Owner decisions;
17 tables specified to the Section 123 standard; 11 worked workflow definitions;
48 mandatory exact-value regression tests.**

### IX.23.1 Compliance with the seven laws

| Law | How this part complies | Test |
|---|---|---|
| **1 — No business literal in code** | Every approver, threshold, SLA, role, stage name, calendar and gate severity is a column in a published version (IX.13) | T-44; the static-analysis literal ban |
| **2 — No schema change at runtime** | The engine executes no DDL; custom subject fields live in `entity_field_values` (spine S9); the runtime DDL on SmartPRS 2's approval path and public token pages is explicitly not carried | T-47 |
| **3 — Nothing overwritten; versioned and dated** | Definitions are `config_versions`; instances complete on their start version; decisions are new rows, comments are append-only, tasks are never updated after decision, nothing is deleted | T-27, T-02, R-IX-097 |
| **4 — Every number explains itself** | Every routing decision, participant resolution, exclusion, SLA computation and auto-decision carries its source, its version and its substituted values (IX.17) | T-41 |
| **5 — Authorisation server-side, declarative, fails closed** | R-IX-008's two-question check; the ordered gate list of R-IX-098; fault injection denies | T-35, T-36, T-37 |
| **6 — Tenancy is structural** | `tenant_id` NOT NULL and first in every unique index on all fourteen tables; the single declared exception (`workflow_timers` sweep index) carries its own conformance test (R-IX-095) | T-35 |
| **7 — Money is exact** | Amount bands compare integer minor units through the `Money` value object; no float touches a routing threshold | T-05, T-48 |

### IX.23.2 Dependencies on other parts

| This part needs | From |
|---|---|
| The artefact lifecycle, scope, precedence, diff, impact preview and the Configuration Studio | Part VI |
| SPX parsing, typing, budgets and the symbol table | Part VII (spine S5) |
| `ROUTE_TO_WORKFLOW` and `REQUIRE_APPROVAL` action semantics and conflict policy | Part VII |
| Formula references used in branch variables | Part VIII |
| Entity definitions, field surfaces and value sets | The metadata part (spine S9) |
| `status_domains`, `status_values`, `status_transitions`, table conventions, partitioning | Part XXVIII |
| Permissions, data scopes, masking, read auditing | The security part (spine S13) |
| The audit ledger service and its chain | The audit part (spine S14) |
| Notification events, audiences, channels and per-recipient delivery tracking | The notifications part |
| Holiday calendars | The attendance part (spine S10) |
| Scheduler, queue, outbox dispatch, health checks and the operations dashboard | The background-operations part |
| Payroll run and line lifecycles, period lock and reopen | The payroll part (spine S11) |

### IX.23.3 What this part deliberately leaves to others

The **content** of a tenant's approval policy: how many steps, at what
thresholds, staffed by whom. This part specifies the mechanism, the eleven seeded
definitions and the controls that may not be weakened. Everything else is a
tenant's configuration — which is the whole point of building an engine rather
than eleven more `if` statements.
