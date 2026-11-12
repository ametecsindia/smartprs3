# PART XXVI — ON-PREMISE ARCHITECTURE

*Covers brief Sections 87 (the on-premise side of deployment parity), 88 (installation), 91
(backup and disaster recovery) and 92 (background-job reliability).*
*Conforms to spine S1 (the seven laws — Law 2 in particular, which this part enforces at the
database-privilege level), S2 (one artefact; the installer refuses to complete without a
working scheduler and queue worker), S13 (security; no default credential ships in any
seeder, installer or release), S16 (data conventions), S18 (deployment parity, the installer,
backups with a tested restore, and the operations dashboard).*
*Depends on: Part XXV (which owns the provisioning pipeline this part's installer calls, the
configuration-template model, and the seat counter), Part XXVII (which owns the licence
artefact the installer activates and the update channel the operations console reports on),
Part XXXI (which owns the metric catalogue, log format, health-endpoint contract and alerting
this part's console renders), Part XXII (security), Part XXIII (audit).*
*Depended on by: Part XXVII (whose update flow runs inside the runtime this part installs).*
*Evidence base: `analysis/I-saas-licensing-deployment.md` and
`analysis/L-background-ops-observability.md`, with the runtime-DDL census from
`analysis/J-database.md` §6.5 and `analysis/G-security-tenancy-api.md`, and the scale figures
from `analysis/M-quality-performance-testing.md` §6.3. Every claim about SmartPRS 2 carries a
`file.php:line` citation carried through from those files.*

---

## XXVI.0 Purpose

This part decides four things.

1. **That the operational runtime is part of the product.** In SmartPRS 2 it is not in the
   repository at all — there is no `deploy/` directory, no cron file, no worker unit, no
   service definition and no runbook (verified absent by `find`; `L-…` DEF-L-27, severity
   CRITICAL) — and the README instructs the operator to run `php artisan queue:work` and
   `php artisan schedule:work` by hand in *"separate terminals"* (`README.md:50-51`). Nothing
   in a release enforces or verifies that either is installed. The two most damaging and most
   silent production failures in the product have no artefact that prevents them.
2. **That an installer that completes leaves a system that works, with no default
   credential.** SmartPRS 2's on-premise seeder creates `admin@smartprs.local` with
   `Hash::make('password')` and the `admin` role, is gated by neither `APP_ENV` nor
   `SMARTPRS_DEMO_DATA`, and prints the password to the console on success
   (`OnPremWorkspaceSeeder.php:70,79,101`); the README publishes that credential and four
   others in a table (`README.md:56-64`); and an unconditional nightly task at 03:30 creates
   or **takes over** administrator accounts with a published password through a
   tenant-unscoped email lookup (`routes/console.php:208-211`; `DemoReset.php:35,101-123`,
   lookup at `:106`; `L-…` SEC-L-01, severity CRITICAL).
3. **That a backup without a restore is not a backup.** SmartPRS 2 writes an unencrypted
   archive to `storage/app` **on the machine it is protecting** (`config/backup.php:72-74`),
   includes `.env` with `APP_KEY` and `DB_PASSWORD` (`:28-38`), **excludes** `storage/app` and
   therefore the licence private key and every uploaded document (`:35`), tries once with no
   retry (`:83-84`), configures no monitoring and no failure notification (`:87-92`), and
   hard-codes the database connection name so an install on any other connection backs up no
   database at all and says nothing (`:45-46`). And **there is no data restore path anywhere
   in the product** — no command, no route, no UI, no runbook; eighteen console commands and
   none of them restores (`L-…` §Q5; `I-…` §10 Q10).
4. **That the application database user holds no schema privilege.** SmartPRS 2 executes six
   raw `ALTER TABLE` statements on HTTP request paths — `MasterController.php:528`, `:547`,
   `ApprovalService.php:229`, `SchemeController.php:107`, `:112`,
   `CodeOfConductController.php:65` (`J-…` §6.5) — on top of 71 `Schema::create()` and 84
   `Schema::table()` call sites across 58 files (`G-…`). The web database user must therefore
   hold `ALTER` in production, which makes least privilege impossible and turns any injection
   or RCE into a schema-destructive event (`J-…` SEC-DB-02, HIGH).

What this part does **not** own: the licence artefact, activation, entitlements, editions and
the update package (Part XXVII); the metric catalogue, structured-log format, tracing and
alert routing (Part XXXI); the provisioning pipeline and configuration templates (Part XXV);
tenant isolation enforcement (Part XXII, Part XXVIII).

---

## XXVI.1 What SmartPRS 2 does today, and why it must change

### XXVI.1.1 Two credentials that a customer never chose

```php
// database/seeders/OnPremWorkspaceSeeder.php:70,79
'email'    => 'admin@smartprs.local',
'password' => Hash::make('password'),
// … syncRoles(['admin']) …
// :101  the success message prints the password
```

It is run manually as `db:seed --class=OnPremWorkspaceSeeder` and is covered by **neither**
`SMARTPRS_DEMO_DATA` nor `APP_ENV` — unlike `DemoCompanySeeder`, which is correctly gated by
`DatabaseSeeder.php:19-26` (`I-…` SEC-04). `README.md:56-64` then publishes all five seeded
logins and their shared password in a table.

```php
// routes/console.php:208-211
Schedule::command('demo:reset')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// app/Console/Commands/DemoReset.php:35
{--password=smartprs-demo}
```

`DemoReset` reads neither `APP_ENV` nor `SMARTPRS_DEMO_DATA` (verified by grep). On any server
running the scheduler, if no tenant with `subdomain='demo'` exists one is **created**, along
with `demo-admin@smartprs.in` holding the Spatie role `admin`, plus `demo-hr@` and
`demo-field@`, each `status='active'` with `password = Hash::make('smartprs-demo')` — echoed
to stdout at `:152`. If those emails already exist under **another** tenant they are taken
over: the lookup at `:106` is `whereRaw('LOWER(email) = ?')` with **no tenant scope**, and the
update at `:108-111` reassigns `tenant_id` and overwrites the password. Nightly re-creation
defeats manual deletion; nightly password reset defeats a manual password change
(`L-…` SEC-L-01).

> **Because of this:** SmartPRS 3 ships **no** credential in any artefact (R-XXVI-050), the
> installer creates the first administrator interactively with a secret the operator chooses
> (R-XXVI-051), and no scheduled task may create, modify or take over a user account
> (R-XXVI-052). A destructive or account-creating command is not schedulable at all without an
> explicit environment allow-list and a per-install opt-in (R-XXVI-053).

### XXVI.1.2 The runtime is not in the product

`find . -iname '*deploy*' -o -iname '*.cron' -o -iname '*supervisor*' -o -iname '*.service'`
returns nothing (`L-…` §0.5). Every claim the reference documentation makes about a cron
line, a Supervisor unit, a systemd service, `numprocs=1`, `--queue=mail` and `/dev/null`
output redirection is unverifiable from the delivered tree. Separately, `I-…` §5(b) item 48
records that **there is no Windows scheduler or Windows service artefact for the on-premise
product at all** — the only scheduler artefact in the delivery is a UNIX crontab line, while
the typical on-premise install is Windows/Laragon.

The consequence, from `L-…` §Q9, is the blast radius of a stopped scheduler:

| Feature | What the customer experiences | Time to hurt |
|---|---|---|
| Biometric attendance | Punches stop importing; because the window is hard-coded `--days=1` (`routes/console.php:225`), **an outage longer than 24 hours loses punches permanently** | 1 hour; unrecoverable after 24 |
| Payroll | Computed from an incomplete register; LOP over-stated, overtime under-paid | at month end |
| Backups | Stop that night; no archive, no error. Discovered during an attempted restore — which does not exist | 1 night |
| Subscription lifecycle | No warnings; exact-equality milestone matching means a missed milestone is lost forever; the tenant is suspended on the first run after the scheduler returns, **having received no warning at all** | 15 days out |
| Compliance (DRA/PCC/BGV) | Field agents work with expired certification — the regulatory exposure the product markets itself on | 1 day |
| Probation, transfers, absence, greetings | Silently stop; no catch-up | 1 day |
| Demo privacy | A prospect's typed data is never erased | 15 minutes |
| Error digest | **The failure of the scheduler silences the only thing that could have reported the failure of the scheduler** | 1 day |

And of a stopped worker: rows accumulate in `jobs` indefinitely, `mail_log.status` stays
`queued` forever, and **no error, no log line, no `failed_jobs` row and no alert** is produced.
Three facts compound it (`L-…` §Q9(B)): the auto-suspend write is direct and unqueued
(`SubscriptionAlerts.php:65`) while every warning is queued, so a paying customer is locked
out having received none of them; `subscription_alerts.status='sent'` is written on the basis
of a `mail_log` id returned before any delivery attempt (`MailService.php:119`), so **the
system positively asserts that an email was sent when it was only enqueued**; and credential
mail is sent inline, so the diagnostic an operator would naturally run comes back green.

There is direct in-code evidence that this has already happened in production and cost a
paying customer access to their workspace — `MailService.php:102-106` records the fix:
*"rev 170: CRITICAL mails … are sent INLINE … so they go out even when no queue worker is
running (the silent failure that locked a paying client out of their new workspace)."*

> **Because of this:** the scheduler and the worker ship **as installable services for every
> supported platform**, the installer registers and verifies them, and the application detects
> and reports their absence loudly (R-XXVI-030, R-XXVI-070, R-XXVI-100).

### XXVI.1.3 Backups that protect nothing, and no restore at all

| Property | SmartPRS 2 | Evidence |
|---|---|---|
| Destination | `storage/app` — the same server, the same volume, that it is backing up. The config comment concedes it: *"For real safety add a remote 'backups' disk (S3/Spaces)… so backups survive a server loss."* | `config/backup.php:69-75` |
| Encryption | **None by default.** `BACKUP_ARCHIVE_PASSWORD` is unset; AES-256 applies only if it is set | `:79-81` |
| Contents — included that should not be | **`.env`**, carrying `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, gateway secrets. `APP_KEY` decrypts `licences.key_enc` and six other encrypted columns. The dump adds every password hash, `personal_access_tokens`, `api_keys.key_hash`, `wa_log.body_values` (OTPs and plaintext first-login passwords) and `jobs`/`failed_jobs.payload` (invite-token URLs and base64 PDF attachments) | `:28-38`; `L-…` SEC-L-03, SEC-L-06, SEC-L-07 |
| Contents — excluded that must not be | **`storage/app`** — which holds `keys/license_private.pem`, the signing identity for every perpetual licence ever issued, and **every user-uploaded document**: branding, employee photos, self-onboarding documents, commission proofs | `:35`; `L-…` SEC-L-13 (HIGH, availability) |
| Retry | `tries = 1`, `retry_delay = 0` | `:83-84` |
| Monitoring | `notifications` and `monitor_backups` deliberately omitted; `notifications.mail.to` set nowhere; `backup:monitor` not scheduled. `.env.production.example:62-63` describes `SMARTPRS_OPS_EMAIL` as the *"backup-failure + daily error-digest recipient"* — but grep confirms that variable is read **only** by `ErrorDigest.php:25` | `:87-92`; `L-…` DOC-L-12 |
| Database targeted | the literal `['mysql']` — an install on any other connection name backs up **no database at all**, silently | `:45-46`; `L-…` DEF-L-26 |
| Retention | **Not determinable from source.** The `cleanup` section is deliberately omitted so the package default applies | `:88-92`; `L-…` OQ-L-03 |
| Hard dependency | the PHP `zip` extension; *"Without it backups can't run."* Nothing at run time checks this or reports its absence | `:15-17` |
| **Restore** | **Does not exist.** No `backup:restore` command, no route, no UI, no runbook, no drill artefact, no `RESTORE.md`. Eighteen console commands, none restores. The only rollback in the codebase is code-only, inside the on-premise self-updater (`updater/updater.php:140-159`), and it does not restore the database while step 9 runs `migrate --force` (`:307`) | `L-…` §Q5; `I-…` §10 Q10 |
| Operator visibility | **None.** No backup screen, no last-run indicator, no restore button. `/admin` is 404 on a client install | `I-…` §10 Q10 |

> **Because of this:** §XXVI.8 specifies a backup set that includes what recovery needs and
> excludes what must never travel, encrypted by default, off-server, monitored, retried,
> retained on an explicit policy — and a **restore path that exists, is documented, and is
> exercised on a schedule with the last successful test date visible in the UI**
> (R-XXVI-120 … R-XXVI-146).

### XXVI.1.4 Six `ALTER TABLE` statements on request paths

| Site | Statement | Runs on |
|---|---|---|
| `MasterController.php:528` | `ALTER TABLE wa_settings MODIFY company_id BIGINT UNSIGNED NULL` | every `wa-settings` master request |
| `MasterController.php:547` | `ALTER TABLE <table> MODIFY <col> DECIMAL(11,7) NULL` | every master request whose coordinate scale ≠ 7 — evidence of prior silent 1 km coordinate truncation (`J-…` NEW-DB-003) |
| `ApprovalService.php:229` | `ALTER TABLE <table> MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'` | **every** list / apply / decide / inbox call, on any of nine tables |
| `SchemeController.php:107` | `ALTER TABLE incentive_schemes MODIFY status VARCHAR(20) NULL DEFAULT 'active'` | every Schemes request |
| `SchemeController.php:112` | `ALTER TABLE incentive_schemes MODIFY <col> <type> NULL` | same |
| `CodeOfConductController.php:65` | `ALTER TABLE code_of_conduct_ack MODIFY employee_id BIGINT UNSIGNED NULL` | every Code-of-Conduct request |

Six of the runtime-DDL sites are reachable from **unauthenticated** endpoints — `/iclock` →
`PushController::ensure()` and the `SchemaHelper` chain (`M-…` S-12).

The on-premise consequence is specific and severe: on a correctly hardened database where the
application user lacks `ALTER`, every one of these fails **silently**, because every call site
swallows the exception — and the product then degrades feature by feature with no error. Four
of those silent degradations are money defects: if `overtime.paid_run_id` cannot be added, OT
is never stamped paid and **next month's run pays the same overtime again**; if
`loans.installments_paid` cannot be added, the loan never closes and the EMI is deducted
**forever**; if `loan_recoveries` cannot be created, a regenerated run **double-recovers**
every EMI; if the clawback columns cannot be added, the clawback is recovered again next month
(`PayrollGenController.php:2571-2616`; `L-…` §Q3 item 1).

> **Because of this:** the installer creates two database users — a migration user with schema
> privileges used only during install and update, and an application user with **no** `CREATE`,
> `ALTER`, `DROP` or `INDEX` — and the health check asserts the absence of those privileges as
> a **pass condition**, not a warning (R-XXVI-016, R-XXVI-090).

---

## XXVI.2 What is identical, and what may legitimately differ

Spine S18 is absolute: one artefact; SaaS, private cloud and on-premise differ only in
infrastructure configuration, never in business logic; there is no `if (on_prem)` in a domain
path. The **complete enumeration** of legitimate deployment-time differences is specified once,
in Part XXXI §XXXI.2, and is not repeated here. This section states only what that means for an
on-premise install.

| Concern | On-premise | Where it is decided |
|---|---|---|
| Business logic | **Identical.** Byte-for-byte the same domain code | spine S18; enforced by R-XXXI-004 |
| Access gate | licence (Part XXVII) rather than subscription (Part XXV) | infrastructure configuration selects the gate implementation; both implement one interface |
| Tenancy | Single tenant is the normal case; the schema, the scoping and the tests are unchanged | Part XXII, Part XXVIII |
| Vendor-facing surfaces | The platform console, signup, quotations and the vendor update server are not routed | route registration by role, not by branching inside a handler |
| Backup destination | Customer-owned; may be a local NAS, an S3-compatible endpoint, or removable media | §XXVI.8.3 |
| Scheduler and worker | Installed as OS services by the installer | §XXVI.5.6 |
| Update delivery | Customer-pulled and customer-applied | Part XXVII §XXVII.4 |
| Rule packs | Pulled, or side-loaded on media in an air-gapped install | Part XXVII §XXVII.5, §XXVI.10 |
| Observability sink | Local by default; exportable to a customer SIEM | Part XXXI §XXXI.6 |

- **R-XXVI-001** The on-premise artefact MUST be the same build, with the same checksum, as the
  artefact Ametecs runs in its own cloud. *Test: the published release checksum matches the
  artefact under test in both environments — the direct fix for SmartPRS 2's separate edition
  packaging, which produced three price points and one product (`I-…` DEF-02).*
- **R-XXVI-002** No domain code may branch on deployment model, edition or licence state.
  *Test: static analysis over `Domain\*`; the twenty-one branch sites enumerated at
  `I-…` §10 Q8 have no successors in `Domain\*`.*
- **R-XXVI-003** Every difference in the table above MUST be expressed as installation
  configuration validated at boot, and an invalid combination MUST refuse to boot with a named
  reason. *Test: the SmartPRS 2 combination that is neither subscription-gated nor
  licence-gated (`I-…` DEF-36) is rejected at boot.*

---

## XXVI.3 Reference architectures

> **These are planning baselines, not measurements.** They must be validated against the load
> harness specified in Part XXXI §XXXI.9 before publication to customers. Where SmartPRS 2
> figures are cited they are the row-count model from `M-…` §6.3 (4 punches per employee per
> day, ~250 working days per year), which is arithmetic, not a benchmark.

### XXVI.3.1 Volumetric model

| Employees | `punch_events` + normalised (3 yr) | `attendance_days` (3 yr) | payslips (3 yr) | documents (typical) |
|---:|---:|---:|---:|---:|
| 50 | ~150,000 | ~37,500 | 1,800 | 0.5–2 GB |
| 1,000 | ~3,000,000 | ~750,000 | 36,000 | 10–40 GB |
| 10,000 | ~30,000,000 | ~7,500,000 | 360,000 | 100–400 GB |

Attendance is the dominant table by row count; documents are the dominant consumer of bytes.
Both drive the backup window, which is why §XXVI.8 separates the database backup from the
document backup.

### XXVI.3.2 Small — up to 50 employees, single server

```
┌──────────────────────── one host ────────────────────────┐
│ web server (TLS termination)                             │
│ PHP-FPM  ×2–4 workers                                    │
│ MySQL 8.0 (local)   Redis (local)                        │
│ scheduler service ×1   queue worker service ×2 processes │
│ documents on local disk                                  │
│ backup → removable/NAS target + off-site copy            │
└──────────────────────────────────────────────────────────┘
```

| | Baseline |
|---|---|
| CPU / RAM | 4 vCPU / 8 GB |
| Storage | 100 GB SSD, of which ≥40 GB free at all times |
| Database | InnoDB buffer pool ~2 GB |
| Redis | 512 MB, persistence **on** (see R-XXVI-011) |
| Worker processes | 2 (`interactive`+`notification`, `bulk`+`integration`+`maintenance`) |
| HA | none; recovery is restore-from-backup, RTO governed by D-XXVI-03 |
| Backup window | minutes |

### XXVI.3.3 Medium — up to 1,000 employees

```
┌─ app host(s) ×2 ────────────┐   ┌─ data host ──────────────┐
│ web + PHP-FPM               │   │ MySQL 8.0 primary        │
│ queue workers per class     │◀─▶│ Redis (persistent)       │
│ scheduler (leader-elected)  │   │ documents on NAS/object  │
└─────────────────────────────┘   └──────────────────────────┘
                     backup → off-server target, encrypted
```

| | Baseline |
|---|---|
| App hosts | 2 × 4 vCPU / 8 GB (also gives rolling updates) |
| Data host | 8 vCPU / 32 GB; buffer pool ~16 GB |
| Storage | 500 GB SSD data + separate document volume |
| Worker processes | 6–10 across five queue classes (§XXV.12.3) |
| Scheduler | one active leader, elected through the Redis lock store |
| HA | optional MySQL replica for read offload and faster recovery |
| Backup window | tens of minutes; documents backed up incrementally |

### XXVI.3.4 Large — up to 10,000 employees

```
┌ load balancer ┐
└──────┬────────┘
   ┌───▼────────────────┐  ┌────────────────────┐  ┌──────────────────┐
   │ app hosts ×3+      │  │ worker hosts ×2+   │  │ MySQL primary    │
   │ (HTTP only)        │  │ (queue classes)    │──│  + replica(s)    │
   └────────────────────┘  │ scheduler leader   │  │ Redis (HA)       │
                           └────────────────────┘  │ object storage   │
                                                   └──────────────────┘
                    backup → off-server, encrypted, verified, restore-drilled
```

| | Baseline |
|---|---|
| App hosts | 3+ × 8 vCPU / 16 GB |
| Worker hosts | 2+ × 8 vCPU / 16 GB, workers separated by queue class so a payroll run cannot starve notifications |
| Database | 16 vCPU / 64–128 GB; buffer pool sized to the hot working set; `attendance_days` and `punch_normalised` partitioned by month (Part XXVIII §XXVIII.9) |
| Storage | 2 TB+ tiered; documents in object storage |
| HA | replica with a documented promotion procedure; Redis with persistence and failover |
| Backup | database via a consistent snapshot or a replica dump; documents incrementally; both verified |
| Restore drill | mandatory quarterly at this size (R-XXVI-140) |

- **R-XXVI-010** Every reference architecture MUST be published with the load-harness result
  that validates it, and MUST be re-validated on every major release. *Test: a release cannot
  be published without a harness run at all three sizes (Part XXXI §XXXI.5).*
- **R-XXVI-011** Redis MUST be configured with persistence in every topology, and the installer
  MUST refuse a Redis without it. *Test: losing Redis loses no queued work — SmartPRS 2 loses
  every queued notification silently, with no `failed_jobs` row and no trace, and additionally
  stops the entire schedule because the overlap locks live there (`L-…` §Q9(C)).*
- **R-XXVI-012** The scheduler MUST be a single elected leader with a lease, never "whichever
  host has cron installed". *Test: killing the leader elects another within the declared lease
  window; two leaders never run concurrently.*
- **R-XXVI-013** Documents MUST never be served from a web-readable path in any topology.
  *Test: a direct URL to a document path returns 404; every document is streamed through an
  authorised controller (spine S13).*

---

## XXVI.4 Prerequisite matrix

Every row is checked by the installer (§XXVI.5.2) and re-checked by the health check
(§XXVI.6). Severity `blocking` means the installer will not proceed.

| # | Prerequisite | Required | Severity | Why |
|---|---|---|---|---|
| 1 | Operating system | a supported release, listed per version | blocking | service registration differs per platform |
| 2 | PHP runtime | the declared major.minor for this release | blocking | SmartPRS 2 hard-requires 8.2 and advises 8.3 (`InstallController.php` `REQUIRED_EXT`/floor) |
| 3 | PHP extensions | the declared closed list (JSON, PDO, pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, fileinfo, bcmath, curl, gd/imagick, **zip**, intl, sodium) | blocking | SmartPRS 2 hard-requires twelve (`InstallController.php:30-33`) and treats `zip` — without which **backups cannot run** (`config/backup.php:15-17`) — as one of a general list nothing re-checks |
| 4 | PHP settings | `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`, `opcache` enabled, `disable_functions` not blocking process control | blocking / advisory per item | `canSpawn()` in SmartPRS 2 tests the wrong function with `||`, so a host that disabled the one actually used passes pre-flight and then fails silently (`I-…` DEF-32) |
| 5 | Database engine and version | MySQL 8.0+ (or the declared compatible list) | blocking | |
| 6 | Database character set and collation | `utf8mb4` + the declared collation | blocking | |
| 7 | Database privileges — migration user | `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`, DML | blocking | |
| 8 | Database privileges — application user | DML **only**; `CREATE`/`ALTER`/`DROP`/`INDEX` **absent** | blocking | Law 2; the direct fix for the six request-path `ALTER TABLE`s (`J-…` §6.5) |
| 9 | Database `sql_mode`, timezone tables, `innodb_file_per_table`, `max_allowed_packet` | declared values | blocking / advisory | |
| 10 | Cache/lock store | Redis reachable, persistence on, atomic locks supported | blocking | `onOneServer()` silently stops deduplicating on a store without locks (`L-…` §Q1) |
| 11 | Queue backend | reachable and writable | blocking | |
| 12 | Object/document storage | path or endpoint writable, not web-readable | blocking | |
| 13 | Free disk | ≥ the declared headroom on data, document, backup and log volumes | blocking | |
| 14 | HTTPS | a valid certificate, or an explicit, recorded, time-limited operator acknowledgement for an internal-only install | blocking unless acknowledged | |
| 15 | Outbound mail | SMTP host reachable and a test message delivered | blocking | credential delivery depends on it |
| 16 | Time synchronisation | NTP active, drift within tolerance | blocking | every lifecycle date, licence expiry and schedule depends on it |
| 17 | Timezone and locale | explicit business timezone recorded; not inherited from the host | blocking | SmartPRS 2 runs the entire schedule against `config('app.timezone')` defaulting to UTC, so an install that omits the line runs **5 h 30 m off** and the day-boundary tasks target the wrong day (`L-…` §Q1) |
| 18 | Service manager | systemd / Windows Service Control Manager / launchd available and usable by the install account | blocking | this is what makes R-XXVI-070 possible |
| 19 | Process control | `proc_open`/`popen` (per platform) available and not disabled | blocking | |
| 20 | Licence | a valid licence artefact, or an explicit offline-activation path (Part XXVII) | blocking | |
| 21 | Rule pack | a signed statutory pack covering the declared jurisdictions, at or above the template's minimum | blocking | a payroll product with no statutory content is not installed |
| 22 | Backup destination | at least one **off-server** destination reachable and writable, credentials verified | blocking unless explicitly deferred with a recorded acknowledgement | §XXVI.8.3 |
| 23 | Restore prerequisites | the restore tool's dependencies present (database client, decryption, archive tools) | blocking | a restore path that cannot run on the box is not a restore path |
| 24 | Egress policy | reachability of the vendor endpoints, or a declared air-gapped mode | advisory | §XXVI.10 |

- **R-XXVI-014** The prerequisite matrix MUST be data, versioned with the release, so a new
  requirement ships without a code change to the installer. *Test: adding a prerequisite is a
  configuration change; the installer renders it with its severity and its remediation text.*
- **R-XXVI-015** Every prerequisite MUST carry operator-facing remediation text naming the
  exact command or setting. *Test: every failing check renders remediation, never a bare
  "failed".*
- **R-XXVI-016** The application database user MUST NOT hold `CREATE`, `ALTER`, `DROP` or
  `INDEX`, and the installer MUST verify this by **attempting** a DDL statement as that user
  and requiring it to fail. *Test: an install where the application user can `ALTER` is
  refused — the direct enforcement of Law 2 against `J-…` §6.5.*

---

## XXVI.5 The installer (brief Section 88)

### XXVI.5.1 Shape

One installer, three faces, one implementation:

| Face | For | Notes |
|---|---|---|
| **Interactive TUI/CLI** | the normal on-premise install | the reference implementation |
| **Browser first-run wizard** | operators without shell comfort | SmartPRS 2's `/install` pre-flight is described by `I-…` §9 row 31 as *"the best-designed piece of operator UX in the area"* and is retained in concept |
| **Unattended (answer file)** | fleet, imaging, air-gapped, re-install | a signed or checksummed answer file; no interactive default credential — see R-XXVI-051 |

- **R-XXVI-020** There MUST be exactly one installer implementation behind all three faces, and
  exactly one provisioning call (Part XXV R-XXV-020). *Test: the three faces produce
  byte-identical configuration from the same inputs — the direct fix for SmartPRS 2's three
  divergent provisioners (`SaasController:132-228`, `InstallController.php:192-204`,
  `ClientProvision.php:66-78`).*
- **R-XXVI-021** The installer MUST be idempotent and resumable: re-running after a failure
  continues from the last completed phase and never duplicates work. *Test: killing the
  installer at each phase and re-running completes correctly — the direct fix for
  `ClientProvision.php`'s non-transactional chain whose idempotency guard is on the admin
  email rather than on "does a tenant exist", so a re-run produces **a second tenant on a
  single-tenant install** (`L-…` DEF-L-16).*
- **R-XXVI-022** The installer MUST self-disable once installation is complete, and the
  disabling condition MUST be an explicit installation-state record, not the incidental
  existence of a user row. *Test: deleting all users does not re-open the installer —
  SmartPRS 2's `alreadyInstalled():36-44` keys on `users()->exists()`.*

### XXVI.5.2 Phase 1 — pre-flight

Every row of §XXVI.4 is evaluated and rendered with its severity, observed value, required
value and remediation. The installer will not proceed while any `blocking` row fails.

- **R-XXVI-023** Pre-flight MUST be re-runnable at any time, before and after installation, and
  MUST produce a machine-readable report suitable for a support ticket. *Test: `preflight
  --json` produces the report; the same report is downloadable from the operations console.*
- **R-XXVI-024** No prerequisite that the product genuinely requires may be recorded at
  `advisory`. *Test: a checklist review asserts each severity against the failure it prevents —
  SmartPRS 2 records the SourceGuardian loader at `warn` level (`InstallController.php:84-89`),
  so a plain-source install is permitted, which is precisely what makes the embedded public key
  one editable line (`I-…` Q-07).*

### XXVI.5.3 Phase 2 — configuration capture

Database endpoint and both credentials, cache/queue endpoint, document storage location,
backup destinations and their credentials, mail transport, business timezone, organisation
identity, jurisdictions, licence artefact, chosen configuration template, and the operations
alert recipients.

- **R-XXVI-025** Secrets captured by the installer MUST be written to the secret store with
  restrictive permissions and MUST NOT be echoed, logged, or included in the pre-flight report.
  *Test: no captured secret appears in any file the installer writes other than the secret
  store — the direct fix for `ClientProvision.php:29,39`, which takes the administrator password
  **on the command line**, so it lands in shell history and the process table while
  `$this->secret()` exists and is unused (`L-…` SEC-L-12).*
- **R-XXVI-026** Configuration that the scheduler and the domain depend on — notably the
  business timezone — MUST NOT be readable only through a cacheable configuration layer that a
  later `config:cache` freezes. *Test: changing the business timezone takes effect without a
  cache rebuild — SmartPRS 2 freezes `APP_TIMEZONE` and `ETIMEOFFICE_ENABLED` at
  `config:cache` time and works around it in exactly one place, with a comment explaining why
  (`L-…` DEF-L-24).*

### XXVI.5.4 Phase 3 — migration

- **R-XXVI-027** Migrations MUST run as the **migration** user, inside a transaction per
  migration where the engine permits, with a recorded batch, and MUST halt on the first
  failure leaving the schema at a known version. *Test: a deliberately failing migration leaves
  the schema at the previous version and the installer reports which migration failed.*
- **R-XXVI-028** A migration MUST NOT delete or rewrite business data. *Test: a CI rule fails
  the build on a `DELETE`/`UPDATE` of business rows inside a migration — SmartPRS 2 runs a
  full-table de-duplication algorithm inside two migrations, i.e. **on every deploy**
  (`M-…` PERF-6).*
- **R-XXVI-029** After migration the installer MUST **revoke** schema privileges from the
  application user and verify the revocation. *Test: R-XXVI-016's DDL attempt fails after
  installation.*

### XXVI.5.5 Phase 4 — first administrator

- **R-XXVI-050** No artefact — seeder, installer, template, answer file, release, container
  image or documentation — may contain a credential, a default password, or a password hint.
  *Test: a full-tree grep for a literal password returns nothing; the build fails on a match.
  The direct fix for `OnPremWorkspaceSeeder.php:70,79`, `DemoCompanySeeder.php:69`,
  `DemoReset.php:35`, `config/smartprs.php:32` and `README.md:56-64`.*
- **R-XXVI-051** The first administrator MUST be created interactively, with an operator-chosen
  secret entered twice, never echoed, meeting the configured password policy, and MFA
  enrolment MUST complete before any privileged action. In unattended mode the installer MUST
  **not** create a password at all: it emits a single-use, short-lived activation token to the
  console and to the installation record, and the account is unusable until it is redeemed.
  *Test: an unattended install produces no usable credential until an operator redeems the
  token.*
- **R-XXVI-052** No scheduled task, no seeder and no maintenance command may create, modify,
  re-tenant or reset a user account. *Test: static analysis asserts no writer of `users` outside
  the identity domain — the direct fix for `DemoReset.php:101-123`, whose tenant-unscoped
  lookup at `:106` reassigns `tenant_id` and overwrites the password of any matching account
  (`L-…` SEC-L-01).*
- **R-XXVI-053** A destructive or account-creating command MUST NOT be schedulable without an
  explicit environment allow-list **and** a per-install opt-in flag, and MUST refuse to run in
  a production environment without a typed confirmation naming the tenant and the affected row
  count. *Test: the SmartPRS 2 schedule entry `Schedule::command('demo:reset')->dailyAt('03:30')`
  (`routes/console.php:208-211`), which has no guard of any kind, has no analogue that can be
  constructed.*
- **R-XXVI-054** No vendor support account may exist on a customer system after installation.
  Vendor access MUST be opt-in, per incident, time-limited, tenant-approved and doubly recorded
  (Part XXII). *Test: a post-install account inventory contains only accounts the customer
  created — the direct answer to `I-…` Q-06, which flags a reported permanent
  `support@ametecsindia.com` super-admin created on every client server by the installer.*

### XXVI.5.6 Phase 5 — runtime registration

This is the phase SmartPRS 2 does not have at all.

| Service | What is registered | Verified how |
|---|---|---|
| **Scheduler** | one service per install, leader-elected, restart-on-failure, journaled output | the installer starts it and waits for the first heartbeat row |
| **Queue workers** | one service per queue class (§XXV.12.3), with declared process counts, memory limits, timeouts and graceful-stop windows | the installer enqueues a probe job per class and waits for completion |
| **Log rotation** | rotation and retention for application and service logs | the installer asserts the policy is active |
| **Backup schedule** | the backup and verification jobs (§XXVI.8) | the installer runs an immediate backup and verifies it |
| **Health probe** | a local probe that writes the health result where the console and any external monitor can read it | the installer reads it back |

- **R-XXVI-070** The installer MUST register the scheduler and every queue-class worker as
  managed OS services on **every** supported platform, including Windows, and MUST NOT complete
  while any of them fails to start. *Test: an install on each supported platform ends with the
  services running and enabled at boot — the direct fix for `L-…` DEF-L-27 and for the absence
  of any Windows scheduler or service artefact (`I-…` §5(b) item 48).*
- **R-XXVI-071** Verification MUST be **behavioural**, not declarative: the installer enqueues a
  probe job per queue class and waits for a scheduler heartbeat, and only a completed probe and
  a recorded heartbeat count as success. *Test: registering a service that starts and
  immediately exits fails the installer.*
- **R-XXVI-072** The installer MUST record, in the installation record, the exact service
  definitions it created, so the operations console can detect drift and offer re-registration.
  *Test: manually disabling a service is reported as drift within the declared detection
  window.*

### XXVI.5.7 Phase 6 — licence and rule pack

- **R-XXVI-073** The installer MUST activate the licence (Part XXVII §XXVII.2) and MUST refuse
  to complete with an unlicensed installation, other than in a declared evaluation mode with a
  visible expiry. *Test: an install with no licence and no evaluation grant does not complete.*
- **R-XXVI-074** The installer MUST install a signed rule pack covering the declared
  jurisdictions and MUST verify its signature before activation. *Test: a tampered pack is
  refused with a named reason.*

### XXVI.5.8 Phase 7 — configuration template and provisioning

- **R-XXVI-075** The installer MUST call the single provisioning service (Part XXV R-XXV-020)
  with the chosen configuration template, and the install MUST NOT complete until the
  configuration-completeness model reports **no blocking prerequisite** (Part XXV R-XXV-035).
  *Test: the acceptance test of Part XXV R-XXV-030 passes immediately after an on-premise
  install: hire one employee through the API and run a payroll with no configuration screen
  opened.*

### XXVI.5.9 Phase 8 — the post-install health check

- **R-XXVI-076** Installation is **not complete** until the deep health check (§XXVI.6) returns
  `pass` on every blocking check. A failing check leaves the installer in a resumable state
  with the failure named; it MUST NOT report success. *Test: breaking any blocking dependency
  after migration causes the installer to fail with that dependency named — the direct fix for
  SmartPRS 2's post-update health check, `php artisan --version`, which proves only that the
  CLI autoloader boots and reports `done, 100%` on an application that 500s every HTTP request
  (`I-…` DEF-26).*
- **R-XXVI-077** The installer MUST emit an **installation certificate**: artefact version and
  checksum, platform facts, prerequisite results, database schema version, services registered,
  licence identity, rule-pack versions, template version, health-check results, the operator's
  identity and a timestamp — written locally and attachable to a support ticket. *Test: the
  certificate exists, is signed by the installation key, and is reproducible from the install
  record.*
- **R-XXVI-078** Every installer phase MUST write to the audit ledger (Part XXIII). *Test: the
  ledger contains the full install narrative including every failed pre-flight check.*

---

## XXVI.6 The health-check specification

One implementation, four surfaces: an HTTP endpoint set (contract owned by Part XXXI
§XXXI.6.4), a CLI command, the operations console, and the installer's phase 8.

### XXVI.6.1 The check catalogue

Severity: **blocking** = the product is not fit to serve; **degraded** = serving but with a
named loss of function; **advisory** = informational.

| # | Check | Blocking? | Pass condition | Detects the SmartPRS 2 failure |
|---|---|---|---|---|
| 1 | Framework boot | blocking | boots | — |
| 2 | Database reachable | blocking | round-trip under budget | — |
| 3 | Schema version matches the artefact | blocking | equal | an update whose migration half-applied (`I-…` DEF-25) |
| 4 | **Application user lacks DDL** | blocking | a DDL attempt fails | the six request-path `ALTER TABLE`s (`J-…` §6.5) |
| 5 | Cache/lock store reachable, locks work | blocking | acquire and release | `onOneServer()` silently degrading |
| 6 | Queue backend reachable | blocking | enqueue and dequeue a probe | — |
| 7 | **Scheduler heartbeat fresh** | blocking | last heartbeat within the declared interval | a stopped cron, invisible today (`L-…` §Q4) |
| 8 | **Worker liveness per queue class** | blocking | a probe job completes within budget, per class | a stopped worker, invisible today |
| 9 | Queue depth and oldest-job age | degraded | within configured thresholds | a silently growing backlog |
| 10 | Failed-job count and age | degraded | within thresholds | `failed_jobs` has no reader today (`L-…` DEF-L-23) |
| 11 | **Last successful backup age** | blocking beyond a hard threshold, degraded beyond a soft one | within policy | a backup that silently stopped (`L-…` §Q5) |
| 12 | **Last successful restore drill age** | degraded (blocking beyond the hard threshold) | within policy | there is no restore at all today |
| 13 | Backup destination reachable and writable | degraded | write and delete a probe object | on-box-only destination |
| 14 | Document storage reachable and writable | blocking | probe | — |
| 15 | Free disk on data, document, backup and log volumes | degraded, then blocking | above thresholds | unpruned update backups growing without bound (`I-…` DEF-31) |
| 16 | Licence valid, and days remaining | blocking on invalid; degraded approaching expiry | per Part XXVII | the hard expiry cliff at midnight (`I-…` BR-LIC-023) |
| 17 | Rule packs present, signed, current for each jurisdiction | degraded; blocking where a filing is due | verified | statutory content shipped only inside application releases (`I-…` §10 Q4) |
| 18 | Mail transport reachable, last send outcome | degraded | probe or recent success | a missing SMTP configuration produces a silent `skipped` today (`L-…` DEF-L-11) |
| 19 | Integration freshness per device/connector | degraded | within per-integration staleness policy | `--days=1` losing punches after 24 h (`L-…` DEF-L-01) |
| 20 | Time synchronisation and drift | blocking beyond tolerance | within tolerance | schedule and expiry correctness |
| 21 | TLS certificate validity and expiry | degraded, then blocking | valid | — |
| 22 | Audit chain verification checkpoint fresh | degraded | within policy | a silently broken chain (Part XXIII) |
| 23 | Configuration completeness — blocking prerequisites | blocking for the affected operations | none unmet | an unusable tenant reported as installed |
| 24 | PHP extension and setting drift since install | degraded | matches the certificate | a host change that silently disables backups (`zip`) |
| 25 | Update state consistent (no half-applied update, no stale maintenance flag) | blocking | consistent | an updater stuck at `installing` (`I-…` DEF-32) |

- **R-XXVI-090** The health check MUST exercise the **real** request path: boot, database,
  cache, queue, storage, scheduler heartbeat, worker probe and a served HTTP route. A check
  that only proves the CLI boots MUST NOT exist. *Test: an installation whose HTTP path is
  broken fails the health check even though the CLI boots.*
- **R-XXVI-091** Every check MUST declare its severity, its threshold, its remediation text and
  its owner, and all four MUST be configuration. *Test: adjusting a threshold requires no code
  change.*
- **R-XXVI-092** Health results MUST be persisted with history, so "when did this start
  failing?" is answerable. *Test: a check's failure onset time is reportable.*
- **R-XXVI-093** The health check MUST NOT expose internal detail — schema names, connection
  strings, migration output, stack traces — to any unauthenticated caller. *Test: the
  unauthenticated liveness response contains status only — the direct fix for SmartPRS 2's
  poll endpoint, which serves the first 400 characters of failed `migrate --force` output
  verbatim to anyone holding the poll token (`I-…` SEC-12).*

---

## XXVI.7 The operations console

The single screen an on-premise operator opens. It renders the signals defined in Part XXXI
§XXXI.6; this section specifies **what an operator must be able to answer without a support
call**. SmartPRS 2's on-premise operator can answer none of it: `/admin` is 404 on a client
install, there is no backup screen, no last-run indicator and no restore button
(`I-…` §10 Q10).

| Panel | Answers |
|---|---|
| **System** | Version installed, schema version, licence identity and expiry, rule-pack versions per jurisdiction, update availability, last update outcome |
| **Runtime** | Scheduler last heartbeat and next due; per-queue-class worker count, liveness, depth, oldest job age; failed jobs by class with their errors; per-job last-run status and duration |
| **Data protection** | Last successful backup (time, size, destination, verification result); **last successful restore drill and its date**; next scheduled drill; retention in force; destinations configured and reachable |
| **Integrations** | Per device and connector: last successful sync, consecutive failures, punches ingested today, quarantined punches awaiting resolution |
| **Notifications** | Delivery counts by state (queued / sent / delivered / failed / suppressed) with the oldest queued age |
| **Storage and capacity** | Free space per volume with projected exhaustion date; database size and growth; document store size |
| **Compliance and health** | Open blocking prerequisites; audit-chain verification status; open health failures with onset times |
| **Actions** | Run a health check; run a backup now; **run a restore drill**; retry failed jobs; re-register a drifted service; download the diagnostic bundle; open a support grant |

- **R-XXVI-100** The operations console MUST be available on an on-premise install, MUST be
  reachable by a dedicated operator role, and MUST NOT be a vendor-only surface. *Test: an
  on-premise operator answers "when was my last good backup, and when was it last proven
  restorable?" from one screen.*
- **R-XXVI-101** Every panel MUST show a value **and** its freshness; a stale value MUST be
  rendered as stale, never as current. *Test: stopping the health probe renders every panel
  stale within the declared window — the direct fix for `biometric_configs.last_sync_at`,
  whose stamp is written inside an empty catch, so the one screen an operator can check shows
  a permanently stale timestamp that is a lie about the write, not about the sync
  (`L-…` §Q3 item 9).*
- **R-XXVI-102** The diagnostic bundle MUST contain the installation certificate, health
  history, service definitions and drift, schema version, redacted configuration, and recent
  logs **passed through the redactor** (Part XXXI R-XXXI-062). *Test: the bundle contains no
  secret and no PII.*
- **R-XXVI-103** Every console action MUST be audited and MUST be reversible or clearly marked
  irreversible before execution. *Test: a restore is preceded by a typed confirmation naming
  the target and the data that will be replaced.*

---

## XXVI.8 Backup and disaster recovery (brief Section 91)

### XXVI.8.1 What is backed up

| Set | Contents | Method | Frequency |
|---|---|---|---|
| **Database** | every schema the application owns, on **every configured connection**, consistent | logical dump from a replica or a consistent snapshot; large installs use a snapshot plus binary logs | per policy; default daily, with continuous log shipping where the topology allows |
| **Documents** | every uploaded object: employee documents, photos, branding, commission proofs, generated payslips and statutory outputs retained by policy | incremental object copy with a manifest | continuous or daily |
| **Configuration** | the full S4 configuration set — rules, formulas, workflows, templates, schema definitions, value sets — as a signed export | export | per backup, and on every publish |
| **Rule packs** | the activated packs and their signatures | copy | on activation |
| **Audit** | the ledger, its anchors and its verification checkpoints (Part XXIII) | included in the database set; sealed archive segments copied separately | per backup |
| **Secrets needed for recovery** | the encryption key material required to read the backup itself, and the licence artefact | **a separate, deliberately managed path** — never inside the archive | on change |
| **Installation record** | the certificate and the service definitions | copy | on change |

- **R-XXVI-120** The backup set MUST include everything recovery requires, and the definition
  MUST be verified by the restore drill actually succeeding — not by inspection. *Test: a drill
  restore from the backup set alone produces a working system. The direct fix for
  `config/backup.php:35`, which excludes `storage/app` and therefore the licence private key
  and every uploaded document (`L-…` SEC-L-13).*
- **R-XXVI-121** Files containing secrets MUST be excluded from the archive; recovery secrets
  MUST travel by a separate managed path with its own access control. *Test: no archive
  contains `.env`, a key file, or any credential — the direct fix for `config/backup.php:28-38`,
  where `.env` with `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` and gateway secrets is in every
  archive (`L-…` SEC-L-06).*
- **R-XXVI-122** Tables that hold credential material MUST be excluded or redacted in the
  backup, and MUST NOT hold it in the first place. *Test: no archive contains an OTP or a
  temporary password — the direct fix for `wa_log.body_values`, a permanent unencrypted
  unpruned store of one-time passcodes and plaintext first-login passwords
  (`L-…` SEC-L-03), and for `jobs.payload`, which carries base64 PDF attachments and invite
  tokens (`L-…` SEC-L-07).*
- **R-XXVI-123** The set of databases backed up MUST be derived from the configured
  connections, never from a literal. *Test: renaming the connection does not silently produce a
  file-only backup — the direct fix for `config/backup.php:45-46` (`L-…` DEF-L-26).*

### XXVI.8.2 Encryption

- **R-XXVI-124** Every archive MUST be encrypted at rest by default with a key held outside the
  archive and outside the machine being backed up, and the installer MUST refuse to configure
  an unencrypted destination. *Test: an unencrypted archive cannot be produced — the direct fix
  for `BACKUP_ARCHIVE_PASSWORD` being unset by default (`config/backup.php:79-81`).*
- **R-XXVI-125** Backup encryption key rotation MUST be supported without invalidating existing
  archives: each archive records the key identifier it was encrypted with. *Test: after
  rotation, an old archive still restores.*

### XXVI.8.3 Destinations

- **R-XXVI-126** At least one **off-server** destination MUST be configured. An installation
  with only a local destination MUST raise a persistent, non-dismissible operator warning and
  MUST fail the corresponding health check as `degraded`. *Test: a local-only configuration is
  visibly warned about on every console load — the direct fix for `disks => ['local']`
  (`config/backup.php:72-74`), whose own comment concedes the point.*
- **R-XXVI-127** Destination credentials MUST be verified at configuration time and re-verified
  by the health check. *Test: revoking the destination credential fails check 13 within one
  cycle.*
- **R-XXVI-128** Supported destination classes MUST include at minimum: an S3-compatible
  endpoint, an SFTP/SMB target, and a mounted volume, with the last classed as off-server only
  when it is demonstrably on different hardware. *Test: a mounted path that resolves to the
  same device is not accepted as off-server.*

### XXVI.8.4 Retention

- **R-XXVI-129** Retention MUST be explicit product-owned configuration — daily, weekly,
  monthly and yearly counts, plus a maximum total size — never an unpinned package default.
  *Test: the retention in force is displayed in the console and is reproducible from
  configuration — the direct answer to `L-…` OQ-L-03, where the policy cannot be determined
  from source at all.*
- **R-XXVI-130** Retention MUST interact correctly with tenant purge: an archive containing a
  purged tenant MUST expire on the date the purge certificate states, and MUST NOT be restorable
  for that tenant afterwards (Part XXV R-XXV-119). *Test: a restore after that date refuses the
  purged tenant.*
- **R-XXVI-131** Update backups (Part XXVII §XXVII.4) MUST be covered by a retention policy with
  a configurable count and age. *Test: the tenth update leaves at most the configured number of
  code backups — the direct fix for `updater.php:342-343`, which tells the operator the folder
  *"can be deleted once everything looks right"* while no code ever deletes it
  (`I-…` DEF-31).*

### XXVI.8.5 Monitoring, retry and verification

- **R-XXVI-132** Backup jobs MUST retry with backoff, and a terminal failure MUST alert through
  a channel that does not depend on the subsystem that failed. *Test: with the queue stopped, a
  backup failure still alerts — the direct fix for `tries = 1, retry_delay = 0`
  (`config/backup.php:83-84`) and for the error digest being delivered through the queue whose
  failure it cannot detect (`L-…` DEF-L-07).*
- **R-XXVI-133** Every backup MUST be **verified** after creation — archive integrity, manifest
  completeness, expected object count, database dump parse — and an unverified backup MUST NOT
  count as a successful backup. *Test: a truncated archive is reported as a failure, not a
  success.*
- **R-XXVI-134** Backup age, size trend, verification result and destination reachability MUST
  be health checks with alerting. *Test: skipping one night's backup raises an alert within one
  cycle — SmartPRS 2's only detection is a human listing a directory and noticing the newest
  zip is old (`L-…` §Q5).*

### XXVI.8.6 Restore — the capability that does not exist today

> **This is the single most important section in this part.** SmartPRS 2 has no restore
> command, no restore route, no restore UI, no restore runbook and no drill artefact
> (`L-…` §Q5; `I-…` §10 Q10). Restoring today means an engineer unzipping an archive and
> running `mysql < database.sql` by hand — on a system whose schema is partly created at
> runtime by application code, so the dump reflects whatever the application had lazily created
> at dump time (`I-…` §10 Q10). SmartPRS 3 removes both halves of that problem: the schema is
> migration-owned (Law 2), and restore is a product feature.

**The restore modes:**

| Mode | Purpose | Target |
|---|---|---|
| `full` | disaster recovery | a clean host |
| `verify` | the scheduled drill | an isolated scratch environment |
| `point-in-time` | recover from a bad change | a scratch environment, then a selective re-import |
| `objects` | recover deleted or corrupted documents | in place, audited |
| `tenant` | recover one tenant on a multi-tenant install | a scratch environment, then a scoped import |

**The procedure** (identical for all modes; the mode selects target and scope):

| # | Step | Guarantee |
|---|---|---|
| 1 | Select archive by date, verify its signature and checksum | a tampered or corrupt archive is refused |
| 2 | Read the manifest; report contents, versions, tenant list and coverage window | the operator sees what they are about to restore |
| 3 | Check compatibility: artefact version, schema version, rule-pack versions | a restore into an incompatible binary is refused with a named remediation |
| 4 | Acquire the encryption key from the managed path | recovery secrets never travel in the archive |
| 5 | For `full`: place the system in maintenance mode; stop workers and scheduler | no concurrent writes |
| 6 | Restore the database | to the declared consistency point |
| 7 | Restore documents, verified against the manifest by count and checksum | no silent partial restore |
| 8 | Restore configuration, rule packs and audit archive segments | the system behaves as it did |
| 9 | Apply forward migrations if the binary is newer, as the migration user | a documented, one-way path |
| 10 | Run the **deep health check**; a failure aborts and reports | a restore that does not work is not reported as a success |
| 11 | Verify the audit chain over the restored range and record the checkpoint | tamper evidence survives (Part XXIII) |
| 12 | Run the **restore assertion suite** (§XXVI.8.7) | correctness, not just liveness |
| 13 | Restart workers and scheduler; leave maintenance mode | — |
| 14 | Write the restore record: archive, operator, scope, duration, results, and — for a drill — the **last successful drill date** | the console value in R-XXVI-142 |

- **R-XXVI-135** A restore capability MUST exist as a first-class product feature with a
  command, an API and a console action. *Test: a restore is performed by an operator following
  the documented procedure with no vendor involvement.*
- **R-XXVI-136** The restore procedure MUST be documented in the product, versioned with the
  release, and reachable from the operations console. *Test: the runbook is present offline on
  an air-gapped install.*
- **R-XXVI-137** A restore MUST be refused rather than partially applied when any verification
  step fails. *Test: a corrupted document manifest aborts before the database is touched.*
- **R-XXVI-138** A restore MUST be audited, and the audit record MUST survive the restore
  itself. *Test: the ledger shows the restore after the restore.*
- **R-XXVI-139** An in-place restore MUST require typed confirmation naming the target and the
  data that will be replaced, and MUST take a **pre-restore backup** first. *Test: an operator
  cannot destroy current data with one click.*

### XXVI.8.7 The drill, and the date the customer sees

- **R-XXVI-140** A restore drill MUST run on a schedule, into an isolated scratch environment,
  automatically. Default cadence is monthly; quarterly is the minimum for the large reference
  architecture. *Test: the drill runs unattended and records its result.*
- **R-XXVI-141** The drill MUST run the **restore assertion suite**: a fixed set of exact-value
  assertions against the restored data — a known payslip's net, a known attendance day's
  status, a known leave balance, the audit chain verifying over the restored range, a known
  document's checksum, and the configuration resolving identically for a known employee on a
  known date. Liveness alone MUST NOT count as a successful drill. *Test: corrupting one
  payslip in the archive fails the drill.*
- **R-XXVI-142** **The date of the last successful restore drill MUST be visible in the
  operations console**, alongside the next scheduled drill and the result of the last one.
  *Test: the value is present, correct and stale-marked when overdue. This is the direct,
  literal fix for the fact that SmartPRS 2 has no restore path of any kind and no way for a
  customer to know it.*
- **R-XXVI-143** A drill that has not succeeded within the configured window MUST raise a
  `degraded` health state, escalating to `blocking` beyond a hard threshold, and MUST be
  reported to the vendor for supported installations. *Test: suppressing the drill for two
  cycles produces both signals.*
- **R-XXVI-144** The drill environment MUST be isolated: no outbound mail, SMS or WhatsApp, no
  integration egress, no licence heartbeat, no webhook delivery. *Test: no external call
  escapes a drill.*

### XXVI.8.8 RTO and RPO

**PRODUCT OWNER DECISION REQUIRED — D-XXVI-03: the published RTO and RPO targets, per
reference architecture.**

*Recommendation, as a starting point for costing:*

| Architecture | RPO (max data loss) | RTO (max time to serve) | What it costs |
|---|---|---|---|
| Small (50) | 24 h | 8 h | daily backup, off-site copy, documented manual restore |
| Medium (1,000) | 4 h | 4 h | 4-hourly database backup or log shipping, incremental documents |
| Large (10,000) | 15 min | 2 h | continuous log shipping, a warm replica, a rehearsed promotion procedure |

The trade-off is entirely cost: an RPO of 15 minutes requires continuous log shipping and a
second machine; an RPO of 24 hours does not. **The number must be published**, because the
question a customer asks during an incident is *"how much have we lost and when will we be
back?"* and today the honest answer for SmartPRS 2 is *"unknown, and there is no restore
procedure"*.

- **R-XXVI-145** The RTO and RPO in force MUST be recorded in configuration, displayed in the
  operations console, and **verified by the drill** — a drill that cannot meet the stated RTO
  MUST fail. *Test: a drill exceeding the configured RTO is recorded as a failure, not a slow
  success.*
- **R-XXVI-146** Where the customer's chosen backup configuration cannot meet the published
  RPO, the console MUST say so explicitly and name what would be required. *Test: a daily-only
  backup on a large install renders "configured RPO 24 h; target RPO 15 min; requires log
  shipping".*

---

## XXVI.9 Background-job reliability (brief Section 92)

### XXVI.9.1 The scheduler is data, not code

SmartPRS 2's schedule is twelve `Schedule::command()` calls with literal cron expressions and
no per-tenant, per-environment or per-install enable flag except one `->when()` guard
(`routes/console.php:143-266`; `L-…` §5.2). None of the twelve uses `->onFailure()`,
`->onSuccess()`, `->pingOnFailure()`, `->emailOutputTo()`, `->sendOutputTo()`,
`->runInBackground()`, `->evenInMaintenanceMode()` or `->timezone()`. All twelve call
`withoutOverlapping()` **with no argument**, so Laravel's 24-hour default lock applies to a
five-minute task; a task killed without releasing its lock is blocked for a day, and the
recovery command appears nowhere in the repository (`L-…` DEF-L-02).

```
scheduled_jobs                      scheduled_job_runs
  job_key, name, cron_expression,     job_key, run_id, started_at, finished_at,
  timezone_source, enabled,           status, items_attempted, items_succeeded,
  scope(platform|per_tenant),         items_failed, error_summary, host, lease_id
  lock_ttl_seconds, max_runtime_s,
  on_failure_action, alert_channel,
  environment_allow_list, requires_optin
```

- **R-XXVI-160** The schedule MUST be a table-driven registry; the twelve current entries seed
  as shipped defaults. *Test: changing a schedule time is a configuration operation.*
- **R-XXVI-161** Every scheduled job MUST declare an overlap-lock TTL proportional to its
  cadence, and the registry MUST reject a TTL longer than a declared multiple of the interval.
  *Test: a five-minute job cannot hold a 24-hour lock — the direct fix for `L-…` DEF-L-02.*
- **R-XXVI-162** Every run MUST write a run row with attempted, succeeded and failed counts.
  *Test: the scheduler heartbeat of health check 7 is derived from real rows, not from a
  process being alive.*
- **R-XXVI-163** A job that processed fewer items than it attempted MUST NOT report success.
  *Test: a per-tenant failure produces a failed run, not a silent under-count — the direct fix
  for `AbsenceService.php:133-135` and `GreetingService.php:156-158`, which wrap the entire
  tenant loop in one catch and exit SUCCESS having abandoned tenants 4–20 (`L-…` DEF-L-03).*
- **R-XXVI-164** Per-item fault isolation MUST be a framework guarantee, not a per-command
  choice. *Test: a failure on tenant 3 of 20 is recorded and alerted, and tenants 4–20 are
  still processed — the shape `SubscriptionAlerts.php:135-137` achieves alone today, and
  `ComplianceController::notify():188-245` achieves not at all, having no try/catch, so one bad
  tenant aborts every remaining tenant's compliance digest (`L-…` DEF-L-04).*
- **R-XXVI-165** Scheduler timezone MUST be resolvable per tenant, not from one global value
  frozen by a configuration cache. *Test: two tenants in different zones receive their 09:00
  notifications at their own 09:00 — the direct fix for `L-…` DEF-L-24.*

### XXVI.9.2 The worker

Requirements for queue classes, fairness, failure handling, pruning and staleness are specified
in Part XXV §XXV.12.3 (R-XXV-127 … R-XXV-131) and are not repeated. Two on-premise-specific
additions:

- **R-XXVI-166** Worker services MUST restart automatically on failure and MUST be
  memory-bounded with graceful stop, and the restart count MUST be visible in the console.
  *Test: a worker killed repeatedly is visible as flapping, not as healthy.*
- **R-XXVI-167** A deployment or update MUST restart workers, and the operations console MUST
  show the code version each worker is running. *Test: after an update no worker runs the old
  code — the direct fix for the absence of `queue:restart` anywhere in SmartPRS 2's update
  path, where long-running workers keep executing pre-update code until `--max-time` expires
  (`I-…` DEF-27).*

### XXVI.9.3 Degrade visibly and loudly — never silently

This is the behavioural core of Section 92.

| Condition | Required product behaviour |
|---|---|
| Scheduler heartbeat stale beyond the soft threshold | Persistent, non-dismissible banner **for every administrator**, naming the condition and the last heartbeat; console panel red; alert to the configured recipients |
| Scheduler heartbeat stale beyond the hard threshold | The above, plus: **operations that silently depend on the scheduler are refused with a named reason rather than performed on stale data** — payroll run, statutory filing generation, bank pack |
| No worker alive for a queue class | Banner naming the class and what is not happening; any user action that would enqueue to that class warns **before** the action, not after |
| Queue depth or oldest-job age beyond threshold | Degraded health, banner, alert; the affected features report "delayed" in their own screens |
| Backup overdue | Banner, degraded health, alert |
| Restore drill overdue | Banner, degraded health, alert (R-XXVI-143) |
| Integration stale beyond its policy | Attendance screens show the staleness inline; payroll preview raises it as an exception rather than computing on incomplete data |
| Licence approaching expiry | Countdown banner from the configured lead time (Part XXVII) |

- **R-XXVI-170** The absence of the scheduler or of any queue-class worker MUST be detected by
  the application and surfaced to administrators within the declared detection window. *Test:
  stopping each service produces the banner and the alert within the window — the direct fix
  for the two failures `L-…` §Q4 identifies as *"the two that hurt most … both leave the
  product looking entirely healthy"*.*
- **R-XXVI-171** No feature may fail silently because a background dependency is absent. Every
  such feature MUST declare its dependency, and the dependency's absence MUST be reported at
  the feature's own surface. *Test: with the worker stopped, opening any screen whose function
  depends on it shows the degradation.*
- **R-XXVI-172** An operation whose correctness depends on fresh background data MUST refuse
  rather than proceed on stale data, naming the staleness. *Test: payroll preview refuses when
  attendance ingestion is stale beyond policy, instead of producing an under-count of payable
  days.*
- **R-XXVI-173** Alerting MUST NOT depend on the subsystem it monitors. *Test: with the queue
  stopped, the "queue stopped" alert is still delivered — the direct fix for `L-…` DEF-L-07,
  where the error digest is delivered through the queue whose failure it cannot detect.*
- **R-XXVI-174** A delivery ledger row MUST distinguish `queued`, `sent`, `delivered`, `failed`
  and `suppressed`, and no caller may treat `queued` as `sent`. *Test: a stopped worker
  produces zero `delivered` rows — the direct fix for `L-…` DEF-L-13.*
- **R-XXVI-175** A message that cannot be delivered because no transport is configured MUST
  raise an operator-visible condition, not a silent `skipped`. *Test: clearing the SMTP
  configuration raises an alert — the direct fix for `MailService.php:148-152`
  (`L-…` DEF-L-11).*

---

## XXVI.10 Air-gapped and restricted-egress installations

### XXVI.10.1 What works, what does not

| Capability | Fully air-gapped | Restricted egress (allow-list) |
|---|---|---|
| All business function — attendance, payroll, statutory computation, leave, workflow, reporting, ESS/MSS | **works** | works |
| Licence activation | works — offline activation (Part XXVII §XXVII.2.6) | works |
| Licence proof-of-life / revocation | **does not reach the vendor**; governed by the declared offline tolerance (Part XXVII D-XXVII-05) | works if the licence host is allowed |
| Application updates | works — side-loaded signed package, verified offline against the embedded public key | works |
| Rule-pack updates | works — side-loaded signed pack | works |
| Outbound mail / SMS / WhatsApp | works only with an internal relay or an allowed provider | works if the provider is allowed |
| Biometric device pull | works — devices are on the local network | works |
| Cloud biometric providers (e.g. an eTimeOffice-class cloud API) | **does not work** | works if allowed |
| Vendor telemetry and remote support | **does not work**; a diagnostic bundle is exported manually | works if allowed |
| Off-server backup | works to a local NAS or removable media | works |

- **R-XXVI-180** Air-gapped operation MUST be a declared installation mode, not an accident of
  a firewall rule. In that mode the product MUST NOT attempt vendor egress, MUST NOT degrade
  because egress fails, and MUST tell the operator which capabilities are consequently
  unavailable. *Test: an air-gapped install produces no outbound vendor connection attempt and
  no egress-related error.*
- **R-XXVI-181** Every vendor endpoint the product may contact MUST be enumerated in the
  documentation with its purpose, so a customer can build an allow-list. *Test: the enumerated
  list matches an observed traffic capture from a full functional run.*
- **R-XXVI-182** Signature verification of updates and rule packs MUST be entirely offline
  against embedded public keys. *Test: an air-gapped install verifies and applies a side-loaded
  pack with no network — the capability SmartPRS 2 nominally has and disables by default:
  `SMARTPRS_OFFLINE_LIC=false` routes a `.lic` through a 20-second HTTP call to the vendor and
  fails without internet, while the activation screen tells the customer it is *"fully offline
  — no internet needed"* (`config/smartprs.php:52`; `licence-activate.blade.php:64`;
  `I-…` DISC-13).*
