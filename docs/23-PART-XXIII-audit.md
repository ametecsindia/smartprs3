# PART XXIII — AUDIT ARCHITECTURE

*Covers brief Sections 74 and 75.*
*Specifies spine S14 (the audit ledger) in full. Conforms to S3 (org model), S4 (effective
dating and versioning), S9 (the entity write service), S13 (security), S15
(explainability), S16 (data conventions), S17 (API).*
*Depends on: Part VI (configuration lifecycle), Part X (employee schema engine — which owns
the single write service that produces field-level diffs), Part IX (workflow engine — whose
every transition writes here), Part XIX (notifications — which routes chain-break alerts).*
*Depended on by: every part of the blueprint. There is no module that does not write here.*
*Evidence base: `analysis/E-leave-requests-notifications.md`,
`analysis/G-security-tenancy-api.md`, `analysis/D-employee-master.md` and
`analysis/N-product-business-modules.md`. Every claim about SmartPRS 2 in this part carries
a `file.php:line` citation carried through from those files.*

---

## XXIII.0 Purpose

This part decides three things.

1. **That there is exactly one audit ledger, with exactly one write path, and that a direct
   insert into it is structurally impossible** — not discouraged by convention, but
   prevented by database privilege, by schema constraint and by build-time enforcement.
   SmartPRS 2's chain is already broken in four places by direct inserts, and the breakage
   is silent (`RequestController.php:1382-1387, :1424-1429, :1444-1449, :1822-1827`;
   `Audit::verify():118-121`).
2. **That the audit trail records what changed, not merely that something happened.**
   Field-level before/after capture is produced by the write service, never by call sites,
   because SmartPRS 2 has the helper for exactly this purpose and it has **zero call sites**
   in the request, leave and self-service area (`AuditTrail::change():78-91`;
   `E-…` §7 Q8, NEW-AUD-109).
3. **That audit and history are two different things, and both exist.** "What was this
   employee's branch on 15 May?" is answered from effective-dated assignment data in one
   service call (spine Law 3), **never** by replaying the audit log. "Who changed it, when,
   from what, and why?" is answered from the ledger. Conflating the two is how systems end
   up able to answer neither.

What this part does **not** own: the effective-dated assignment tables themselves (Part V
and Part X); the configuration version envelope (Part VI); the workflow transition record
(Part IX, which writes *to* this ledger); the notification delivery ledger (Part XIX,
which is an operational record, not an audit record).

---

## XXIII.1 What SmartPRS 2 does today, and why it must change

The design in SmartPRS 2 is sound and the implementation defeats it. That distinction
matters, because the SmartPRS 3 answer is not "invent a different mechanism" but "make the
mechanism that was intended actually hold".

### XXIII.1.1 The chain is broken in four places, silently, by design of the verifier

`Audit::record()` appends a SHA-256 hash-chained row (`Audit.php:68-100`), with the payload
built at `:49-61`:

```
payload  = implode('|', [prev, tenantId ?? '', userId ?? '', action, entity,
                         entityId, detail ?? '', when])
prev     = (SELECT row_hash FROM activity_logs ORDER BY id DESC LIMIT 1) ?: 'GENESIS'
row_hash = hash('sha256', payload)
```

Verification recomputes from `'GENESIS'` using `hash_equals`, which is timing-safe and
correct (`:106-132`).

**Four code paths bypass `record()` entirely**, inserting into `activity_logs` with
`DB::table('activity_logs')->insert([...])` and **no `prev_hash` and no `row_hash`**:

| Site | Written when |
|---|---|
| `RequestController.php:1382-1387` | commission approval carrying a **DRA warning** |
| `RequestController.php:1424-1429` | commission approval carrying a **compliance warning** |
| `RequestController.php:1444-1449` | commission approval carrying a **grievance warning** |
| `RequestController.php:1822-1827` | a **bounce** |

`verify()` treats a row with a null or empty `row_hash` as a legacy row, **skips it, and
resets `$prev` to `'GENESIS'`** (`Audit::verify():118-121`). The consequence is precise and
severe:

> **Every commission approval carrying a DRA, compliance or grievance warning — and every
> bounce — silently destroys the tamper-evidence of everything written before it.** The
> chain does not report a break. It reports that everything after the reset verifies
> perfectly, because it re-anchors there (`E-…` NEW-AUD-104, SEC-15, CRITICAL).

The four bypassing paths are precisely the highest-value rows in the ledger: money
decisions taken **against** a compliance warning. An adversary wishing to break the chain
would choose exactly these.

### XXIII.1.2 The chain has a read-then-insert race with no lock

`Audit.php:91-96` reads the current head and inserts, with **no transaction and no
`lockForUpdate`** — grep for `DB::transaction|lockForUpdate` in `Audit.php`,
`RequestController.php` and `LeaveController.php` returns nothing (`E-…` NEW-AUD-103).
Two concurrent `record()` calls read the same `$prev` and both chain onto it. `verify()`
walks by `id` and reports the second as **broken**.

The result is false tamper alarms under ordinary concurrency — which is worse than no alarm
at all, because an alarm that fires routinely is an alarm nobody investigates.

### XXIII.1.3 The chain is global, not per tenant, and verification is unbounded

`Audit.php:91` takes the head across the whole table with `orderByDesc('id')`, so one
tenant's rows chain onto another's. Verifying one tenant requires reading every tenant's
rows (`E-…` NEW-AUD-102), and one tenant's break reports as a break for all
(`G-…` D-19). `verify()` loads the entire table into memory with
`DB::table('activity_logs')->orderBy('id')->get()` (`Audit::verify():111`) — an
O(n) memory blow-up on a mature install, and a denial-of-service path reachable by any
administrator clicking *Activity Logs* (`G-…` D-18).

### XXIII.1.4 The only before/after helper has no call sites

`AuditTrail::change($request, $action, $entity, $id, $before, $after)` exists precisely to
record field-level diffs and produces `['changes' => ['field' => ['from'=>…, 'to'=>…]]]`
(`AuditTrail.php:78-91`). **It has no call site in `RequestController`, `LeaveController` or
`EssController`** (`E-…` NEW-AUD-109, verified by grep). What is recorded instead:

```
request submit  → {"employee":"<name>"}
request decide  → {"remarks":"…"}       and the new status as the action
leave submit    → {"type":"…","days":n}
leave decide    → {"remarks":"…"}
```

**No before-state, no after-state, no field-level diff, anywhere in that area.** The helper
also uses loose comparison (`$old != $v`), so `'0'`, `0` and `false` register as unchanged,
and it returns early writing **no log at all** when the computed diff is empty
(`AuditTrail.php:78-91`; `E-…` NEW-AUD-108).

The only place old→new exists at all is `commission_logs.details`, as a **human-readable
English sentence** built by string concatenation
(`RequestController.php:807, :815, :832, :844, :861, :870-871`) — for example:

```
amount ₹10,000.00 gross → ₹12,000.00 gross (TDS 5% = ₹600.00, net ₹11,400.00)
(edited AFTER approval)
```

That is unparseable, unqueryable, uncomparable, capped at 1,000 characters by `mb_substr`
(`ApprovalService.php:292`), **outside the hash chain entirely**, and **fail-soft** — a
failure emits only `Log::warning` and the business action succeeds without its audit row
(`:296-298`).

### XXIII.1.5 Employee self-service edits are entirely unaudited

`EssController::updateProfile()` makes **no audit call of any kind**
(`E-…` §7 Q8, DEF-ESS-02). The fields it writes include the contact details, the date of
birth, and **the payslip delivery email address**. That address is `employees.email`, it is
editable instantly with no verification and no confirmation to the existing address, and
payslips and decision emails are delivered to it (`E-…` requirement 54, SEC-08).

An employee can therefore redirect their own payslip delivery, and the system holds no
record that they did.

### XXIII.1.6 Identity and access management is unaudited except successful login

There are **eleven functional `Audit::record` call sites in the entire product** (fourteen
including three in a dead `EmployeeController`) (`G-…` §0, counted by grep). Successful
login is audited at `AuthController.php:271-284`. **Not audited:** logout, failed login,
role assignment, user creation, account disablement, administrator password sets, staff
CRUD, and tenant provisioning (`G-…` NEW-SEC-113, D-18, SEC-21).

This matters because of what else is true in that area. An `hr_manager` may create a user
with role `admin`, and may **set the password of any user in the tenant, including the
tenant administrator** (`UserController.php:34-37, 150-152, 262-278`) — full tenant
takeover from an HR account, and **no audit record is written for either action**
(`G-…` SEC-07). Separately, `POST /app/state` accepts an arbitrary array and replaces the
tenant's `app_state`, which contains the permission matrix `DB.roles[].perms` that
`applyPerms()` reads on every login (`AppStateController.php:40-52`;
`AppController.php:676-683, 769-790`); a `{"state":{}}` post empties every non-normalised
screen with **no history, no soft delete and no audit** (`G-…` SEC-03).

Nothing in the ledger would have detected any of it.

### XXIII.1.7 The primary employee write path writes no audit row at all

`AppDataController::storeEmployee()` — the main employee create/update path, covering all
fifty-six registry fields — **does not write an `activity_logs` or `Audit::record` entry**
(`D-…` §, `AppDataController.php:2718-3089`; the analysis notes this is unlike
`MasterController`, which does audit every create/update/delete at
`MasterController.php:792-800`).

There is no field-level history either: `employees` carries exactly one current value per
attribute and every writer issues `UPDATE employees SET …` (`D-…` §). Two consequences
follow directly and are cited as defects in their own right:

| Consequence | Evidence |
|---|---|
| `employee_references` are **deleted on every save** and re-inserted from the posted array; a save that omits `refs` wipes the reference and verification history **with no audit and no undo** | `AppDataController.php:3021, :3055-3071` (DEF-03, HIGH) |
| A biometric device id can be **silently stolen from another employee**: a second save with `force` NULLs the previous holder's `device_user_id` and re-keys their punches, with **no audit row and no notification to the losing employee** | `BiometricConfigController.php:617-640` (NEW-EMP-072) |
| `cleanOrphanCommissions` **hard-deletes rows from three tables with no record of any kind** | `RequestController.php:1254-1259` (SEC-22) |

### XXIII.1.8 Actors are recorded as display-name strings

`decided_by`, `entered_by`, `by`, `accounts_by`, `locked_by` and `bounced_by` all hold a
display name rather than a user id (`RequestController.php:869, :929, :1036, :1285, :1406,
:1656, :1736`; `LeaveController.php:420`; `E-…` NEW-AUD-107). A renamed or deleted user
makes the trail ambiguous, and two employees with the same name are indistinguishable.

Employee identity resolution compounds it: identity is resolved **by name match with
`->first()` and no ordering** in places (`E-…` requirement 98, SEC-23).

### XXIII.1.9 A "tamper-evident" reference that is deterministic within a day

The RBI agent audit pack — the artefact a recovery agency hands to a client bank, and the
product's clearest vertical differentiator — carries a reference and a hash:

```
$ref  = "RAC/{Y}/{EMP_CODE}"
$hash = substr(sha256("$ref|{$e->id}|{$today->toIso8601String()}"), 0, 16)
```

`ComplianceController.php:643-644`. There is **no secret, no persistence and no table that
stores an issued report** (`N-…` V-04). Because `$today = Carbon::today()`,
`toIso8601String()` is midnight, so **the hash is deterministic within a calendar day** and
changes daily (`N-…` DISC-04, correcting the reference documentation's own description of
the mechanism). A bank cannot verify a presented PDF, and Ametecs cannot prove what a given
report said. A forged report is indistinguishable from a genuine one (`N-…` D-06).

### XXIII.1.10 Auditing is best-effort, and there are two audit tables

`Audit::record()` swallows every exception silently at `Audit.php:97-99`. A silent audit gap
is therefore possible with no alert (`E-…` NEW-AUD-105).

And there are two audit tables whose names differ by one character. `activity_logs`
(plural) is the real trail, created by migration
`2026_01_01_000700_create_salary_slips_table.php:13-23` — note the migration name — with
`prev_hash` and `row_hash` added at **runtime** by `Audit::ensure():25-46`. `activity_log`
(singular) is Spatie `laravel-activitylog`, installed, migrated three times
(`2026_05_30_025626/7/8`), configured with stock settings, and **never written to by
anything** (`E-…` NEW-AUD-110). Its retention setting
(`delete_records_older_than_days = 365`) applies to the permanently empty table, and its
clean command is not scheduled (`E-…` NEW-NOT-117).

`activity_logs` has **no `updated_at` and no database-level immutability** — no trigger, no
append-only constraint, no separate credentials. Any `UPDATE` or `DELETE` at the database
level is possible; the chain only *detects* it, and only if the chain is intact
(`E-…` §7 Q8 point 3). `commission_logs` has no chain at all — its immutability is a
comment.

Finally, **no retention policy exists** against a published Privacy Policy that commits to
ninety days (`PolicyController.php:12`; `E-…` SEC-18).

### XXIII.1.11 Defect-to-design map

| SmartPRS 2 behaviour | SmartPRS 3 response | Where |
|---|---|---|
| Four direct inserts break the chain | Direct insert is **structurally impossible**: no privilege, no model, no nullable hash, CI sweep | §XXIII.3 |
| `verify()` resets to `GENESIS` at a null hash | **There is no skip branch.** An unhashable row cannot exist; an unverifiable row is a break | R-XXIII-027, R-XXIII-060 |
| Read-then-insert race | Sequence allocation under a per-tenant head lock, inside the transaction; batch append | §XXIII.2.4 |
| Global chain across tenants | **One chain per tenant** | R-XXIII-006 |
| `verify()` loads the whole table | Incremental, checkpointed, range-bounded verification | §XXIII.7.1 |
| `AuditTrail::change()` unused | Diffs produced by the **write service**, never by call sites | R-XXIII-041 |
| Loose comparison hides changes | Strict, type-aware comparison with declared normalisation | R-XXIII-047 |
| ESS self-edits unaudited | Every surface routes through the one write service | R-XXIII-042 |
| Only successful login audited | Full IAM coverage including failure and privilege change | §XXIII.4.2 |
| `storeEmployee()` writes no audit row | Impossible: the write service emits the audit row in the same transaction | R-XXIII-042 |
| Actors as name strings | Actor is a **user id**, with a denormalised display name | R-XXIII-011 |
| Deterministic, unpersisted, unkeyed report hash | Issued artefacts are persisted, content-hashed, HMAC-keyed and verifiable | R-XXIII-035 |
| Best-effort auditing | **Audit failure aborts the business transaction** | R-XXIII-015 |
| Two audit tables, one empty | One ledger. Spatie is removed | §XXIII.14 |
| Hard deletes with no record | Hard delete is prohibited; every deletion is a soft delete with an audit row | R-XXIII-073 |
| No retention, against a published 90-day commitment | Declared retention class, legal hold, audited purge | §XXIII.8 |
| No read auditing | Sensitive reads are audited | §XXIII.4.6 |

