# SmartPRS 2 Discovery Toolkit

Collects a **Discovery Pack** from one live SmartPRS 2 install: what its schema actually is,
what its data actually contains, and which of the known defects are live on it.

You do not need to read Part XXIX to run this. The short version of why it exists:

> No production schema dump has ever been taken from any SmartPRS 2 install. A live database
> has roughly 152 tables; `php artisan migrate:fresh` produces 102. Around 49 tables exist only
> because application code created them at run time, 15 more get a *different* schema depending
> on which screens were opened first, and there are **zero foreign keys** on business tables.
> There is therefore no such thing as "the SmartPRS 2 schema" — only *this install's* schema.
> Until you have run this against a given customer, every migration estimate for that customer
> is a guess.

The pack is the evidence for **Gate G-1**: no migration script, effort estimate, cutover date or
commercial commitment for an install may be approved without it.

---

## 1. Safety — read this before you run it

**This toolkit is read-only, and it is built so that it cannot be otherwise.**

- Every statement is checked against a `SELECT` / `SHOW` / `EXPLAIN` / `DESCRIBE` allow-list in
  `lib.php` (`dsc_q()`) *before* it reaches the server. A non-read statement is refused and
  logged, not executed. There is no `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE` or `DROP`
  anywhere in this directory.
- No transaction is held open. The session runs at `READ COMMITTED` specifically so that a long
  collection does not hold a read view open and make InnoDB purge fall behind on a busy server.
- No table is locked. Nothing is written to the database — not a temp table, not a log row.
- A per-query server-side time limit is set (`--timeout`, default 30 s), so a query that turns
  out to be pathological is killed by the server rather than running all afternoon.
- The collector checks its own grants and **warns you if it is connected with a write-capable
  account**. Give it a read-only user (see §3).

**What is safe during business hours:** everything in D1, D3, D5, D6, D7, D8, D11, and most of
D4 and D9. These read `information_schema` or small tables.

**What is not, on a large install:** the exact `COUNT(*)` in D2 over `attendance_logs`, the
`emp_code` join in D4 (O5), and the group-by scans in D9 over `attendance_logs`. On InnoDB these
are full index scans. If `attendance_logs` holds more than a few million rows:

> **Run the pack against a replica or a restored backup, not against the live primary.** If you
> must run against the primary, use `--skip-heavy` (which reports what it skipped) or run out of
> business hours, and collect the heavy checks separately later.

**Before you touch the database at all**, a full verified backup of the database *and* the
document storage tree must exist, and must have been restored into a scratch instance and
row-count checked. An unrestored backup is not a backup — and no restore has ever been performed
from a SmartPRS backup, because the product has no restore path.

**Never run SmartPRS 2's own migrations or seeders while you are there.** Two of its migrations
hard-delete rows from `attendance_logs` inside `up()`, and one invokes an unlogged unbounded
delete automatically. Nothing in this toolkit can trigger that, but `php artisan migrate` can.

---

## 2. What you need

| | |
|---|---|
| PHP | 8.1, 8.2 or 8.3 (8.4 also works). The customer's own PHP is fine. |
| Extensions | `pdo_mysql` and `json`. Both are already required by SmartPRS 2. |
| Composer | **Not needed.** No dependencies at all. |
| Platform | Windows/Laragon and Linux. Nothing is shell-specific except two optional probes. |
| Access | A read-only MySQL user, and read access to the application directory. |

Copy this whole `discovery/` folder to the machine (a USB stick is fine). It writes nothing
outside its output directory.

---

## 3. Create the read-only user

```sql
CREATE USER 'smartprs_discovery'@'localhost' IDENTIFIED BY '<a long random password>';
GRANT SELECT, SHOW VIEW ON <database>.* TO 'smartprs_discovery'@'localhost';
FLUSH PRIVILEGES;
```

Drop the user when you leave. If the customer will not create one, the toolkit still runs with
the application's own credentials and records a MEDIUM finding saying so.

---

## 4. Run it

The simplest form reads the database credentials from the application's own `.env`:

```bash
php run-discovery.php --app=/var/www/smartprs --install=acme-hq
```

Windows / Laragon:

```
php run-discovery.php --app=C:\laragon\www\smartprs --install=acme-hq
```

A full on-site invocation:

```bash
php run-discovery.php \
  --app=/var/www/smartprs \
  --install=acme-hq \
  --customer="Acme Security Services Pvt Ltd" \
  --collected-by="E. Ahmed" \
  --host=127.0.0.1 --user=smartprs_discovery --pass='…' \
  --access-log=/var/log/apache2/access.log \
  --hash-files \
  --sign-key=./ametecs-discovery.key
```

`--app` is worth supplying even when you pass explicit credentials: it is what lets the tool
read `version.json`, parse the migrations directory (so provenance is *derived* rather than
assumed), inventory the document storage tree, and see whether `demo:reset` is scheduled.

### Options