- **R-XXVI-183** Side-loading MUST be a first-class, documented, audited operator action with
  media verification, not a file copied into a directory. *Test: a side-loaded artefact of the
  wrong type, version or signature is refused with a named reason.*

### XXVI.10.2 How content reaches an air-gapped install

```
Ametecs ──signs──▶ artefact (update package | rule pack | configuration template)
        ──publishes──▶ portal download by an entitled customer contact
        ──customer transfers on media──▶ operator console → "Import signed artefact"
                                            ├─ verify signature against the embedded key
                                            ├─ verify manifest and per-file hashes
                                            ├─ check compatibility and effective dates
                                            ├─ preview the diff and its impact
                                            └─ apply (Part XXVII §XXVII.4 / §XXVII.5)
```

- **R-XXVI-184** The import flow MUST be identical to the online flow after the transport step,
  so an air-gapped install exercises the same verification, preview, apply and rollback code.
  *Test: the same package applied online and by import produces identical results and identical
  audit entries.*
- **R-XXVI-185** An air-gapped install MUST be able to produce a signed **status export** — the
  installation certificate, licence state, seat usage, version register entry and health
  summary — for the vendor to reconcile entitlement without egress. *Test: the export verifies
  against the installation key and is accepted by the vendor's version register (Part XXVII
  §XXVII.6.5).*