---

## XXIII.2 The audit ledger

### XXIII.2.1 What the ledger is, and what it is not

**R-XXIII-001** The audit ledger is an **append-only, hash-chained, per-tenant record of
who did what, when, from where, to which entity, with which values before and after, under
which authority, and why.** It is evidence. It is not a cache, not a history mechanism, not
a change-data-capture stream, and not a debugging log.

**R-XXIII-002** The ledger is **not** the mechanism by which the application answers
questions about past state. That is effective-dated data (§XXIII.6). A design that requires
replaying the ledger to know what something was is a defective design and is rejected at
review.

**R-XXIII-003** Three record classes exist in one ledger, distinguished by a column, so
that a single verification covers all of them:

| Class | Examples |
|---|---|
| `mutation` | any create, update, state transition or deletion of a business or configuration record |
| `access` | a read of a classified resource (§XXIII.4.6) |
| `system` | a scheduled action, an engine decision recorded for defensibility, an integrity event, an administrative operation |

### XXIII.2.2 Schema of a ledger entry

Spine S14 fixes the field set. This part types it.

```
audit_entries
  -- identity and chain
  id                bigint unsigned      internal, monotonic within the partition
  uuid              char(36)             UUIDv7, the only external identifier
  tenant_id         bigint unsigned      NOT NULL              -- Law 6
  seq               bigint unsigned      NOT NULL              -- per-tenant, gapless
  prev_hash         char(64)             NOT NULL              -- never nullable
  hash              char(64)             NOT NULL
  hash_algo         varchar(16)          NOT NULL              -- 'sha256' at v3.0
  chain_epoch       int unsigned         NOT NULL              -- see R-XXIII-008

  -- who
  actor_type        varchar(24)          NOT NULL   user | system | integration | support
  actor_user_id     bigint unsigned      NULL       -- NOT NULL when actor_type='user'
  actor_display     varchar(160)         NOT NULL   -- denormalised at write time
  on_behalf_of_user_id bigint unsigned   NULL       -- impersonation subject
  actor_role_ids    json                 NOT NULL   -- roles held AT THE TIME
  actor_scope       json                 NOT NULL   -- data scope held AT THE TIME
  api_key_id        bigint unsigned      NULL
  support_grant_id  bigint unsigned      NULL       -- Part VI time-boxed support access

  -- when and where
  occurred_at       datetime(6)          NOT NULL   -- UTC, microsecond
  business_date     date                 NOT NULL   -- tenant business date
  tenant_tz         varchar(64)          NOT NULL
  ip                varbinary(16)        NULL       -- binary, v4 and v6
  user_agent_hash   char(64)             NULL
  device_ref        varchar(120)         NULL
  session_ref       char(36)             NULL
  request_id        char(36)             NOT NULL
  correlation_id    char(36)             NOT NULL
  origin            varchar(24)          NOT NULL   ui | api | mobile | job | console
                                                    | webhook | import | migration

  -- what
  record_class      varchar(16)          NOT NULL   mutation | access | system
  action            varchar(120)         NOT NULL   -- from the closed action catalogue
  entity_type       varchar(64)          NOT NULL
  entity_id         bigint unsigned      NULL
  entity_uuid       char(36)             NULL
  entity_label      varchar(200)         NULL       -- denormalised, for readability
  parent_entity_type varchar(64)         NULL       -- e.g. payroll_run for a payslip line
  parent_entity_id  bigint unsigned      NULL

  -- the change
  change_ref        bigint unsigned      NULL       -- FK -> audit_field_changes batch
  change_digest     char(64)             NULL       -- hash of the full diff (in the chain)
  old_value         json                 NULL       -- whole-record snapshot, see R-XXIII-034
  new_value         json                 NULL

  -- authority and provenance
  reason            varchar(500)         NULL       -- required for declared actions
  reason_code       varchar(48)          NULL       -- from a value set where declared
  workflow_instance_id bigint unsigned   NULL
  workflow_step_id  bigint unsigned      NULL
  approval_ref      varchar(120)         NULL
  policy_ref        varchar(120)         NULL       -- the policy that authorised it
  rule_version_id   bigint unsigned      NULL
  config_version_id bigint unsigned      NULL
  rulepack_version_id bigint unsigned    NULL
  trace_id          char(36)             NULL       -- the S15 explainability trace

  -- governance
  severity          varchar(16)          NOT NULL   info | notable | sensitive | critical
  retention_class   varchar(32)          NOT NULL
  legal_hold        tinyint(1)           NOT NULL DEFAULT 0
  pii_classes       json                 NOT NULL   -- classes touched, for access control
```

**R-XXIII-004** `prev_hash` and `hash` are **`NOT NULL`**. This single constraint is what
makes SmartPRS 2's failure mode unrepresentable: there is no such thing as a ledger row
without a chain position, so there is nothing for a verifier to "skip".

**R-XXIII-005** There is **no `updated_at`, no `deleted_at`, and no `UPDATE` or `DELETE`
grant** on this table for any application role. *SmartPRS 2's `activity_logs` has no
`updated_at` either, but also has no privilege separation and no immutability constraint —
the chain merely detects, and only when intact (`E-…` §7 Q8 point 3).*

### XXIII.2.3 The hash chain

**R-XXIII-006** The chain is **per tenant**, keyed `(tenant_id, chain_epoch)`. Verifying
one tenant reads only that tenant's rows, and one tenant's break is one tenant's break.
*This replaces `Audit.php:91`, which takes the head across the entire table.*

**R-XXIII-007** The hash is computed over a **canonical, length-prefixed serialisation** of
a declared field list — not over a delimiter-joined string. Field order, encoding and null
representation are fixed by a versioned canonicalisation specification carried in
`hash_algo`.

```
hash = SHA-256( canon(
        prev_hash, tenant_id, seq, chain_epoch,
        actor_type, actor_user_id, on_behalf_of_user_id, api_key_id, support_grant_id,
        occurred_at (microsecond, UTC, ISO-8601), business_date,
        ip, session_ref, request_id, correlation_id, origin,
        record_class, action, entity_type, entity_id, entity_uuid,
        parent_entity_type, parent_entity_id,
        change_digest, old_value_digest, new_value_digest,
        reason, reason_code,
        workflow_instance_id, workflow_step_id, approval_ref, policy_ref,
        rule_version_id, config_version_id, rulepack_version_id,
        severity, retention_class, pii_classes ) )
```

Length-prefixing matters: SmartPRS 2 joins with `|` (`Audit.php:49-61`), so two different
field sets can produce the same payload string. Canonical length-prefixed encoding removes
that ambiguity.

**R-XXIII-008** `chain_epoch` increments only when the platform performs a **declared,
audited chain migration** — a hash-algorithm upgrade, or a re-anchoring after a verified
archival seal (§XXIII.8.3). An epoch boundary is itself a signed record naming the previous
epoch's terminal hash. **An epoch boundary is never created by a failure**, and no
application code path can create one.

**R-XXIII-009** The genesis row of each `(tenant, epoch)` has `prev_hash` set to a
**declared genesis constant that includes the tenant id and the epoch**, so a row from one
tenant's chain cannot be transplanted into another's and still verify. *SmartPRS 2 uses the
bare literal `'GENESIS'` for every chain start (`Audit.php:91`).*

**R-XXIII-010** `occurred_at` is `datetime(6)`. *SmartPRS 2 uses
`now()->toDateTimeString()` — second resolution (`E-…` §4.9) — which is adequate only
because `prev` distinguishes rows; microsecond resolution removes the ambiguity for
forensic ordering.*

**R-XXIII-011** The actor is recorded as a **user id**, with `actor_display` denormalised
at write time for readability, plus the roles and data scope held **at that moment**.
*SmartPRS 2 records display-name strings across at least eight columns
(`RequestController.php:869, :929, :1036, :1285, :1406, :1656, :1736`;
`LeaveController.php:420`), so a renamed or deleted user makes the trail ambiguous.*
Recording the roles held at the time is what makes "was this person entitled to do this?"
answerable years later, after their roles have changed.

### XXIII.2.4 Appending — how the race is removed

**R-XXIII-012** A ledger append is performed **inside the business transaction**, in this
order:

```
1. BEGIN                        (the business transaction, already open)
2. SELECT head_seq, head_hash
     FROM audit_chain_heads
    WHERE tenant_id = ? AND chain_epoch = ?
      FOR UPDATE                -- the serialisation point, per tenant
3. for each entry in this transaction's audit batch (ordered):
        seq       := ++head_seq
        prev_hash := head_hash
        hash      := SHA-256(canon(...))
        head_hash := hash
        INSERT INTO audit_entries (...)
4. UPDATE audit_chain_heads SET head_seq, head_hash, updated_at
5. COMMIT                       -- entries and business rows commit together
```

**R-XXIII-013** The head lock is acquired **once per transaction**, not once per entry, and
a transaction that writes N audit rows chains all N under that one acquisition. A payroll
run finalising ten thousand payslips takes the lock once.
*ponytail: one head row per tenant is a serialisation point. At the sizing of §XXIII.8.1 —
roughly 0.2 appends per second average and a few hundred per second at payroll peak, all
batched — it is not a bottleneck. If a tenant ever exceeds it, the upgrade path is declared:
shard to `chain_key = (tenant_id, shard_no)` with the daily anchor computing a Merkle root
across shards. **No change to the verifier's contract is required**, which is why the
simple form is correct now.*

**R-XXIII-014** `(tenant_id, chain_epoch, seq)` is **uniquely constrained**, so a lost
update on the head row cannot produce two rows at the same sequence. The database enforces
gaplessness, not the application.

**R-XXIII-015** **An audit write failure aborts the business transaction.** There is no
best-effort path, no `catch` that continues, and no configuration that relaxes this.
*SmartPRS 2 swallows every exception in `Audit::record()` at `Audit.php:97-99`, and
`ApprovalService.php:296-298` lets the business action succeed without its audit row after
a `Log::warning`. SmartPRS 3 does not replicate this: an action that cannot be recorded
does not happen.* (Spine S5.6's prohibition on silent catches in a domain path; `L-…`
requirement 44: no control whose purpose is a legal obligation may be best-effort.)

**R-XXIII-016** Appends are **never queued and never asynchronous**. A queued audit row is
a row that may not exist, and Part XIX §XIX.1.1 is the evidence of what happens when a
durable claim depends on a worker nobody installed.

### XXIII.2.5 The daily signed anchor

**R-XXIII-017** For each tenant, at the end of each **tenant-local business day**, the
platform computes and signs an **anchor**:

```
audit_anchors
  tenant_id, chain_epoch, business_date,
  first_seq, last_seq, entry_count,
  first_hash, last_hash,
  merkle_root            -- over the day's entry hashes, ordered by seq
  prev_anchor_hash       -- anchors are themselves chained
  anchor_hash
  algo, canon_version
  signed_at, signature, signing_key_id, key_algo
  external_notarisation_ref   NULL
  status                 sealed | pending | failed
```

**R-XXIII-018** The anchor is **signed with a private key that is not available to the
application at request time.** The anchoring job holds it; ordinary request paths do not.
Installations carry the public verification key.

**R-XXIII-019** Anchors are **themselves chained** (`prev_anchor_hash`), so a break in the
anchor sequence is detectable independently of the entry chain. A break in the entry chain
is then **localised to a single business date** by comparing anchors, without re-verifying
the whole ledger — which is the operational point of anchoring.

**R-XXIII-020** Where connectivity permits and a support agreement provides for it, the
anchor hash — **and only the anchor hash, never any content** — may be counter-notarised by
an Ametecs anchor service, so that a compromised installation cannot silently re-sign its
own history. **PO-XXIII-03** decides whether this is default, optional or absent.

**R-XXIII-021** A missing, failed or late anchor raises `audit.anchor_missing` — a
critical, non-suppressible operational event routed through Part XIX §XIX.7.5 on an
out-of-band channel.

---

## XXIII.3 The single write path — why a direct insert is impossible

Spine S14 states that every write path goes through the audit service *"because that is
exactly how SmartPRS 2's chain is already broken in four places."* Stating it is not
enough. SmartPRS 2 also intended it — `Audit::record()` exists and is the documented way —
and four developers wrote `DB::table('activity_logs')->insert(...)` anyway. The requirement
is therefore that the direct insert be **impossible**, by five independent mechanisms, no
one of which is trusted alone.

**R-XXIII-022 — Mechanism 1: database privilege.**
`audit_entries`, `audit_field_changes` and `audit_anchors` are owned by a dedicated
database role. The application's ordinary connection holds **`SELECT` only** on them. A
second, narrowly scoped connection — used by `AuditService` and by nothing else — holds
`INSERT`. **No role in any environment holds `UPDATE` or `DELETE`** on these tables; purge
and archival run under a separate, audited maintenance role that is not available to the
application (§XXIII.8.3).

*Note:* the append must commit atomically with the business write (R-XXIII-012), so the
`INSERT` grant is on the same connection as the business transaction, distinguished by
database role rather than by connection. Where the deployment target cannot express
per-table privilege on a shared connection, Mechanism 2 becomes load-bearing and the
installer records which mechanism is active in the health report. **PO-XXIII-01** records
this platform decision.

**R-XXIII-023 — Mechanism 2: schema constraint.**
`prev_hash`, `hash`, `seq` and `chain_epoch` are `NOT NULL`, and `(tenant_id, chain_epoch,
seq)` is unique. A caller who does not compute the chain **cannot produce a valid row**.
The SmartPRS 2 shape — an insert with no hash — is rejected by the database.

**R-XXIII-024 — Mechanism 3: no model, no query builder access.**
There is no Eloquent model, no repository and no query-builder helper for these tables
outside `Domain\Audit`. A CI sweep fails the build on any occurrence of the table names,
or of a query-builder call against them, anywhere else in the codebase — including tests
that are not in the audit test namespace.

**R-XXIII-025 — Mechanism 4: trigger.**
`BEFORE UPDATE` and `BEFORE DELETE` triggers on `audit_entries` raise. This catches the
path privilege cannot: a database session held by an operator. It does not stop a
privileged operator with `SUPER`, and is not claimed to; it makes casual and accidental
mutation impossible and deliberate mutation noisy.

**R-XXIII-026 — Mechanism 5: verification.**
Everything above can be defeated by someone with sufficient database privilege. The chain,
the anchors and the external notarisation are what make defeat **detectable and
attributable to a date** (§XXIII.7). Detection is the last line, not the first.