| Option | Meaning |
|---|---|
| `--app=PATH` | Application root. Reads `.env`, `version.json`, `composer.lock`, `database/migrations/`, `storage/`. |
| `--host --port --db --user --pass` | Explicit connection, overriding `.env`. |
| `--dsn=` | Full PDO DSN if you need something unusual. |
| `--install=ID` | Install identifier used in the pack name. Defaults to the database name. |
| `--out=PATH` | Output directory. Defaults to `./discovery-pack-<install>-<date>`. |
| `--counts=estimate` | Skip exact `COUNT(*)`. Fast, but the pack then **cannot** pass Gate G-1. |
| `--skip-heavy` | Skip full scans of tables above `--heavy-rows` (default 5,000,000). Records what it skipped. |
| `--timeout=MS` | Per-query server-side limit. Default 30000. |
| `--sample=N` | Max sample rows kept per evidence query. Default 50. |
| `--access-log=PATH` | Web-server access log, for the document-exposure evidence. **Collect this first — logs rotate.** |
| `--hash-files` | SHA-256 every document file. Slower; required before any file transfer. |
| `--sign-key=PATH` | PEM private key; signs `manifest.json` into `manifest.sig`. |
| `--only=` / `--skip=` | Run or skip individual checks, e.g. `--only=d3_enums,d4_orphans`. |
| `--unredacted` | **Off by default.** Includes raw PII; requires `--i-have-consent`. See §7. |
| `--verbose`, `--help` | |

### How long it takes

Dominated entirely by `attendance_logs`. Everything else is seconds.

| Install size | Typical wall time |
|---|---|
| Small (< 100 employees, < 500k punches) | under a minute |
| Medium (< 1000 employees, few million punches) | 2–10 minutes |
| Large, or a slow shared host | 10–45 minutes; use a replica |
| `--counts=estimate --skip-heavy` | under a minute on anything |

Interrupting is harmless — nothing is written to the database. Re-run and it starts clean.

---

## 5. What it collects

Twelve deliverables, D1–D12 in Part XXIX terms. Every check runs independently:
`php checks/d3_enums.php --app=… --out=…`.

| Check | What it answers |
|---|---|
| `d1_schema` | `SHOW CREATE TABLE` for every table; all columns, indexes, foreign keys; engine, collation, size. **Which tables no migration creates** (parsed from the source tree when `--app` is given). Which of the known type divergences this install carries — `dra_expiry`, `pcc_deadline`, `dob`, `doj`, `salary_components.calc_value`. Which shape `attendance_pending` has. The **schema fingerprint** that assigns the install to a migration profile. |
| `d2_counts` | Exact row counts per table, per-tenant counts with `NULL` and `0` sentinels kept apart, date ranges for every date column, and the reconciliation baselines the customer will sign. |
| `d3_enums` | Every `ENUM` column's declared set versus the values actually present. Finds all violations on this install, not just the twelve known ones, and separates "value the enum forbids" from "silently blanked on write". |
| `d4_orphans` | ~30 orphan classes that carry money, evidence or identity — payslips without a run or an employee, attendance without an employee, commissions with no employee, documents without an owner, loan recoveries against a deleted run, and the rest. Plus the duplicates the missing unique constraints allow. |
| `d5_app_state` | The `app_state` JSON blob per tenant: top-level keys, types, element counts, key unions, distinct element shapes, byte sizes, per-collection SHA-256. Extracts the role permission matrices. Flags unparseable blobs and duplicate rows. |
| `d6_config_blobs` | `statutory_settings`, `statutory_configs`, `pf_esi_settings`/`tds_rules`/`pt_slabs`, `late_policy`, `shifts`, `pay_cycles`, `app_settings`, holidays, leave types, salary components. Credential *presence* only, never values. |
| `d7_money` | Loan balances stored versus derived from the recovery ledger; over-recovered loans; recoveries pointing at deleted runs; advances with open balances; clawbacks. |
| `d8_leave` | Whether `type_id` was ever written; the LOP-paid-in-full population and which payslips it touched; leave type names that no longer resolve; the naive derived balance. |
| `d9_defects` | Evidence for each confirmed live defect: fabricated `paid` payroll runs, payslips at exactly CTC/12, commissions stranded by the enum mismatch, cross-midnight attendance, PCC status, audit-chain breaks, demo attendance, the demo tenant, archived employees consuming seats, locked-period corrections, backdated salary reductions, invoice over-credit. Produces the **Contamination Register**. |
| `d10_files` | Document storage inventory by tree, count, bytes and (with `--hash-files`) SHA-256. Whether `public/storage` is a live symlink and whether anything protects it. Access-log evidence of actual fetches. |
| `d11_env` | `version.json`, PHP and MySQL versions, `sql_mode`, timezones, `.env` configuration, `composer.lock`, whether the scheduler and queue worker are actually running, whether `demo:reset` is scheduled, and whether the default on-prem credential account still exists with its published password. |
| `summary` | `SUMMARY.md` and the signed `manifest.json`. |

`sql/` holds the same queries as plain SQL for anyone who prefers to run them by hand or against
a replica. They are not guarded: a query against a table this install does not have will error,
and **that error is a result** — record it as "table absent" and carry on.

---

## 6. What you get