---

## XXVI.11 Configuration surface

| # | What the operator can change | Where | Restart needed? |
|---|---|---|---|
| 1 | Every schedule entry: cron, timezone source, enabled, lock TTL, max runtime, failure action, alert channel | `scheduled_jobs` registry | no |
| 2 | Queue classes, worker counts, memory limits, timeouts, per-class thresholds | runtime configuration + service definitions | worker restart, performed by the console |
| 3 | Backup: schedule, sets included, destinations, encryption key reference, retention counts and size cap, verification depth | backup policy | no |
| 4 | Restore drill: cadence, scratch target, assertion suite selection, RTO target | DR policy | no |
| 5 | Health-check thresholds, severities, remediation text, alert recipients per check | health policy | no |
| 6 | Alert channels and routing (email, webhook, syslog/SIEM, on-call integration) | alert policy (Part XXXI) | no |
| 7 | Log level, retention, redaction policy, export target | logging policy (Part XXXI) | no |
| 8 | Business timezone, working calendar defaults | organisation configuration | no (R-XXVI-026) |
| 9 | Document storage location and retention classes | storage policy | no |
| 10 | Integration staleness policies per device and connector | integration policy | no |
| 11 | Password policy, lockout, session lifetime, MFA requirement per role | security policy (Part XXII) | no |
| 12 | Air-gapped mode; vendor endpoint allow-list; telemetry opt-in | installation configuration | boot |
| 13 | Update channel and auto-check behaviour | update policy (Part XXVII) | no |
| 14 | Operator role membership and the operations-console permission set | roles (Part XXII) | no |
| 15 | Maintenance windows for scheduled heavy work | maintenance policy | no |

