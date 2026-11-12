# RUNBOOK — collecting a Discovery Pack at a customer site

For the Ametecs engineer doing the visit. `README.md` explains the tool; this is the procedure.

Budget half a day. Most of it is conversation, backup verification and the findings review, not
the collection — the collection itself is usually minutes.

---

## 0. Before you travel

- [ ] Written authorisation from the customer exists, naming this install, and says who
      authorised it and when. See §1.
- [ ] You have a copy of `discovery/` on a device you control (a laptop, not the customer's).
- [ ] You have the Ametecs signing key (`--sign-key`) if the pack must be signed on site.
- [ ] You know who the customer's escalation contact is, because you may have to raise a live
      security finding before you leave (§6).
- [ ] You have somewhere encrypted to put the pack.

---

## 1. Consent and notification — the position

**You are reading a production database containing employee personal data.** Under the DPDP Act
2023 the customer is the data fiduciary and Ametecs is a processor. Consequently:

1. **Do not connect without written authorisation** from someone at the customer who can give
   it — the person who signs the support contract, or their delegate in writing. A verbal "go
   ahead" from an admin on the phone is not enough. E-mail is enough.
2. **The authorisation must name what you will do:** connect read-only to the production
   database, read the schema and aggregate data, take counts, and take a redacted evidence pack
   away with you. Say plainly that the pack contains pseudonymised employee identifiers and
   aggregate salary and headcount data.
3. **Redaction stays on unless the customer has agreed in writing that it comes off.** Running
   `--unredacted` requires `--i-have-consent`, which is your assertion that a written basis
   exists for *that specific install*. If in doubt, run redacted; you can always come back.
4. **Tell them when you connect and when you disconnect.** Log it in the ticket. If the customer
   has a change-control process, this goes through it — it is a read, but it is a read of
   production by an outsider.
5. **The customer's own staff may run the tool instead**, supervised by you over a call. That is
   preferable where the customer's policy forbids third-party access. The pack is the same.
6. **Anything you find stays confidential to Ametecs and the customer.** Do not carry findings
   from one customer to another other than as an anonymised schema fingerprint.

If the customer declines a read-only user, or declines access to the live database at all: ask
for a **restored backup in a scratch instance** instead. That is the better environment anyway
(§4), and it removes the production-access question entirely.

---

## 2. Before you touch the database

- [ ] **A full backup exists** — database *and* the `storage/` document tree.
- [ ] **That backup has been restored** into a scratch instance and row-count checked. SmartPRS
      has no restore path in the product and no restore has ever been performed from one of its
      backups, so "we have backups" is not the same as having a backup. If nobody has ever
      restored one, that is itself a finding to raise, and a reason to prefer §4.
- [ ] **Nobody is going to run `php artisan migrate` while you are there.** Two SmartPRS 2
      migrations hard-delete rows from `attendance_logs` inside `up()`, and one triggers an
      unlogged unbounded delete. Say this out loud to whoever administers the box.
- [ ] The read-only user exists (README §3), or you have recorded why it does not.

---

## 3. Collect, in this order

**Step 1 — the access log, first, before anything else.**

Web-server access logs rotate. They are the only evidence of whether employee KYC, PAN, Aadhaar
and bank documents on the publicly served disk have actually been fetched from outside the
application, and that evidence is the input to a DPDP disclosure assessment. Find the log and
either point the tool at it or copy it aside:

```bash
php checks/d10_files.php --app=/var/www/smartprs --out=./pack \
    --access-log=/var/log/apache2/access.log --hash-files
```

If the logs have already rotated beyond the window, the pack records **"exposure window not
evidenced"**. That is an acceptable answer; a blank is not. Note the retention setting and how
far back the surviving logs go.

**Step 2 — the whole pack.**

```bash
php run-discovery.php \
  --app=/var/www/smartprs \
  --install=<short-id> --customer="<legal name>" --collected-by="<your name>" \
  --user=smartprs_discovery --pass='…' \
  --access-log=/var/log/apache2/access.log --hash-files \
  --sign-key=./ametecs-discovery.key
```

Watch the console. Each check prints its elapsed time. If one is still running after a few
minutes, see §4.

**Step 3 — read `SUMMARY.md` §1 before you leave the site.** Some findings are live risks that
need raising today (§6).

**Step 4 — record the facts the tool cannot see.** In the ticket, not the pack:
who authorised the collection, when you connected and disconnected, whether the backup restore
was verified, what the customer said about the demo tenant, and any screen the customer says
they use that the data suggests nobody uses.

---

## 4. When a query is too slow

The expensive parts are, in order: `COUNT(*)` on `attendance_logs`, the `emp_code` join in D4
(orphan class O5), and the group-by scans in D9 over `attendance_logs`. All three are full index
scans on InnoDB. The per-query server-side limit (`--timeout`, default 30 s) means the *server*
kills a runaway query; the tool records it as a note and carries on. Nothing hangs the database.

Escalate in this order:

1. **Raise the timeout and let it run out of hours.**
   `--timeout=600000` and run at 22:00. Simplest, and keeps the numbers exact.

2. **Skip the heavy tables, finish everything else now.**
   ```bash
   php run-discovery.php --app=… --skip-heavy --heavy-rows=2000000
   ```
   The pack records exactly what was skipped and why, in `integrity/orphans.csv`
   (`status = SKIPPED`), `profile/row_counts.csv` (`mode`) and `_meta/`. This gets you a usable
   pack today with an honest hole in it.

3. **Estimate the counts.** `--counts=estimate` uses the InnoDB row estimate. Fast, and
   **the pack then cannot pass Gate G-1** — the summary says so. Use it only for a first look
   or for sizing, never for a reconciliation baseline.

4. **Move to a replica or a restored backup.** This is the right answer for any large install,
   and it is the only correct answer if the customer will not accept a scan on the primary.
   Restore the verified backup into a scratch MySQL, point the tool at it, and note the backup's
   timestamp in the ticket — the counts are as of the backup, not as of now.

   ```bash
   php run-discovery.php --db=smartprs_restored --user=root --install=acme-hq-from-backup
   ```

5. **Run the checks individually across a window.** Every check stands alone and writes into the
   same pack directory:
   ```bash
   php checks/d1_schema.php  --app=… --out=./pack     # seconds
   php checks/d3_enums.php   --app=… --out=./pack
   php checks/d2_counts.php  --app=… --out=./pack     # the slow one, later
   php checks/summary.php    --pack=./pack --sign-key=…   # rebuild SUMMARY.md at the end
   ```

Never "fix" a slow query by adding an index to the customer's database. Adding an index is DDL
on production and is outside the authorisation you have.

---

## 5. What is redacted before the pack comes back

Redaction is on by default and produces this. Say it to the customer in these words if they ask.

| Field class | What the pack carries |
|---|---|
| Names, employee codes, e-mail, mobile, PAN, Aadhaar, UAN, ESIC, bank account, IFSC, address, document file paths, device user ids, `employee_id` | A salted SHA-256 token, first 12 hex, e.g. `xa19f2c4b7e01`. Same person, same token, in every file — so rows still join. **Pseudonymised, not anonymised:** with the source database it can be reversed, which is why the pack is still treated as personal data. |
| Date of birth | Year only: `1990-**-**`. |
| Latitude / longitude | Rounded to one decimal (~11 km). |
| Passwords, tokens, API keys, SMTP passwords | Never carried. Recorded as `[present:len=N]`, or `[CREDENTIAL WITHHELD]`, with no fingerprint. The default-password check reports a yes/no verdict only; the hash is not written. |
| Web-server client IP addresses | Hashed with the same salt. |
| Counts, totals, money amounts, statuses, dates other than DOB, table/column names, enum values, configuration vocabularies, leave type names, error messages | Carried in the clear. This is what migration decisions are made from. |
| `app_state` | Structure only — collection names, element counts, key unions, shape counts, byte sizes, SHA-256 per collection. Contents are dumped **only** for collections on the configuration allow-list; anything holding people, or anything unrecognised, is profiled and never dumped. |

The salt defaults to a value derived from the install id, so tokens are stable across re-runs of
the same install and different between installs. Pass `--salt=` with a secret if you need tokens
that Ametecs alone can correlate.

**What redaction does *not* do:** it does not make the pack safe to circulate. Headcounts,
salary aggregates, org structure and per-employee leave and loan rows are personal data in
aggregate. Treat the pack as confidential customer data throughout.

**If you need an unredacted pack** — and you will, for the verbatim `app_state` capture — get it
in writing first, run with `--unredacted --i-have-consent`, and **leave that pack on the
customer's machine** unless the written basis explicitly covers taking it away. The redacted
pack is what travels.

---

## 6. Findings you must raise before you leave

These are live risks in SmartPRS 2 today, not migration items. If `SUMMARY.md` shows any of
them, tell the customer during the visit and record that you did:

| Finding | Why today |
|---|---|
| **Default credential account still uses a published password** | `admin@smartprs.local` (or a `demo-*` account) is reachable right now with a password that is in the shipped source. Get it changed before you leave. |
| **A real tenant matches the demo discriminator** | A tenant with `subdomain='demo'` is both product-locked and the target of a seeder that hard-deletes 23 tables including `employees`. If that tenant is a paying customer, it is one command away from losing its people, org and payroll history. |
| **`demo:reset` is scheduled and the scheduler runs** | The same risk, nightly, automatic. |
| **`public/storage` is a live symlink with no `.htaccess`** | Employee KYC, PCC, PAN, Aadhaar, bank proofs and self-onboarding ID documents are fetchable over the web without authentication. If `files/public_exposure.csv` shows HTTP 200 fetches, this is a suspected personal-data breach and goes to the customer's escalation contact the same day. |
| **No queue worker is running** | Nothing queued has ever been delivered. Alerts marked `sent` were not sent. The customer probably believes otherwise. |
| **Over-recovered loans** | Employees have repaid more than they borrowed. That is money owed back to named people. |
| **LOP leave paid in full** | Money has already left the business. It is a mispayment, and it is ongoing until fixed. |

Everything else can wait for the report.

---

## 7. Handing the pack back

1. **Check it.** `SUMMARY.md` exists, `manifest.json` lists every file, `manifest.sig` verifies:
   ```bash
   openssl dgst -sha256 -verify ametecs-discovery.pub \
       -signature <(base64 -d manifest.sig) manifest.json
   ```
   If signing failed on site, `manifest.sig` says so — sign it before sending, not after
   editing.
2. **Never edit a file inside the pack.** It invalidates the manifest and the chain of custody.
   Corrections go in the ticket.
3. **Encrypt it before it moves.** An encrypted archive with the key sent separately, or the
   agreed secure transfer. Not e-mail, not a chat attachment, not an unencrypted USB stick.
4. **Commit it** to the migration tooling repository under the install id, encrypted.
5. **Register the schema fingerprint** (`schema/fingerprint.json`) in the schema profile
   register. A matching fingerprint means this install shares a migration profile with one you
   already understand — that is the whole point of the exercise.
6. **Start the retention clock.** Destroy the pack 90 days after this install signs off, and
   record the destruction. Delete the read-only database user. Delete any working copies from
   the customer's machine and from your laptop's temp directories.
7. **Log the collection** in the install's migration record: who, when, which revision, which
   fingerprint, redacted or not, and where the pack is stored.

---

## 8. If something goes wrong

| Symptom | What it means | What to do |
|---|---|---|
| `Cannot connect` | Credentials, host, or the user is not allowed from this host. | Check `.env`; try `--host=127.0.0.1` rather than `localhost` (socket vs TCP). |
| `Connected but no default database selected` | The DSN had no `dbname`. | Add `--db=NAME`. |
| Many notes saying `Unknown table` / `Unknown column` | **Normal.** SmartPRS 2 installs genuinely differ; a missing table is data, not an error. | Nothing. They are recorded in `SUMMARY.md` §7. |
| A check prints `FAILED` | An unexpected exception; the others still ran. | Re-run that check alone with `--verbose`, keep the output, carry on. A partial pack is better than none. |
| `REFUSED non-read statement` in the notes | The safety allow-list stopped something. | This should never happen. Keep the pack and report it — it is a bug in the toolkit, and until it is understood, stop using that copy. |
| Collection is still running after 45 minutes | A heavy scan. | Ctrl-C is safe. See §4. |
| The customer asks what you are running | Show them `README.md` §1 and `sql/`. Every query is readable plain SQL. | |