```
discovery-pack-acme-hq-2026-09-03/
  SUMMARY.md          <- read this first
  manifest.json       <- SHA-256 of every file
  manifest.sig        <- with --sign-key
  schema/             create_tables.sql, columns.csv, indexes.csv, foreign_keys.csv,
                      provenance.csv, divergent_columns.csv, fingerprint.json, server.json
  profile/            row_counts.csv, tenant_counts.csv, date_ranges.csv, baseline.json
  integrity/          enum_violations.csv, enum_observed.csv, orphans.csv, duplicates.csv,
                      orphan_samples/, duplicate_samples/
  blobs/              app_state_inventory.csv, app_state_tenants.csv, app_state_config/,
                      app_state_roles/, statutory_*, late_policy.csv, shifts.csv,
                      app_settings.csv, credential_presence.csv
  money/              loans_summary.csv, loans_derived.csv, loans_over_recovered.csv, advances.csv
  leave/              derivation.csv, lop_credited_as_paid.csv, lop_overlapping_payslips.csv
  defects/            contamination_register.csv + one CSV per defect
  files/              inventory.csv, file_manifest.csv, public_exposure.csv, htaccess.csv
  env/                fingerprint.json, default_accounts.csv
  _meta/              per-check findings and collection notes
```

`SUMMARY.md` leads with **what changes the migration plan** — blockers first, then this
install's migration profile, the reconciliation baselines, the contamination register, and gate
readiness. Raw dumps are listed at the end, not at the front.

Running the pack twice against an unchanged database produces byte-identical output apart from
the timestamps and the signature.

---

## 7. Redaction — what leaves the customer's site

**Redaction is ON by default.** You have to pass both `--unredacted` and `--i-have-consent` to
turn it off, and the pack then says so on its first line and in its manifest.

In a redacted pack:

- **Hashed** (salted SHA-256, first 12 hex, prefixed `x`): names, employee codes, e-mail
  addresses, mobile numbers, PAN, Aadhaar, UAN, ESIC, bank accounts, IFSC, addresses, document
  file paths, device user ids, and `employee_id` values. The hash is *deterministic within one
  pack*, so the same person is the same token in every file and rows still join across CSVs.
  It is **pseudonymisation, not anonymisation**: with the source database you can reverse it.
- **Bucketed:** dates of birth become `1990-**-**`; coordinates are rounded to one decimal
  (~11 km).
- **Withheld entirely:** passwords, tokens, API keys, SMTP passwords — recorded as
  `[present:len=N]` and never as a value or a fingerprint.
- **Kept:** counts, totals, money, dates other than DOB, statuses, configuration vocabularies,
  table and column names, enum values, leave type names, error messages. These are what the
  migration decisions are made from.
- **`app_state`:** structure and sizes only — collection names, element counts, key unions,
  shape counts, byte sizes, SHA-256 per collection. Contents are dumped **only** for
  collections on the configuration allow-list in `checks/d5_app_state.php`
  (roles, leave types, holidays, templates, point rules, incentive schemes, policies, notices …).
  Anything holding people — employees, complaints, escalations, payroll runs, archived
  employees — is profiled and never dumped. Anything unrecognised is treated as personal data.
  The verbatim blob capture required by R-XXIX-017 therefore needs an unredacted run, taken and
  kept on the customer's own machine.

Even redacted, **the pack is classified as containing personal data** (headcounts, salary
aggregates and org structure are personal data in aggregate). Encrypt it at rest, move it over
an authenticated channel, and destroy it 90 days after the install signs off.

---

## 8. What to do with the output

1. **Read `SUMMARY.md` §1 on site, before you leave.** Some findings are live risks in
   SmartPRS 2 today, not migration items — a default admin password that still works, a real
   customer tenant named `demo` that a seeder will hard-delete, employee documents fetchable
   over the web without authentication. Those are raised with the customer during the visit.
2. **Register the schema fingerprint** in the migration tooling's schema profile register. If it
   matches an install you already have, this customer shares that migration profile. If it does
   not, it is a new profile and needs its own transformation variants.
3. **Commit the pack** to the migration tooling repository, encrypted, under the install id.
4. **Fill the Contamination Register.** Every row needs a Product Owner disposition before
   anything is loaded into SmartPRS 3.
5. **Only then** produce an estimate for this install. Everything before the pack is provisional
   by definition.

---

## 9. Testing the toolkit itself

`test/fixture.sql` builds a miniature SmartPRS 2 database carrying the pathologies described
above — VARCHAR dates, a DECIMAL `calc_value`, blanked enum values, orphan payslips, duplicate
PANs, an unparseable `app_state`, an over-recovered loan, LOP leave paid in full, fabricated
`paid` runs, a broken audit chain, a demo tenant and a default admin account. `test/smoke-test.php`
runs the whole collector against it and asserts that each one is found, and that no fixture PII
appears anywhere in a redacted pack.

```bash
mysql -u root < test/fixture.sql
php test/smoke-test.php --host=127.0.0.1 --user=ro --pass=ro
```

Point it only at a throwaway database. If you change a check, run this before you ship it.

---

Next: `RUNBOOK.md` — the step-by-step for the engineer at the customer site, including consent,
what to do when a query is too slow, and how to hand the pack back.