**The Section 127 test, on-premise.** *"Back up at 22:30, keep 14 dailies and 12 monthlies,
send to the NAS and to S3, drill the restore on the first Sunday of each month, and alert the
site engineer by webhook."* Every clause is a row above. In SmartPRS 2 the times are PHP
literals (`routes/console.php:157,162`), the destination is a PHP array
(`config/backup.php:72-74`), retention is an unpinned package default (`:88-92`), the drill
does not exist, and there is no alert recipient wired to backups at all
(`L-…` DOC-L-12).

---

## XXVI.12 Data model

Section 123 standard; spine S16 conventions. Installation-scoped tables carry no `tenant_id`
because they describe the installation, not a tenant; they are enumerated here as the
documented exception to Law 6 and hold no tenant business data. Every table is
migration-owned (Law 2).

### XXVI.12.1 `installation`

*Purpose:* the single row describing this installation.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | tinyint | N | PK, always 1 (CHECK) |
| `installation_uuid` | char(36) | N | UUIDv7; the identity in the vendor version register |
| `deployment_model` | varchar(16) | N | infrastructure-only (R-XXVI-002) |
| `installed_version` | varchar(32) | N | one version identity (Part XXVII R-XXVII-090) |
| `artefact_checksum` | char(64) | N | |
| `schema_version` | varchar(32) | N | |
| `business_timezone` | varchar(64) | N | not cache-frozen (R-XXVI-026) |
| `air_gapped` | tinyint(1) | N | |
| `installed_at`, `installed_by_label` | datetime(6) / varchar(190) | N | |
| `certificate_ref` | varchar(190) | N | R-XXVI-077 |