**R-XXIII-027** **There is no `verify()` skip branch, at any version, ever.** An entry that
does not chain is a **break**, reported as a break. The verifier has no code path that
re-anchors, resets, or treats any row as legacy. *This is a one-line requirement standing
in for the single most damaging line in SmartPRS 2's audit implementation
(`Audit::verify():118-121`).*

**R-XXIII-028** The **only** legitimate producers of audit entries are:

| Producer | Emits |
|---|---|
| `EntityWriteService` (spine S9, Part X) | every entity mutation, with field-level diffs |
| `ConfigVersionService` (Part VI) | every configuration lifecycle transition |
| `WorkflowEngine` (Part IX) | every workflow transition, with the entity snapshot hash |
| `AuthorisationService` (spine S13) | authentication, authorisation and privilege events |
| `AccessAuditInterceptor` | classified reads (§XXIII.4.6) |
| `PayrollRunService`, `PeriodLockService`, and the equivalent lifecycle services | run and period state transitions |
| `RulePackService` (Part XVII) | pack activation and override |
| `MaintenanceService` | purge, archival, legal hold, restore, migration |

Every one of them calls `AuditService::append()`. Nothing else may.

**R-XXIII-029** `AuditService::append()` **validates its input against the action
catalogue** (§XXIII.4.1) and rejects an unknown action, a missing mandatory reason, a
missing diff on an action declared `requires_field_diff`, or a PII class not declared on
the entity. An invalid append is an exception, and by R-XXIII-015 it aborts the business
transaction. *This is deliberate: it is better for a release to fail loudly in staging on a
mis-declared action than to ship a code path that writes an unusable audit row.*

---

## XXIII.4 Coverage — the catalogue of audited actions

### XXIII.4.1 The action catalogue is data

**R-XXIII-030** Every auditable action is a row in a **platform-owned, versioned action
catalogue**, declaring:

```
audit_action_definitions
  action_key            e.g. employee.salary.changed
  module, entity_type, record_class
  label, business_description
  severity_default
  requires_reason           0|1   -- append is rejected without one
  reason_value_set          NULL | a value set key
  requires_field_diff       0|1
  requires_approval_ref     0|1   -- must name a workflow instance
  captures_old_new          none | changed_fields | full_snapshot
  sensitive_field_policy    per §XXIII.5.4
  retention_class
  is_system                 1 for platform-declared actions
```

**R-XXIII-031** An action emitted by code but absent from the catalogue **fails the build**.
An action in the catalogue that no code path emits **fails the build**. *Test:* a CI sweep
compares both directions, the same discipline Part XIX applies to the event catalogue.

### XXIII.4.2 Coverage — brief Section 74 plus what the analysis found

The Section 74 set, extended. Every row marked **[GAP]** is unaudited in SmartPRS 2 today,
with the evidence cited.

**Identity, access and authority**

| Action | Notes |
|---|---|
| `auth.login.succeeded` | audited today (`AuthController.php:271-284`) — the only one |
| `auth.login.failed` · `auth.logout` · `auth.lockout` · `auth.mfa.enrolled` / `.reset` / `.challenge_failed` | **[GAP]** (`G-…` NEW-SEC-113, SEC-21) |
| `auth.password.changed` / `.reset_requested` / `.set_by_admin` | **[GAP]** — an `hr_manager` may set any user's password including the tenant admin's, unaudited (`UserController.php:262-278`; `G-…` SEC-07) |
| `user.created` / `.disabled` / `.enabled` / `.deleted` | **[GAP]** (`G-…` D-18) |
| `role.assigned` / `.revoked` / `.created` / `.permissions_changed` | **[GAP]** — and the permission matrix itself lives in a user-writable JSON blob (`AppStateController.php:40-52`; `G-…` SEC-03) |
| `data_scope.assigned` / `.revoked` | **[GAP]** |
| `apikey.created` / `.rotated` / `.revoked` / `.scope_changed` / `.used` | `.used` is sampled, not per-call (§XXIII.4.7) |
| `impersonation.started` / `.ended` | every entry during impersonation carries `on_behalf_of_user_id` |
| `support_grant.granted` / `.exercised` / `.expired` / `.revoked` | Part VI's time-boxed consultant access |
| `session.terminated_by_admin` | |

**Employee and person data**

| Action | Notes |
|---|---|
| `employee.created` / `.updated` / `.archived` / `.restored` / `.deleted` | **[GAP]** — `storeEmployee()` writes no audit row at all (`AppDataController.php:2718-3089`; `D-…` §) |
| `employee.assignment.changed` | org, designation, grade, shift, cost centre — the effective-dated assignments of spine S3 |
| `employee.status.changed` | with the transition and its guard |
| `employee.bank.changed` · `.statutory_ids.changed` · `.pan.changed` · `.aadhaar.changed` | **maker-checker**, sensitive-field policy applies |
| `employee.salary.changed` · `.structure_assigned` | field diff mandatory |
| `employee.document.uploaded` / `.replaced` / `.deleted` / `.verified` | |
| `employee.reference.added` / `.removed` | **[GAP]** — today references are deleted on every save with no audit and no undo (`AppDataController.php:3021, :3055-3071`; DEF-03) |
| `employee.biometric_mapping.changed` / `.reassigned` | **[GAP]** — today a device id can be silently taken from another employee, re-keying their punches, with no audit and no notification (`BiometricConfigController.php:617-640`) |
| `ess.profile.change_submitted` / `.applied` / `.rejected` | **[GAP]** — `EssController::updateProfile()` makes no audit call of any kind (`E-…` DEF-ESS-02) |
| `ess.contact.verification_sent` / `.verified` | the payslip delivery address is a controlled field (`E-…` SEC-08) |

**Attendance, leave and time**

| Action | Notes |
|---|---|
| `punch.manual_entry` / `.corrected` / `.quarantined` / `.released` | corrections are overlays (spine S10) |
| `attendance.day.overridden` / `.recalculated` | records the rule versions applied |
| `attendance.period.locked` / `.reopened` | reopening is workflow-gated |
| `attendance.dedupe.quarantined` | never a deletion (spine S10) |
| `leave.application.*` · `leave.balance.adjusted` · `leave.ledger.corrected` | adjustment requires a reason |
| `roster.published` / `.overridden` | |

**Payroll and money**

| Action | Notes |
|---|---|
| `payroll.run.created` / `.previewed` / `.submitted` / `.approved` / `.finalised` / `.reopened` / `.regenerated` | **[GAP in part]** — SmartPRS 2 logs only regeneration (`N-…` requirement 37) |
| `payroll.input.overridden` | with old and new, and a mandatory reason |
| `payslip.published` / `.superseded` / `.viewed` / `.downloaded` | `.viewed` is an `access` record |
| `payroll.exception.overridden` | which preview exception, and by whose authority |
| `bank_file.generated` / `.downloaded` / `.marked_paid` | |
| `loan.sanctioned` / `.rescheduled` / `.written_off` · `advance.*` · `recovery.*` | |
| `commission.approved` / `.edited_after_approval` / `.paid` / `.locked` / `.clawback_raised` | **[GAP]** — today the diff goes to `commission_logs` as an English sentence outside the chain, and payments, locks and Accounts decisions never reach the ledger (`RequestController.php:870, :943, :1289, :1663`; `E-…` §7 Q8) |
| `statutory.deduction.overridden` · `tax.declaration.submitted` / `.proof_verified` | |

**Configuration, rules and publication** *(the additions this part makes explicit)*

| Action | Notes |
|---|---|
| `config.version.created` / `.submitted` / `.approved` / `.published` / `.superseded` / `.rolled_back` | pins `config_version_id` |
| `rules.published` / `.deactivated` | pins `rule_version_id` |
| `workflow.published` / `.instance_migrated` | |
| `template.published` / `.superseded` | Part XIX |
| `subscription.published` / `.activated` / `.deactivated` | Part XIX |
| `formula.published` · `structure.published` · `report_definition.published` | |
| `rulepack.downloaded` / `.signature_verified` / `.activated` / `.rejected` / `.auto_accept_policy_changed` | Part XVII |
| `statutory.baseline_breach.overridden` | with the recorded compliance exception |
| `field_definition.created` / `.changed` / `.retired` | Part X; consulted by Part XIX R-XIX-015 |
| `value_set.value_added` / `.deactivated` | |
| `scope_weights.changed` | platform-only; changes every historical resolution |

**Workflow and approvals**

| Action | Notes |
|---|---|
| `workflow.instance.started` / `.step_assigned` / `.decided` / `.delegated` / `.reassigned` / `.escalated` / `.returned` / `.cancelled` / `.finalised` | each carries the **entity snapshot hash** (spine S8) |
| `workflow.self_approval.blocked` | an attempted control breach is evidence |
| `maker_checker.pair_recorded` | maker and checker, both, on one row |

**Data movement**

| Action | Notes |
|---|---|
| `export.requested` / `.generated` / `.downloaded` | row count, columns, filters, scope, file hash |
| `import.uploaded` / `.previewed` / `.committed` / `.rolled_back` | source file hash, mapping, row counts, rejects |
| `document.viewed` / `.downloaded` / `.link_generated` / `.link_used` | `access` records |
| `report.executed` / `.exported` / `.scheduled` | **[GAP]** — SmartPRS 2 bypasses its own audit service in the report whose job is to verify the chain (`H-…` requirement 47, BR-RPT-012) |
| `backup.created` / `.restored` / `.restore_tested` / `.downloaded` | |
| `purge.executed` / `.anonymisation_executed` | itself audited, always |

**Platform, tenancy and vertical**

| Action | Notes |
|---|---|
| `tenant.provisioned` / `.suspended` / `.reactivated` / `.settings_changed` | **[GAP]** (`G-…` D-18) |
| `licence.activated` / `.expired` / `.overridden` · `subscription.*` | |
| `integration.credential_set` / `.rotated` / `.revoked` | value never recorded |
| `webhook.endpoint_registered` / `.secret_rotated` | |
| `device.registered` / `.approved` / `.rejected` / `.revoked` | **[GAP]** — today the row is stamped and no ledger entry is written (`E-…` §7 Q8) |
| `broadcast.sent` | **[GAP]** — today one aggregate `messages_log` row and no ledger entry |
| `compliance.gate_blocked` / `.overridden` | the DRA money gate (spine S19 item 1) |
| `audit_pack.issued` / `.verified` | §XXIII.4.5 |
| `policy.acknowledged` | with the policy version acknowledged |
| `credential.verified` / `.rejected` | **[GAP]** — today "Mark Verified" performs no checks and writes no audit row (`D-…` §, `hrVerify():570-582`) |

**Integrity**

| Action | Notes |
|---|---|
| `audit.verification_run` / `.chain_break_detected` / `.anchor_sealed` / `.anchor_missing` | §XXIII.7 |
| `audit.exported` / `.read` / `.legal_hold_placed` / `.released` | §XXIII.9 |
| `audit.epoch_migrated` | the only path that changes `chain_epoch` |

### XXIII.4.3 Configuration publication is audited as a first-class action

**R-XXIII-032** Publishing configuration, a rule, a workflow, a template, a formula, a
report definition or a statutory pack writes a ledger entry pinning the version id, the
scope, the effective range, the impacted-employee count computed at publish, the approver,
and the diff digest. *This exists because SmartPRS 2 has five parallel configuration stores,
four of them entirely unaudited and none but `statutory_configs` effective-dated
(`H-…` requirement 49), and because every rate change in the legacy statutory blob is an
`updateOrInsert` with no trail (`F-…` DEFECT-F-02, SEC-F-03).*

### XXIII.4.4 Data export, document access and payslip view

**R-XXIII-033** An export writes **one** entry naming the report or dataset, the columns,
the filters, the resolved scope, the row count, the destination and the **hash of the
generated file**. Downloading the generated file writes a second `access` entry.
*SmartPRS 2 audits the employee export (`EmployeeExportController.php:75-122, :147-154`) —
one of the few places it does — but a super admin's export crosses every tenant in one file
because `->when($tid, …)` skips the filter for a null tenant (`D-…` SEC-09).*

**R-XXIII-034** Viewing a payslip, a document, a ledger, a colleague's record, a statutory
register or an audit trail is an `access` entry. *This exists because the highest-impact
findings in SmartPRS 2 are **read** paths — the statutory register PDF exposes every
employee's PAN and UAN to any login (`AppDataController::statutoryPdf:1642-1645`), and
`GET /app/documents-mgr/{id}/download` grants every role with no ownership test
(`DocumentController::download:263-283`) — and none of them leaves a trace
(`G-…` requirement 24).*

### XXIII.4.5 Issued artefacts are persisted and verifiable

**R-XXIII-035** Any artefact issued to a third party as evidence — the RBI agent audit
pack, a compliance certificate, a payslip PDF, a Form 16, an experience letter — is
**persisted as issued**, with an issued-artefact row carrying: the artefact content hash,
the template version, the data snapshot reference, the issuer, the issue timestamp, and a
reference whose verification code is an **HMAC keyed with a per-tenant secret**, not a bare
truncated hash of public inputs. A public verification endpoint confirms whether a presented
reference and content hash match an issued artefact.

> *This exists because SmartPRS 2's audit-pack reference is
> `substr(sha256("RAC/{Y}/{EMP_CODE}|{id}|{today}"), 0, 16)` with **no secret, no
> persistence and no issued-report table** (`ComplianceController.php:643-644`), and because
> `$today` is midnight so the value is **deterministic within a calendar day**
> (`N-…` V-04, DISC-04). A bank cannot verify a presented report and Ametecs cannot prove
> what any issued report said.*

### XXIII.4.6 Read auditing, bounded

**R-XXIII-036** Read auditing applies to a **declared classified set**, not to everything.
The set is platform-declared and tenant-visible: payslip and salary detail, bank details,
statutory identifiers, employee documents, another employee's record, leave balances of
others, the audit trail itself, statutory registers, export downloads, and any field whose
`pii_class` is `sensitive` or above.

**R-XXIII-037** A read entry records **one row per query**, naming the resource class, the
filter, the resolved scope and the **row count** — never one row per record returned. A
directory listing of five hundred employees writes one entry, not five hundred.
*Volume discipline; §XXIII.8.1 depends on it.*

**R-XXIII-038** Repeated identical reads by the same actor within a declared window are
**coalesced into one entry with an occurrence count**, so a user pressing refresh does not
generate a thousand rows. The window is configuration.

### XXIII.4.7 High-frequency actions

**R-XXIII-039** Three action families are explicitly **not** audited per occurrence,
because doing so would produce noise rather than evidence, and each has a declared
alternative:

| Not audited per occurrence | Recorded instead |
|---|---|
| Every punch ingested | `punch_events` is itself append-only and immutable (spine S10); only manual entry, correction, quarantine and release are audited |
| Every derived attendance day and payslip line | Derived records are **superseded, never deleted**, and carry the rule and formula version ids (spine Law 3); only overrides and reopens are audited |
| Every API key call | Sampled plus aggregated per key per hour; **every** call that mutates, exports or reads a classified resource is audited individually |

**R-XXIII-040** The boundary in R-XXIII-039 is **declared in the action catalogue and
visible to the customer**, so nobody is surprised in an audit conversation by what is not
in the ledger.

---

## XXIII.5 Field-level before/after capture

### XXIII.5.1 Produced by the write service, never by call sites

**R-XXIII-041** Field-level diffs are computed by the **write service** from the loaded
aggregate and the persisted state, using the field metadata of Part X. **No call site ever
passes a before or an after value.**

> *This is the whole lesson of `AuditTrail::change()`. SmartPRS 2 has a correct helper with
> the correct signature, and it has **zero call sites** in requests, leave and self-service
> (`AuditTrail.php:78-91`; `E-…` NEW-AUD-109). A capture mechanism that depends on every
> developer remembering to call it will not be called. Making it the write service's
> responsibility means a call site cannot omit it, because a call site never sees it.*