PK `id`. Hist: `installation_events`. SD: no. Audit: every column. PII: none.

### XXVI.12.2 `installation_preflight_results`

`run_id`, `check_key`, `severity`, `status`, `observed`, `required`, `remediation`,
`checked_at`. PK `id` · Index `(run_id, severity)`. Append-only. R-XXVI-023.

### XXVI.12.3 `installation_services`

*Purpose:* what the installer registered, so drift is detectable.

`service_key` (`scheduler`, `worker:<class>`, `backup`, `health_probe`), `platform`,
`definition_hash`, `definition_text`, `registered_at`, `last_verified_at`,
`last_verification_result`, `drift_detected_at`.
PK `id` · Unique `(service_key)`. R-XXVI-070, R-XXVI-072.

### XXVI.12.4 `scheduled_jobs`, `scheduled_job_runs`

Per §XXVI.9.1. `scheduled_jobs` PK `id` · Unique `(job_key)`.
`scheduled_job_runs` PK `id` · Unique `(job_key, run_id)` ·
Index `(job_key, started_at DESC)` · Index `(status, started_at)`.
Retained per the log retention class; pruned by a scheduled job (the pruning SmartPRS 2 has
for none of its six log-shaped tables, `L-…` DEF-L-29).

### XXVI.12.5 `health_checks`, `health_check_results`