**R-XXIII-042** The services that own writes — `EntityWriteService` (spine S9),
`ConfigVersionService`, `WorkflowEngine`, and the domain lifecycle services — are the only
paths that mutate their aggregates (spine S9: *"One write service… Every surface calls
it"*). Diff production is a stage of that pipeline, between persistence and event emission.
A new surface — mobile, API, import, ESS, recruitment conversion — inherits auditing by
using the service, and has no way to bypass it.

**R-XXIII-043** Which entities **require** field-level capture is declared, not assumed:

| Entity | Capture | Rationale |
|---|---|---|
| Employee identity, contact, statutory ids, bank | `changed_fields`, mandatory | spine S14 |
| Salary structure assignment and component values | `changed_fields`, mandatory | dispute and defence |
| Role, permission, data scope | `changed_fields`, mandatory | privilege forensics |
| Statutory configuration and rule-pack overrides | `changed_fields`, mandatory | spine S14 |
| Rules, formulas, workflows, templates, report definitions | `full_snapshot` digest + structured diff | a rule diff is a tree, not a field list |
| Leave balance adjustments, loan reschedules, recoveries | `changed_fields`, mandatory | money |
| Payroll input overrides | `changed_fields` + mandatory reason | money |
| Attendance day overrides | `changed_fields` + rule versions | pay consequence |
| Org structure and effective-dated assignments | `changed_fields`, mandatory | S3 |
| Devices, integrations, webhooks | `changed_fields`; secrets by policy | |
| Derived records (attendance days, payslip lines) | `none` | superseded, not updated (R-XXIII-039) |

### XXIII.5.2 The diff record

**R-XXIII-044** Diffs are stored in a **typed child table**, not as prose:

```
audit_field_changes
  id, tenant_id, audit_entry_id (FK, ON DELETE RESTRICT),
  field_path        varchar(200)   -- 'bank.account_number', 'components.HRA.amount'
  field_definition_id bigint unsigned NULL   -- Part X, where the field is metadata-defined
  data_type         varchar(24)
  pii_class         varchar(24)
  treatment         varchar(24)    -- plain | masked | hashed | suppressed
  old_display       varchar(500) NULL
  new_display       varchar(500) NULL
  old_hash          char(64)     NULL
  new_hash          char(64)     NULL
  old_numeric       decimal(18,4) NULL   -- typed, for range queries on money and units
  new_numeric       decimal(18,4) NULL
  currency          char(3)      NULL
  source_surface    varchar(32)  -- ui | api | ess | mobile | import | job | migration
  ordinal           smallint
```

PK `id` · Unique `(tenant_id, audit_entry_id, field_path, ordinal)` ·
Index `(tenant_id, field_definition_id, occurred_at)` — "show me every change to bank
account, ever" · Index `(tenant_id, field_path, new_numeric)` — "show me every salary
change over ₹1,00,000".

**R-XXIII-045** `change_digest` on the parent entry is the hash of the canonical
serialisation of the entry's complete diff set, and it is **inside the chain hash**
(R-XXIII-007). A diff row cannot be altered without breaking the chain, even though it
lives in a separate table.

**R-XXIII-046** A diff is **queryable and comparable**, with typed numeric columns
alongside display strings. *SmartPRS 2's only diff is an English sentence built by string
concatenation, capped at 1,000 characters, outside the chain
(`RequestController.php:807-871`; `ApprovalService.php:292`).*

**R-XXIII-047** Comparison is **strict and type-aware**, with declared normalisation per
data type (trailing-space trimming for text, scale normalisation for decimals, timezone
normalisation for instants). `'0'`, `0`, `false` and `null` are four different values.
*SmartPRS 2 uses `$old != $v` (`AuditTrail.php:78-91`), so `'0'` versus `0` registers as
unchanged.*

**R-XXIII-048** An action declared `requires_field_diff` whose computed diff is **empty**
still writes an entry, recording that the operation occurred and changed nothing. *SmartPRS
2 returns early and writes **no log at all** when the diff is empty
(`AuditTrail.php:78-91`), so "somebody opened and saved this record" is invisible.*

### XXIII.5.3 The audit-of-record versus the entity's own state

**R-XXIII-049** The diff records **what the write service changed**, not what the payload
contained. A PATCH that supplies a field with an unchanged value produces no diff row for
that field but is still recorded as an update. *This matters because SmartPRS 2's employee
save is a full-record replace that nulls every omitted key (spine S9), so a payload-based
diff would report the entire record as changed on every save.*

### XXIII.5.4 Sensitive values — recorded usefully, never in plaintext

**R-XXIII-050** Each field's `pii_class` determines its `treatment`, declared in the field
metadata (Part X) and applied by the write service:

| Class | Examples | Treatment | Stored |
|---|---|---|---|
| `public` | designation, department, grade | `plain` | old and new in full |
| `internal` | joining date, employment type, shift | `plain` | old and new in full |
| `financial` | salary, CTC, component amounts, loan terms | `plain`, access-controlled | old and new in full, typed numerics — **needed for dispute resolution and defensible in an inspection** |
| `identifier` | Aadhaar, PAN, UAN, ESIC, bank account, IFSC-plus-account, passport | `hashed` | **masked display** (`XXXX XXXX 4821`) **plus a keyed HMAC-SHA-256 of the full value**, keyed per tenant |
| `sensitive` | health information, disciplinary detail, biometric template reference | `hashed` | masked display plus keyed HMAC; the underlying record's own access controls still apply |
| `secret` | passwords, OTPs, API keys, webhook secrets, private keys | `suppressed` | **nothing** — the field name, the fact of change, the actor and the time; **no value, no hash, not even of the old value** |

**R-XXIII-051** The keyed HMAC is what makes an `identifier` diff **useful without being a
disclosure**. Dispute resolution works by **proving a claim, not by reading the ledger**:
an employee or an inspector who presents a value can have it confirmed or refuted —
`HMAC(claimed_value, tenant_key) == old_hash` — but nobody can read the value out of the
ledger, and a stolen ledger yields no identifiers.

**R-XXIII-052** The HMAC key is **per tenant, stored separately from the ledger, rotatable,
and never present in a backup of the ledger alone**. On rotation, existing hashes are
**not** recomputed — that would require the plaintext, which the ledger does not hold.
Instead the entry records `key_version`, and verification uses the version in force at
write time.

**R-XXIII-053** A `secret`-class value is never written to the ledger, in any form, under
any configuration, by any actor. *This exists because `wa_log.body_values` stores the
plaintext temporary password of a tenant administrator, permanently
(`WaService.php:171`; `WaTemplateController.php:103-104`; `E-…` SEC-01). The prohibition is
stated in Part XIX §XIX.6 for message logs and is restated here, unqualified, for the
ledger.*

**R-XXIII-054** `pii_classes` on the parent entry lists the classes the entry touches, so
**access control on reading the ledger is enforceable without parsing the diff rows**
(§XXIII.9.2).

---

## XXIII.6 Effective-dated history versus audit *(brief Section 75)*

### XXIII.6.1 The distinction, stated once, precisely

These are two different records answering two different questions, and a system needs both.

| | **Effective-dated history** | **Audit ledger** |
|---|---|---|
| **Question answered** | *What was true, as at a date?* | *Who changed it, when, from what, to what, and under what authority?* |
| **Organised by** | business validity — `effective_from` / `effective_to` | wall-clock event time |
| **Subject** | the state of a thing | an act by an actor |
| **Corrections** | a correction **is** a new row with its own validity; the record shows what was true | a correction is a **new act**, recorded alongside the original |
| **Mutability** | rows are superseded, never edited | rows are immutable and chained |
| **Query pattern** | `WHERE effective_from <= D AND (effective_to IS NULL OR effective_to >= D)` | `WHERE entity = X ORDER BY seq` |
| **Cost of loss** | the system cannot compute correctly | the system cannot be defended |
| **Used by** | every engine, on every calculation | investigation, dispute, inspection, forensics |
| **Retention** | for as long as the entity is calculable | for the evidentiary retention period |
| **Volume** | small — one row per real change | large — one row per act, including reads |

**R-XXIII-055** **Business state is never reconstructed by replaying the audit ledger.**
Any design, query, report or migration that does so is rejected at review. The ledger is
evidence about changes; it is not a state store, it is not complete for that purpose by
construction (R-XXIII-039 excludes derived records deliberately), and treating it as one
makes it impossible to prune, archive or redact.

**R-XXIII-056** **Authorship is never inferred from effective-dated data.** An
effective-dated row carries `created_by` for convenience; the authoritative record of who
made a change, from what previous value, with what authority, is the ledger entry.

### XXIII.6.2 The worked example — "what was this employee's branch on 15 May?"

Spine Law 3's test requires this to be answerable by **a single service call**.

**The answer comes from effective-dated assignment data.**

```
EmployeeAssignmentService::resolve(employee_uuid, dimension: 'branch', as_of: 2026-05-15)

  SELECT org_unit_id, effective_from, effective_to, assignment_id
    FROM employee_org_assignments
   WHERE tenant_id = :t
     AND employee_id = :e
     AND dimension = 'branch'
     AND effective_from <= '2026-05-15'
     AND (effective_to IS NULL OR effective_to >= '2026-05-15')

  → Hyderabad — Banjara Hills  (effective 01-Apr-2026 → 31-Jul-2026)
```

One indexed query. No ledger involvement. This is the number payroll, attendance,
compliance and every report use, and it is the number that is correct even if the ledger
were deleted entirely.

**Now the audit question, which is a different question:**

```
Explain(employee, 'branch', as_of: 2026-05-15)   →  assignment #88214

AuditService::forEntity('employee_org_assignment', 88214)

  seq 41,882  2026-03-28 16:12:07.418 IST  employee.assignment.changed
              actor      Meera S (user 331), role hr_manager, scope company:2
              origin     ui        ip 10.4.2.19    request 7d1e…
              workflow   TRANSFER-STD instance 9142, step 3 (approved by user 88)
              diff       org_unit_id  → 14 (Hyderabad — Banjara Hills)   [was 9 (Pune)]
                         effective_from → 2026-04-01
              reason     "Transfer per approved request TRF-2026-0412"
              rule/config  transfer policy v6
```

**R-XXIII-057** Three properties of the pair are requirements:

1. **A retrospective correction is visible in both.** If, in June, HR corrects the transfer
   to have been effective 01-March, the assignment table gains a corrected row and the
   ledger gains a second entry recording the correction, its actor, its reason, and the
   values before and after. The effective-dated data then answers "what was true on 15 May"
   **with the corrected answer**; the ledger answers "and it was corrected on 12 June by
   Meera because the transfer letter said March". *Neither record alone tells the whole
   story, which is exactly why both exist.*
2. **Every derived record pins the versions it used** (spine S4), so a payslip computed on
   the pre-correction data remains explicable even after the correction, and the difference
   between the two is computable rather than mysterious.
3. **A ledger entry that changes effective-dated data names the affected date range**, so
   the blast radius of a retrospective edit is queryable: *"which payroll periods does this
   correction touch?"*

**R-XXIII-058** SmartPRS 2 cannot answer the branch question **at all**: `employees`
carries exactly one current value per attribute and every writer issues
`UPDATE employees SET …` with no history and no field-level audit trail (`D-…` §). The
transfer path additionally **clears** `reporting_manager`, `team_leader`, `team` and
`reporting_manager_id` on a company transfer (`TransferService.php:150-157`), so a group
with dotted-line reporting loses it **unrecoverably** (`L-…` §Q8 item 35). Restating the
consequence plainly: the historical answer does not exist to be migrated.

### XXIII.6.3 Where each is authoritative

| Question | Authoritative source |
|---|---|
| What branch / department / grade / shift / structure applied on date D? | effective-dated assignment (Part V, Part X) |
| Which policy, rule, formula or pack version applied on date D? | `config_versions` resolution (Part VI, spine S4) |
| What did the payslip say, and from what inputs? | the payslip and its pinned input snapshot (Part XIV, Part XVI) |
| Who changed the assignment, when, and from what? | **the ledger** |
| Who published that rule version, and who approved it? | **the ledger** |
| Who viewed this payslip? | **the ledger** (`access`) |
| Was the ledger itself altered? | the chain and the anchors (§XXIII.7) |

---

## XXIII.7 Verification and tamper evidence

### XXIII.7.1 The verify procedure

**R-XXIII-059** Verification is **per tenant, per epoch, incremental, checkpointed and
range-bounded**. It never loads a table into memory. *SmartPRS 2's `verify()` loads every
row of every tenant with `DB::table('activity_logs')->orderBy('id')->get()`
(`Audit::verify():111`) — an O(n) memory blow-up reachable by any administrator opening
Activity Logs (`G-…` D-18).*

```
verify(tenant, epoch, from_seq, to_seq, expected_prev_hash) → VerificationResult
```

Procedure:

```
1. Load audit_verification_checkpoints for (tenant, epoch): the last verified seq
   and its hash. Absent → start at the epoch genesis constant.
2. Stream entries in seq order in bounded chunks (declared chunk size).
   For each entry:
     a. assert entry.prev_hash == running_hash
     b. recompute hash from canon(...) using the entry's own canon_version
     c. assert hash_equals(recomputed, entry.hash)      -- timing-safe
     d. recompute change_digest from audit_field_changes and assert equality
     e. assert seq is exactly running_seq + 1           -- gaplessness
     f. running_hash := entry.hash ; running_seq := entry.seq
3. On reaching a business-date boundary, assert the day's anchor:
     first_seq, last_seq, entry_count, first_hash, last_hash, merkle_root,
     anchor signature verifies against the public key,
     anchor.prev_anchor_hash chains to the previous anchor.
4. Write a new checkpoint. Emit audit.verification_run (itself a ledger entry).
5. On any failure: STOP, record the break, emit audit.chain_break_detected.
```

**R-XXIII-060** **There is no branch in this procedure that skips a row or resets the
running hash.** Restated deliberately, because `Audit::verify():118-121` is a four-line
block that nullifies the entire mechanism.

**R-XXIII-061** Verification runs on a schedule: the previous business day's range nightly
per tenant, plus a rolling re-verification of a declared window of older anchors, plus a
full-epoch verification on demand and before any archival seal or regulator export.

**R-XXIII-062** Checkpoints are themselves **signed**, so a checkpoint cannot be forged to
make an unverified range appear verified.

**R-XXIII-063** Verification is **read-only** and runs against a replica where one exists.
It never holds the chain head lock and never blocks appends.

### XXIII.7.2 What a break means

**R-XXIII-064** A break is one of five conditions, and the verifier distinguishes them,
because the operational response differs:

| Break | Meaning | Likely cause |
|---|---|---|
| `hash_mismatch` | a row's content no longer matches its hash | the row was modified |
| `link_mismatch` | `prev_hash` does not match the predecessor's hash | a row was inserted or removed, or reordered |
| `sequence_gap` | `seq` is not contiguous | a row was deleted, or an append failed after allocating a sequence |
| `digest_mismatch` | the entry hashes correctly but its diff rows do not match `change_digest` | the diff table was modified |
| `anchor_mismatch` | entries verify but the day's signed anchor does not agree | the anchor or a whole day's range was replaced |

**R-XXIII-065** A break is **never** interpreted as a defect in the verifier, an
acceptable legacy condition, or a reason to re-anchor. **A break stands until it is
explained**, and the explanation is recorded.

**R-XXIII-066** A break **does not** stop the application, does not lock the tenant, and
does not block payroll. It raises a critical, non-suppressible incident. *Rationale: an
integrity alarm that halts an HR system will be disabled by the first operator who
encounters it at month-end. The control that survives contact with operations is the one
that is loud, permanent and impossible to clear without a recorded explanation.*

### XXIII.7.3 How a break is reported, and to whom

**R-XXIII-067** `audit.chain_break_detected` is routed through Part XIX §XIX.7.5 as a
**critical, non-suppressible** event that:

- reaches the tenant's **designated audit contact** — a mandatory tenant role, declared at
  provisioning, that cannot be left empty;
- reaches **Ametecs platform operations** where a support agreement provides for it, as
  **status and hash only**, never content;
- travels on an **out-of-band channel** (Part XIX R-XIX-112), because a break may coincide
  with a compromise of the ordinary path;
- **cannot be unsubscribed**, digested, quiet-houred or deactivated;
- opens an `audit_integrity_incidents` row that persists until closed with a recorded
  explanation and a named closer, and appears on the operations dashboard until then.

**R-XXIII-068** The incident record names: the tenant, the epoch, the break class, the
**business date** the anchors localise it to, the first and last good sequence, the entries
implicated, the actors who appear in the implicated range, and the verification run that
found it.

**R-XXIII-069** Closing an incident requires a **reason and a named closer**, is itself a
ledger entry, and **never** clears the underlying break record. A broken range stays marked
broken forever. Any subsequent export or regulator disclosure covering that range carries
the break notice with it (§XXIII.9.4).

### XXIII.7.4 How the anchor localises a break

**R-XXIII-070** Because each business day's anchor carries `first_seq`, `last_seq`,
`entry_count`, `first_hash`, `last_hash` and a Merkle root, and because anchors are
themselves chained, a break is localised **without re-verifying the whole ledger**:

```
1. Verify the anchor chain — cheap: one row per tenant per day.
   The first anchor whose prev_anchor_hash or signature fails bounds the problem to
   its date and everything after.
2. For a date whose anchor signature is intact but whose entries do not verify, the
   Merkle root identifies the affected subtree, and the search is O(log n) in the
   day's entry count rather than linear in the ledger.
3. Report: "the chain is intact through 11-Aug-2026 (seq 1..812,004) and broken
   within 12-Aug-2026, between seq 812,005 and 812,533."
```

**R-XXIII-071** Anchors are retained for the **full evidentiary retention period even when
the entries they cover have been archived to cold storage** (§XXIII.8.3), so the statement
"the chain was intact on this date" remains provable after the detail is off the primary
database.

**R-XXIII-072** Where external notarisation is in force (R-XXIII-020), a break in which
**both** the entries and the local anchor agree but the externally notarised anchor hash
does not is reported distinctly as `external_anchor_mismatch` — the signature of a
wholesale local rewrite, which purely local anchoring cannot detect.

---

## XXIII.8 Retention, volume, performance and legal hold

### XXIII.8.1 Expected volume at 10,000 employees

The figures below are **engineering estimates with their assumptions shown**, not measured
values, and they are stated so the storage and partitioning design can be reviewed rather
than taken on trust. Nothing here is a legal or statutory assertion.

Assumptions: one tenant, 10,000 employees, a standard month with 22 working days, ESS and
MSS in active use, read auditing bounded per R-XXIII-036 to R-XXIII-038, derived records
excluded per R-XXIII-039.

| Source | Rate | Entries / month |
|---|---|---|
| Employee master changes (all surfaces) | 0.4 per employee per month | 4,000 |
| Attendance overrides, corrections, manual punches | 0.8 per employee per month | 8,000 |
| Leave applications and decisions | 1.2 events per employee per month | 12,000 |
| Requests, claims, reimbursements and their decisions | 0.6 per employee per month | 6,000 |
| Workflow transitions (all modules) | 2.5 per employee per month | 25,000 |
| Payroll run lifecycle, overrides, exceptions, payslip publication | 1.3 per employee per month | 13,000 |
| Authentication and session events | 25 per employee per month | 250,000 |
| Configuration, rules, templates, packs | ~400 per month, tenant-wide | 400 |
| Exports, imports, reports | ~600 per month, tenant-wide | 600 |
| Classified reads — payslip, document, colleague record (coalesced) | 6 per employee per month | 60,000 |
| **Total mutation + system** | | **~69,000** |
| **Total access + auth** | | **~310,000** |
| **All classes** | | **≈ 380,000 / month** |

| Derived figure | Estimate |
|---|---|
| Entries per year | ≈ 4.6 million |
| Field-change rows per year (≈ 2.4 per diff-bearing entry, ~30% of entries) | ≈ 3.3 million |
| Average entry row, with JSON columns | ≈ 900 bytes |
| Average field-change row | ≈ 220 bytes |
| **Primary data per year** | ≈ **4.9 GB** |
| **With indexes (× ≈ 1.6)** | ≈ **7.8 GB** |
| Anchors per year | 365 rows — negligible, and retained beyond the entries (R-XXIII-071) |
| At the recommended 8-year retention, uncompressed | ≈ **62 GB per 10,000-employee tenant** |

**R-XXIII-073** **Hard deletion of a business or evidentiary record is prohibited.** Every
deletion is a soft deletion with a ledger entry naming the actor and the reason (spine
S16). *This exists because `cleanOrphanCommissions` hard-deletes rows from three tables with
no record of any kind (`RequestController.php:1254-1259`; `E-…` SEC-22), and because
`employee_references` are deleted and re-inserted on every employee save with no audit and
no undo (`AppDataController.php:3021, :3055-3071`).*

**R-XXIII-074** The volume model above is a **published sizing input**, reviewed against
production telemetry after the first year and revised. The auth-and-access classes dominate
by roughly four to one, which is why R-XXIII-036 to R-XXIII-038 bound them explicitly; an
unbounded read-audit design is roughly an order of magnitude larger and is rejected on that
basis.

### XXIII.8.2 Partitioning and performance

**R-XXIII-075** `audit_entries` is **range-partitioned monthly on `business_date`**, with a
scheduled job creating partitions ahead of need and never dropping one that a live retention
class or a legal hold still covers.

**R-XXIII-076** Declared indexes, and the query each serves:

| Index | Serves |
|---|---|
| `(tenant_id, chain_epoch, seq)` **UNIQUE** | chain walk, verification, gaplessness |
| `(tenant_id, entity_type, entity_id, occurred_at DESC)` | "the history of this record" — the commonest query |
| `(tenant_id, actor_user_id, occurred_at DESC)` | "what did this user do?" |
| `(tenant_id, action, occurred_at DESC)` | "every salary change this quarter" |
| `(tenant_id, business_date, record_class)` | anchoring, daily reporting, purge |
| `(tenant_id, correlation_id)` | "everything that happened in this request" |
| `(tenant_id, workflow_instance_id)` | approval forensics |
| `(tenant_id, severity, occurred_at DESC)` | the sensitive-action review queue |
| `(tenant_id, legal_hold, retention_class, business_date)` | purge eligibility |

**R-XXIII-077** The append path touches **one head row and one insert per entry**, with the
head lock held for the duration of one transaction's batch (R-XXIII-013). Reads never block
appends (R-XXIII-063).

**R-XXIII-078** Ledger reads run against a **read replica** where the deployment provides
one, and the audit read API is **cursor-paginated with a hard result cap**, never
offset-paginated. *This exists because SmartPRS 2's audit view loads every row of every
tenant into memory (`Audit::verify():111`; `G-…` D-18).*

**R-XXIII-079** No user-facing screen may trigger a full-chain verification. Verification is
scheduled (R-XXIII-061) or explicitly requested with a declared range, and reports progress.

### XXIII.8.3 Archival to cold storage, preserving verifiability

**R-XXIII-080** Entries older than a declared **online window** (recommended 24 months) are
moved to cold storage by a **sealed-segment** procedure:

```
1. Verify the segment [from_seq .. to_seq] completely, and its anchors.
2. Serialise the entries and their field-change rows in canonical form.
3. Compute the segment Merkle root; assemble a manifest:
     tenant, epoch, from_seq, to_seq, from_date, to_date, entry_count,
     terminal_hash, merkle_root, the anchors covering the range,
     canon_version, hash_algo, signing_key_id
4. Sign the manifest. Write the segment and manifest to the archive destination,
   encrypted, with an integrity check on write.
5. Record an audit_archive_segments row: manifest, destination, checksum,
   restore instructions, verification status, sealed_at.
6. Only then remove the entries from the online partition — under the maintenance
   role, in a transaction, writing a `purge.executed` ledger entry naming the range.
```

**R-XXIII-081** **Archival never breaks the chain.** The online chain's continuity across an
archived range is preserved because the sealed segment's `terminal_hash` is retained online
in `audit_archive_segments`, and the first online entry after the range chains to it. A
verifier that reaches the boundary uses the segment's terminal hash as its running hash and
**records that the range is archived, not that it is missing.**

**R-XXIII-082** The **anchors covering an archived range stay online** (R-XXIII-071), so
"the chain was intact through this date" remains provable without retrieving the archive.

**R-XXIII-083** A sealed segment is **independently verifiable offline**, using only the
segment file, its signed manifest and the public key. Regulator and auditor exports use the
same format (§XXIII.9.4), so the verification tool is one tool.

**R-XXIII-084** Archive integrity is re-verified on a schedule by fetching a rolling sample
of segments and checking their Merkle roots against their manifests. A failure raises
`audit.archive_integrity_failed`, treated as a break (§XXIII.7.3).

### XXIII.8.4 Retention, purge and legal hold

**R-XXIII-085** Every entry carries a `retention_class` assigned from the action catalogue.
Classes and their **recommended** periods — values to be confirmed by the Product Owner and
counsel, never asserted here as legally required:

| Class | Covers | Recommended |
|---|---|---|
| `evidentiary_statutory` | payroll, statutory, money, contract, statutory filings | to the statutory record-keeping period, set from the rule pack |
| `evidentiary_hr` | employee master, roles, approvals, disciplinary, workflow | 8 years from the later of the event and the employee's exit |
| `security` | authentication, authorisation, privilege, API keys, impersonation | 3 years |
| `access` | classified reads | 2 years |
| `operational` | integrity runs, maintenance, purge, backup | 3 years |

**R-XXIII-086** Purge is a **scheduled, previewable, audited operation**, executed under the
maintenance role, never by application code, and it writes a `purge.executed` entry naming
the class, the range, the row count and the operator. **The purge of audit data is itself
audited, and that entry is never purged.**

**R-XXIII-087** **Purge respects the chain.** Only whole sealed segments are removable
(R-XXIII-080); individual entries are never deleted from within a live range, because that
would produce a `sequence_gap` indistinguishable from tampering.

**R-XXIII-088** **Redaction is not deletion.** Where a data-protection erasure obligation
reaches audit content, the erasure applies to the **diff values**, not to the entry: the
`audit_field_changes` row's display and hash columns are replaced with a redaction marker,
the parent entry's `change_digest` is **left unchanged**, and a `redaction` record names the
authority, the actor, the legal basis and the range. Verification then reports the entry as
`redacted`, still chaining correctly, with the redaction record as its explanation. The
alternative — silently rewriting the digest — would be indistinguishable from tampering and
is prohibited.
**PO-XXIII-04** records the erasure-versus-evidence position.

**R-XXIII-089** **Legal hold** is a named, scoped, audited suspension of purge and
archival-deletion. A hold declares its scope (tenant, employee, entity, date range, action
set), its authority, its requester, its reason, its review date and its expiry. Purge
eligibility queries exclude held rows by index (`legal_hold` is in the purge index,
R-XXIII-076). Placing, extending and releasing a hold are each ledger entries, and a hold
overrides every retention class.

**R-XXIII-090** Retention periods and the online window are **configuration with a
platform floor**. A tenant may retain longer than the floor and may never retain less.
*This exists because SmartPRS 2 has no retention of any kind against a published Privacy
Policy that commits to ninety days (`PolicyController.php:12`; `E-…` SEC-18), and because
the one retention setting that exists (`delete_records_older_than_days = 365`) applies to a
permanently empty table whose clean command is not scheduled.*

---

## XXIII.9 Access to the audit trail

### XXIII.9.1 Who may read it

**R-XXIII-091** Reading the ledger requires a permission **and** a data scope, both
evaluated server-side and both failing closed (spine S13, Law 5). The permission set:

| Permission | Grants |
|---|---|
| `audit.self.read` | one's own entries, and entries about oneself as subject — granted to every user by default |
| `audit.entity.read` | the history of records within the actor's data scope |
| `audit.security.read` | authentication, authorisation and privilege entries within scope |
| `audit.financial.read` | entries whose `pii_classes` include `financial` |
| `audit.sensitive.read` | entries whose `pii_classes` include `identifier` or `sensitive` |
| `audit.tenant.read` | the whole tenant's ledger |
| `audit.verify` | run a verification and read its result |
| `audit.export` | produce a signed export (§XXIII.9.4) |
| `audit.hold.manage` | place and release legal holds |
| `platform.audit.read` | Ametecs staff, **only under an active, time-boxed, tenant-approved support grant**, and every such read is doubly recorded |

**R-XXIII-092** Access control uses the entry's `pii_classes` column, so no reader ever sees
a diff row whose class they lack — enforced by the query, not by post-filtering
(R-XXIII-054).

**R-XXIII-093** **No role reads the ledger by virtue of being an administrator.**
`audit.tenant.read` is separately assignable, and the maker-checker discipline of spine S8
applies to granting it. *This exists because in SmartPRS 2 `platform_support` is created
with `tenant_id = NULL` — the widest scope in the product — is checked by zero code paths,
and has unrestricted read of every customer's employee, salary and bank data with no audit
distinction from a super administrator (`StaffController.php:17-20, 26-28, 49`;
`N-…` S-03).*

**R-XXIII-094** **Nobody can write to the ledger through any read surface**, and no role
anywhere, including platform, holds `UPDATE` or `DELETE` (R-XXIII-022, R-XXIII-005).

### XXIII.9.2 The audit of audit access

**R-XXIII-095** Every read of the ledger writes an `audit.read` entry recording the actor,
the filter, the resolved scope, the **row count returned**, the PII classes reached, and the
purpose code where the read is of another person's records. This is one entry per query, not
per row (R-XXIII-037).

**R-XXIII-096** Reading the audit-of-audit entries is itself an `audit.read`. There is no
recursion problem — each read produces exactly one new entry — and no exemption, because an
exempt read path is exactly where an investigation would be conducted unobserved.

**R-XXIII-097** A read reaching `identifier` or `sensitive` classes, or spanning more than a
declared row threshold, additionally raises `audit.bulk_access` to the tenant's audit
contact. Bulk access to an audit trail is a notable event whether or not it is legitimate.

### XXIII.9.3 What a reader sees

**R-XXIII-098** The trail is presented in three views, all over the same data:

| View | For |
|---|---|
| **Record history** — one entity, chronological, with diffs rendered field by field and the effective-dated context alongside | the commonest question: "what happened to this employee's record?" |
| **Actor activity** — one user, chronological, across entities, with the roles held at each moment | investigation |
| **Action review** — one action class across a period and scope, filterable, with typed numeric filters on diffs | "every salary change over ₹1,00,000 this quarter" |

**R-XXIII-099** Every view shows, per entry, the **verification status of the range it falls
in** — verified to a date, unverified, or broken — so a reader is never shown ledger content
without knowing whether the range is trustworthy.

### XXIII.9.4 Export for a regulator or an auditor

**R-XXIII-100** An audit export is a **signed bundle**, not a spreadsheet:

```
manifest.json        tenant, epoch, seq range, date range, entry count,
                     filters applied, requester, approver, generated_at,
                     terminal_hash, merkle_root, canon_version, hash_algo,
                     signing_key_id, verification status of every covered range,
                     and any break notices intersecting the range
entries.jsonl        canonical serialisation, one entry per line
field_changes.jsonl  the diff rows, with treatments as stored
anchors.jsonl        every anchor covering the range, with signatures
public_key.pem       the verification key
VERIFY.md            the procedure, reproducible without SmartPRS
```

**R-XXIII-101** The bundle is **verifiable without the application** — the same tool that
verifies a sealed archive segment (R-XXIII-083).

**R-XXIII-102** A **full-tenant** export requires **two-person authorisation** through the
workflow engine (spine S8 maker-checker) and is itself audited, with the manifest hash
recorded in the entry, so the export that left the building is identifiable later.

**R-XXIII-103** **Sensitive treatments are not reversed for an export.** An `identifier`
diff exports as its masked display and its keyed HMAC. Where a regulator requires plaintext,
it is produced from the **source records** under their own access controls and their own
audit entries — never by unmasking the ledger. *The ledger does not hold the plaintext to
unmask (R-XXIII-050); this requirement records that as deliberate.*

**R-XXIII-104** An export covering a **broken or redacted range carries the break notice and
the redaction record in its manifest**, prominently. An export may not silently present a
compromised range as sound.

---

## XXIII.10 Data model

Section 123 standard; conventions per spine S16. Every table carries
`tenant_id BIGINT UNSIGNED NOT NULL` participating in every unique index (Law 6), applied by
the persistence layer. **No table in this part is created at runtime** (Law 2) — the direct
replacement for `Audit::ensure():25-46`, which adds `prev_hash` and `row_hash` to
`activity_logs` at runtime.

### XXIII.10.1 `audit_entries`

*Purpose:* the ledger. Columns as specified in §XXIII.2.2.

PK `id` · **Unique `(tenant_id, chain_epoch, seq)`** · Unique `(uuid)` ·
Indexes per R-XXIII-076 · Range-partitioned monthly on `business_date`.
FKs: `actor_user_id`, `on_behalf_of_user_id`, `api_key_id`, `support_grant_id`,
`workflow_instance_id`, `config_version_id`, `rule_version_id`, `rulepack_version_id` — all
**ON DELETE RESTRICT**, because a referenced row may not be removed while evidence points at
it.
Tenant scope: structural. Hist: **this is the history**. SD: **no** — and no
`deleted_at`, no `updated_at`, no `UPDATE`/`DELETE` grant. Audit: the table is the audit.
PII: `pii_classes` declares per row; the table as a whole is classified **restricted**.

### XXIII.10.2 `audit_field_changes`

*Purpose:* the typed diff. Columns as specified in §XXIII.5.2.

PK `id` · Unique `(tenant_id, audit_entry_id, field_path, ordinal)` ·
Index `(tenant_id, field_definition_id, id)` · Index `(tenant_id, field_path, new_numeric)`.
FK `audit_entry_id` **ON DELETE RESTRICT**; FK `field_definition_id` ON DELETE RESTRICT.
Partitioned alongside the parent. Hist: n/a. SD: no. PII: per row; `secret` never appears.

### XXIII.10.3 `audit_chain_heads`

*Purpose:* the per-tenant chain head — the serialisation point of R-XXIII-012.

| Column | Type | Null | Notes |
|---|---|---|---|
| `tenant_id` | bigint unsigned | N | part of PK |
| `chain_epoch` | int unsigned | N | part of PK |
| `head_seq` | bigint unsigned | N | last allocated sequence |
| `head_hash` | char(64) | N | last entry's hash; the epoch genesis constant when empty |
| `genesis_hash` | char(64) | N | the declared per-(tenant, epoch) genesis (R-XXIII-009) |
| `canon_version` | varchar(16) | N | canonicalisation in force |
| `hash_algo` | varchar(16) | N | |
| `updated_at` | datetime(6) | N | |

PK `(tenant_id, chain_epoch)`. Exactly one live row per tenant per epoch. **The only row in
this part that is ever updated**, and only under `FOR UPDATE` inside an append transaction.

### XXIII.10.4 `audit_anchors`

*Purpose:* the daily signed anchor (§XXIII.2.5). Columns as listed there.

PK `id` · Unique `(tenant_id, chain_epoch, business_date)` ·
Index `(tenant_id, status, business_date)`.
Hist: immutable. SD: no. Retention: **outlives the entries it covers** (R-XXIII-071,
R-XXIII-082). PII: none — hashes and counts only.

### XXIII.10.5 `audit_verification_checkpoints`

`tenant_id`, `chain_epoch`, `verified_through_seq`, `verified_through_hash`,
`verified_through_date`, `verified_at`, `verifier_ref`, `result`, `checkpoint_signature`,
`canon_version`.
PK `id` · Unique `(tenant_id, chain_epoch, verified_through_seq)` ·
Index `(tenant_id, chain_epoch, verified_at DESC)`. Signed per R-XXIII-062.

### XXIII.10.6 `audit_integrity_incidents`

`tenant_id`, `chain_epoch`, `break_class`, `detected_at`, `detected_by_run`,
`localised_business_date`, `first_good_seq`, `last_good_seq`, `implicated_from_seq`,
`implicated_to_seq`, `implicated_actor_ids json`, `status` (`open|explained|unresolved`),
`explanation`, `closed_by`, `closed_at`, `notification_message_id`.
PK `id` · Index `(tenant_id, status, detected_at DESC)`.
**Rows are never deleted and the break record is never cleared** (R-XXIII-069).

### XXIII.10.7 `audit_archive_segments`

`tenant_id`, `chain_epoch`, `from_seq`, `to_seq`, `from_date`, `to_date`, `entry_count`,
`terminal_hash`, `merkle_root`, `manifest json`, `manifest_signature`, `signing_key_id`,
`destination`, `object_ref`, `checksum`, `encryption_key_id`, `sealed_at`, `sealed_by`,
`last_verified_at`, `last_verification_result`.
PK `id` · Unique `(tenant_id, chain_epoch, from_seq)` ·
Index `(tenant_id, to_date)`.
`terminal_hash` stays online forever, because it is what keeps the chain continuous across
the gap (R-XXIII-081).

### XXIII.10.8 `audit_action_definitions`

*Purpose:* the action catalogue (§XXIII.4.1). Platform-owned; `tenant_id` NULL for platform
rows — the single declared Law 6 exception in this part, on the same basis as Part XIX's
event catalogue: it is product content, not business data.
Unique `(tenant_id, action_key)` · Index `(module, record_class)`.
Versioned with the release; changes are themselves audited.

### XXIII.10.9 `audit_legal_holds`

`tenant_id`, `hold_key`, `scope json` (tenant / employee / entity / date range / action
set), `authority`, `requested_by`, `approved_by`, `reason`, `placed_at`, `review_on`,
`expires_at`, `released_by`, `released_at`, `status`.
PK `id` · Unique `(tenant_id, hold_key)` · Index `(tenant_id, status, expires_at)`.
Every lifecycle transition is a ledger entry (R-XXIII-089).

### XXIII.10.10 `audit_redactions`

`tenant_id`, `audit_entry_id`, `field_change_id`, `authority`, `legal_basis`,
`requested_by`, `approved_by`, `redacted_at`, `reason`.
PK `id` · Index `(tenant_id, audit_entry_id)`.
Explains a `redacted` verification result (R-XXIII-088).

### XXIII.10.11 `issued_artefacts`

*Purpose:* R-XXIII-035 — the persisted evidence of anything issued to a third party.

`tenant_id`, `artefact_type`, `subject_type`, `subject_id`, `template_version_id`,
`data_snapshot_ref`, `content_hash`, `storage_ref`, `reference_code`,
`verification_hmac`, `hmac_key_version`, `issued_by`, `issued_at`, `revoked_at`,
`revocation_reason`, `audit_entry_id`.
PK `id` · Unique `(tenant_id, reference_code)` · Index `(tenant_id, subject_type,
subject_id, issued_at DESC)`.
Hist: immutable; a reissue is a new row. SD: no; revocation is a stamp. PII: **restricted**.

### XXIII.10.12 What is deliberately absent

| Absent | Why |
|---|---|
| A second audit table | *SmartPRS 2 has two whose names differ by one character, one permanently empty (`activity_logs` / `activity_log`; `E-…` NEW-AUD-110)* |
| Spatie `laravel-activitylog` | Installed, migrated three times, never written to; removed entirely |
| `commission_logs` or any module-local audit table | One ledger. Module-local trails are what put money diffs outside the chain |
| A nullable `hash` or `prev_hash` | R-XXIII-004 — the constraint that makes the SmartPRS 2 failure unrepresentable |
| An `updated_at` or `deleted_at` on the ledger | R-XXIII-005 |
| A queue-backed audit writer | R-XXIII-016 |
| A free-text `details` column as the diff | R-XXIII-044 — *SmartPRS 2's only diff is an English sentence (`RequestController.php:807-871`)* |

---

## XXIII.11 API surface

All `/api/v3`, UUIDs only, cursor pagination with a hard result cap, one error envelope, and
an **explicit policy binding on every route** (Law 5). **Every route here is a read except
verification, hold management, export and redaction — there is no write endpoint for the
ledger, at any permission level.**

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/audit/entries?entity_type=&entity_uuid=&actor=&action=&class=&from=&to=&severity=` | filtered ledger read, cursor-paginated | `audit.entity.read` **scoped**, plus class permissions |
| GET | `/audit/entries/{uuid}` | one entry with its diff rows, treatments applied | as above |
| GET | `/audit/entities/{type}/{uuid}/history` | record history view (§XXIII.9.3) | `audit.entity.read` scoped |
| GET | `/audit/actors/{userUuid}/activity` | actor activity view | `audit.security.read` scoped |
| GET | `/me/audit` | my own entries and entries about me | `audit.self.read` |
| GET | `/audit/actions` | the action catalogue, with what is and is not audited (R-XXIII-040) | `audit.entity.read` |
| POST | `/audit/verify` | run a bounded verification `{epoch, from_seq, to_seq}` | `audit.verify` |
| GET | `/audit/verify/{runUuid}` | verification result and progress | `audit.verify` |
| GET | `/audit/status` | verified-through date and sequence, open incidents, anchor health | `audit.verify` |
| GET | `/audit/anchors?from=&to=` | anchors with signatures, for independent checking | `audit.verify` |
| GET | `/audit/incidents` \| POST `/audit/incidents/{uuid}/explain` | integrity incidents; explanation never clears the break | `audit.verify` |
| POST | `/audit/exports` | request a signed bundle (two-person for full-tenant) | `audit.export` |
| GET | `/audit/exports/{uuid}` \| `/download` | status; download through a signed, expiring URL | `audit.export` |
| GET/POST | `/audit/legal-holds` \| POST `/audit/legal-holds/{uuid}/release` | hold lifecycle | `audit.hold.manage` |
| POST | `/audit/redactions` | erasure against diff values, never against entries | `audit.hold.manage` + approval |
| GET | `/audit/retention` \| PUT | retention classes and the online window (floor-bounded) | `audit.tenant.read` + `config.publish` |
| GET | `/artefacts/{referenceCode}/verify` | public verification of an issued artefact — confirms match, discloses nothing | public, rate-limited |

**R-XXIII-105** The audit API exposes **no endpoint that creates, alters or deletes a ledger
entry**, in any version, for any actor. Entries arrive only through `AuditService::append()`
inside a business transaction (R-XXIII-028).

**R-XXIII-106** `GET /artefacts/{referenceCode}/verify` answers only *"does this reference,
with this content hash, correspond to an artefact this tenant issued, and is it revoked?"*
It discloses no employee data and is rate-limited and monitored. *This is what
`ComplianceController.php:643-644` was reaching for and could not achieve, having neither a
secret nor a stored issued-report row (`N-…` V-04).*

---

## XXIII.12 Events

Emitted:

| Event | When | Consumers |
|---|---|---|
| `audit.entry_appended` | every append (in-process, for projections) | search index, dashboards — **never a delivery channel** |
| `audit.verification_run` | a verification completes | ops dashboard, compliance |
| `audit.chain_break_detected` | any break class | **critical, non-suppressible, out-of-band** (Part XIX §XIX.7.5, R-XIX-122) |
| `audit.anchor_sealed` / `audit.anchor_missing` | daily anchoring | ops dashboard; the second is critical |
| `audit.archive_sealed` / `audit.archive_integrity_failed` | archival | ops dashboard; the second is a break |
| `audit.bulk_access` | a large or sensitive ledger read | tenant audit contact |
| `audit.exported` | a signed bundle is produced | tenant audit contact, platform |
| `audit.legal_hold_placed` / `.released` | hold lifecycle | compliance |
| `audit.redaction_applied` | an erasure against diff values | compliance |
| `audit.epoch_migrated` | the only path that changes `chain_epoch` | platform |

Consumed: none. **This part consumes no events**, because an audit entry is written
synchronously by the acting service inside its own transaction (R-XXIII-012, R-XXIII-016).
An audit ledger driven by asynchronous event consumption is an audit ledger that can lose
entries, and Part XIX §XIX.1.1 is the evidence of what a durable claim resting on an
uninstalled worker is worth.

---

## XXIII.13 Explainability *(spine S15)*

**R-XXIII-107** The ledger is where "who and why" lives in the S15 trace; the engines own
"what and how". `Explain(entity, field)` returns the derivation; the trace's provenance
section links to the ledger entry that produced the input.

```
Employee EMP-04417 · Basic salary · as at 01-Sep-2026

Value            ₹42,000.00
From             salary_structure_assignment #7714, effective 01-Aug-2026 →
Structure        "Telangana Collections" v7   (Part XV)
Formula          BASIC v4 (published 01-Apr-2026)         (Part VIII)

Provenance — who put this here
  audit seq 41,993   2026-07-28 10:04:22.117 IST   employee.salary.changed
    actor      Meera S (user 331) · roles at the time: hr_manager
                                  · scope at the time: company:2
    origin     ui · ip 10.4.2.19 · request 4c81…  · correlation 4c81…
    authority  workflow INCREMENT-STD instance 9411, step 2
               maker user 331 / checker user 88 (maker-checker pair recorded)
    diff       components.BASIC.amount   ₹38,000.00 → ₹42,000.00   [financial, plain]
               effective_from            2026-08-01
    reason     "Annual increment cycle 2026, band B, per approved letter INC-2026-0331"
    chain      verified · in the anchor for 28-Jul-2026 (sealed, signature valid)
```

**R-XXIII-108** Every trace that surfaces a ledger entry also surfaces the **verification
status of the range containing it** (R-XXIII-099). A number presented as defensible must
state whether its evidence is verified.

**R-XXIII-109** For a **retrospective correction**, the trace shows both acts — the original
and the correction — with their dates and reasons, and the effective-dated consequence of
each (§XXIII.6.2 rule 1). This is what makes a three-year-old payslip defensible: the
payslip pins its inputs, and the ledger explains why those inputs were what they were.

---

## XXIII.14 Migration from SmartPRS 2

**R-XXIII-110** `activity_logs` is migrated into a **separate, clearly marked legacy
archive** — `audit_legacy_entries` — and **not** into `audit_entries`. It is presented in
the UI alongside the ledger, labelled **"pre-migration, unverifiable"**, and it is excluded
from every chain verification and every signed export unless explicitly and separately
requested with the label attached.

The justification is factual, not cautious: **the chain is already broken.** It is broken at
every commission approval carrying a DRA, compliance or grievance warning and at every
bounce (`RequestController.php:1382-1387, :1424-1429, :1444-1449, :1822-1827`, with
`verify():118-121` re-anchoring at each), it may be broken elsewhere by the read-then-insert
race (`Audit.php:91-96`), and it was chained globally across tenants (`:91`), so a
per-tenant chain cannot be reconstructed from it even in principle. Presenting it as
verified history would be a false claim. *(This is `E-…` PO-14, and the recommendation
there is the one adopted here.)*

**R-XXIII-111** The migration runs `verify()`'s SmartPRS 3 equivalent over the legacy data
**once, honestly** — without the skip branch — and publishes a **legacy integrity report**
per tenant: total rows, rows with no hash, the sequence positions where the chain breaks,
the actions and date ranges affected, and the count of rows whose tamper-evidence is
consequently void. The customer receives this report before cut-over. It is uncomfortable
and it is accurate.

**R-XXIII-112** Migration transformation:

| SmartPRS 2 asset | Verdict | Transformation |
|---|---|---|
| `activity_logs` rows | **ARCHIVE, NOT MIGRATE** | into `audit_legacy_entries` with the original columns preserved verbatim, plus a per-row `chain_status` (`hashed_ok` / `hashed_broken` / `unhashed`) from the honest verification |
| `activity_logs.user_id` and name strings | **RESOLVE WHERE POSSIBLE** | resolved to user ids where an unambiguous match exists; where a display name maps to zero or more than one user, `actor_user_id` stays NULL and `actor_display` keeps the string, flagged `ambiguous_actor`. *No name is guessed* (`E-…` DEF-AUD-04) |
| `commission_logs` | **ARCHIVE** | into the legacy archive as free text; the English sentences are **not** parsed into structured diffs, because parsing prose into evidence would fabricate precision that was never recorded (`RequestController.php:807-871`) |
| `activity_log` (Spatie, singular) | **DROP** | permanently empty; the package is removed |
| Spatie migrations `2026_05_30_025626/7/8` | **DROP** | with the table |
| `Audit::ensure()` runtime DDL | **REMOVE** | schema is migration-owned (Law 2) |
| The four direct-insert sites | **REMOVE** | replaced by `AuditService::append()` calls inside the same transaction as the business write |
| `AuditTrail::change()` | **REMOVE** | superseded by write-service diff production (R-XXIII-041) |
| Issued compliance packs | **CANNOT BE MIGRATED AS EVIDENCE** | no table stores an issued report (`N-…` V-04); packs issued before migration cannot be verified and the fact is recorded per tenant |

**R-XXIII-113** The SmartPRS 3 chain **starts fresh at epoch 1, seq 1, at cut-over**, with a
genesis entry per tenant that records: the cut-over timestamp, the legacy row count, the
legacy integrity report's hash, and the operator who performed the migration. **The new
chain never claims to extend the old one.**

**R-XXIII-114** Because there is no field-level history to migrate — `employees` carries one
current value per attribute and every writer issues `UPDATE employees SET …` (`D-…` §) —
the migration report states plainly, per tenant, that **employee change history begins at
cut-over**. *(`D-…` migration verdict: "audit history starts fresh — there is none to
migrate".)*

---

## XXIII.15 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | Two concurrent transactions append for the same tenant | The head lock serialises them; both chain correctly; neither reports a break. *This is the SmartPRS 2 race (`Audit.php:91-96`), tested at T3 below* |
| 2 | The business transaction rolls back after appends | The appends roll back with it. The head row's update rolls back too, so the sequence is re-allocated — **gaplessness is preserved because the head is read under lock inside the same transaction** |
| 3 | A deadlock on the head row | Standard retry of the whole business transaction; audit entries are re-derived, not replayed |
| 4 | The audit write fails for any reason | The business transaction aborts (R-XXIII-015). The user sees a clear failure, not a silent success |
| 5 | An action arrives that is not in the catalogue | Append rejected, transaction aborts. Caught in CI before release (R-XXIII-031) |
| 6 | A `secret`-class field changes | One entry, `treatment = suppressed`, field name and fact of change only, no value and no hash (R-XXIII-050) |
| 7 | A field's `pii_class` is raised after entries exist | Historic entries keep the treatment in force when written; the class change is itself audited; **historic values are not retrospectively hashed**, because that would change `change_digest` and break the chain. Where erasure is required, R-XXIII-088's redaction path applies |
| 8 | An HMAC key is rotated | New entries use the new `key_version`; old hashes stand and verify against the version recorded on the entry (R-XXIII-052) |
| 9 | Clock skew or a backwards clock jump | `occurred_at` may be non-monotonic; **`seq` is authoritative for ordering**, and a backwards jump beyond a threshold raises `ops.clock_skew_detected` |
| 10 | The anchoring job misses a day | The next run anchors every unanchored day in order; a gap beyond a threshold raises `audit.anchor_missing` (R-XXIII-021) |
| 11 | The anchor signing key is unavailable | Anchors go `pending`; entries continue; a pending anchor older than a threshold is critical. **Appends are never blocked by an anchoring failure** |
| 12 | The anchor signing key is compromised | Epoch migration with a new key (R-XXIII-008), the boundary itself signed; every anchor under the old key is marked `key_compromised` and external notarisation, where present, becomes the authority for that range |
| 13 | A verification finds a break at seq 812,411 | Verification stops, records the break, localises via anchors to a business date, opens an incident, alerts out-of-band. The application continues (R-XXIII-066) |
| 14 | An operator "fixes" a break by editing the ledger | Impossible through the application; through the database it produces a further break which the next verification finds. External notarisation makes a wholesale rewrite detectable (R-XXIII-072) |
| 15 | A tenant is deleted | The ledger is **retained** for its evidentiary retention period under the tenant's retention classes, then purged as a whole under R-XXIII-086, with the purge audited into the platform ledger |
| 16 | An employee exercises an erasure right | Redaction against diff values (R-XXIII-088), never entry deletion. The entry remains, chaining correctly, marked `redacted` with its authority recorded |
| 17 | A legal hold conflicts with a retention expiry | The hold wins, always (R-XXIII-089) |
| 18 | An archived range is requested during an investigation | The sealed segment is retrieved, verified against its manifest offline, and presented with its verification result. The online chain is unaffected |
| 19 | The archive destination is unreachable at seal time | The seal does not complete and **nothing is removed from the online partition**. Step 6 of R-XXIII-080 is strictly ordered for this reason |
| 20 | Impersonation | Every entry carries `on_behalf_of_user_id`; the acting staff member is `actor_user_id`. Both appear in every view. Impersonation start and end are separately audited |
| 21 | A support grant expires mid-session | The session's authority ends; subsequent writes fail closed and the attempt is audited |
| 22 | An import creates 40,000 employees | One entry per employee (`origin = import`, one `correlation_id`), chained in one batch under one lock acquisition, plus one `import.committed` summary entry |
| 23 | A migration writes historic data | `origin = migration`, `actor_type = system`, with the migration run id; migration-written entries are visually distinguished in every view |
| 24 | Partition creation falls behind | An append into a missing partition fails, which by R-XXIII-015 fails the business transaction — so partition headroom is a monitored health signal with a wide margin, not an assumption |
| 25 | A reader lacks a PII class present in the entry | The entry is returned with those diff rows omitted and a count of omitted rows shown. **Nothing is silently hidden without being counted** |
| 26 | An audit read returns 400,000 rows | The hard cap applies, the response is truncated with a cursor, `audit.bulk_access` is raised, and the read is audited with the true row count of the underlying query |
| 27 | Ledger and effective-dated data disagree about the current value | The effective-dated data is authoritative for state; the disagreement is an integrity defect raising `audit.state_divergence_detected`, investigated as a bug, never reconciled by rewriting either |
| 28 | A rule engine decision with no human actor | `actor_type = system`, with the rule version, the trace id and the scheduled run id. "The system did it" is never an acceptable terminal answer |

---

## XXIII.16 Tests — exact-value regression scenarios

**P0 — must exist before any production traffic**

| # | Scenario | Exact assertion |
|---|---|---|
| T1 | **No direct insert** | A test attempting `DB::table('audit_entries')->insert(...)` fails on privilege; the CI sweep finds zero references to the ledger tables outside `Domain\Audit` |
| T2 | **No nullable hash** | An insert with `prev_hash` or `hash` null is rejected by the database |
| T3 | **The SmartPRS 2 race** | 200 concurrent appends across 20 threads for one tenant produce 200 entries with contiguous `seq` from 1 to 200, a chain that verifies end to end, and **zero false break reports** |
| T4 | **No skip branch** | Source inspection plus a mutation test: an entry whose hash is altered produces `hash_mismatch` and **stops**; the verifier's running hash is never reset. A test that deliberately reproduces the `verify():118-121` shape must fail |
| T5 | **Audit failure aborts the business write** | Fault injection in `AuditService::append()` → the employee update does not persist; the API returns a failure; no partial state |
| T6 | **Per-tenant chain** | Tenant A's break does not affect tenant B's verification; verifying A reads no rows of B |
| T7 | **Cross-tenant isolation** | Generated per endpoint: no entry, diff, anchor, incident or segment is readable across tenants |
| T8 | **Fail-closed reads** | Fault injection inside authorisation denies on every audit route, including `/me/audit` |
| T9 | **Immutability** | `UPDATE audit_entries SET action=…` and `DELETE FROM audit_entries` both fail, under every application role, and the trigger fires |
| T10 | **`storeEmployee` regression** | Creating and updating an employee through **every** surface — UI, API, ESS, mobile, import, self-onboarding, recruitment conversion — produces an entry with field-level diffs from each. *Directly targets `AppDataController::storeEmployee()` writing no audit row and `EssController::updateProfile()` making no audit call* |

**P1 — core behaviour**

| # | Scenario | Exact assertion |
|---|---|---|
| T11 | Diff correctness | Changing `basic` from ₹38,000.00 to ₹42,000.00 yields one diff row, `old_numeric = 38000.0000`, `new_numeric = 42000.0000`, `currency = INR`, `treatment = plain` |
| T12 | Strict comparison | `'0'` → `0`, `0` → `false`, `null` → `''` each produce a diff row. *Targets `AuditTrail.php`'s `$old != $v`* |
| T13 | Empty diff still recorded | Saving a record with no changes writes an entry with zero diff rows. *Targets the early return that logs nothing* |
| T14 | Identifier treatment | Changing a bank account writes `treatment = hashed`, a masked display, and an HMAC; a byte search of the ledger for the account number returns **zero hits**; `HMAC(known_value, key)` equals the stored hash |
| T15 | Secret treatment | A password change writes the field name and no value and no hash of any kind |
| T16 | Digest binding | Altering an `audit_field_changes` row produces `digest_mismatch` on the parent entry |
| T17 | Canonicalisation | Two entries whose field values differ only by delimiter placement produce different hashes. *Targets the `implode('|', …)` ambiguity* |
| T18 | Genesis isolation | An entry lifted from tenant A's chain and inserted into tenant B's does not verify, because the genesis constant includes the tenant id |
| T19 | Batch append | A payroll finalisation writing 10,000 entries acquires the head lock once and produces a contiguous, verifying range |
| T20 | Anchor localisation | Corrupt one entry on 12-Aug in a 90-day ledger: verification reports the chain intact through 11-Aug and broken within 12-Aug, naming the sequence bounds, **without full re-verification** |
| T21 | Anchor chain | Removing one day's anchor is detected by the anchor chain independently of the entry chain |
| T22 | Anchor signature | An anchor re-signed with a different key fails verification against the public key |
| T23 | Incremental verification | Verification of a 5-million-entry ledger runs in bounded memory, resumes from its checkpoint, and never loads the table. *Targets `Audit::verify():111`* |
| T24 | Checkpoint forgery | An unsigned or wrongly signed checkpoint is rejected |
| T25 | Archive continuity | Sealing and removing seq 1–500,000 leaves verification of 500,001 onward passing, chaining to the segment's terminal hash, and reporting the earlier range as **archived**, not missing |
| T26 | Offline verification | A sealed segment verifies with the tool, the manifest and the public key only, with no application present |
| T27 | Redaction preserves the chain | Redacting a diff value leaves the entry verifying, marked `redacted`, with the redaction record retrievable |
| T28 | Legal hold | A held range survives a purge run; the purge is audited; releasing the hold makes it eligible |
| T29 | Purge is whole-segment only | An attempt to delete individual entries from a live range is rejected |
| T30 | Read auditing bounded | Listing 500 employees writes one `audit.read` entry with `row_count = 500`; five identical refreshes within the window coalesce to one entry with an occurrence count of five |
| T31 | Audit of audit | Reading `audit.read` entries writes a new `audit.read` entry; no exemption exists |
| T32 | Class-based redaction on read | A reader without `audit.sensitive.read` receives the entry with sensitive diff rows omitted **and a count of omissions** |
| T33 | Bulk access alert | A read exceeding the threshold raises `audit.bulk_access` to the audit contact |
| T34 | Break alerting | An injected break raises `audit.chain_break_detected` out-of-band even with the queue and the outbox stopped; it cannot be unsubscribed, digested or quiet-houred |
| T35 | Incident permanence | Explaining an incident does not clear the break record; a subsequent export over that range carries the break notice |
| T36 | IAM coverage | Failed login, lockout, role assignment, admin password set, user disable, API key mint and tenant provisioning each write an entry. *Targets the 11-call-site coverage and `hr_manager` → `admin` escalation being unaudited* |
| T37 | Roles at the time | An entry written by a user who later loses a role still shows the role held at write time |
| T38 | Impersonation | Every entry during impersonation carries `on_behalf_of_user_id`; start and end are separately audited |
| T39 | Export bundle | A signed bundle verifies offline; a full-tenant export requires two approvers; the manifest hash appears in the `audit.exported` entry |
| T40 | Issued artefact | Two agent audit packs issued **on the same calendar day** for the same agent have **different** verification codes, both verify, and a forged reference does not. *Targets the deterministic-within-a-day hash at `ComplianceController.php:643-644`* |
| T41 | Effective-dated versus audit | "What was this employee's branch on 15-May-2026?" is answered by **one** service call against assignment data; the same question against the ledger is not implemented and no code path attempts it |
| T42 | Retrospective correction | A June correction of a March-effective transfer changes the May answer from the assignment data **and** produces a second ledger entry explaining the correction; both are visible in the trace |
| T43 | Hard delete prohibited | No code path hard-deletes a business record; the `cleanOrphanCommissions` shape is asserted absent |
| T44 | Volume and partitioning | A synthetic year at the §XXIII.8.1 rates partitions correctly, and the record-history query stays within its latency budget at 5 million entries |
| T45 | Trace provenance | Every S15 trace surfacing a value derived from an audited change links to the entry **and** states the verification status of its range |

**P2 — migration**

| # | Scenario | Exact assertion |
|---|---|---|
| T46 | Legacy segregation | Zero legacy rows land in `audit_entries`; every one lands in `audit_legacy_entries` with a `chain_status` |
| T47 | Honest legacy verification | Run against a fixture reproducing the four direct-insert sites: the report names each break position and does **not** report the ledger as verified |
| T48 | Genesis | The new chain starts at epoch 1, seq 1, with a genesis entry recording the legacy row count and the integrity report hash |
| T49 | Ambiguous actors | A display name matching two users leaves `actor_user_id` NULL and flags `ambiguous_actor`; no name is guessed |
| T50 | Spatie removed | `activity_log` and the package are absent from the schema and from `composer.json` |

---

## XXIII.17 Open items — PRODUCT OWNER DECISION REQUIRED

**PO-XXIII-01 — The enforcement mechanism where per-table privilege cannot be expressed.**
*Context:* R-XXIII-022 relies on database role separation on the same connection, which not
every supported deployment target expresses identically, particularly constrained on-premise
MySQL installations.
**Recommendation: ship all five mechanisms, require Mechanisms 2 to 5 everywhere, and make
Mechanism 1 a strong recommendation that the installer verifies and the health report
states.** *Trade-off:* an installation without privilege separation is one mechanism weaker,
and the customer should know which posture they are running. *If the answer is to require
Mechanism 1 absolutely,* some on-premise installations need database administration the
customer may not have.

**PO-XXIII-02 — Read auditing scope.**
*Context:* R-XXIII-036 bounds read auditing to a declared classified set. §XXIII.8.1 shows
access and authentication classes at roughly four times the mutation volume even so; an
unbounded design is roughly an order of magnitude larger.
**Recommendation: the declared set as listed, with per-tenant extension permitted and
per-tenant reduction prohibited.** *Trade-off:* a customer under a specific regulatory
regime may need broader read auditing and can extend; nobody may narrow below the floor,
because the floor is what makes the product defensible in an inspection.

**PO-XXIII-03 — External notarisation of daily anchors.**
*Context:* purely local signing means a sufficiently privileged operator on an on-premise
installation could rewrite history and re-sign it. External counter-notarisation of the
anchor hash — hash only, no content — removes that.
**Recommendation: default ON for SaaS; OPTIONAL and opt-in for on-premise, presented at
installation with a plain statement of what it does and does not disclose.** *Trade-off:*
opting out is a materially weaker integrity posture and the health report should say so;
requiring it would make an air-gapped deployment impossible, and some customers genuinely
are air-gapped.

**PO-XXIII-04 — Erasure versus evidence.**
*Context:* R-XXIII-088 redacts diff values and preserves the entry. This is a considered
position on a genuine tension between a data-protection erasure obligation and an
evidentiary record-keeping obligation, and **it is a legal question, not an engineering
one.** No legal position is asserted here.
**Recommendation: adopt the redaction model, and obtain written counsel confirming it, and
record that confirmation as part of the compliance pack shipped to customers.**
**PRODUCT OWNER + LEGAL DECISION REQUIRED.**

**PO-XXIII-05 — Retention periods per class.**
*Context:* §XXIII.8.4's periods are recommendations. SmartPRS 2 has no retention at all
against a **published Privacy Policy committing to ninety days**
(`PolicyController.php:12`; `E-…` PO-11, SEC-18), which is a live contradiction that must
be resolved before release: either the published commitment is amended, or the retention is
shortened. It cannot remain as it is.
**Recommendation: set `evidentiary_hr` to 8 years, `evidentiary_statutory` from the rule
pack per statute, `security` 3 years, `access` 2 years, and amend the published policy to
distinguish operational logs from evidentiary records.**
**PRODUCT OWNER + LEGAL DECISION REQUIRED.**

**PO-XXIII-06 — Does the legacy `activity_logs` archive ship to customers at all?**
*Context:* R-XXIII-110 keeps it, marked unverifiable. The alternative is not to migrate it.
**Recommendation: keep it, labelled.** *Trade-off:* it will be read as an audit trail by
somebody despite the label, and the label must therefore appear on every screen and in every
export, not only at import. *If the answer is not to keep it,* the customer loses the only
record of pre-migration activity, imperfect as it is — and that loss should be an explicit,
signed customer decision, not a silent consequence.

**PO-XXIII-07 — Who is the tenant's designated audit contact?**
*Context:* R-XXIII-067 requires a mandatory, non-empty tenant role to receive integrity
alerts. Without a named human, a chain break alerts nobody, which is the SmartPRS 2 outcome
by a different route.
**Recommendation: mandatory at provisioning, blocking completion; the tenant owner is the
default; a quarterly confirmation prompt keeps it current.**

**PO-XXIII-08 — Retrospective visibility of the sensitive-action review queue.**
*Context:* the `(tenant_id, severity, occurred_at)` index exists to support a standing
review queue of `sensitive` and `critical` actions — bank changes, salary changes, role
grants, gate overrides, bulk exports.
**Recommendation: ship the queue, with a per-tenant configurable requirement that entries be
reviewed and signed off within an SLA.** *Trade-off:* a review queue nobody works is worse
than none, because it manufactures an appearance of oversight; recommend shipping it **off**
by default and enabling it deliberately.

---

## XXIII.18 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XXIII-001 – R-XXIII-005 | What the ledger is; schema invariants (§XXIII.2.1–2.2) | 5 |
| R-XXIII-006 – R-XXIII-011 | The hash chain, canonicalisation, epochs, genesis, actor identity (§XXIII.2.3) | 6 |
| R-XXIII-012 – R-XXIII-016 | Appending, the head lock, atomicity, no best-effort, no queue (§XXIII.2.4) | 5 |
| R-XXIII-017 – R-XXIII-021 | The daily signed anchor (§XXIII.2.5) | 5 |
| R-XXIII-022 – R-XXIII-029 | The single write path; five mechanisms; no skip branch (§XXIII.3) | 8 |
| R-XXIII-030 – R-XXIII-031 | The action catalogue as data (§XXIII.4.1) | 2 |
| R-XXIII-032 – R-XXIII-035 | Configuration publication, export, access, issued artefacts (§XXIII.4.3–4.5) | 4 |
| R-XXIII-036 – R-XXIII-040 | Read auditing, coalescing, high-frequency boundaries (§XXIII.4.6–4.7) | 5 |
| R-XXIII-041 – R-XXIII-049 | Field-level capture by the write service; the diff record (§XXIII.5.1–5.3) | 9 |
| R-XXIII-050 – R-XXIII-054 | Sensitive values: masked, hashed, suppressed (§XXIII.5.4) | 5 |
| R-XXIII-055 – R-XXIII-058 | Effective-dated history versus audit (§XXIII.6) | 4 |
| R-XXIII-059 – R-XXIII-063 | The verify procedure (§XXIII.7.1) | 5 |
| R-XXIII-064 – R-XXIII-072 | Break classes, reporting, escalation, anchor localisation (§XXIII.7.2–7.4) | 9 |
| R-XXIII-073 – R-XXIII-079 | No hard delete; volume; partitioning; performance (§XXIII.8.1–8.2) | 7 |
| R-XXIII-080 – R-XXIII-084 | Archival with preserved verifiability (§XXIII.8.3) | 5 |
| R-XXIII-085 – R-XXIII-090 | Retention, purge, redaction, legal hold (§XXIII.8.4) | 6 |
| R-XXIII-091 – R-XXIII-099 | Access to the trail; audit of audit access; views (§XXIII.9.1–9.3) | 9 |
| R-XXIII-100 – R-XXIII-104 | Regulator and auditor export (§XXIII.9.4) | 5 |
| R-XXIII-105 – R-XXIII-106 | API constraints (§XXIII.11) | 2 |
| R-XXIII-107 – R-XXIII-109 | Explainability (§XXIII.13) | 3 |
| R-XXIII-110 – R-XXIII-114 | Migration (§XXIII.14) | 5 |
| **Total** | | **114** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | The action catalogue, retention classes and periods, the online window, read-audit scope, coalescing windows, verification schedule and chunk size, break thresholds, escalation ladders and hold policies are all data. The only constants in code are cryptographic: the hash algorithm identifier, the canonicalisation version and the genesis derivation — declared platform constants on the allow-list, not business values | Static analysis over `Domain\Audit`; the action catalogue is a table, not an enum |
| **2 — no schema change at runtime** | All eleven tables in §XXIII.10 are migration-owned. There is no successor to `Audit::ensure():25-46`, which adds `prev_hash` and `row_hash` to `activity_logs` at runtime. Partition creation is a scheduled maintenance operation under the maintenance role, not application DDL | CI grep for DDL outside migrations; `migrate:fresh` parity; T24's partition-headroom signal |
| **3 — nothing overwritten** | The ledger is append-only: no `UPDATE`, no `DELETE`, no `updated_at`, no `deleted_at`, no grant, and a trigger. `audit_chain_heads` is the **only** updatable row, and only under lock inside an append. Redaction adds a record and preserves the entry; archival seals and preserves the chain | T9, T25, T27 |
| **4 — every number explains itself** | The ledger supplies the "who and why" half of every S15 trace (§XXIII.13), and every trace states the verification status of the range its evidence falls in | T45 |
| **5 — authorisation fails closed** | Every route in §XXIII.11 carries an explicit policy binding; permission and data scope are both required; PII classes gate diff visibility with omissions counted, never silently hidden; platform access requires a time-boxed tenant-approved grant and is doubly recorded; **no write endpoint exists at any permission level** | T8 fault injection; T32; route-policy sweep; the `denyUnlessRole` fail-open shape asserted absent |
| **6 — tenancy is structural** | `tenant_id NOT NULL` on every table and in every unique index; the chain is **per tenant** with a tenant-derived genesis, so a foreign row cannot verify; verification, anchoring, archival, purge and export are all per tenant. The single documented exception is the platform-owned action catalogue, which holds no business data | T6, T7, T18; the `Audit.php:91` global-head shape asserted absent |
| **7 — money is exact** | Money in a diff is stored as `DECIMAL(18,4)` with an explicit currency and is a **recorded fact, never a computed one** — this part performs no arithmetic on money. Rendering uses Indian grouping (₹1,50,000.00) at the presentation layer only | T11 exact-value assertion; no float and no arithmetic operator over a money column anywhere in `Domain\Audit` |