`health_checks`: `check_key`, `label`, `severity`, `threshold_json`, `remediation`,
`owner_role`, `enabled`. Unique `(check_key)`.
`health_check_results`: `check_key`, `observed_at`, `status`, `observed_json`,
`failure_onset_at`. PK `id` · Unique `(check_key, observed_at)` ·
Index `(status, observed_at DESC)`. R-XXVI-091, R-XXVI-092.

### XXVI.12.6 `backup_policies`

`policy_key`, `schedule_cron`, `sets_json`, `destinations_json`, `encryption_key_id`,
`retention_json`, `verification_depth`, `rto_target_minutes`, `rpo_target_minutes`,
`enabled`, `effective_from`. Unique `(policy_key)`. R-XXVI-129, R-XXVI-145.

### XXVI.12.7 `backup_runs`

`run_uuid`, `policy_key`, `started_at`, `finished_at`, `status`, `set_key`,
`destination_ref`, `archive_object_ref`, `bytes`, `manifest_checksum`, `encryption_key_id`,
`verification_status`, `verification_detail`, `covers_from`, `covers_to`,
`contains_tenants_json`, `expires_on`, `attempt_no`.
PK `id` · Unique `(run_uuid)` · Index `(policy_key, started_at DESC)` ·
Index `(status, finished_at DESC)` · Index `(expires_on)`.
`expires_on` and `contains_tenants_json` are what make purge-versus-backup correctness
(R-XXVI-130) checkable. Append-only.

### XXVI.12.8 `restore_runs`

`run_uuid`, `mode` (`full|verify|point_in_time|objects|tenant`), `source_backup_run_uuid`,
`target_ref`, `requested_by`, `confirmed_text_hash`, `started_at`, `finished_at`, `status`,
`steps_json`, `health_result_json`, `assertion_results_json`, `duration_seconds`,
`met_rto`, `audit_entry_id`.
PK `id` · Unique `(run_uuid)` · Index `(mode, status, finished_at DESC)`.
**The table SmartPRS 2 has no analogue of, because the capability does not exist.**

### XXVI.12.9 `restore_assertions`

`assertion_key`, `label`, `kind` (`payslip_net|attendance_status|leave_balance|audit_chain|
document_checksum|config_resolution`), `fixture_ref`, `expected_json`, `enabled`.
Unique `(assertion_key)`. R-XXVI-141.

### XXVI.12.10 `dr_status` *(single row, denormalised for the console)*

`last_successful_backup_at`, `last_successful_backup_run_uuid`,
**`last_successful_restore_drill_at`**, `last_restore_drill_run_uuid`,
`last_restore_drill_result`, `next_drill_due_on`, `rto_target_minutes`,
`rpo_target_minutes`, `configured_rpo_minutes`, `updated_at`.
PK fixed at 1. Maintained by the backup and restore services; read by R-XXVI-142.

### XXVI.12.11 `artefact_imports`

*Purpose:* the audited side-load path for air-gapped installs.

`import_uuid`, `artefact_kind` (`update|rulepack|template|licence`), `filename`,
`declared_version`, `signature_key_id`, `signature_status`, `manifest_status`,
`compatibility_status`, `imported_by`, `imported_at`, `applied_at`, `result`,
`audit_entry_id`. Unique `(import_uuid)` · Index `(artefact_kind, imported_at DESC)`.
R-XXVI-183.

### XXVI.12.12 `operations_alerts`

`alert_key`, `severity`, `opened_at`, `closed_at`, `source_check_key`, `detail_json`,
`channels_notified_json`, `acknowledged_by`, `acknowledged_at`.
PK `id` · Index `(severity, opened_at DESC)` · Unique `(alert_key)` where open.
Deduplicated on `alert_key` so a persisting condition does not re-alert every cycle — the
behaviour `ErrorDigest` lacks, sending two identical emails for two runs
(`L-…` §Q7).

---

## XXVI.13 API and CLI surface

### XXVI.13.1 CLI (the installer and operator tooling)

| Command | Purpose |
|---|---|
| `smartprs install` | interactive install (all phases) |
| `smartprs install --answers <file>` | unattended install; no credential created (R-XXVI-051) |
| `smartprs preflight [--json]` | run the prerequisite matrix at any time |
| `smartprs health [--deep] [--json]` | the check catalogue |
| `smartprs services register|verify|repair` | runtime registration and drift repair |
| `smartprs backup run [--set=…]` | on-demand backup |
| `smartprs backup verify <run>` | verify an archive |
| `smartprs restore --mode=… --backup=… --target=…` | the restore procedure (§XXVI.8.6) |
| `smartprs restore-drill` | the scheduled drill, runnable on demand |
| `smartprs artefact import <file>` | side-load a signed artefact |
| `smartprs diagnostics bundle` | redacted support bundle |
| `smartprs licence activate|status` | Part XXVII |

- **R-XXVI-190** Every command that deletes or rewrites data MUST require an explicit scope,
  offer a dry run, refuse to run in production without typed confirmation, and write an audit
  record of what it changed. *Test: each such command refuses without confirmation — the direct
  fix for `app:clear-state`, `attendance:dedupe` and `attendance:purge-demo`, which all default
  to **all tenants** with no confirmation, no `--force` and no environment check
  (`L-…` SEC-L-10).*
- **R-XXVI-191** Every console command MUST have automated tests. *Test: coverage exists for
  each — SmartPRS 2 has **zero** tests for any of its eighteen commands (`L-…` DEF-L-34).*

### XXVI.13.2 API

Under `/api/v3/ops`, gated by the operator permission set (Part XXII), never public.

| Method | Path | Permission |
|---|---|---|
| GET | `/ops/health`, `/ops/health/history` | `ops.health.view` |
| GET | `/ops/runtime` | `ops.runtime.view` |
| POST | `/ops/services/{key}/verify`, `/repair` | `ops.runtime.manage` |
| GET | `/ops/schedule`, PATCH `/ops/schedule/{job_key}` | `ops.schedule.manage` |
| GET | `/ops/queues`, POST `/ops/queues/{class}/retry-failed` | `ops.queue.manage` |
| GET | `/ops/backups`, POST `/ops/backups/run` | `ops.backup.view` / `ops.backup.run` |
| GET | `/ops/dr-status` | `ops.backup.view` — serves R-XXVI-142 |
| POST | `/ops/restore-drills` | `ops.restore.drill` |
| POST | `/ops/restores` | `ops.restore.execute` (two-person, typed confirmation) |
| POST | `/ops/artefacts/imports` | `ops.artefact.import` |
| GET | `/ops/diagnostics/bundle` | `ops.diagnostics.export` |

Liveness and readiness endpoints are unauthenticated by necessity and expose **status only**
(R-XXVI-093); their contract is specified in Part XXXI §XXXI.6.4.

---

## XXVI.14 Events

| Event | Emitted when |
|---|---|
| `install.phase.started` / `.completed` / `.failed` | each installer phase |
| `install.completed` | certificate written |
| `service.registered` / `.drift_detected` / `.repaired` | runtime registration |
| `scheduler.heartbeat.missed` / `.recovered` | detection window crossed |
| `worker.class.unavailable` / `.recovered` | per queue class |
| `queue.depth.exceeded` / `.oldest_age.exceeded` | thresholds |
| `job.failed.terminal` | a job exhausts retries |
| `backup.started` / `.succeeded` / `.failed` / `.verified` / `.verification_failed` | backup runs |
| `backup.overdue` | age threshold |
| `restore.started` / `.succeeded` / `.failed` | restores |
| `restore_drill.succeeded` / `.failed` / `.overdue` | drills |
| `health.check.failed` / `.recovered` | per check, deduplicated |
| `artefact.imported` / `.rejected` | side-load |
| `capacity.threshold.crossed` | disk, database size, document store |

Consumed: `rulepack.activated`, `licence.state.changed`, `update.applied`, `update.rolled_back`
(Part XXVII); `payroll.run.completed`, `punch.batch.ingested` (for freshness signals).

---

## XXVI.15 Migration implication

| Item | Verdict | Notes |
|---|---|---|
| Existing on-premise installs | **re-install, then migrate data** | The schema is partly runtime-created, so an in-place upgrade cannot establish a known starting schema. Part XXVIII §XXVIII.11 owns the data migration; this part owns the target being installed first |
| Existing `admin@smartprs.local` and any `demo-*` accounts | **do not migrate** | Delete before migration; every affected install must be treated as credential-compromised and audited (`L-…` SEC-L-01) |
| Existing backups | **not a migration input** | Format, contents and encryption all change. Retain the old archives read-only for their remaining retention |
| `wa_log` credential-bearing rows | **purge before migration** | R-XXVI-122 |
| The operational runtime | **new** | Nothing to migrate; there is nothing there (`L-…` DEF-L-27) |
| Database privileges | **new** | Every install must be re-provisioned with two users; the migration checks R-XXVI-016 as a gate |
| `settings(tenant_id=0, key='licence')` | **do not migrate** | Part XXVII: every install re-activates on cutover |

- **R-XXVI-200** Before any on-premise cutover, an inventory MUST establish, per install: whether
  the scheduler and worker were running, whether `demo:reset` was scheduled, whether
  `admin@smartprs.local` exists, whether any backup succeeded in the last 30 days, and whether
  the application database user holds `ALTER`. *Test: the inventory is complete and signed off —
  the direct answer to `L-…` OQ-L-01 (*"the single most urgent question in this document"*) and
  OQ-L-02, and to `I-…` Q-09.*
- **R-XXVI-201** Any install found to have run `demo:reset` MUST be treated as having had an
  administrator account with a published password, and the customer MUST be notified. *Test:
  the notification record exists for every affected install.*

---

## XXVI.16 Edge cases and failure states

| # | Situation | Required behaviour |
|---|---|---|
| 1 | Installer interrupted mid-migration | Resumable; schema at a known version; the failed migration named (R-XXVI-021, R-XXVI-027) |
| 2 | Service registration succeeds but the service exits immediately | Behavioural verification fails the installer (R-XXVI-071) |
| 3 | Operator has no service-manager rights | Blocking pre-flight failure with remediation; never a silent skip |
| 4 | Windows host with no Task Scheduler entry | Impossible: registration is part of install and verified (R-XXVI-070) |
| 5 | Host clock wrong at install | Blocking pre-flight failure (matrix row 16) |
| 6 | Host timezone changed after install | Business timezone is explicit and unaffected; drift check 24 reports the host change |
| 7 | Application user granted `ALTER` later by a DBA | Health check 4 fails; banner and alert |
| 8 | Database connection renamed | Backup set derives from configured connections (R-XXVI-123); no silent file-only backup |
| 9 | `zip` extension removed by a host update | Drift check 24 fails; backup health degrades **before** the next backup fails |
| 10 | Backup destination full | Retry with backoff; alert; retention pruning does not delete the last verified archive to make room |
| 11 | Backup destination credential revoked | Check 13 fails within one cycle (R-XXVI-127) |
| 12 | Archive corrupt at restore time | Refused at step 1; the next-newest verified archive is offered |
| 13 | Restore into a **newer** binary | Forward migrations run as the migration user; recorded as a one-way path (step 9) |
| 14 | Restore into an **older** binary | Refused with a named reason; downgrade is not a restore path |
| 15 | Drill scratch environment unavailable | Drill fails and alerts; it is **not** recorded as skipped or successful |
| 16 | Drill succeeds but exceeds the RTO target | Recorded as a **failure** (R-XXVI-145) |
| 17 | Documents restored but the manifest count mismatches | Restore aborts (R-XXVI-137) |
| 18 | Redis lost, no persistence | Impossible at install (R-XXVI-011); if it happens, queued work is recovered from the durable backing store and the incident is raised |
| 19 | Two scheduler leaders after a network partition | Lease-based election; the losing leader self-terminates; overlapping runs are prevented by the per-job lock |
| 20 | A scheduled job crashes holding its lock | Lock TTL is proportional (R-XXVI-161); the stale lock clears and the run is recorded as failed |
| 21 | Worker OOM loop | Flapping visible in the console (R-XXVI-166); alert; the class is reported unavailable |
| 22 | Disk fills with logs | Rotation and retention are installed and verified; capacity check crosses `degraded` first |
| 23 | Air-gapped install, licence proof-of-life unreachable | Behaviour is the declared offline tolerance (Part XXVII), announced in advance, never a surprise lock-out |
| 24 | Side-loaded artefact of the wrong kind or version | Refused with a named reason and recorded in `artefact_imports` |
| 25 | Operator restores production in place by mistake | Typed confirmation and a mandatory pre-restore backup (R-XXVI-139) |
| 26 | Customer has never configured an off-server destination | Persistent non-dismissible warning and a degraded health check from day one (R-XXVI-126) |
| 27 | A tenant is purged, then a pre-purge archive is restored | Refused for that tenant (R-XXVI-130) |
| 28 | Health probe itself stops | Every panel renders stale (R-XXVI-101); an external monitor sees the readiness endpoint go stale |

---

## XXVI.17 Tests

| # | Scenario | Assertion |
|---|---|---|
| T1 | Install on each supported platform | scheduler and every worker class registered, running, enabled at boot; probe job completed; heartbeat recorded |
| T2 | Install with the application user holding `ALTER` | refused (R-XXVI-016) |
| T3 | Post-install DDL attempt as the application user | fails |
| T4 | Full-tree grep for a literal password in every artefact | zero matches (R-XXVI-050) |
| T5 | Unattended install | no usable credential exists until the activation token is redeemed |
| T6 | Attempt to schedule an account-creating command | rejected by the registry (R-XXVI-053) |
| T7 | Kill the installer at each phase, re-run | completes; no duplicate tenant, no duplicate company, no second admin (regression for `L-…` DEF-L-16) |
| T8 | Break the HTTP path after migration; run the installer's health phase | install does not complete (regression for `I-…` DEF-26) |
| T9 | Provision from the default template on-premise, hire one employee, run payroll | exact net asserted; no configuration screen opened |
| T10 | Stop the scheduler | banner for every administrator, degraded health, alert, all within the declared window |
| T11 | Stop each queue-class worker in turn | class-specific banner and alert; enqueueing actions warn before acting |
| T12 | Stop the worker, then exhaust a grace period | no auto-suspension occurs (cross-check with Part XXV T22) |
| T13 | Stop the queue, then fail a backup | the failure alert is still delivered (R-XXVI-173) |
| T14 | Five-minute job crashes holding its lock | next run proceeds within the proportional TTL (regression for `L-…` DEF-L-02) |
| T15 | Per-tenant failure in a multi-tenant scheduled job | remaining tenants processed; run recorded as failed with counts (regression for `L-…` DEF-L-03, DEF-L-04) |
| T16 | Backup with the destination unreachable | retries, then alerts; run recorded failed |
| T17 | Truncate an archive; run verification | reported as failed, not successful (R-XXVI-133) |
| T18 | Configure only a local destination | persistent warning and degraded health |
| T19 | Attempt to produce an unencrypted archive | refused (R-XXVI-124) |
| T20 | Inspect an archive for `.env`, keys, OTPs, temporary passwords | none present (R-XXVI-121, R-XXVI-122) |
| T21 | Rename the database connection; run a backup | the database is still backed up (regression for `L-…` DEF-L-26) |
| T22 | **Full restore drill on a production-shaped dataset** | completes; deep health passes; every assertion in the suite passes; duration within the RTO |
| T23 | Corrupt one payslip in the archive; run the drill | drill fails (R-XXVI-141) |
| T24 | Drill exceeding the RTO target | recorded as failed (R-XXVI-145) |
| T25 | Suppress drills for two cycles | degraded then blocking health, plus vendor notification |
| T26 | Read `last_successful_restore_drill_at` from the console and the API | present, correct, stale-marked when overdue (R-XXVI-142) |
| T27 | Restore into a newer binary | forward migrations applied; health passes |
| T28 | Restore into an older binary | refused |
| T29 | Restore after a tenant purge, from a pre-purge archive | that tenant refused (R-XXVI-130) |
| T30 | In-place restore without typed confirmation | refused; with confirmation, a pre-restore backup is taken first |
| T31 | Air-gapped install, full functional run | zero vendor egress attempts; capability list rendered |
| T32 | Side-load a tampered rule pack | refused with a named reason and recorded |
| T33 | Side-load a valid pack air-gapped | applied; identical audit entries to the online path |
| T34 | Ten sequential updates | update backups pruned to the configured count (R-XXVI-131) |
| T35 | Remove the `zip` extension after install | drift check fails before the next backup fails |
| T36 | Diagnostic bundle contents | no secret, no PII (R-XXVI-102) |
| T37 | Attempt a destructive command without scope or confirmation | refused (R-XXVI-190) |
| T38 | Every console command | has a test (R-XXVI-191) |
| T39 | Health endpoint unauthenticated | status only; no schema name, no connection string, no stack trace (R-XXVI-093) |
| T40 | Payroll preview with stale attendance ingestion | refused with the staleness named (R-XXVI-172) |

---

## XXVI.18 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Decision | Recommendation | Trade-off if refused |
|---|---|---|---|
| **D-XXVI-01** | Which platforms are supported on-premise, and to what tier? | Linux (declared distributions) as tier 1; Windows Server as tier 1 because it is the installed base; container images as tier 1 for new installs; desktop-class Windows/Laragon as **unsupported** | Supporting every host the installed base uses means the installer must register services on all of them; declaring a floor is cheaper than pretending |
| **D-XXVI-02** | May a customer operate without an off-server backup destination? | Only with a recorded, time-limited, contractual acknowledgement; the warning is never dismissible | Silent acceptance recreates SmartPRS 2's on-box-only posture, where a server loss loses the backups too |
| **D-XXVI-03** | **The published RTO and RPO per reference architecture** | 24 h / 8 h small, 4 h / 4 h medium, 15 min / 2 h large | Without published targets a customer cannot size their infrastructure and Ametecs cannot be held to — or defended by — a number |
| **D-XXVI-04** | Restore drill cadence, and is it mandatory? | Monthly by default; quarterly minimum at the large architecture; mandatory for supported installations | An optional drill is a drill that never runs, which is the position today |
| **D-XXVI-05** | May the on-premise operator perform a restore themselves? | Yes, with two-person authorisation and typed confirmation | If not, Ametecs owns every customer's data recovery, at Ametecs' availability — the current de facto position (`I-…` Q-19) |
| **D-XXVI-06** | Is telemetry (health summary, version, seat usage) sent to Ametecs by default? | Opt-in, with the exact payload published and inspectable; opt-in strongly encouraged in the support contract | Without it, Ametecs cannot know which customers are on unsupported versions or have failing backups — which is the position today (`I-…` Q-09) |
| **D-XXVI-07** | Is a fully air-gapped install a supported configuration, and at what tier? | Yes, tier 2: all business function supported; the unavailable capabilities are published | Ambiguity here produces the SmartPRS 2 outcome, where offline activation is advertised on the activation screen and disabled by default |
| **D-XXVI-08** | Who owns the customer's backup encryption key? | The customer, with an optional escrow the customer opts into | Vendor-held keys make Ametecs a target and a single point of failure; customer-held keys mean a lost key is an unrecoverable backup, which must be stated in the contract |
| **D-XXVI-09** | Is source protection (bytecode encoding) mandatory on customer builds? | Decide explicitly; if yes it becomes a blocking prerequisite, not a warning | SmartPRS 2 checks the loader at `warn` level (`InstallController.php:84-89`), so a plain-source install is permitted and the embedded verification key is one editable line (`I-…` Q-07) |
| **D-XXVI-10** | What is the supported-version window for on-premise installs? | N and N−1 minor versions, plus a security-fix window for N−2 | Owned jointly with Part XXVII §XXVII.6.5; without it the version register has no meaning |

---

## XXVI.19 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XXVI-001 – R-XXVI-003 | Parity; no domain branching (§XXVI.2) | 3 |
| R-XXVI-010 – R-XXVI-016 | Reference architectures and the prerequisite matrix (§XXVI.3–4) | 10 |
| R-XXVI-020 – R-XXVI-029 | Installer shape, pre-flight, configuration capture, migration (§XXVI.5.1–5.4) | 10 |
| R-XXVI-050 – R-XXVI-054 | First administrator; no default credential; no vendor account (§XXVI.5.5) | 5 |
| R-XXVI-070 – R-XXVI-078 | Runtime registration, licence, template, health gate, certificate (§XXVI.5.6–5.9) | 9 |
| R-XXVI-090 – R-XXVI-093 | The health-check specification (§XXVI.6) | 4 |
| R-XXVI-100 – R-XXVI-103 | The operations console (§XXVI.7) | 4 |
| R-XXVI-120 – R-XXVI-134 | Backup: contents, encryption, destinations, retention, monitoring (§XXVI.8.1–8.5) | 15 |
| R-XXVI-135 – R-XXVI-146 | **Restore, the drill, and RTO/RPO** (§XXVI.8.6–8.8) | 12 |
| R-XXVI-160 – R-XXVI-175 | Background-job reliability; loud degradation (§XXVI.9) | 16 |
| R-XXVI-180 – R-XXVI-185 | Air-gapped and restricted egress (§XXVI.10) | 6 |
| R-XXVI-190 – R-XXVI-191 | CLI safety and tests (§XXVI.13.1) | 2 |
| R-XXVI-200 – R-XXVI-201 | Migration gates (§XXVI.15) | 2 |
| **Total** | | **98** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Schedules, lock TTLs, health thresholds, backup sets, destinations, retention, drill cadence, RTO/RPO targets, staleness policies, prerequisite severities and alert routing are **all** data. Constants in code are platform facts (service-manager identifiers, check keys) on the declared allow-list | Static analysis; T14, T18, T24, T25 |
| **2 — no schema change at runtime** | The installer creates two database users and **revokes** schema privileges from the application user, and health check 4 asserts the revocation by attempting DDL. The six request-path `ALTER TABLE`s (`J-…` §6.5) and the 71 `Schema::create()` sites have no successors | T2, T3, T7; CI grep for DDL outside migrations |
| **3 — nothing overwritten** | Pre-flight results, service registrations, scheduled-job runs, health results, backup runs, restore runs and artefact imports are all append-only. `dr_status` is a derived read model, not a source of truth | Append-only grants asserted in CI |
| **4 — every number explains itself** | Every health result carries its observed value, threshold, onset and remediation; every backup and restore run carries its verification detail and assertion results; the console never shows a bare status | R-XXVI-091, R-XXVI-092; T22, T26 |
| **5 — authorisation fails closed** | Every `/ops` route carries an explicit policy binding; liveness and readiness expose status only; restore requires two-person authorisation and typed confirmation; no vendor account exists post-install and vendor access requires a time-boxed tenant-approved grant | T30, T36, T39; route-policy sweep |
| **6 — tenancy is structural** | Installation-scoped tables are enumerated as the documented exception and hold no tenant business data; every per-tenant scheduled job iterates with per-item fault isolation and per-tenant timezone; the tenant-unscoped user lookup at `DemoReset.php:106` has no successor | T15; R-XXVI-052, R-XXVI-165 |
| **7 — money is exact** | This part performs no money arithmetic. Its contribution to Law 7 is the restore assertion suite, which asserts an **exact** payslip net after recovery — so a restore that silently corrupts money fails the drill | T22, T23 |

---

*End of Part XXVI.*
