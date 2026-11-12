# PART XXVII — LICENSING, EDITIONS AND UPDATES

*Covers brief Sections 89 (the application update system) and 90 (rule-pack and statutory
content updates), together with the licence artefact, entitlements, editions and the release
engineering discipline at Ametecs that the two update channels depend on.*
*Conforms to spine S1 (the seven laws), S4 (effective-dated, versioned artefacts), S12 (the
India Statutory Rule Pack — asymmetric signing, the private key never leaves Ametecs, packs
are content and not code), S13 (security; no default or shared credential ships in any
release), S14 (the audit ledger), S18 (deployment parity: one artefact, signed application
updates with database backup and rollback, and rule-pack updates separate from application
updates).*
*Depends on: Part XXVI (which owns the runtime the update flow runs inside, the installer, the
health-check catalogue, backup and restore), Part XXV (which owns the SaaS subscription that
resolves an entitlement set, the seat counter and the provisioning pipeline), Part XVII (which
owns the statutory rule pack's content model, signature scheme, validation, preview and
acceptance flow), Part XXII (security), Part XXIII (audit), Part XXXI (metrics, logs,
alerting).*
*Depended on by: Part XXV (§XXV.6.2 delegates the entitlement grammar to §XXVII.3), Part XXVI
(§XXVI.5.7 activation, §XXVI.10 side-loading, §XXVI.12.1 `installed_version`, D-XXVI-10 the
supported-version window), Part XVII (§XVII.4.9, whose content channel is the second of the two
channels specified here).*
*Evidence base: `analysis/I-saas-licensing-deployment.md`. Every claim about SmartPRS 2 below
carries a `file.php:line` citation carried through from that file. Where the analysis states
that a claim could not be verified against the supplied source extract, this part says so and
does not assert it.*

---

## XXVII.0 Purpose

This part decides four things.

1. **That there is exactly one licensing primitive, and it is asymmetric.** SmartPRS 2 has
   three licence mechanisms and a fourth artefact that is what actually gates access. One
   mechanism is correct (RSA-2048 over a `.lic` file, private key confined to the Super Admin
   install — `LicenseSigner.php:36-61`, `LicenseFile.php:34-44,140-148`). One is symmetric
   HMAC-SHA256 keyed by a secret that `config/smartprs.php:56-58` states "MUST be identical on
   the Super Admin and on every client install" — a **signing** key specified, by design, to be
   present in every customer build (`LicenseService.php:265-281`). And the thing every
   downstream gate actually reads is an unsigned JSON blob in a settings row that one `UPDATE`
   statement rewrites (`ClientUpdateController.php:31-57,74-108`; `I-…` SEC-01, CRITICAL).
2. **That every artefact a customer's installation evaluates is signed, and verification can
   never be switched off.** SmartPRS 2's update packages are not signed at all. The only
   integrity control is a SHA-256 supplied by the same server in the same JSON response, and
   the check is conditional at both ends (`UpdateClient.php:254`, `updater.php:174`). A
   compromised release server — or a compromised `/super` account — is remote code execution as
   the web user on every AMC-active customer's payroll server, delivered by the product's own
   mechanism, with maintenance mode and a backup, reported to the administrator as a successful
   update (`I-…` SEC-06, HIGH).
3. **That an update that can fail must be able to be undone, including the database.**
   SmartPRS 2's code backup, maintenance mode, migration and code rollback genuinely exist and
   are well built — the client updater removes each replaced entry before restoring, precisely
   so that files the new version added cannot survive a rollback (`updater.php:140-165`). There
   is **no database backup and no database rollback anywhere in the update flow**
   (`updater.php:252-269` backs up files only; `:307` runs `migrate --force`; `:140-165`
   restores code only). There is no `queue:restart`, no cache rebuild, no `composer install`,
   and the health check is `php artisan --version` (`I-…` DEF-25, DEF-26, DEF-27).
4. **That the commercial model and the enforcement model must be the same model.** SmartPRS 2
   sells three editions at three price points and ships one product: `Edition::level()` returns
   the literal `3`, `hiddenNavIds()` and `blockedPatterns()` both return the SaaS-only sets, and
   `BLOCK_DNA` and `BLOCK_L2` are returned by nothing (`Edition.php:93-97,119-127,169-174`;
   `I-…` DEF-02, HIGH commercial). The one edition behaviour that is enforced is the lock at
   activation — a distinction that does not exist is enforced against the customer.

What this part does **not** own: the statutory rule pack's content schema, SPX content,
validation, golden cases, impact preview and acceptance semantics (Part XVII §XVII.4); the
installer, the health-check catalogue, backup, restore and the operations console (Part XXVI);
the subscription lifecycle, dunning, invoicing and the seat counter (Part XXV); the audit
ledger's construction (Part XXIII); the metric catalogue and alert routing (Part XXXI).

---

## XXVII.1 What SmartPRS 2 does today, and why it must change

### XXVII.1.1 Licence signing is both symmetric and asymmetric — and the symmetric key is specified to be in every customer build

Three mechanisms exist. Stating this precisely matters, because a summary that says only
"SmartPRS 2 uses RSA" is wrong and a summary that says only "SmartPRS 2 ships its signing key"
is also wrong.

| # | Mechanism | Primitive | Signing key location | Live today? |
|---|---|---|---|---|
| A | `.lic` file | **RSA-2048, asymmetric** — `openssl_sign` over the exact base64url payload (`LicenseSigner.php:36-61`), verified with a PEM public key compiled into the build (`LicenseFile.php:34-44,140-148`) | `storage/app/keys/license_private.pem` on the `/super` server only, `0600` in a `0700` directory (`MakeLicenseKeys.php:35-49`); `storage/` is on the updater's protected list so it is never packaged | Yes, but **disabled by default** (`SMARTPRS_OFFLINE_LIC=false`, `config/smartprs.php:52`) |
| B | SPRSX1 "offline License Code" | **HMAC-SHA256, symmetric**, truncated to 160 bits (`LicenseService.php:265-281`) | `SMARTPRS_LICENCE_SECRET` — **specified to be identical on the vendor server and on every client install** | **No** — unreachable dead code |
| C | Online activation key `SPRS-XXXX-…` | none; the vendor server is the authority (`UpdateServerController::activate()` `:45-100`) | n/a | Yes — the primary path |

**Mechanism A is correct and must be preserved as a pattern.** The analysis verifies that the
private key is not present in customer builds for this path.

**Mechanism B is the finding that governs this part.** The requirement is stated three times in
source, unambiguously:

```php
// config/smartprs.php:56-58
| Shared secret for OFFLINE self-contained License Codes (rev146). The
| Super Admin signs a key with it; the client verifies the signature
| locally — no server needed. It MUST be identical on the Super Admin and
| on every client install.

// config/smartprs.php:66-67
| set the SAME value on the licensing server and in each client's .env.

// app/Services/LicenseService.php:202  (inside the production exception text)
| identical on the licensing server and every client install
```

HMAC is a symmetric primitive: the key that verifies is the key that signs. A design that puts
the verifying key on every customer's disk necessarily puts the **signing** key there too. Any
customer who reads their own `.env` could mint unlimited, unexpiring, unlocked License Codes
for any edition and any company.

Two qualifications, both material and both from the analysis:

- **It is currently dead code.** `makeOfflineKey()` is called only from
  `OnpremClientController::offlineKey()`, which has **no route**; `activateOfflineKey()` has
  **no caller**; both are verified absent by exhaustive grep (`I-…` DEF-01). The exposure is
  latent, not live. That is the only reason SEC-02 is rated "CRITICAL as a design decision to
  eliminate" rather than as an active breach.
- **The literal secret value could not be verified.** The reference documents report a 64-hex
  value at `PKG:client-package-tools/env.client.template:16` and `PKG:.../ClientBuilder.bat:114`.
  **Neither file is in the supplied extract** (`I-…` DISC-01). What is confirmed is the
  *design* that requires the secret on every client, which is what SmartPRS 3 must never
  reproduce. Whether the value was in fact shipped, and to how many customers, is D-XXVII-04.

Additionally, in any non-production environment with the variable unset, `offlineSecret()`
`:204` returns the source-visible literal `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'`,
and the shipped `.env.example:2` sets `APP_ENV=local`.

> **The rule this produces.** SmartPRS 3 distributes **no shared signing secret to a customer
> build, under any circumstance, for any feature, in any edition, on any deployment model**.
> There is no symmetric licence path to revive, because there is no symmetric licence path.
> This is R-XXVII-002 and it is not subject to configuration.

### XXVII.1.2 What actually gates access is an unsigned JSON blob in a settings row

Neither A nor B is what the product checks at request time. `licenceStatus()`
(`ClientUpdateController.php:74-108`) reads `settings(tenant_id=0, key='licence')` — a JSON
blob written by `saveState()` `:45-57` — and trusts it. It requires only that `cert` and
`activated_at` are non-empty and that `cert.amc_expires_on >= today`.

The certificate carries an HMAC `sig`:

```php
// app/Services/LicenseService.php:410-422
$payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));
```

**Grep finds no consumer of `cert['sig']` anywhere** (`I-…` DEF-07). And even if there were
one, the HMAC key is the licence key, which the client already holds — encrypted in `key_enc`,
decryptable with the `APP_KEY` in its own `.env`. It could never prove anything against a party
on the client machine.

The consequence, quoted from the analysis:

```sql
UPDATE settings
   SET value = '{"cert":{"amc_expires_on":"2099-12-31"},"activated_at":"2026-01-01"}'
 WHERE tenant_id = 0 AND `key` = 'licence';
```

One statement grants a permanent licence (`I-…` SEC-01, CRITICAL). Every downstream control
reads through this blob, including the seat cap: `device_limit` lives in it
(`ClientUpdateController.php:498-504`; bypass B12).

Six deliberate fail-open catches compound it — `LicenseGate.php:73-75`, `AuthController.php:267-269`,
`EditionGuard.php:55-57`, `SubscriptionService::state()` `:75-77`, `seatUsage()` `:116-118`,
`recordDevice()` `:479-481` — each documented and intentional ("a licence check must never take
a client's HR system down"). The availability priority is right. Its invisibility is not: any
provoked exception grants an unlicensed, unmetered session and nothing counts it (`I-…` SEC-07).

### XXVII.1.3 Update packages are not signed

There is **no code signing on update packages**. Verification is exactly two things, and both
are conditional:

```php
// app/Services/UpdateClient.php:252-261
if (! empty($available['package_hash'])) { … hash_equals … }

// updater/updater.php:174
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) { … }
```

`$available['package_hash']` comes from `UpdateServerController::check()` `:287` — the **same
JSON response** that supplies `download_url`. It proves the bytes arrived intact from whoever
answered. It proves nothing about who answered. `$sha` in the updater comes from `job.json`,
which in an attacker-writes-storage scenario is the attacker's own input. The only unconditional
gate is a shape test: `app/` and `config/` must exist at the payload root
(`updater.php:218-223`).

| Threat | What it yields today |
|---|---|
| Compromised release server, or a compromised `/super` super-admin account | Arbitrary PHP pushed to every AMC-active client that presses "Check for updates". The package is extracted over the application root and `php artisan migrate --force` runs (`updater.php:285-311`). **Full remote code execution as the web user on every customer's HR and payroll server**, with maintenance mode and a backup, reported as a successful update |
| Network MITM | Mitigated only by TLS. Laravel's `Http` client verifies certificates and caps redirects (`UpdateClient.php:120,226`), but there is **no certificate pinning**, so a polluted trust store — corporate TLS interception, a rogue CA on a Windows box — is full exposure |
| Channel repointing | `SMARTPRS_UPDATE_URL` is a plain `.env` value. Anyone with file access on the client repoints the entire update channel at their own server, which the client then trusts completely |
| Downgrade / replay | The download token is a random cache key with no binding to a version the client asked for beyond the check response |

The blast radius is every client with a live AMC. This is `I-…` SEC-06, HIGH, and it is the
single most severe finding in the deployment layer.

### XXVII.1.4 The update flow has no database backup and no database rollback

What genuinely exists, and is well built — this part preserves all of it:

| Step | Evidence |
|---|---|
| Version detection from `version.json`, BOM-stripped, config fallback, channel preserved across the update | `UpdateClient.php:44-77`; `updater.php:246-250` |
| Server-side entitlement, AMC and version comparison | `UpdateServerController::check()` `:199-295` |
| Payload shape proof before anything is touched — unwrap a single-folder wrapper, require `app/`+`config/`, non-empty after stripping protected paths | `updater.php:196-241` |
| **Code backup** of every replaced top-level entry plus `version.json` | `updater.php:252-269` |
| Maintenance mode with `secret => null` so there is no bypass URL, and a direct `storage/framework/down` fallback if artisan is unbootable | `updater.php:271-283` |
| **Code rollback that removes each replaced entry before restoring**, with a comment explaining exactly why copy-over is wrong: *"Copying alone would leave behind every file the new version ADDED — including a stray new migration in database/migrations, which would then run on the next update and damage the database this rollback exists to protect."* | `updater.php:140-165,146-152` |
| A detached, framework-free updater process so the code being replaced is not the code doing the replacing | `UpdateClient::install()` `:280-329`, `spawn()` `:428-458` |
| A framework-free progress endpoint gated by a one-time `hash_equals` token | `updater/update-status.php` |
| Audit of every update action on both sides | `UpdateServerController::log()` `:360-370`; `logLocal()` `:866-878` |

What does not exist:

| Missing step | Evidence | Consequence |
|---|---|---|
| **Database backup** | No dump, no snapshot, no `backup:run` anywhere in the update path (grep across `updater.php`, `UpdateClient.php`, `ReleaseController.php`) | The only safety net is a nightly `spatie/laravel-backup` that the updater does not trigger and that a Windows on-prem install typically does not run at all, because no Windows scheduler artefact ships |
| **Database rollback** | No `migrate:rollback`, no snapshot restore. `rollback()` `:140-165` restores code only, and its own comment acknowledges the risk `:134-138` | A migration that half-applies and then fails leaves the schema ahead of the restored code, **and the application is brought back up in that state** |
| `queue:restart` | grep: absent from `updater.php` and `ReleaseController` | Long-running workers keep executing pre-update code until `--max-time=3600` expires |
| Cache rebuild | `optimize:clear` twice (`:306`, `:333`); never `config:cache`, `route:cache`, `view:cache` | A config-cached install permanently loses that optimisation after its first update |
| `composer install` | Absent. A release that adds a dependency must ship `vendor/`, and `vendor/` is **not** on the protected list (`updater.php:233`), so it is silently replaced by the build machine's copy | Dependency state is whatever the build machine had |
| A real health check | `artisan --version` only (`updater.php:316-320`) | Proves the CLI autoloader boots. Does not touch the database, does not exercise a route, does not confirm the web SAPI serves. An update that boots the CLI and 500s every HTTP request reports `done, 100%` |
| Backup retention | `updater.php:342-343` tells the operator the folder can be deleted; no code deletes it | Unbounded disk growth on the customer's payroll server |
| One version identity | `version.json` says `2026.6.2`; `config/smartprs.php:43` says `2026.6.1`; the client reports the former and logs the latter | A release built from the config value registers as `2026.6.1` while every client reports `2026.6.2`, so `check()` `:258` answers `up_to_date` and the release reaches nobody (`I-…` DEF-33) |

The platform-side twin, `applyPlatform()`, is materially worse: its rollback copies over instead
of removing first (`ReleaseController.php:249-254`; DEF-28), it overwrites `public/` and
`bootstrap/` without backing them up (`CODE_DIRS` `:21` versus the extraction loop `:224-241`;
DEF-29), and it does not unwrap a single-folder package, so a wrapped archive installs nothing,
stamps `applied_platform_at` and reports success (DEF-30).

### XXVII.1.5 Editions L1/L2/L3 are byte-identical

```php
// app/Services/Edition.php:93-97
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int { return 3; }

// app/Services/Edition.php:119-127
public static function hiddenNavIds(): array
{ return self::current() === 'saas' ? [] : self::SAAS_ONLY; }

// app/Services/Edition.php:169-174
public static function blockedPatterns(): array
{ return self::current() === 'saas' ? [] : self::BLOCK_SAAS; }

// app/Services/Edition.php:176-180
public static function label(): string { return 'SmartPRS'; }
```

`BLOCK_DNA` (`:149-155`), `BLOCK_L2` (`:158-167`), `DNA` (`:105`) and `L2_MODULES` (`:108-117`)
are declared and **returned by nothing** (verified by grep). Three price points, one product.

| Claim | Reality | Status |
|---|---|---|
| L1 = Core HR only | L1 serves every module | **NOT IMPLEMENTED** |
| L2 = core + nine advanced modules | Identical to L1 and L3 | **NOT IMPLEMENTED** |
| L3 = everything except the SaaS platform | True — but so are L1 and L2 | **NOT IMPLEMENTED as a differentiator** |
| Modules outside the licence are invisible and locked | No module is ever off | **NOT IMPLEMENTED** |
| The friendly 403 upsell | `platformPatterns()` and `blockedPatterns()` return the identical array and the `abort(404)` branch runs first (`EditionGuard.php:34-53`) — unreachable | **NOT IMPLEMENTED** |
| SaaS / Super-Admin surfaces hidden on-prem | `abort(404)`, so no trace is hinted | **IMPLEMENTED** |
| **Edition lock at activation** | Enforced on all three activation paths (`UpdateServerController.php:58-62`; `ClientUpdateController.php:324-327,441-444`) | **IMPLEMENTED** |
| Volume Hiring is L3-only | `cfg.editionLevel` is always 3 (`AppController.php:7566`) | **NOT IMPLEMENTED** |
| Interakt webhook is L3-only | `level() >= 3` is always true (`routes/web.php:200`) | **NOT IMPLEMENTED** |

The one enforced edition behaviour is the activation lock, and it is enforced against a
distinction that does not exist: a client sold L1 who is mistakenly issued an L2 `.lic` is
blocked from an application that would behave identically either way. The enforcement that
exists penalises the customer; the enforcement that would protect revenue does not exist.

Two adjacent causes are worth naming, because they are what SmartPRS 3's design must prevent:

1. **Two parallel lists.** Navigation hiding (`hiddenNavIds()`) and endpoint blocking
   (`blockedPatterns()`) are maintained separately. They silently converged into the same
   SaaS-only set, and nothing detected it. One declaration must drive both (R-XXVII-063,
   restating Part XXV R-XXV-095).
2. **The constants were kept "for reference / easy revert"** (`Edition.php:119-127` comment).
   Dead capability sets that look like a specification are worse than no specification: the
   sales desk, the marketing pages and three demo routes continued to describe them.

### XXVII.1.6 One channel for application and statutory content, and a lapsed AMC blocks corrections

There is exactly one delivery artefact — a zip of the application tree — one grant object, one
client flow and one install action. Grep finds no data-only, config-only, seed-only or
content-only channel, and no `releases.kind` column (`I-…` §10 Q4, NEW-UPD-01).

`StatutoryConfig`'s own docblock states the consequence:

> *"EDITABLE PT SLABS — the **16 state slabs and 10 PT-free states are compiled into the
> engine**. A state changing its slab, or a state that is not built in at all, needed a code
> change."* (`StatutoryConfig.php:31-35`)

So a Professional Tax slab change reaches customers in exactly two ways: an operator re-enters
it by hand as a per-tenant override on every install, or Ametecs ships a full application
release and waits for each customer's administrator to notice an email, sign in, press Check,
press Download, press Install and accept a maintenance window on their payroll server.

And on an install whose AMC has lapsed, `UpdateServerController::check()` `:238-246` refuses
everything — so **a customer who has not paid AMC cannot receive a statutory correction at
all**, though the statute applies to them regardless. That is D-XXVII-02 (§XXVII.5.4), and it
is the same decision as Part XVII's D-XVII-02, stated here from the distribution side.

### XXVII.1.7 The rest of the licensing surface, in one table

| Finding | Evidence | Answered by |
|---|---|---|
| A node-locked `.lic` on an internet-isolated machine is effectively un-revokable: `revocationSweep()` runs only on-prem, only from `AuthController::login()` `:206`, at most once per 24 h, and returns early when the vendor is unreachable. There is **no scheduled heartbeat anywhere** | `LicenseService.php:171-215`; `routes/console.php` has 12 scheduled tasks, none licence-related (`I-…` DEF-08) | §XXVII.2.7 |
| The node-lock is defeated by copying one cache file: `storage/app/.machine_fp` is trusted whenever it holds 40 hex characters, **before any hardware is read** | `LicenseFile.php:72-78` (`I-…` SEC-09) | §XXVII.2.5 |
| `grace_days` is signed into every `.lic` and never read; on-prem expiry is a hard cliff at midnight | `LicenseSigner.php:52`; `licenceStatus()` `:102-107` (`I-…` BR-LIC-023, DISC-18) | §XXVII.2.8 |
| The server-seat cap is computed correctly and then discarded: `activate()` `:92` and `heartbeat()` `:145` ignore `recordDevice()`'s refusal, and any exception inside it returns `['ok' => true, 'reason' => 'store_error']` | `LicenseService.php:449-482`; `UpdateServerController.php:92,145` (`I-…` DEF-06) | §XXVII.2.10 |
| `reactivations_used` is incremented and never read; the documented "1 machine move per year" cap does not exist | `LicenseService.php:72`; `shiftMachine()` `:484`; `deactivate()` `:724` (`I-…` DEF-05) | §XXVII.2.5, D-XXVII-07 |
| Session flags grant a full licence-gate bypass on an on-prem install, with no check that the session belongs to a demo tenant or that this is the SaaS server | `LicenseGate.php:36-39` (`I-…` SEC-08) | §XXVII.2.8 |
| `app/first-password` is missing from the `LicenseGate` allow-list, so a freshly provisioned admin on an unactivated box is signed out instead of reaching the mandatory create-password screen | `LicenseGate.php:25` (`I-…` BR-LIC-022) | §XXVII.2.8 |
| A CLI-minted `.lic` has no server-side row, because `IssueLicense.php:44-54` omits `recordOffline()`; under the default configuration it cannot be activated at all | (`I-…` DEF-39) | §XXVII.2.4 |
| The `.lic` public key is a PHP constant; rotation invalidates every issued licence and requires rebuilding every edition package | `LicenseFile.php:34-44`; `MakeLicenseKeys.php:31` (`I-…` SEC-15) | §XXVII.2.3 |
| The private signing key is **excluded from every backup** (`config/backup.php:34` excludes `storage/app`) and is overwritable by `--force` with only a console warning | `LicenseSigner.php:21`; `MakeLicenseKeys.php:29-33` (`I-…` SEC-16 — low likelihood, catastrophic impact) | §XXVII.2.3, §XXVII.6.3 |
| The default on-prem licence is unlimited-seat: `seats_licensed = 100000` at provisioning, and the `.lic` `seats` field defaults to `0` ⇒ `device_limit = null` | `ClientProvision.php:72`; `InstallController.php:198`; `OnpremClientController.php:341` (`I-…` DISC-12) | D-XXVII-07 |
| The activation screen tells the customer that `.lic` activation is *"fully offline — no internet needed"* while `offline_lic=false` routes it through a 20-second HTTP call to the vendor | `licence-activate.blade.php:64`; `config/smartprs.php:52` (`I-…` DISC-13) | §XXVII.2.6 |
| The beta channel is a client-side illusion: the client sends `channel`, the server reads it into a variable and never uses it; there is no `releases.channel` column | `UpdateClient.php:127`; `UpdateServerController.php:203` (`I-…` DEF-10) | §XXVII.6.4 |
| Release upload cannot work through the shipped web server: the controller validates 500 MB while the shipped nginx sets `client_max_body_size 20M`, and documents an SFTP workaround at length | `ReleaseController.php:46-60` (`I-…` DEF-35) | §XXVII.6.1 |
| There is no test for any licensing path, the update flow, the editions or any middleware | `tests/Feature/BillingTest.php` is the only test in the area (`I-…` §6.6) | §XXVII.14 |

---

## XXVII.2 The SmartPRS 3 licence model

### XXVII.2.0 Four absolute rules

These are stated before the design because the design is only sound if they hold without
exception. Each is a direct reversal of a confirmed SmartPRS 2 property.

- **R-XXVII-001** Every artefact a customer installation evaluates for a trust decision — the
  licence, the locally held licence state, the application update package and its manifest, the
  statutory rule pack, a configuration template and a key-rotation announcement — MUST carry a
  **detached asymmetric signature** verified against a public key compiled into the build.
  *Test: a static-analysis rule fails the build if any trust decision reads an artefact for
  which no verification call precedes it; each artefact type has a negative test asserting that
  a single-byte mutation is refused.*
- **R-XXVII-002** **No signing key, shared secret, symmetric MAC key, or any other key capable
  of producing a signature the product will accept, may be present in a customer build, a
  customer `.env`, a customer database, an installer, a build script, a configuration default
  or a support tool — under any circumstance, in any edition, on any deployment model, for any
  customer, including air-gapped installs.** There is no symmetric licence path, therefore
  there is none to revive. *Test: CI greps the entire release artefact and every installer
  script for private-key PEM headers, `hash_hmac` in any licensing, update or rule-pack path,
  and any configuration key whose documentation contains the word "identical"; the build fails
  on a hit. Direct reversal of `config/smartprs.php:56-58` and `LicenseService.php:191-205,
  265-281` (`I-…` SEC-02).*
- **R-XXVII-003** **No configuration value, environment variable, permission, role, licence
  state, support mode, command-line flag or database row may disable, weaken, bypass or make
  conditional any signature verification.** There is no `--force`. *Test: fault injection
  attempts each of the enumerated mechanisms against each artefact type; all are refused.
  Direct reversal of `UpdateClient.php:254` and `updater.php:174`, where verification is
  conditional on the server having chosen to supply a hash (`I-…` DEF-09).*
- **R-XXVII-004** The licence gate MUST distinguish a **deterministic** failure (signature
  mismatch, malformed artefact, expired validity, unknown key id, missing crypto extension)
  from a **transient** one (database unreachable, clock source unavailable, disk read error).
  Deterministic failures fail **closed**. Transient failures fail **open for a bounded,
  configured window**, are counted, are surfaced on the operations console, and raise an alert;
  when the window elapses the state degrades as if deterministic. *Test: a corrupt licence
  blocks immediately; a simulated database timeout does not block but increments a visible
  counter and opens an alert; a timeout persisting past the window degrades. Direct answer to
  the six unconditional fail-open catches at `I-…` SEC-07, whose availability bias is correct
  and whose invisibility is not.*

### XXVII.2.1 What a licence asserts — the claim set

A SmartPRS 3 licence is a signed document making a closed set of claims. Nothing outside this
set is a licence claim; nothing inside it may be asserted anywhere else.

| Claim | Type | Purpose | SmartPRS 2 equivalent |
|---|---|---|---|
| `licence_id` | UUIDv7 | Identity. Appears in every audit entry, every proof-of-life report and every support conversation | `licences.id`, internal only |
| `key_id` | string | Which signing key produced this document (§XXVII.2.3) | none — the public key is a compiled constant with no identity (`LicenseFile.php:34-44`) |
| `issued_at`, `issue_seq` | DateTime, integer | Monotonic per `licence_id`. A licence document with a lower `issue_seq` than the one already held is refused — this is the anti-rollback control | none |
| `customer` | `{customer_id, legal_name, contact_ref}` | Who it was issued to. Rendered in the product so the operator can see it | `onprem_clients` |
| `tenant_binding` | `all` \| list of tenant UUIDs | Which tenants this licence covers. On a single-tenant on-prem install, one. On a private-cloud multi-tenant install, several | none — the on-prem install has one tenant by convention |
| `entitlement_set_ref` | `{set_key, version}` | **The only statement of what the customer may use** (§XXVII.3) | `edition` string, enforced against a distinction that does not exist |
| `seat_entitlement` | `{basis, limit, enforcement, overage_policy}` | How many people may be carried, on which counting basis (Part XXV §XXV.8.1's seat policy), and what happens at the boundary | `device_limit` inside the `.lic`, defaulting to null ⇒ unlimited (`I-…` DISC-12) |
| `module_entitlements` | list of `{module_key, granted, config_ref}` | Present only if D-XXVII-01 selects an entitlement-bearing model. Resolved through the same grammar as SaaS entitlements | `DNA` / `L2_MODULES` constants, returned by nothing |
| `validity` | `{valid_from, valid_to, perpetual: bool}` | Right to run. A perpetual licence has `valid_to = null` and never expires | `licences.amc_expires_on` conflated with the right to run |
| `support_window` | `{amc_from, amc_to}` | Right to updates and support. **Separate from `validity`** — this separation is the whole point | conflated: `amc_expires_on` gates both running and updating |
| `deployment_binding` | `on_prem` \| `private_cloud` \| `saas` | Which deployment model this licence authorises. A licence issued for on-premise does not license a hosted instance | the two-variable `SMARTPRS_EDITION` / `SMARTPRS_DEPLOYMENT` split, which produces an unbillable, unenforceable combination (`I-…` DEF-36) |
| `instance_binding` | `{mode, max_instances, binding_policy}` | `unbound` \| `bound` \| `bound_pool`. See §XXVII.2.5 | `fingerprint` on the licence row, `server_limit` with no UI |
| `environment_grants` | list of `{purpose, max}` | Non-production instances the licence permits — `production`, `dr_standby`, `test`, `training`. A DR standby is not a second sale | none; a customer standing up a DR box is technically in breach |
| `grace` | `{grace_days, offline_tolerance_days, read_only_after_days}` | The degradation schedule (§XXVII.2.8) | `grace_days` is signed into every `.lic` and **never read** (`I-…` DISC-18) |
| `proof_of_life` | `{required: bool, interval_days, endpoint_ref}` | Whether and how often this install reports (§XXVII.2.7) | none — no scheduled heartbeat exists |
| `jurisdiction_scope` | list | Which statutory jurisdictions the customer is licensed for, feeding the rule-pack channel's entitlement | none |
| `notes_to_customer` | text | Rendered verbatim in the licence screen. Special terms are visible to the customer, not only to sales | none |

- **R-XXVII-010** The claim set is closed and versioned. A licence document declares a
  `claims_version`; an installation that does not understand a `claims_version` **refuses the
  licence** rather than ignoring the claims it does not recognise. *Test: a licence with an
  unknown claims version is refused with a named reason and an upgrade instruction.*
- **R-XXVII-011** `validity` (the right to run) and `support_window` (the right to updates and
  support) MUST be independent claims, and no code path may derive one from the other. *Test:
  a perpetual licence with a lapsed support window runs indefinitely and receives no feature
  update. Direct fix for SmartPRS 2, where `amc_expires_on` gates both, so a customer who has
  paid for a perpetual licence and declined AMC is locked out of software they own.*
- **R-XXVII-012** Every claim MUST be rendered to the customer, verbatim and without
  interpretation, on a licence screen that is reachable without a licence — because an
  administrator whose licence is failing must be able to see why. *Test: with an expired
  licence, the licence screen renders the full claim set and the failure reason.*
- **R-XXVII-013** No licence claim may be stated anywhere except in the signed document. There
  is no settings row, environment variable or configuration table that supplements, overrides
  or defaults a claim. *Test: grep finds no configuration key whose name matches a claim key;
  removing the licence removes every claim, not some of them.*

### XXVII.2.2 The artefact and the signature scheme

```
smartprs-licence-<customer>-<licence_id>.slic          (a text file, safe to email)

  <base64url(JCS-canonical claims JSON)> "." <base64url(Ed25519 detached signature)>
```

| Element | Specification | Why |
|---|---|---|
| Algorithm | **Ed25519** detached signature over `SHA-256(JCS-canonical(claims))` | Aligns with the rule-pack scheme (Part XVII §XVII.4.6): small, fast, no parameter choices to get wrong, available in PHP through libsodium, a core extension. SmartPRS 2's RSA-2048 is not wrong; one algorithm across all three artefact classes is simpler to operate and to rotate |
| Canonicalisation | **RFC 8785 (JCS)** before hashing | A signature over a pretty-printed document is a signature over a formatting choice |
| Key material | **Separate key pair per purpose**: licence signing, release signing, rule-pack signing, key-announcement signing. A compromise of one does not permit forging another | Stated as a requirement in Part XVII §XVII.4.6 for rule packs; generalised here |
| Trust anchor | The **root verification key set is compiled into the build and pinned**. Not fetched, not configurable, not overridable by an environment variable, not present in any tenant setting or database row | Otherwise the scheme is theatre: an attacker who can set a config value signs their own licence. SmartPRS 2 gets this right for `.lic` (`LicenseFile.php:34-44`) and this part preserves it |
| Transport | Any. Email, portal download, USB stick, a paste into a text box | The signature is the trust boundary, not the transport |

- **R-XXVII-014** The licence artefact MUST be a self-contained text file that survives being
  emailed, pasted and re-typed, and MUST carry a checksum line so a corrupted paste is
  distinguished from an invalid signature. *Test: a licence with a mangled line break reports
  "the file was damaged in transfer", not "invalid signature" — because those two messages send
  a support ticket to two different places.*
- **R-XXVII-015** Signature verification MUST occur at **import**, at **activation**, on
  **every application boot**, and on a **scheduled re-verification** whose interval is
  configuration. The verified claim set is held in memory for the process lifetime and is
  re-read from the verified artefact, never from a mutable cache. *Test: mutating the stored
  artefact between boots blocks on the next boot; mutating any derived row has no effect on the
  decision.*

### XXVII.2.3 Key custody, key identity, rotation and revocation

SmartPRS 2's key model has two defects that are cheap to fix and expensive to leave: the public
key has **no identity**, so rotation invalidates every issued licence and requires rebuilding
every package (`I-…` SEC-15); and the private key is **excluded from every backup** and
overwritable with `--force` and a console warning (`I-…` SEC-16 — low likelihood, catastrophic
impact: losing it means no licence can ever be issued or renewed again for any existing
customer).

| Control | Specification |
|---|---|
| Custody | Signing keys live in an **HSM or a cloud KMS**. The signing service holds no exportable private key material. No human can carry a key out; no laptop, build agent or `storage/app/keys/` directory holds one |
| Authorisation | Licence signing requires an authenticated operator plus a second approver for any licence outside the standard catalogue (unlimited seats, perpetual with no support window, extended grace). Every signing operation writes to the audit ledger (spine S14) |
| Key identity | Every signature carries `key_id`. Installations hold a **key set** with per-key validity windows, not a single key |
| Rotation | A new key is announced through a **signed key-announcement artefact**, signed by the **outgoing** key, distributed both in an application release and on the rule-pack channel, with an overlap window of at least two release cycles. Rotation is announced before it is used |
| Revocation | A revoked `key_id` is distributed as a signed revocation list. Artefacts signed by a revoked key stop verifying **for future import and activation**; artefacts already accepted remain readable and explainable, marked `signing_key_revoked` |
| Backup and escrow | Key material is backed up by the KMS's own mechanism, in a separate custody domain from the application backup, with a documented, drilled recovery procedure and a named quorum |
| Compromise runbook | A published procedure: revoke, rotate, re-announce, re-issue affected artefacts, notify every customer, and record the window during which a forged artefact could have been accepted |

- **R-XXVII-016** Every signature MUST carry a `key_id`, and every installation MUST hold a
  **key set** with validity windows, so that key rotation does not invalidate a single already
  issued licence, package or pack. *Test: rotate the signing key; every previously issued
  artefact continues to verify until its key's stated retirement date. Direct fix for
  `LicenseFile.php:34-44` + `MakeLicenseKeys.php:31`, where rotation invalidates every `.lic`
  and requires rebuilding every edition package.*
- **R-XXVII-017** Private signing key material MUST NOT exist as a file on any general-purpose
  server, MUST NOT be reachable by application code, and MUST NOT be created, overwritten or
  destroyed by a product command. *Test: no command in the shipped artefact can generate,
  export or overwrite a signing key — the direct reversal of `smartprs:make-license-keys
  --force` (`MakeLicenseKeys.php:29-33`).*
- **R-XXVII-018** Key rotation MUST be announced through a signed artefact before it is used,
  with an overlap of at least two release cycles, so an installation that has not updated in a
  year is not locked out. *Test: an installation on an N−2 version accepts an artefact signed by
  the new key after applying only the key announcement.*
- **R-XXVII-019** A key-compromise runbook MUST exist, be rehearsed at the cadence set in
  §XXVII.6, and name the quorum required to execute it. *Test: the rehearsal is logged with a
  date and the participants; the date is visible on the Ametecs operations dashboard.*

### XXVII.2.4 Online activation

```
 REQUEST ─► ISSUE ─► DELIVER ─► IMPORT ─► VERIFY ─► BIND ─► ACTIVE
```

| Step | What happens | Failure behaviour |
|---|---|---|
| **Request** | The installation posts `{licence_key, instance_claim, installation_id, product_version, deployment_model}` to the Ametecs licence service over TLS with the vendor identity pinned (§XXVII.4.3). `instance_claim` is the instance identity of §XXVII.2.5 | Network failure is retried with backoff; the operator sees the attempt count and the last error verbatim |
| **Issue** | The licence service validates the key, the commercial state (paid, or an explicitly ticked and audited partial), the deployment model and the seat/instance headroom, then signs a licence document | A refusal returns a **neutral, non-enumerating** message plus a support reference, and is audited on the vendor side with the real reason |
| **Deliver** | The signed document is returned in the response body and also filed against the customer record for re-download | — |
| **Import** | The document is written to the installation's licence store | — |
| **Verify** | Signature, `key_id` validity, `claims_version`, `issue_seq` monotonicity, deployment binding, product-version compatibility, instance binding | **Refuse.** Nothing is written beyond an audited rejection record |
| **Bind** | The instance binding is recorded on both sides (§XXVII.2.5) | A binding refusal (no headroom) is **enforced**, not advisory |
| **Active** | The gate opens; the licence screen renders the claim set | — |

- **R-XXVII-020** There MUST be **one** activation implementation, called by the installer, the
  licence screen, the CLI and the vendor console. *Test: a licence minted by the CLI activates
  identically to one minted by the console — the direct fix for `IssueLicense.php:44-54`, whose
  CLI twin omits `recordOffline()` so a CLI-minted `.lic` cannot be activated at all under the
  default configuration (`I-…` DEF-39).*
- **R-XXVII-021** Activation refusals MUST be neutral to the caller and specific in the vendor
  audit. *Test: an unknown key and a revoked key produce the identical customer-facing message
  and distinguishable vendor audit entries.*
- **R-XXVII-022** Every activation, re-activation, binding, unbinding, renewal, re-issue and
  revocation MUST write to the audit ledger on **both** sides, with the actor, the instance and
  the reason. *Test: a full activation lifecycle is reconstructable from either side alone.*
- **R-XXVII-023** Activation MUST be possible **before** the first administrator password is
  set, and the licence gate's allow-list MUST include every route the first-run sequence needs.
  *Test: a freshly provisioned installation completes activation and first-password without
  being signed out — the direct fix for `LicenseGate.php:25`, where `app/first-password` is
  absent from the allow-list (`I-…` BR-LIC-022).*

### XXVII.2.5 Instance binding, and what happens on legitimate hardware change

**The problem with SmartPRS 2's binding.** The fingerprint is
`substr(sha256('SMARTPRS|' + hardware UUID), 0, 40)` (`LicenseFile.php:59-83`), cached to
`storage/app/.machine_fp`, and that cache is **trusted whenever it contains 40 hex characters,
before any hardware is read** (`:72-78`). Copying `license.lic` plus one 40-byte file to a
second machine makes both verify (`I-…` SEC-09). The cache exists for a real reason — a
per-request `wmic` call was slow and intermittently bounced valid administrators to `/login`
(`:62-67`) — so any fix must keep the caching and remove the trust.

**The SmartPRS 3 instance identity.**

```
instance_identity := Ed25519 keypair generated on the installation at install time
                     private key held in the OS keystore where available, else a
                     0600 file outside the web root and outside every backup set
                     that leaves the machine unencrypted

instance_claim    := { installation_id, public_key, host_facts_digest, generated_at }
                     signed by the instance private key
```

- The binding is to a **key the installation proves possession of**, not to a string it merely
  presents. Copying files to a second machine copies the key too, so this is not tamper-proof
  — but it makes the copy **detectable**, because two instances then present the same
  `installation_id` with different `host_facts_digest` values and overlapping proof-of-life
  reports, which is an incident, not a silent success (§XXVII.2.11).
- `host_facts_digest` is a digest over an ordered set of weighted host facts (machine UUID,
  primary MAC, CPU signature, hostname, database server UUID). **Drift is expected and
  tolerated:** a change below a configured weight threshold is recorded and accepted; a change
  above it triggers a re-binding challenge rather than a lock-out.
- **The cache stays, and is bound.** The cached digest is stored encrypted under a key derived
  from the instance private key, so a copied cache file is meaningless on another machine. A
  cache that fails to decrypt is discarded and re-derived, never trusted.

| Event | Behaviour |
|---|---|
| Host facts drift below threshold (a NIC replaced, a hostname changed) | Accepted, recorded, reported at the next proof-of-life. No customer action |
| Host facts drift above threshold (restore onto new hardware, VM migration, cloud instance re-provision) | The installation enters `rebinding`. It **continues to run** for the configured `rebind_grace_days` while it requests a re-binding, online or offline. The operator sees a banner naming the deadline |
| Rebinding refused (no instance headroom) | The operator is told which instances hold the headroom, with their last-seen dates, and is offered self-service release of a stale instance |
| Genuine second instance (DR standby, test copy of production) | Covered by an `environment_grants` entry, not by consuming a production seat. A DR standby that has never served a request reports `standby` and does not consume a production instance |
| Disaster restore onto replacement hardware | The **restore path** (Part XXVI §XXVI.8.6) explicitly includes re-binding, and a restore never leaves the customer unable to run payroll because the hardware changed |

- **R-XXVII-024** Instance binding MUST be to a key the installation proves possession of, and
  the cached host digest MUST be cryptographically bound to that key so that copying it to
  another machine is useless. *Test: copying the licence store and every cache file to a second
  machine does not produce a second working instance — the direct fix for
  `LicenseFile.php:72-78` (`I-…` SEC-09).*
- **R-XXVII-025** Legitimate hardware change MUST NOT produce a lock-out. A binding mismatch
  MUST open a bounded re-binding window during which the product runs normally, with a visible
  countdown and a self-service path. *Test: replacing the host machine and restoring from
  backup leaves the product running for the full window, and re-binding completes without
  contacting support.*
- **R-XXVII-026** Instance headroom decisions MUST be **enforced**, and a storage error while
  making one MUST fail closed for the binding decision while remaining fail-open for the
  running session. *Test: a database error during binding refuses the new binding and does not
  sign anyone out — the direct fix for `LicenseService::recordDevice()` `:479-481`, which
  returns `['ok' => true, 'reason' => 'store_error']`, and for `UpdateServerController.php:92,145`,
  which discard the refusal entirely (`I-…` DEF-06).*
- **R-XXVII-027** Self-service instance release MUST exist, be rate-limited by a configured
  allowance, be audited, and MUST tell the customer their remaining allowance **before** they
  use it. *Test: the allowance is enforced and displayed — the direct fix for
  `reactivations_used`, incremented and never read, against a documented "1/year" cap that does
  not exist (`I-…` DEF-05). The allowance value is D-XXVII-07.*
- **R-XXVII-028** A DR standby, a test copy and a training copy MUST be expressible as
  `environment_grants` and MUST NOT consume a production instance or a production seat. *Test:
  a standby restored from a production backup activates against its grant and reports
  `standby`.*

### XXVII.2.6 Offline activation for air-gapped installs

Part XXVI §XXVI.10 declares air-gapped operation a supported mode. This is its licensing half.
It must be a **first-class, documented path** — not, as in SmartPRS 2, a capability that exists,
is advertised on the activation screen as *"fully offline — no internet needed"*, and is
**disabled by default** so that a `.lic` is routed through a 20-second HTTP call to the vendor
and fails without internet (`config/smartprs.php:52`; `licence-activate.blade.php:64`;
`I-…` DISC-13).

```
  ON THE INSTALLATION                       AT AMETECS
  ───────────────────                       ──────────
  licence screen / CLI
    └─ produce ACTIVATION REQUEST  ────────► portal upload or support channel
       (a short signed text block:              │
        installation_id, instance public key,   ├─ verify the request signature
        host_facts_digest, product version,     ├─ check commercial state + headroom
        deployment model, requested claims)     ├─ sign the licence document
                                                └─ return the .slic file
  import the .slic  ◄─────────────────────── transferred by any means
    ├─ verify signature against the embedded key set   (no network)
    ├─ verify the licence's instance binding matches this instance
    ├─ verify issue_seq > the highest previously seen
    └─ activate
```

- **R-XXVII-029** Offline activation MUST be enabled by default, MUST be the identical code
  path after the transport step, and MUST NOT require any network call. *Test: an installation
  with all egress blocked completes activation from a file and the audit entries are identical
  to the online path's — the direct reversal of `SMARTPRS_OFFLINE_LIC=false`.*
- **R-XXVII-030** The activation request MUST be a short, signed, human-transferable text block
  that carries no customer business data — only the installation identity, the instance public
  key, the host digest, the product version and the requested claims. *Test: the request is
  inspectable in a text editor and contains no employee, payroll or tenant data. This is what
  makes the request safe to email from a restricted network.*
- **R-XXVII-031** An air-gapped installation MUST be able to produce a **signed status export**
  — licence state, seat usage, instance bindings, version and health summary — so that Ametecs
  can reconcile entitlement and populate the version register without egress. *Test: the export
  verifies against the instance key and is accepted by the register (§XXVII.6.5). Referenced by
  Part XXVI R-XXVI-185.*
- **R-XXVII-032** The customer-facing text on every activation surface MUST state what the path
  actually does. *Test: a documentation review asserts that no screen claims a capability the
  shipped default configuration disables — the direct fix for DISC-13/DISC-14, where two
  customer-facing texts describe an offline path that is either disabled or unreachable dead
  code.*

### XXVII.2.7 Proof-of-life, offline tolerance and revocation

SmartPRS 2's revocation is defeated by one firewall rule. `revoke()` sets a status on the vendor
server; the client learns of it only through `revocationSweep()`, which runs on-prem only, only
from `AuthController::login()` `:206`, at most once per 24 hours, and returns early when the
vendor is unreachable. There is **no scheduled heartbeat anywhere** — `routes/console.php` has
twelve scheduled tasks and none is licence-related. A non-paying customer who blocks one
outbound host keeps running until `expires_at` (`I-…` DEF-08, HIGH).

**The SmartPRS 3 model.**

```
proof_of_life  := a scheduled job, first-class, visible on the operations console
   payload      installation_id, licence_id, issue_seq, product version,
                seat usage, instance count, rule-pack versions held,
                health summary — signed by the instance key
   response     the vendor's signed acknowledgement carrying:
                  { server_time, licence_status, revocation_list_digest,
                    latest_issue_seq, notices[] }
```

| Property | Rule |
|---|---|
| Cadence | `proof_of_life.interval_days`, a licence claim, defaulting to a platform value. Configuration, never a literal |
| Offline tolerance | `grace.offline_tolerance_days`, a licence claim. Until it elapses, a failure to report is **invisible to the user** and visible to the operator on the console. An air-gapped licence declares a tolerance of `null` and never reports |
| After tolerance | The installation degrades on the schedule of §XXVII.2.8 — **it never hard-stops at a boundary the customer was not warned about** |
| Revocation | Delivered in the acknowledgement as a signed revocation-list digest. Fetching the list is a separate signed artefact. A revoked licence degrades to read-only on the schedule, with the reason displayed |
| Clock | Expiry, grace and tolerance are evaluated against `max(local_time, last_trusted_server_time)`, where `last_trusted_server_time` comes from a signed acknowledgement. Moving the local clock backwards cannot extend anything |
| Data minimisation | The payload carries counts and versions, never names, never employee data, never payroll figures |

- **R-XXVII-033** Proof-of-life MUST be a scheduled job, not a side effect of a human logging
  in, and its last success, last failure and next due time MUST appear on the operations
  console (Part XXVI §XXVI.7). *Test: an installation nobody signs into still reports on
  schedule — the direct fix for `revocationSweep()`'s single caller at
  `AuthController.php:206` (`I-…` DEF-08, DISC-06).*
- **R-XXVII-034** Revocation MUST take effect within the declared offline tolerance on **any**
  installation, including one that firewalls the vendor, by expiry of the licence's own trusted
  time rather than by a message the installation can refuse to receive. *Test: revoke a licence
  on an installation with egress blocked; it degrades within the tolerance.*
- **R-XXVII-035** Licence time arithmetic MUST NOT rely on local system time alone. *Test:
  setting the system clock back one year does not extend a licence, a grace period or an
  offline tolerance.*
- **R-XXVII-036** The proof-of-life payload MUST be enumerated in the published documentation,
  field by field, and MUST carry no customer business data. *Test: a traffic capture matches
  the published payload exactly. This is what makes the mechanism acceptable to a customer's
  security review, and Part XXVI R-XXVI-181 depends on it.*
- **R-XXVII-037** A failure to reach the vendor MUST NOT degrade any function before the
  declared tolerance elapses, MUST NOT produce a user-visible error, and MUST NOT be retried at
  a rate that fills a log. *Test: a week of blocked egress on a licence with a 30-day tolerance
  produces no user-visible symptom and one operations-console indicator.*

> **PRODUCT OWNER DECISION REQUIRED — D-XXVII-05: the offline tolerance for a licensed
> installation, and the cadence of proof-of-life.**
> *Recommendation: proof-of-life every 7 days; offline tolerance 45 days for a standard
> licence; `null` (never required) for a licence explicitly issued as air-gapped, which is
> instead reconciled by the signed status export of R-XXVII-031 at each support interaction.*
> The trade-off is direct: a short tolerance makes revocation effective and makes a restrictive
> customer network a support burden; a long tolerance is invisible to honest customers and
> gives a determined one a long window. Both numbers are licence claims, so a specific customer
> can be issued different values without a code change. Referenced by Part XXVI §XXVI.10.1.

### XXVII.2.8 The licence gate — fails closed, degrades to read-only

**This is a deliberate commercial and ethical position, and it is stated as such.**

A customer's payroll data is theirs. Their statutory filings are theirs. Payslips already issued
to their employees are those employees' evidence of pay. A commercial dispute between Ametecs
and a customer — a lapsed licence, an unpaid renewal, a revocation, a hardware change that could
not be re-bound in time — must never make a customer's own records unreachable, and must never
prevent them from producing a payslip an employee has already been paid against or a return a
statutory authority has already been filed with.

Ametecs is also entitled to be paid. The reconciliation is that the gate **fails closed against
new work and open against existing records.**

| State | Entry condition | What works | What does not |
|---|---|---|---|
| `active` | Verified licence, within `validity`, within tolerance | Everything the entitlement set grants | — |
| `expiring` | Within `notice_lead_days` of `valid_to` | Everything | — (a countdown banner appears for privileged roles, and only for them) |
| `grace` | Past `valid_to`, within `grace_days` | Everything | — (the banner escalates and is shown to all administrators) |
| `restricted` | Past grace, within `read_only_after_days` | Everything except starting a **new** payroll run or finalising one | Starting/finalising a payroll run |
| `read_only` | Past `read_only_after_days`; or revoked; or offline tolerance elapsed; or a deterministic verification failure | **Sign in. View. Search. Report. Export. Download any payslip, challan, return or document already produced. Full data export (Part XXV §XXV.11.2). Backup and restore. Attendance punch ingestion into the raw layer. Licence activation, renewal and re-binding. Support diagnostics** | Every write to business data: hire, edit, approve, run payroll, apply leave, publish configuration, import, integrate inbound beyond raw punches, ESS/MSS transactions |
| `unlicensed` | No licence has ever been imported, and no evaluation grant | The installer, the licence screen and the activation path only | Everything else |

Two properties of `read_only` that are load-bearing:

1. **Raw attendance ingestion continues.** Punches are append-only evidence (spine S10 layer 1).
   Refusing them destroys evidence the customer cannot recreate, for a commercial reason. They
   are accepted and stored; **derivation and payroll are what stop**. A customer who settles
   their account gets their attendance back, not a hole.
2. **Export is never withheld.** A customer in `read_only` can extract the whole of their data
   in the documented format. Withholding a customer's own data as leverage is not a control
   this product implements.

- **R-XXVII-038** The licence gate MUST fail closed on a deterministic verification failure and
  MUST degrade to `read_only` rather than to no access. A customer whose licence has lapsed
  MUST retain sign-in, read, report, export and download of everything already produced.
  *Test: with a revoked licence, an administrator signs in, opens a finalised payroll run,
  downloads a payslip and a challan, and exports the full dataset; every write is refused with
  a licence reason.*
- **R-XXVII-039** Payroll already run MUST remain readable, explainable (spine S15) and
  exportable in every degraded state. *Test: "How was this calculated?" renders in
  `read_only`.*
- **R-XXVII-040** Raw punch ingestion MUST continue in every degraded state. *Test: punches
  received during `read_only` are present and derivable after re-licensing, with no gap.*
- **R-XXVII-041** Degradation MUST be announced before it happens: at `notice_lead_days`, at
  entry to grace, at entry to `restricted`, and at entry to `read_only`, each through the
  notification engine and each visible in-product, naming the date of the next transition.
  *Test: no state transition occurs that was not announced at least once — the direct fix for
  SmartPRS 2's hard cliff at midnight (`I-…` BR-LIC-023).*
- **R-XXVII-042** `grace_days`, `read_only_after_days`, `notice_lead_days` and
  `offline_tolerance_days` MUST be licence claims that are read and honoured. *Test: a licence
  issued with a 30-day grace grants 30 days — the direct fix for `grace_days`, signed into every
  `.lic` and never read (`LicenseSigner.php:52`; `I-…` DISC-18).*
- **R-XXVII-043** No session flag, request header, cookie, query parameter or demo state may
  bypass the licence gate. *Test: the `edition_demo` / `demo_team` bypass shape
  (`LicenseGate.php:36-39`; `I-…` SEC-08) is asserted absent by a CI check, and a fuzz sweep
  over session keys grants nothing.*
- **R-XXVII-044** The gate's allow-list MUST be derived from a declared set of "reachable
  without a licence" route attributes, not from a hand-maintained path list. *Test: adding a
  first-run route without the attribute makes it unreachable, and adding it with the attribute
  makes it reachable, with no edit to any list — the structural fix for the missing
  `app/first-password` entry.*

### XXVII.2.9 Renewal, re-issue and entitlement change

Every commercial change is **a new signed licence document with a higher `issue_seq`**. Nothing
is edited in place; there is no partial update to a licence; there is no field a support agent
can change on a running installation.

| Change | Mechanism | Customer experience |
|---|---|---|
| AMC renewal | New document, `support_window.amc_to` extended, `issue_seq + 1` | Automatic at the next proof-of-life if online; a file to import if not |
| Seat increase | New document, `seat_entitlement.limit` raised | Same |
| Module or edition change | New document, `entitlement_set_ref` changed | Same, plus an entitlement-change notice naming exactly what became available or unavailable |
| Perpetual conversion | New document, `validity.perpetual = true` | Same |
| Instance count change | New document, `instance_binding.max_instances` raised | Same |
| Downgrade or non-renewal | New document with reduced claims, **or** the existing document simply expires | Announced per R-XXVII-041 |

- **R-XXVII-045** A licence document MUST be immutable. A change is a new document with a
  higher `issue_seq`, and the superseded document is retained for audit. *Test: no code path
  writes into a stored licence document.*
- **R-XXVII-046** An installation MUST refuse a licence document whose `issue_seq` is not
  greater than the highest it has ever seen, even if that document is otherwise valid and
  correctly signed. *Test: replaying yesterday's licence after today's renewal is refused —
  this is the anti-rollback control that makes a database-level restore ineffective as a
  licensing attack (§XXVII.2.11).*
- **R-XXVII-047** An entitlement reduction MUST NOT delete or hide the customer's data. A
  module that becomes unentitled becomes unreachable, and its data remains present, exportable
  and restorable if the entitlement returns. *Test: removing a module entitlement and restoring
  it recovers the module with its data intact.*
- **R-XXVII-048** Renewal MUST be possible from inside a degraded installation. *Test: an
  installation in `read_only` can import a renewal licence and returns to `active` without any
  other intervention.*

### XXVII.2.10 Seats — one counter, one definition

The seat model is owned by Part XXV §XXV.8: one seat policy artefact, one counting definition
expressed in SPX, one counter, and enforcement at **every** creation door. This part adds only
the licence-side integration, because SmartPRS 2's seat enforcement fails on both sides at once:
two contradictory definitions of "active employee" (`SubscriptionService::seatUsage()` `:88-97`
versus `ClientUpdateController::activeEmployeeCount()` `:515-520`), fourteen enumerated bypasses
of which four are trivial through the UI, and a default on-prem cap of 100,000 with a `.lic`
seat field defaulting to unlimited.

- **R-XXVII-049** The licence's `seat_entitlement.basis` MUST name a **seat policy artefact
  key** (Part XXV §XXV.14.8), not encode a counting rule of its own. The licence states *how
  many*; the seat policy states *what counts*. *Test: `seatUsage()` on the SaaS side, the
  licence-side check and every enforcement door return the identical number for every dataset —
  the direct fix for `I-…` DEF-04.*
- **R-XXVII-050** The licence-side seat check MUST call the same counter as every other door,
  and MUST be re-evaluated on a schedule, not only at activation. *Test: growth past the cap
  after activation is detected within one scheduled cycle — the direct fix for bypass B11,
  where `activateLicenceFile()` `:459-469` checks at activation and never again.*
- **R-XXVII-051** Exceeding the seat entitlement MUST behave per `seat_entitlement.overage_policy`
  — `block` (refuse new people), `soft` (permit, warn, report to Ametecs) or `meter` (permit,
  bill) — and MUST NOT silently permit. *Test: each policy behaves as declared and is visible
  to the customer before they cross the boundary.*
- **R-XXVII-052** A seat entitlement of "unlimited" MUST be an **explicit claim value**, never
  the result of an absent, zero or unparseable value. *Test: a licence with a malformed seat
  claim is refused at verification, not treated as unlimited — the direct fix for
  `SubscriptionService.php:131` (`licensed <= 0` means unlimited) and for the `.lic` `seats`
  field defaulting to `0` ⇒ `device_limit = null` (bypasses B4, B5, B6).*

### XXVII.2.11 Tamper resistance, and its honest limit

**Why the licence state must be a verified signed artefact rather than a settings row.** In
SmartPRS 2 the artefact is not what is checked. `licenceStatus()` reads a JSON blob from
`settings(tenant_id=0, key='licence')` and trusts it; the blob's HMAC `sig` is produced and never
verified. One `UPDATE` grants a permanent licence. Every derived control — expiry, expiry mode,
seat cap — reads through that blob, so every derived control inherits its forgeability. A
signed artefact that is verified on every evaluation removes the entire class: there is no
derived state to edit, because the decision is recomputed from the signature each time.

**What an attacker with full database access on their own server can and cannot do.**

| Attack | Outcome |
|---|---|
| Edit any row holding licence-derived state | **No effect.** The gate recomputes from the verified artefact (R-XXVII-015). Derived rows are a cache, never an authority |
| Delete the licence store, or corrupt the artefact | **Fails closed** to `read_only` (R-XXVII-004, R-XXVII-038). Denial of service against themselves |
| Forge a licence with different claims | **Impossible without the private key.** Ed25519 signature over the canonical claims |
| Replace the embedded public key set in the database or a config file | **No effect.** The key set is compiled into the build and pinned; it is not read from configuration or from any row (R-XXVII-001) |
| Restore an older database, or replay an older licence document, to undo a downgrade | **Refused.** `issue_seq` monotonicity is enforced against the highest value ever seen, which is recorded outside the licence store and reported at every proof-of-life (R-XXVII-046) |
| Move the system clock back | **No effect on licence arithmetic.** Trusted time is `max(local, last signed server time)` (R-XXVII-035) |
| Copy the installation to a second machine | The copy carries the instance private key, so it verifies — **but both instances report the same `installation_id` with divergent host digests, which is an incident at Ametecs, not a silent success** (R-XXVII-024, §XXVII.6.5) |
| Block all egress to avoid revocation | Runs until the declared offline tolerance elapses, then degrades (R-XXVII-034) |
| **Patch the application source to remove the gate** | **Succeeds.** See below |

**The honest limit, stated plainly.** On-premise software cannot be made unbreakable. A customer
who controls the server controls the interpreter that runs the code, and a determined party with
that access can remove any check the product performs, whatever cryptography surrounds it.
Bytecode encoding (D-XXVI-09) raises the cost and the effort; it is not a control and this
blueprint does not present it as one. Any design that claims otherwise is selling the Product
Owner a guarantee it cannot honour.

**Therefore the design goal is tamper-evident and audit-visible, not unbreakable.** The
mechanisms that deliver that goal are:

- **Every licence decision is written to the append-only, hash-chained audit ledger** (spine
  S14), including every fail-open event, every verification failure, every binding mismatch and
  every degradation transition. A tampered installation that stops writing them has a
  detectable gap.
- **The proof-of-life report carries counters that must be internally consistent** —
  `issue_seq`, seat usage, instance count, audit chain head, ledger sequence. Ametecs reconciles
  them. An installation whose reported chain head does not advance, or whose seat usage
  contradicts its licensed count, appears on an anomaly report.
- **Silence is itself a signal.** An installation that stops reporting appears on the version
  register (§XXVII.6.5) with its last-seen date. Today, an installation that firewalls the
  vendor is indistinguishable from one that was decommissioned.
- **The commercial and legal instruments do the rest.** A licence agreement, an audit right, and
  the fact that tampering is visible and dated, are the real controls over a business customer.
  Technical measures raise the cost and produce the evidence; they do not, and are not designed
  to, make circumvention impossible.

- **R-XXVII-053** Licence state MUST NOT be persisted in any form that a gate reads as an
  authority. Any cached derivation MUST carry the verified artefact's digest, and a mismatch
  MUST invalidate the cache rather than the artefact. *Test: editing every row in the schema
  that mentions the licence changes no gate decision — the direct fix for `I-…` SEC-01.*
- **R-XXVII-054** Every licence decision, verification failure, fail-open event, binding
  mismatch and degradation transition MUST be written to the audit ledger through the audit
  service, with no direct insert path. *Test: the ledger's chain verifies across a full licence
  lifecycle; a removed entry is detected.*
- **R-XXVII-055** The proof-of-life report MUST carry the audit chain head and the monotonic
  counters, and Ametecs MUST run a reconciliation that flags inconsistency, stagnation and
  duplicate installation identities. *Test: a cloned installation and a stalled audit chain both
  appear on the anomaly report within one reporting cycle.*
- **R-XXVII-056** No documentation, contract, sales material or blueprint section may describe
  the licensing mechanism as preventing circumvention. *Test: a documentation review asserts the
  claim is "tamper-evident and audit-visible", never "tamper-proof".*

---

## XXVII.3 Entitlements and editions

Part XXV §XXV.6.2 delegates the entitlement grammar to this section, because one grammar must
serve both a SaaS subscription and an on-premise licence. This section owns the grammar; Part
XXV owns how a subscription resolves one; §XXVII.2.1 owns how a licence carries one.

### XXVII.3.1 The grammar

```
entitlement_set
  set_key, name, description
  versions[]
    version_no, status, effective_from, effective_to        (spine S4 envelope)
    features[]  { feature_key, granted: bool, config_json }
    limits[]    { limit_key, value, enforcement: hard|soft|meter }
    quotas[]    { quota_key, value, window, on_exceed: block|throttle|meter }
```

| Element | Meaning | Example |
|---|---|---|
| `feature_key` | A named capability, declared once in a **platform feature catalogue** that is generated from the modules themselves, not hand-maintained | `recruitment.volume_hiring`, `compliance.dra_gate`, `payroll.offcycle_run` |
| `limit_key` | A ceiling on a countable thing | `seats`, `companies`, `legal_entities`, `biometric_devices`, `api_clients` |
| `quota_key` | A rate over a window | `api_requests`, `export_rows`, `outbound_messages` |
| `enforcement` | `hard` refuses; `soft` permits and reports; `meter` permits and bills | — |
| `config_json` | Per-feature configuration a grant carries, typed against the feature's declared schema | `{ "max_concurrent_runs": 2 }` |

- **R-XXVII-060** The feature catalogue MUST be **generated from the modules** — each module
  declares the feature keys it owns, and the build fails if a module gates on a key it does not
  declare or declares a key nothing gates on. *Test: introducing a gate on an undeclared key
  fails the build. This is what prevents a repeat of `BLOCK_DNA` and `BLOCK_L2` — constants
  that described a product for two years while being returned by nothing (`Edition.php:149-167`;
  `I-…` DEF-02).*
- **R-XXVII-061** An entitlement set version MUST be a spine S4 artefact: versioned,
  effective-dated, published rather than edited, and referenced by version from every licence
  and every subscription that resolves it. *Test: publishing a new version changes no existing
  licence or subscription (Part XXV R-XXV-097).*
- **R-XXVII-062** An entitlement decision MUST return a **trace** (spine Law 4) naming the
  resolved set, its version, the source (licence or subscription), the feature key and the
  grant or refusal. *Test: "why can I not use this?" names all five, in the UI, to an
  administrator.*

### XXVII.3.2 One declaration, two enforcement points

SmartPRS 2's two parallel lists — `hiddenNavIds()` for navigation and `blockedPatterns()` for
endpoints — silently converged on the same SaaS-only set and nothing noticed. Both are now
derived from a single declaration.

```
Module declares:  feature_key  ──┬──► route attribute  #[RequiresFeature('x.y')]  ──► server gate
                                 ├──► navigation node metadata                    ──► UI visibility
                                 ├──► API capability document                     ──► /api/v3 discovery
                                 └──► the feature catalogue                       ──► entitlement authoring
```

- **R-XXVII-063** Navigation visibility, endpoint authorisation and API capability discovery
  MUST derive from **one** declaration per feature. *Test: a feature cannot be visible and
  unreachable, or reachable and invisible; a generated sweep asserts the three surfaces agree
  for every feature key. Restates Part XXV R-XXV-095 from the licence side.*
- **R-XXVII-064** Hiding a feature is **never** the control. Every unentitled endpoint MUST
  refuse server-side on every HTTP method, including `GET`. *Test: an unentitled endpoint
  refuses a direct `GET` with no navigation involved (spine Law 5).*
- **R-XXVII-065** An unentitled feature MUST return a **single, consistent** response shape —
  either "not available on your plan" everywhere, or "not found" everywhere, per a platform
  policy decision — and MUST NOT have one branch that is unreachable because another runs
  first. *Test: every unentitled route returns the declared shape — the direct fix for
  `EditionGuard.php:34-53`, where the 404 branch runs first and the 403 upsell branch is dead
  code (`I-…` DEF-03).*
- **R-XXVII-066** Entitlement enforcement MUST fail **closed** on a deterministic error and
  **open with a counted, alerting metric** on a transient one, per R-XXVII-004. *Test: a
  corrupt entitlement set blocks; a store timeout does not, and increments a visible counter.*

### XXVII.3.3 Resolution — one path, two sources

```
resolve_entitlements(actor, tenant, feature_key, as_of) →
    1. deployment supplies the entitlement source:
         SaaS         → subscription → plan version → entitlement_set_ref
         on-premise   → verified licence          → entitlement_set_ref
         private cloud→ verified licence          → entitlement_set_ref
    2. resolve the entitlement set version effective on as_of  (spine S4)
    3. read the feature grant, limit or quota
    4. return { granted, value, trace }
```

- **R-XXVII-067** There MUST be exactly one entitlement resolution implementation, and no
  domain code may branch on deployment model to reach it. *Test: static analysis over
  `Domain\*` finds no deployment branch — restating Part XXVI R-XXVI-002 for this path,
  against the twenty-one branch sites enumerated at `I-…` §10 Q8.*
- **R-XXVII-068** An installation configured so that **neither** a subscription gate nor a
  licence gate applies MUST refuse to boot, naming the invalid combination. *Test: the
  SmartPRS 2 combination `SMARTPRS_EDITION=l2` + `SMARTPRS_DEPLOYMENT=saas` — unbillable and
  unenforceable, and implied by a source comment to have occurred in production
  (`ClientUpdateController.php:225-230`; `I-…` DEF-36) — is rejected at boot. Restates Part
  XXVI R-XXVI-003.*

### XXVII.3.4 PRODUCT OWNER DECISION REQUIRED — D-XXVII-01: do editions exist in SmartPRS 3, and what do existing L1/L2 customers own?

**The position today, stated exactly.** Ametecs sells three on-premise editions at three price
points and ships one product. `Edition::level()` returns the literal `3`; `hiddenNavIds()` and
`blockedPatterns()` both return the SaaS-only sets; `BLOCK_DNA`, `BLOCK_L2`, `DNA` and
`L2_MODULES` are returned by nothing; `Edition::label()` returns `'SmartPRS'` for every edition
(`Edition.php:93-97,105,108-117,119-127,149-167,169-174,176-180`). The sales desk records
`onprem_clients.edition`, and `/app1`, `/app2` and `/app3` are marketed as three different
products. The only edition behaviour enforced anywhere is the lock at activation, which refuses
an L2 licence on an L1 install — enforcing a distinction that does not exist, against the
customer (`I-…` DEF-02, HIGH commercial; Q-01, Q-02).

**This is a commercial and possibly contractual question before it is a technical one.** An L1
customer has been sold, and has paid for, a differentiated product. They received a
non-differentiated one. Three consequences follow that engineering cannot resolve:

1. **They received more than they bought.** Enforcing entitlements in SmartPRS 3 removes
   function they have been using — in some cases for years, in business processes built around
   it. That is a downgrade delivered by an upgrade.
2. **Revenue recognition and pricing integrity.** Three price points for one deliverable is a
   sales-integrity exposure independent of what SmartPRS 3 does. It should be assessed by
   whoever owns Ametecs' commercial governance, not decided as a side effect of an architecture
   choice.
3. **What the contract says.** If a contract enumerates modules, the customer has a contractual
   position on either side of this question. The contracts must be read before the decision is
   taken, not after.

| Option | Commercial | Engineering | Verdict |
|---|---|---|---|
| **A. Drop editions. One product, priced on seats, companies and named optional modules purchased individually** | Simplest to sell and to explain. Matches what has actually been delivered for years, so no existing customer loses anything. Optional modules become genuine add-ons with genuine value, priced when bought. Removes the sales-integrity exposure prospectively. Loses the "good/better/best" anchoring that makes tiered pricing effective, and loses the natural upgrade path a tier ladder gives a salesperson | The entitlement engine is still built — it is needed for SaaS plans (Part XXV), for optional modules, and for limits and quotas — but the **on-premise licence carries one standard entitlement set plus purchased module grants**. No migration of existing customers is required and no function is withdrawn | **Recommended** |
| **B. Keep three editions as entitlement bundles, and grandfather every existing customer at the full set** | Preserves the tier ladder, the marketing and the price anchoring. New customers get what they pay for. Existing customers lose nothing, which removes the contractual risk. The cost is a permanent population of grandfathered licences that Ametecs must track and explain, and a sales conversation ("you have everything, but new customers at your price do not") that is awkward for years | Editions become three named `entitlement_set` keys. The grandfathered population is expressed as an entitlement set version pinned per licence — the mechanism exists for exactly this (R-XXVII-061). Some engineering cost in maintaining three bundles and testing them | **Viable second choice.** Choose this if the tier ladder is commercially important |
| **C. Keep editions and actually enforce them against existing customers** | Recovers the theoretical revenue the tiers were meant to produce. Also withdraws function from paying customers who have built processes on it, which is a support event, a renewal risk and a possible contract dispute, all at the same moment as a major version migration | Identical engineering to B. The difference is entirely commercial and legal | **Not recommended.** The migration to SmartPRS 3 is the highest-risk moment in the product's life; adding a function withdrawal to it converts a technical migration into a commercial confrontation |

**Recommendation: Option A**, with three conditions.

1. **Existing L1/L2/L3 customers are issued licences carrying the full standard entitlement
   set.** Nothing is withdrawn from anyone. This is true under A and B alike, and it is the
   part of the recommendation that should not be traded away.
2. **`onprem_clients.edition` migrates as a historical sales attribute only** — it records what
   was sold, for reconciliation and for the commercial review, and it grants nothing.
3. **The commercial review of what L1 and L2 customers were sold, and whether anything is owed,
   is a separate exercise with a named owner and a date.** It is not resolved by this blueprint
   and must not be assumed resolved by it.

- **R-XXVII-069** Whichever option is selected, **the entitlement engine is built** — it is
  required by the SaaS plan model (Part XXV §XXV.6), by optional modules, and by limits and
  quotas — and the on-premise licence resolves through it. Editions, if retained, are named
  entitlement sets and nothing else. *Test: adding, removing or re-bundling an edition requires
  publishing an entitlement set version and no code change; three price points cannot again
  resolve to one enforcement path.*

### XXVII.3.5 The upgrade path

- **R-XXVII-070** An entitlement upgrade MUST take effect on the import of a new licence
  document with no reinstallation, no file replacement and no restart. *Test: importing a
  licence with an additional module grant makes the module available within one request cycle.
  SmartPRS 2's design intent — "upgrading is just a new `SMARTPRS_EDITION` value in `.env`"
  (`Edition.php:5-17`) — is right; the mechanism is an environment variable read by parsing
  `.env` line by line to defeat `config:cache` (`Edition.php:56-86`), which is not.*

---

## XXVII.4 The application update system *(brief Section 89)*

> **The rule that governs this whole section, stated once and without qualification: no shared
> signing secret is ever distributed in a customer build, under any circumstance.** Update
> packages are verified with a public key compiled into the build. There is no configuration,
> environment variable, support mode or command-line flag that permits an unsigned or
> unverifiable package to be installed. R-XXVII-002 and R-XXVII-003 apply here in full.

### XXVII.4.1 The release artefact

```
smartprs-<version>-<build_id>.pkg          (a zip; content-addressed, never overwritten)
  ├── manifest.json                        canonical, JCS-normalised
  ├── manifest.sig                         detached Ed25519, release signing key
  ├── manifest.countersig                  detached Ed25519, second authorised signer
  └── payload/                             the application tree
```

Three artefacts, three properties: the payload is bytes, the manifest describes the bytes, and
the signature covers the manifest. **Nothing is trusted because of where it was fetched from.**

### XXVII.4.2 The manifest

| Field | Purpose | Answers |
|---|---|---|
| `artefact_type` | `application_update` \| `application_install` \| `hotfix` | The package validator distinguishes an update from an installer by a **signed type field**, not by guessing at directory shape. `inspectPackage()` cannot tell them apart today and its own rejection text claims it can (`ReleaseController.php:180-186`; `I-…` DISC-22, DEF-30) |
| `product`, `version`, `build_id`, `channel` | One version identity (§XXVII.4.10) | `version.json` says `2026.6.2` and `config/smartprs.php:43` says `2026.6.1` (`I-…` DEF-33) |
| `min_from_version`, `max_from_version` | Which installed versions this package may be applied to | Nothing today prevents applying a package across an unsupported gap |
| `requires_php`, `requires_extensions`, `requires_db_version` | Runtime prerequisites, checked before anything is touched | — |
| `migrations[]` | Every migration the package adds, with `reversible: bool` and a declared `phase` (expand / contract) | Migrations are discovered at run time today, so their reversibility is unknown until they fail |
| `dependency_lock_digest` | Digest of `composer.lock`, so the updater knows whether `composer install` is needed | `vendor/` is not on the protected list and is silently replaced by the build machine's copy (`updater.php:233`; `I-…` §10 Q3 step 8b) |
| `files[]` | Every payload file with its **SHA-256** and its mode | A hash over the zip proves only that the zip arrived; a per-file manifest proves the extracted tree |
| `protected_paths[]`, `removed_paths[]` | What is never touched, and what this release **deletes** | `removed_paths` does not exist today, so a file the new version drops survives forever |
| `entitlement_requirements` | The entitlement or support-window state required to install | — |
| `rule_pack_floor` | The minimum rule-pack versions this build expects | The two channels compose (§XXVII.5.2) |
| `security` | `{severity, advisories[]}` — set for a security release | Feeds the patch policy (§XXVII.6.6) |
| `signed_at`, `key_id` | Provenance | The public key has no identity today |

- **R-XXVII-071** Update packages MUST be signed and signature verification MUST be
  unconditional. An unsigned, unverifiable, or wrong-key package MUST be refused under every
  configuration. *Test: stripping the signature, corrupting one byte of the manifest, and
  signing with an untrusted key each refuse the install, and no configuration permits any of
  them — the direct fix for `UpdateClient.php:254` and `updater.php:174`, where verification is
  conditional on the server supplying a hash (`I-…` SEC-06, DEF-09).*
- **R-XXVII-072** The manifest MUST carry a **per-file SHA-256**, verified **after extraction
  and before installation**. A file present in the extracted tree that is not in the manifest,
  or whose hash differs, aborts before anything is written. *Test: adding one file to the
  extracted payload aborts the update with that file named.*
- **R-XXVII-073** The package MUST carry a **second signature** by a distinct authorised
  signer, and both MUST verify. *Test: a package with one valid signature is refused. Four eyes
  on shipped code is the same control as maker-checker on payroll (spine S8), and it is what
  makes a single compromised `/super` account insufficient — the exact threat at `I-…` SEC-06.*
- **R-XXVII-074** The package type MUST be a signed manifest field, and an installer package
  MUST be refused by the updater. *Test: an install package offered to the update flow is
  refused by type, not by shape.*
- **R-XXVII-075** The manifest MUST declare `removed_paths`, and the updater MUST delete them.
  *Test: a file removed in release N+1 is absent after the update.*

### XXVII.4.3 The channel, and where trust actually lives

| Control | Specification |
|---|---|
| Transport | HTTPS with full certificate validation and a capped redirect chain |
| Pinning | The update client **pins the vendor's signing identity**, and additionally pins the update host's certificate public key with a published backup pin and a documented rotation procedure |
| Channel address | The endpoint is compiled into the build. An override exists for a **vendor-operated mirror** only, and an overridden endpoint may serve bytes but **cannot make an unsigned or wrong-key package installable** |
| Replay and downgrade | The check request carries a nonce; the response is signed and binds `{nonce, installation_id, offered_version, package_digest}`. A package whose manifest version is lower than the installed version is refused unless it is an explicitly signed, explicitly acknowledged rollback release |
| Failure | Unreachable is not a failure state. The client retries with backoff, reports on the operations console, and never blocks anything |

- **R-XXVII-076** Repointing the update endpoint MUST NOT be sufficient to deliver installable
  code. *Test: a rogue server offering a well-formed but differently signed package is refused —
  the direct fix for `SMARTPRS_UPDATE_URL` as a plain `.env` value that repoints the whole
  channel at a server the client then trusts completely (`I-…` SEC-06).*
- **R-XXVII-077** The update channel MUST pin the vendor's certificate public key with a
  published backup pin. *Test: a MITM with a trusted-but-different certificate — a corporate
  TLS interception proxy or a rogue CA on a Windows box — cannot serve the check response.*
- **R-XXVII-078** The check response MUST be signed and MUST bind the nonce, the installation
  identity, the offered version and the package digest. *Test: replaying a captured check
  response to a different installation, or for a different version, is refused.*

### XXVII.4.4 Preconditions

Evaluated before **anything** is downloaded, and again before anything is written.

| # | Precondition | Failure |
|---|---|---|
| 1 | Signature, countersignature, key validity, manifest hash | Refuse; audited rejection |
| 2 | `artefact_type == application_update`, `product` matches | Refuse |
| 3 | Installed version within `[min_from_version, max_from_version]` | Refuse, naming the intermediate release required |
| 4 | `requires_php`, `requires_extensions`, `requires_db_version` satisfied | Refuse, naming the unmet prerequisite and the remedy |
| 5 | Entitlement / support-window state permits this release (§XXVII.5.4 governs the statutory exception) | Refuse with a commercial message and a renewal path |
| 6 | Disk headroom ≥ (payload + code backup + database backup + declared margin) | Refuse, naming the shortfall in bytes |
| 7 | Database backup destination is writable and a test write succeeds | Refuse. **An update never begins without a proven place to put the backup** |
| 8 | No payroll run is in a non-terminal state; no period is mid-finalisation | Refuse, naming the run and offering to schedule |
| 9 | Queue depth below a configured threshold, or the operator explicitly accepts draining | Warn or refuse per policy |
| 10 | No update run is already in a non-terminal state | Refuse; offer resume or recover (§XXVII.4.9) |
| 11 | Scheduler and worker are healthy (Part XXVI §XXVI.6) | Warn; an update onto a broken runtime hides the breakage in the update |
| 12 | The maintenance window, if one is configured, is open | Refuse or schedule |

- **R-XXVII-079** Every precondition MUST be evaluated and reported **as a set**, before the
  first one fails the operator's day. *Test: an installation failing three preconditions is
  told all three at once, with each remedy.*
- **R-XXVII-080** An update MUST NOT begin unless the database backup destination has been
  proven writable in this run. *Test: an unwritable destination refuses the update before
  maintenance mode is entered.*
- **R-XXVII-081** An update MUST refuse while a payroll run is in a non-terminal state. *Test:
  an in-progress run blocks the update with the run named.*

### XXVII.4.5 The update state machine

```
        idle
          │ operator or policy initiates
          ▼
     PRECHECK ──fail──► refused (nothing written)
          │
          ▼
     DOWNLOAD ──fail──► refused (partial file deleted)
          │
          ▼
      VERIFY  ──fail──► refused (package quarantined, audited)
          │
          ▼
      STAGE          extract to a staging directory; verify every file hash
          │ ──fail──► refused (staging discarded)
          ▼
   ┌─ BACKUP_DB      consistent database backup + restore-readiness assertion
   │      │ ──fail──► refused (nothing changed)
   │      ▼
   │  BACKUP_CODE    every path the manifest will write or remove, plus version identity
   │      │ ──fail──► refused (nothing changed)
   │      ▼
   │  MAINTENANCE    down, no bypass secret, retry-after set
   │      ▼
   │  SWAP_CODE      atomic-as-possible replacement from staging; removed_paths deleted
   │      ▼
   │  DEPENDENCIES   composer install --no-dev, only if dependency_lock_digest changed
   │      ▼
   │  MIGRATE        forward migrations, one at a time, each recorded before and after
   │      ▼
   │  CACHE_REBUILD  config, route, view, event caches built (not merely cleared)
   │      ▼
   │  WORKER_RESTART scheduler paused, workers restarted, drain confirmed
   │      ▼
   │  HEALTH_CHECK   the real check catalogue (§XXVII.4.8)
   │      │ ──fail──────────────┐
   │      ▼                     │
   │  MAINTENANCE_OFF           │
   │      ▼                     │
   │  COMPLETED                 │
   └──────────────────────────► ▼
                            ROLLBACK    restore code (remove-then-restore) and database
                                 ├─ ok ──► ROLLED_BACK  → maintenance off → alert → audited
                                 └─ fail ► ROLLBACK_FAILED
                                              maintenance stays ON
                                              critical alert to operator and Ametecs
                                              the documented manual runbook is displayed
```

| State | Idempotent? | Rollback point? | On interruption (§XXVII.4.9) |
|---|---|---|---|
| `PRECHECK`, `DOWNLOAD`, `VERIFY`, `STAGE` | Yes | n/a — nothing has changed | Discard and restart |
| `BACKUP_DB` | Yes (a partial backup is discarded) | n/a | Restart the step |
| `BACKUP_CODE` | Yes | n/a | Restart the step |
| `MAINTENANCE` | Yes | from here on, rollback is defined | Resume forward |
| `SWAP_CODE` | Yes (staging is the source of truth) | Code restore | Resume the swap; the staging tree is authoritative |
| `DEPENDENCIES` | Yes | Code restore | Resume |
| `MIGRATE` | **Per-migration**, recorded in a ledger | **Database restore** (see §XXVII.4.6) | Consult the ledger; roll back |
| `CACHE_REBUILD`, `WORKER_RESTART` | Yes | Code + database restore | Resume |
| `HEALTH_CHECK` | Yes | Code + database restore | Re-run |
| `MAINTENANCE_OFF` | Yes | — | Resume |
| `ROLLBACK` | Yes | — | Resume the rollback |
| `ROLLBACK_FAILED` | terminal | — | Manual runbook only |

- **R-XXVII-082** Every state transition MUST be persisted before the work of the state begins
  and again when it completes, with a timestamp, a result and an operator-readable line, and
  MUST be written to the audit ledger. *Test: the full run is reconstructable from the ledger
  alone, on both the customer and the vendor side.*
- **R-XXVII-083** Every step MUST be idempotent, and re-entering a step MUST NOT compound its
  effects. *Test: each step is executed twice in a harness and the outcome is identical.*
- **R-XXVII-084** Maintenance mode MUST have **no bypass secret**, MUST set a retry-after, and
  MUST NOT be exited until the health check passes. *Test: no URL reaches the application during
  the window. SmartPRS 2 gets this right (`updater.php:271-283`, `secret => null`) and this part
  preserves it.*
- **R-XXVII-085** The updater MUST run as a **detached process independent of the code being
  replaced**, and its progress endpoint MUST be framework-free, token-gated, present from the
  first install, and MUST NOT expose migration output, schema names, table names or connection
  strings. *Test: an update proceeds with the framework unbootable at every intermediate point;
  the poll endpoint on a never-updated installation returns progress; a failed migration's
  detail is a reference code to the operator, and the text is in the local log only —
  preserving `UpdateClient::spawn()` `:428-458` and `updater/update-status.php` while fixing
  DEF-34 (one of the two copies missing) and SEC-12 (migration output served verbatim).*

### XXVII.4.6 Database backup and rollback — the step that does not exist today

This is the single largest gap in SmartPRS 2's update flow, and the design has one honest
constraint that must be stated rather than designed around.

> **MySQL does not have transactional DDL.** A migration that alters a table cannot be rolled
> back by aborting a transaction, and `migrate:rollback` depends on a correct, tested `down()`
> for every migration in the batch — which is exactly the thing that is unavailable at the
> moment it is needed. **Therefore database rollback in SmartPRS 3 is restore-from-snapshot,
> and `down()` methods are a convenience, never the safety net.**

| Element | Specification |
|---|---|
| What is taken | A consistent logical dump (schema + data) of the application database, plus the migration ledger state, plus the licence store digest and the installed version identity |
| Consistency | `--single-transaction` on InnoDB, or a storage/filesystem snapshot where the platform provides one and the installer has recorded it as available |
| Where | The backup destination configured in Part XXVI §XXVI.8.3, **proven writable in precheck 7**, never the directory being updated |
| Encryption | Per Part XXVI §XXVI.8.2. An update backup is a backup and obeys the same policy |
| Restore readiness | The backup is **asserted restorable** before the update proceeds: the dump is read back, its structure parsed, its row counts compared against the live tables within a declared tolerance, and its digest recorded. A backup that cannot be asserted stops the update |
| Retention | Governed by Part XXVI R-XXVI-131: a configurable count and age. **Never "kept forever and never pruned"**, which is the current behaviour and an unbounded disk-growth defect on a customer's payroll server (`updater.php:342-343`; `I-…` DEF-31) |
| Large databases | If the estimated dump time exceeds a configured threshold, the operator is warned in precheck with the estimate and offered a snapshot-based path or a scheduled window. **The answer is never to skip the backup** |

**Rollback semantics.**

| Failure point | Code | Database |
|---|---|---|
| Before `SWAP_CODE` | nothing to undo | nothing to undo |
| During or after `SWAP_CODE`, before `MIGRATE` | remove-then-restore | untouched; no restore needed |
| During `MIGRATE` | remove-then-restore | **restore from the snapshot** |
| After `MIGRATE`, at `CACHE_REBUILD` / `WORKER_RESTART` / `HEALTH_CHECK` | remove-then-restore | **restore from the snapshot** |
| Rollback itself fails | maintenance stays on; the runbook is displayed; a critical alert fires locally and to Ametecs | same |

- **R-XXVII-086** An update MUST take a consistent database backup before the first migration
  and MUST assert it restorable before proceeding. *Test: an update whose backup cannot be
  asserted does not proceed — the direct fix for the absence of any database backup in the
  update path (`I-…` DEF-25, HIGH).*
- **R-XXVII-087** Rollback MUST restore **both** code and database to a consistent point, and
  the application MUST NOT be brought back up in a state where the schema is ahead of the code.
  *Test: a migration that fails halfway leaves the system on the previous version with the
  previous schema, verified by a schema digest comparison — the direct fix for
  `updater.php:307-311`, where the code is rolled back and the schema is not, and the
  application is brought back up in that state.*
- **R-XXVII-088** Code rollback MUST be **remove-then-restore**, identically on the customer
  installation and on the Ametecs platform, covering every directory the manifest can write.
  *Test: a rollback leaves no file the new version added, on both paths — preserving
  `updater.php:140-165` and fixing `ReleaseController.php:249-254`, which copies over and
  therefore leaves behind every added file, including stray migrations
  (`I-…` DEF-28, DEF-29, DEF-30).*
- **R-XXVII-089** `ROLLBACK_FAILED` MUST be a first-class terminal state: maintenance stays on,
  a critical alert fires locally **and** to Ametecs, and the operator is shown the specific
  manual recovery runbook with the backup paths, the digests and the exact commands. *Test: a
  forced rollback failure produces the runbook and the alert, and does not bring the
  application up.*

### XXVII.4.7 Migration safety

- **R-XXVII-090** **One version identity.** The version the build stamps, the version the
  manifest declares, the version the installation stores, the version the client reports, the
  version the audit records and the version displayed MUST all come from one source, written
  once by the build. *Test: no two files in a release artefact state different versions, and
  the register's record matches the installation's report — the direct fix for `version.json`
  `2026.6.2` versus `config/smartprs.php:43` `2026.6.1`, which makes a release invisible to
  every client (`I-…` DEF-33). Referenced by Part XXVI §XXVI.12.1.*
- **R-XXVII-091** Every migration in a release MUST declare a `phase` — **expand** (additive,
  safe with the previous code running) or **contract** (destructive, requires the new code) —
  and a release MUST NOT contain a contract migration for a change whose expand phase shipped
  in the same release. *Test: a release mixing both phases for one change fails CI. This is
  what makes a rollback survivable: the previous code can run against an expanded schema.*
- **R-XXVII-092** Destructive migrations — column drop, table drop, type narrowing, non-null
  addition without a default — MUST be flagged in the manifest, surfaced to the operator before
  the update, and MUST require an explicit acknowledgement. *Test: an update containing a
  destructive migration cannot proceed unattended under any auto-update policy.*
- **R-XXVII-093** Migrations MUST be recorded in a ledger **before** they run and **after** they
  complete, so that an interrupted migration is identifiable by name. *Test: killing the process
  mid-migration leaves a ledger entry naming the migration that was in flight.*
- **R-XXVII-094** A migration MUST NOT depend on application code that a rollback would remove,
  and MUST NOT call domain services. *Test: static analysis over `database/migrations` finds no
  `Domain\*` reference.*
- **R-XXVII-095** A long-running data migration MUST be a **queued, resumable job** dispatched
  after the update, not a step inside the maintenance window, and its outstanding state MUST be
  visible on the operations console. *Test: a migration over ten million rows does not extend
  the maintenance window.*

### XXVII.4.8 The health check

SmartPRS 2's post-update health check is `php artisan --version` (`updater.php:316-320`). That
proves the CLI autoloader boots. It does not touch the database, does not exercise a route and
does not confirm the web SAPI serves; an update that boots the CLI and 500s every HTTP request
is reported as `done, 100%` (`I-…` DEF-26).

The SmartPRS 3 check is the **installer's own health-check catalogue** (Part XXVI §XXVI.6),
executed unchanged. One catalogue, three consumers: post-install, post-update, and the scheduled
operations check.

| Class | Must pass before maintenance is lifted |
|---|---|
| Framework boots (CLI **and** web SAPI, through a real HTTP request to a health endpoint) | yes |
| Database reachable, schema digest matches the release's expected digest, migration ledger consistent | yes |
| Cache and session stores reachable | yes |
| Queue connection reachable; a canary job dispatched and consumed | yes |
| Scheduler registered and its heartbeat advancing | yes |
| Storage writable; document store reachable | yes |
| Licence verifies and the gate resolves | yes |
| Rule packs present, verifying, and satisfying the release's `rule_pack_floor` | yes |
| A representative read path returns a known-good result (a synthetic tenant's payslip re-render matches its stored digest) | yes |
| Mail transport reachable | warn only |

- **R-XXVII-096** The post-update health check MUST be the same catalogue as the post-install
  check, MUST exercise the real HTTP request path, the database, the queue and the scheduler,
  and MUST fail the update on any blocking check. *Test: an update that breaks a route, a
  migration or the queue connection is rolled back automatically.*
- **R-XXVII-097** The health check MUST include a **golden re-render**: a synthetic tenant's
  finalised payslip is recomputed and compared against its stored digest. *Test: a release that
  silently changes a payroll result fails its own update. This is the check that catches the
  class of defect a route-level check cannot.*

### XXVII.4.9 Interruption and resumption

Power loss, an operator closing a terminal, a killed PHP process, a rebooted server, a
hypervisor migration mid-update.

```
on boot, or on any request:
   read update_runs where state NOT IN (completed, rolled_back, refused)
     └─ found ──► the application refuses ordinary traffic and serves the RECOVERY CONSOLE
                    showing: the run, the state, when it was last advanced, what is safe
                    offering: [ Resume ]  [ Roll back ]  [ Show runbook ]
```

| Rule | Reason |
|---|---|
| A non-terminal run **blocks normal service** until it is resolved. The recovery console is reachable without a licence and without the queue | An installation with a half-applied update must not quietly serve payroll |
| Resumption re-runs the interrupted step from its beginning, relying on step idempotence (R-XXVII-083) | The state before an interruption is unknown; only re-running a step whose repetition is harmless is safe |
| The staging tree, the code backup and the database backup are all retained until the run reaches a terminal state, and their digests are re-verified before use | A rollback that restores a corrupted backup is worse than no rollback |
| If the interrupted step was `MIGRATE`, the migration ledger names the migration in flight, and the **default offered action is rollback**, not resume | A partially applied DDL statement is not resumable and must not be guessed at |
| Automatic resumption is permitted only for steps before `SWAP_CODE`. From `SWAP_CODE` onward a human decides | An unattended machine must not choose between forward and backward on its own |
| A run that has been non-terminal for longer than a configured threshold raises a critical alert locally and to Ametecs | An abandoned update is an incident, not a state |

- **R-XXVII-098** An interrupted update MUST leave the installation in a state that refuses
  ordinary traffic and offers a recovery console reachable without a licence. *Test: killing the
  updater at each state leaves a recoverable installation and never a silently serving one.*
- **R-XXVII-099** From `SWAP_CODE` onward, resumption MUST require an explicit human decision.
  *Test: no auto-update policy resumes a code-swapped run unattended.*

### XXVII.4.10 Auto-update policy

- **R-XXVII-100** Auto-update MUST be per-installation configuration with the axes: enabled,
  channel, classes eligible (`security` \| `patch` \| `minor` \| `major`), maintenance window,
  and a mandatory delay after publication. *Test: each axis behaves as declared.*
- **R-XXVII-101** Auto-update MUST NEVER apply a release containing a destructive migration
  (R-XXVII-092), a major-version change, or a release whose preconditions include an operator
  acknowledgement. *Test: such a release waits for a human regardless of policy.*
- **R-XXVII-102** A security release MUST be offered to every supported installation regardless
  of channel, and MUST be visible as outstanding on the operations console until applied or
  explicitly deferred with a reason. *Test: a deferred security release is on the vendor's
  exposure register with the customer's reason attached.*

---

## XXVII.5 Rule-pack updates *(brief Section 90)*

### XXVII.5.1 What this section owns

Part XVII §XVII.4 owns the statutory rule pack itself: its anatomy, manifest schema, content
schema, jurisdiction identifiers, versioning, the Ed25519 two-signature scheme, the nine-gate
publication pipeline at Ametecs, the customer-side verify/validate/golden/preview/accept flow,
the auto-acceptance policy (D-XVII-03) and retrospective packs. **None of that is restated
here.** This section owns only the *distribution* half — the channel, its authorisation, how it
composes with the application update channel, and the commercial gate.

The separation itself is spine S12 and S18 and is not negotiable: **statutory content is not
application code.** A Professional Tax slab change is content, and it ships as content.
SmartPRS 2 has exactly one delivery artefact, so a slab change means bumping the version,
building a zip, publishing, granting, and then waiting for two hundred administrators each to
accept a maintenance window on their payroll server (`I-…` §10 Q4, NEW-UPD-01;
`StatutoryConfig.php:31-35`).

| Property | Application channel (§XXVII.4) | Rule-pack channel |
|---|---|---|
| Artefact | Signed package of files | Signed content document |
| Signing key | Release signing key | **Separate** rule-pack signing key (Part XVII §XVII.4.6) |
| Applying it | Maintenance window, file replacement, migration, worker restart, health check | **No maintenance window, no file replacement, no migration, no restart** |
| Rollback | Code + database restore | Deactivate the version; the predecessor's effective range reopens |
| Cadence | Release cycle | Whenever a statute changes |
| Authorisation | Support window (AMC) | **Licence validity** — see §XXVII.5.4 |
| Reversibility for the customer | An update is applied or not | A pack may be **declined with a reason** (Part XVII R-XVII-033) |

- **R-XXVII-103** Applying a rule pack MUST NOT stop the application, replace a file, run a
  migration, restart a worker or require a deployment. *Test: a pack is applied on a live
  installation under load with no dropped request and no restarted process.*
- **R-XXVII-104** The two channels MUST use **separate signing keys**, so that compromise of the
  release key does not permit forging statutory content and vice versa. *Test: a rule pack
  signed with the release key is refused, and a release signed with the rule-pack key is
  refused.*
- **R-XXVII-105** The rule-pack channel MUST be polled on a schedule as a first-class scheduled
  job, and a missed poll MUST be visible on the operations console. *Test: an installation
  nobody signs into still discovers a new pack — restating Part XVII R-XVII-037 as a channel
  property, against a product that today has no scheduled update check at all.*
- **R-XXVII-106** The channel MUST be pull-only and MUST carry outbound only the installation
  identifier, the product version, the bound jurisdictions and the pack versions currently
  held. *Test: a traffic capture matches the published payload. Restates Part XVII R-XVII-039.*
- **R-XXVII-107** Side-loading a pack MUST be identical to the online path after the transport
  step, with identical verification, preview, acceptance and audit. *Test: the same pack applied
  online and by import produces identical results and identical audit entries — restating Part
  XXVI R-XXVI-184 for this channel.*

### XXVII.5.2 How the two channels compose

They are independent in operation and coupled only by two declared version floors. Neither may
silently require the other.

```
 pack declares   min_platform_version   ─────► "this pack needs application ≥ X"
 release declares rule_pack_floor       ─────► "this build expects pack ≥ Y for these packs"
```

| Situation | Behaviour |
|---|---|
| A pack requires a newer application than is installed | The pack is **announced and not applied**. The installation shows: *"Pack IN-TG-PT 2026.5 requires SmartPRS 3.4; you are on 3.2. Update the application to receive it."* The pack is **not** silently skipped, and the customer appears on the Ametecs exposure register with the reason `platform_too_old` (Part XVII R-XVII-009 refuses it; this requirement governs what the customer is told) |
| An application release requires a newer pack than is held | The release's precondition 12 fails until the pack is fetched. The updater offers to fetch and apply the pack first, in one operator action |
| A pack arrives while an update is mid-flight | Queued. Packs are never applied between `MAINTENANCE` and `MAINTENANCE_OFF` |
| An application rollback moves the build below a held pack's `min_platform_version` | The pack is **deactivated, not deleted**, its predecessor's effective range reopens, and the operator is told which periods are affected. Payslips already produced retain their pack reference and remain explainable |
| An air-gapped installation | Both floors are checked identically on the side-loaded artefacts; the mismatch messages are the same |

- **R-XXVII-108** A pack that cannot be applied because the application is too old MUST be
  announced, recorded, surfaced to the customer with the required version named, and reported to
  Ametecs — never silently skipped. *Test: an installation two versions behind shows the
  outstanding pack and the version it needs.*
- **R-XXVII-109** An application rollback that invalidates a held pack MUST deactivate rather
  than delete it, MUST reopen the predecessor's effective range, and MUST NOT alter any
  finalised period. *Test: rolling back after a pack activation leaves every finalised payslip
  unchanged and still explainable (spine Law 3, Part XVII R-XVII-035).*
- **R-XXVII-110** The two channels MUST NOT share a schedule, a lock, a queue or a state
  machine, so that a stuck application update cannot stop statutory content from arriving.
  *Test: an installation in `ROLLBACK_FAILED` still receives and stores packs for later
  activation.*

### XXVII.5.3 Preview, acceptance and auto-accept

Owned by Part XVII §XVII.4.8 in full: the mandatory impact preview computed on the real payroll
basis, the authority reference and filed evidence, declining as a first-class audited outcome,
retrospective handling, and D-XVII-03's auto-acceptance recommendation (auto-accept by default
for `impact: none` and `impact: prospective`, always manual for `impact: retrospective`, for a
pack raising a block-severity floor finding, and for the first pack a tenant binds for a
jurisdiction).

This section adds only the channel-side requirements that make it operable:

- **R-XXVII-111** A pack that has been announced but not accepted MUST appear on the
  installation's operations console **and** on the vendor's exposure register, with the days
  outstanding. *Test: an unaccepted pack is visible on both sides within one reporting cycle.
  Silence about a pending statutory change is the failure mode this prevents.*
- **R-XXVII-112** Auto-acceptance policy MUST be per tenant and per statute, changing it MUST be
  an audited permissioned act, and the policy in force MUST be shown with every announcement.
  *Test: the announcement states whether this pack will auto-apply and on what date.*
- **R-XXVII-113** An installation MUST be able to state, for any past date, which pack versions
  were held, which were activated, which were declined and by whom. *Test: the register answers
  "what statutory content was in force on 15 August 2026?" as a single query (spine Law 3).*

### XXVII.5.4 PRODUCT OWNER DECISION REQUIRED — D-XXVII-02: is rule-pack delivery gated on an active AMC?

> **This is the same decision as Part XVII's D-XVII-02.** It is recorded in both parts because
> it is simultaneously a statutory-content question (Part XVII) and a distribution-channel
> authorisation question (this part). **One decision, one answer, recorded once by the Product
> Owner and reflected in both.** The recommendation below is identical to Part XVII's.

**The position today.** `UpdateServerController::check()` `:238-246` refuses everything when AMC
has lapsed, and statutory content is inside the application package, so a customer whose AMC has
lapsed **cannot receive a Professional Tax correction at all** — though the statute applies to
them regardless (`I-…` §10 Q4, Q-10).

| Option | For | Against |
|---|---|---|
| **A. Gate statutory packs on AMC, as today** | Preserves AMC renewal pressure — the strongest commercial lever the on-premise product has | The vendor knowingly ships a product that computes a legally wrong deduction, and knowingly withholds the correction it has already produced. The customer's employees bear the consequence, and they are not party to the AMC |
| **B. Never gate statutory packs; gate features, updates and support** | Statutory correctness reaches every licensed install unconditionally. The vendor's own liability drops sharply. Feature releases, new modules, support SLAs and priority response remain fully gated, which is what AMC is actually bought for | Removes one lever |
| C. Deliver but degrade — send the pack, block payroll output until AMC is settled | Retains pressure while the content is technically present | Worse than A: blocking payroll is a more severe harm than withholding a slab, and it punishes employees for a commercial dispute |
| D. Free for a grace period, then gate | A compromise | Arbitrary, hard to communicate, and it fails precisely in the long-lapse case that matters most |

**Recommendation: Option B.** Statutory rule packs are delivered to every **licensed**
installation regardless of AMC status, and this is stated in the contract as a vendor
commitment. Three reasons, in order of weight:

1. **Liability.** A wrong Professional Tax deduction computed by SmartPRS is Ametecs' defect.
   Having produced the correction and then withheld it from a known-affected installation for
   commercial reasons is materially worse, in any forum, than not having produced it — and the
   withholding is documented in the product's own audit trail on both sides.
2. **Reputation.** "The payroll vendor let our filings go wrong because we were late on a
   maintenance invoice" reaches an industry association. Ametecs' installed base — collections
   agencies and BPOs — is a small, well-connected market.
3. **Commercial reality.** Statutory currency is a poor lever. It is the part of AMC a customer
   feels least and disputes most, and a customer who discovers the gate at filing time does not
   renew — they leave, and they say why. Features, support response, new modules and the
   compliance calendar are better levers and are unaffected by Option B.

**Implementation of the recommendation.**

- **R-XXVII-114** The rule-pack channel MUST authorise on **licence validity**, not on support
  window currency. A licensed installation, including one whose AMC has lapsed, receives every
  pack for every jurisdiction it has bound. *Test: an installation with a lapsed support window
  receives a pack and is refused a feature release, in the same polling cycle.*
- **R-XXVII-115** The support window continues to gate application updates, feature
  entitlements, support SLAs and the statutory calendar's advisory and reminder services —
  which are a service, not a correction. *Test: each is refused with a commercial message and a
  renewal path.*
- **R-XXVII-116** A lapsed-support installation receiving a pack MUST see a clear,
  **non-blocking** notice: statutory content is current; features and support are not. *Test:
  the notice appears and blocks nothing.*
- **R-XXVII-117** **If the Product Owner selects Option A instead**, then R-XXVII-114 is
  replaced by a requirement that the refusal is **explicit, visible to the customer, and audited
  on both sides**. The installation must display: *"Statutory content is being withheld. Your
  support contract lapsed on `<date>`. The deductions this system computes may be out of date
  from `<pack effective date>`."* Ametecs' exposure register must record every customer being
  withheld from, which packs, and for how long, and that register must be reviewable by whoever
  owns Ametecs' legal exposure. *Test: the withholding is visible on both sides and no pack is
  ever silently not delivered.* **Silence is the one outcome that is not acceptable under either
  option**, and this requirement stands whichever way the decision goes.

---

## XXVII.6 Release engineering at Ametecs

Everything above depends on Ametecs being able to produce a signed, reproducible, tested
artefact and to know who is running what. SmartPRS 2's pipeline is a Windows `.bat` with
hard-coded developer paths, no CI, two version identities, and a build that does not rewrite the
file the client reads (`I-…` §9 row 34, DEF-33). Its primary release-upload path cannot work
through its own shipped web server, and the controller documents an SFTP workaround at length
(`ReleaseController.php:46-60`; DEF-35).

### XXVII.6.1 The pipeline

```
 commit ─► CI ─────────────────────────────────────────────────────────────────► artefact
            ├─ static analysis: the seven laws (S1), Deptrac module boundaries,
            │  DDL-outside-migrations grep, float-in-Money ban, silent-catch ban,
            │  hard-coded-literal rule, the R-XXVII-002 secret sweep
            ├─ unit + integration + cross-tenant isolation suites
            ├─ migration suite: migrate:fresh parity, expand/contract phase check,
            │  destructive-migration flagging, up-and-down on a production-shaped schema
            ├─ golden payroll replay: a fixed corpus reproduces exact stored values
            ├─ upgrade rehearsal: restore a production-shaped database at N−1 and N−2,
            │  apply this package, run the health check, then roll back and verify
            ├─ package build: deterministic, version stamped once, manifest generated
            ├─ manifest signing: HSM/KMS, two signers, four eyes
            └─ publication: channel, rollout plan, advisories, release notes
```

- **R-XXVII-120** The build and release pipeline MUST be CI-driven, platform-neutral and
  scriptless-on-a-developer-machine. No release may be produced from an individual's
  workstation. *Test: the artefact is byte-reproducible from a clean checkout by CI, and a
  workstation build is not publishable because it cannot be signed.*
- **R-XXVII-121** CI MUST fail the build on: a version mismatch anywhere in the tree, an unsigned
  artefact, a failing test, a static-analysis violation of any of the seven laws, a migration
  without a declared phase, an undeclared feature key, or any hit on the R-XXVII-002 secret
  sweep. *Test: each condition is injected and fails the build.*
- **R-XXVII-122** The upload path for a release artefact MUST work through the shipped
  infrastructure, at the artefact's real size. *Test: the largest supported artefact publishes
  through the standard path with no documented workaround — the direct fix for a 500 MB
  validator behind a 20 MB body limit (`I-…` DEF-35).*

### XXVII.6.2 Reproducible builds

- **R-XXVII-123** A release MUST be **byte-reproducible**: the same commit, the same dependency
  lock and the same toolchain produce an artefact with the same digest, with normalised
  timestamps, ordering and file modes. *Test: two independent CI runs of the same commit produce
  identical digests. This is what lets a second signer verify what they are counter-signing
  rather than trusting the first signer's machine.*
- **R-XXVII-124** Dependencies MUST be pinned by lock file with verified hashes, and the
  dependency set MUST be scanned for known vulnerabilities on every build and on a schedule
  between builds. *Test: an advisory published after a release surfaces against every affected
  released version within one scan cycle.*
- **R-XXVII-125** The artefact MUST carry a **software bill of materials**, published alongside
  it. *Test: a customer security review can be answered from the published SBOM without contact.*

### XXVII.6.3 Artefact signing and key custody

The four key pairs — licence, release, rule pack, key announcement — share one custody regime,
specified in §XXVII.2.3. The release-specific additions:

- **R-XXVII-126** Signing MUST occur inside CI against an HSM or KMS, MUST require two distinct
  authorised signers, and MUST be impossible from a developer machine. *Test: no local
  credential can produce an accepted signature.*
- **R-XXVII-127** Every signing operation MUST write to the Ametecs audit ledger with the
  artefact digest, the signers, the reason and the approval reference. *Test: every published
  artefact traces to two named humans and an approval.*
- **R-XXVII-128** Key rotation MUST be scheduled — not only reactive — at a published cadence,
  and MUST be rehearsed end to end against a staging fleet before it is used in production.
  *Test: the rehearsal is logged with a date, and the date is on the Ametecs operations
  dashboard.*
- **R-XXVII-129** Key revocation MUST be executable within a published time from the decision,
  and the runbook MUST name the quorum. *Test: a revocation drill completes within the published
  time.*

### XXVII.6.4 Staging, canary and rollout

SmartPRS 2's "beta channel" is a client-side illusion: the client sends a channel, the server
reads it into a variable and never uses it, and there is no `releases.channel` column
(`I-…` DEF-10).

```
 build ─► internal ─► staging (Ametecs' own SaaS estate) ─► canary ─► general ─► default
           │            │                                    │          │
           │            │                                    │          └─ auto-update eligible
           │            │                                    └─ named consenting customers,
           │            │                                       a declared soak period
           │            └─ Ametecs runs the release it will ship, in production, first
           └─ every commit
```

| Control | Specification |
|---|---|
| Channels | `internal`, `staging`, `canary`, `general` — **real, server-side, and the authority on what an installation is offered** |
| Ametecs eats first | The SaaS estate runs a release for a declared soak period before any customer is offered it. **A release Ametecs will not run is a release Ametecs will not ship** |
| Canary consent | Canary participation is explicit, per customer, revocable, and recorded — never a silent selection |
| Soak gates | Promotion between channels requires: the soak elapsed, no open severity-1 or severity-2 defect against the release, error and latency budgets within threshold, and no elevated rollback rate |
| Rollback of a release | A release may be **withdrawn**. Withdrawal stops further offers immediately, notifies every installation that applied it, and states the recommended action. It never deletes the artefact |
| Rollout pacing | General availability is paced — a maximum percentage of the installed base per period — so a defect that survives canary does not reach everyone in an hour |

- **R-XXVII-130** Channels MUST be server-side and authoritative. *Test: an installation
  claiming a channel it is not assigned to is offered its assigned channel's releases.*
- **R-XXVII-131** No release may be offered to any customer before it has soaked on Ametecs' own
  production estate for the declared period. *Test: the register shows the soak start and end
  for every published release.*
- **R-XXVII-132** Promotion between channels MUST be gated on declared, measurable criteria and
  MUST be recorded with the evidence. *Test: a promotion with an open severity-1 defect is
  refused by the pipeline, not by a human's judgement.*
- **R-XXVII-133** A release MUST be withdrawable, and withdrawal MUST notify every installation
  that applied it. *Test: withdrawing a release notifies the affected installations within one
  polling cycle and appears on their operations consoles.*

### XXVII.6.5 The customer version register

Ametecs cannot support, patch or warn an installed base it cannot see. Today it cannot: there is
no register, `client_updates` records actions rather than state, and an installation that
firewalls the vendor is indistinguishable from one that was decommissioned (`I-…` DEF-08, Q-09).

| Held per installation | Source |
|---|---|
| Customer, licence id, deployment model, environment purpose (production / DR / test) | Licence issuance |
| Installed version, build id, channel, last update run and its outcome | Proof-of-life (§XXVII.2.7) or the signed status export (R-XXVII-031) |
| Rule-pack versions held, activated, declined — per jurisdiction and statute | Same |
| Seat usage against entitlement; instance count against entitlement | Same |
| Health summary: scheduler, queue, backup age, **last successful restore drill** (Part XXVI §XXVI.8.7) | Same |
| Support window and licence validity dates | Ametecs commercial record |
| Outstanding security advisories applicable to the installed version | Advisory register × installed version |
| Last seen; days since last report; reporting mode (`online` \| `air-gapped/manual`) | Derived |

Derived views that must exist because they are the ones that get used:

- **Unsupported versions** — every installation below the supported window (D-XXVI-10 /
  D-XXVII-10), with the customer contact and the last contact date.
- **Unpatched advisories** — every installation exposed to an open advisory, by severity and
  days outstanding. This is the list that is worked during an incident.
- **Silent installations** — reporting overdue beyond tolerance, with the last-seen date. An
  air-gapped installation is *expected* silence and is shown separately from *unexplained*
  silence.
- **Statutory exposure** — every installation with an announced, unaccepted or undeliverable
  pack, and for how long (feeds Part XVII's exposure register and R-XXVII-117).
- **Operational risk** — failing backups, no restore drill on record, dead scheduler, growing
  queue — the things a customer will call about, before they call.

- **R-XXVII-134** A customer version register MUST exist, be populated by proof-of-life and by
  signed status exports from installations that do not report, and MUST distinguish *expected*
  silence from *unexplained* silence. *Test: an air-gapped customer and a decommissioned server
  are distinguishable in the register.*
- **R-XXVII-135** The register MUST drive an outbound contact workflow: an installation outside
  the supported window, or exposed to an open advisory, generates a tracked contact task with an
  owner and an outcome. *Test: an unsupported installation produces a task, and closing it
  records what the customer was told and what they decided.*
- **R-XXVII-136** The register MUST NOT hold customer business data. It holds versions, counts,
  dates and health states. *Test: a schema review confirms no employee, payroll or tenant
  business data — this is what makes the register acceptable under the customer's own data
  protection posture (Part XXVI D-XXVI-06 governs the opt-in).*

### XXVII.6.6 Security-patch policy

- **R-XXVII-137** Ametecs MUST maintain a **security advisory register**: each advisory with an
  identifier, severity, affected version ranges, fixed-in version, workaround, and disclosure
  timeline. *Test: every security release references at least one advisory, and every advisory
  names its fixed-in version.*
- **R-XXVII-138** Published **target** remediation times per severity MUST exist and be measured
  against actual performance, reported internally each quarter. *Recommendation: critical — fix
  available within 7 days of confirmation, notified within 48 hours of confirmation; high —
  30 days; medium — the next scheduled release; low — the next minor version. These are targets
  the Product Owner must ratify (D-XXVII-08), because they are the ones customers' security
  reviews will ask for.* *Test: the measured distribution is reported and gaps are explained.*
- **R-XXVII-139** A security release MUST be produced for **every version inside the supported
  window**, not only the newest, so that a customer on N−1 is not forced through a feature
  upgrade to receive a fix. *Test: a critical advisory produces a patch release on each
  supported minor line.*
- **R-XXVII-140** Every installation exposed to an open advisory MUST be notified through the
  register's contact workflow, and its exposure MUST be visible on its own operations console.
  *Test: an exposed installation shows the advisory, its severity and the remedy.*
- **R-XXVII-141** A security fix MUST NOT be bundled with feature change where it can be shipped
  alone. *Test: patch releases contain only the fix and its tests — because a customer who must
  accept a feature change to get a security fix will defer the security fix.*

### XXVII.6.7 End-of-life and the support matrix

| Term | Definition |
|---|---|
| **Supported** | Receives feature releases, security fixes, statutory rule packs, and full support response |
| **Maintenance** | Receives security fixes and statutory rule packs. No feature releases. Support is limited to defects and upgrade assistance |
| **End of life** | Receives statutory rule packs only while the licence is valid (per D-XXVII-02 / D-XVII-02, which is not conditioned on the version being supported). No fixes. Support is upgrade assistance only |

**Recommendation for the window (D-XXVII-10, held jointly with Part XXVI D-XXVI-10):** the
current minor and the previous minor are **supported**; the one before that is **maintenance**
for a published period; anything older is **end of life**. Every date is published at the time
the version is released, so a customer knows on day one when their version leaves support.

- **R-XXVII-142** Every released version MUST carry published `supported_until` and
  `maintenance_until` dates, set at release time, and the installation MUST display its own
  dates on its operations console. *Test: an operator can answer "when does my version leave
  support?" without contacting Ametecs.*
- **R-XXVII-143** An end-of-life version MUST continue to receive statutory rule packs while its
  licence is valid, and MUST continue to run. **End of life is not a kill switch.** *Test: an
  end-of-life installation runs, receives packs and computes correctly.*
- **R-XXVII-144** The support matrix — versions × platforms × PHP × database × browser — MUST be
  published, machine-readable, and asserted by CI, so that a combination Ametecs claims to
  support is a combination CI actually tests. *Test: every published combination has a passing
  CI job; a combination with no job cannot be published.*
- **R-XXVII-145** Approaching end of life MUST be announced to the customer through the register
  contact workflow at published lead times, not discovered at a support call. *Test: the
  notification history is on the customer's record.*

---

## XXVII.7 Configuration surface

Tested against the brief's Section 127 standard: nothing in this table requires code, and
nothing outside it is a business decision. Every value SmartPRS 2 compiles in — the 24-hour
revocation interval, the 60-minute download token, the protected-path list, the `SPRS-` key
format, the fingerprint algorithm's inputs, the health check, the grace behaviour — appears
here as data or is deliberately not customer-configurable and is marked so.

**Set by Ametecs, per licence, delivered as signed claims (not editable on the installation):**

| # | What | Where |
|---|---|---|
| 1 | Entitlement set and version | `entitlement_set_ref` claim |
| 2 | Seat entitlement: limit, basis (a seat policy key), enforcement, overage policy | `seat_entitlement` claim |
| 3 | Module grants, and per-module configuration | `module_entitlements` claim |
| 4 | Validity: from, to, perpetual | `validity` claim |
| 5 | Support window: from, to | `support_window` claim |
| 6 | Deployment binding | `deployment_binding` claim |
| 7 | Instance binding mode, maximum instances, self-service release allowance | `instance_binding` claim |
| 8 | Environment grants (production, DR standby, test, training) | `environment_grants` claim |
| 9 | Grace days, read-only-after days, notice lead days, offline tolerance days | `grace` claim |
| 10 | Proof-of-life requirement and interval | `proof_of_life` claim |
| 11 | Jurisdiction scope for the rule-pack channel | `jurisdiction_scope` claim |
| 12 | Free-text notes rendered to the customer | `notes_to_customer` claim |

**Set by the customer, on the installation, without code:**

| # | What | Where | Constrained by |
|---|---|---|---|
| 13 | Update channel (within those the licence permits) | Update policy artefact | Ametecs assigns channel eligibility |
| 14 | Auto-update: enabled, eligible classes, mandatory delay after publication | Update policy artefact | R-XXVII-101 caps it |
| 15 | Maintenance window: days, times, timezone, blackout dates | Update policy artefact | — |
| 16 | Pre-update notification: audiences, lead time, channels | Update policy artefact | Part XIX |
| 17 | Update backup retention: count and age | Update policy artefact | Part XXVI R-XXVI-131 |
| 18 | Whether an update may run unattended at all | Update policy artefact | — |
| 19 | Rule-pack auto-acceptance, per statute per tenant | Statutory policy (Part XVII) | D-XVII-03 |
| 20 | Who is notified of a licence approaching expiry, and at what lead times | Notification policy | R-XXVII-041 sets the minimum |
| 21 | Which roles may initiate an update, accept a pack, activate a licence, release an instance | Roles and permissions (Part XXII) | — |
| 22 | Proof-of-life outbound proxy, if the customer requires egress through one | Installation configuration | — |
| 23 | Health-check thresholds for the post-update gate | Health policy (Part XXVI §XXVI.6) | Blocking checks cannot be disabled |

**Deliberately NOT configurable, by anyone, ever — and why:**

| What | Why |
|---|---|
| Whether signature verification runs | R-XXVII-003. A verification that can be switched off is not a verification |
| The embedded public key set | R-XXVII-001. Compiled and pinned; configurable trust anchors are theatre |
| Whether a database backup is taken before migrating | R-XXVII-086. The step whose absence is the largest gap in SmartPRS 2 |
| Whether the health check gates maintenance exit | R-XXVII-096 |
| Whether the licence gate degrades rather than blocks reads | R-XXVII-038. This is a commitment to the customer, not a setting |
| Whether raw punch ingestion continues while degraded | R-XXVII-040. Evidence is not commercial leverage |
| Whether a licence claim can be supplemented locally | R-XXVII-013 |
| Whether an entitlement decision can be bypassed by a session flag | R-XXVII-043 |

---

## XXVII.8 Data model

Conventions per spine S16 throughout: `snake_case`, plural tables, `_id` foreign keys, `_at`
instants in UTC, `_on` dates, `is_`/`has_` booleans, no database `ENUM` types (status
vocabularies are reference tables), foreign keys with declared `ON DELETE`, every business-unique
combination database-constrained, and no runtime DDL (spine Law 2 — the direct answer to the
**nineteen tables in this area that SmartPRS 2 creates on the request path inside a swallowed
`try/catch`**, `I-…` §2.1, and to `ApprovalService::safeRow()`, which silently drops keys the
live table does not have).

Two scopes appear below and are labelled on every table:

- **Platform-scoped** — lives in the Ametecs vendor installation. No `tenant_id`; these are not
  tenant business tables and hold no tenant business data (spine Law 6's enumerated exception,
  as used in Part XXV §XXV.14).
- **Installation-scoped** — lives in every SmartPRS installation, including on-premise. Where a
  row concerns a tenant it carries `tenant_id NOT NULL`; licence and update state are
  installation facts and are marked so.

### XXVII.8.1 `signing_keys` *(platform-scoped; the public half is compiled into every build)*

*Purpose:* the register of every signing key, its purpose, its validity window and its
revocation state. Its absence in SmartPRS 2 is why rotation invalidates every issued licence.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `key_id` | varchar(64) | N | — | The identifier carried in every signature. Unique |
| `purpose_code` | varchar(32) | N | — | FK → `signing_key_purposes` (`licence`, `release`, `rule_pack`, `key_announcement`) |
| `algorithm_code` | varchar(32) | N | — | FK → `signature_algorithms` (`ed25519`) |
| `public_key` | varbinary(512) | N | — | The verification half |
| `custody_ref` | varchar(255) | N | — | HSM/KMS key reference. **Never private key material** |
| `valid_from_at` | datetime(3) | N | — | |
| `valid_to_at` | datetime(3) | Y | NULL | Retirement date; artefacts signed before it keep verifying |
| `announced_at` | datetime(3) | Y | NULL | When the signed key announcement was published |
| `revoked_at` | datetime(3) | Y | NULL | |
| `revocation_reason` | varchar(500) | Y | NULL | |
| `created_by_user_id` | bigint unsigned | N | — | FK → `users` ON DELETE RESTRICT |
| `created_at` | datetime(3) | N | — | |

PK `id` · Unique `(key_id)` · Index `(purpose_code, valid_from_at)`, `(revoked_at)`
Tenant scope: none (platform) · History: append-only; rows are never updated except to set
`revoked_at`/`valid_to_at`, each audited · Soft delete: never · Audit: **mandatory**, field-level
· PII: none.

### XXVII.8.2 `licences` *(platform-scoped)*

*Purpose:* the commercial licence — one row per customer entitlement relationship, independent
of how many documents have been issued under it.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `licence_uuid` | char(36) | N | — | UUIDv7. **The only identifier used externally** (spine S2) |
| `customer_id` | bigint unsigned | N | — | FK → `customers` ON DELETE RESTRICT |
| `deployment_binding_code` | varchar(24) | N | — | FK → `deployment_models` |
| `status_code` | varchar(24) | N | `pending` | FK → `licence_statuses` (`pending`, `active`, `suspended`, `revoked`, `superseded`) |
| `entitlement_set_id` | bigint unsigned | N | — | FK → `entitlement_sets` ON DELETE RESTRICT |
| `entitlement_set_version_no` | int unsigned | N | — | Pinned; a new set version does not move a licence (R-XXVII-061) |
| `seat_policy_key` | varchar(64) | N | — | The seat policy artefact the count uses (Part XXV §XXV.14.8) |
| `seat_limit` | int unsigned | Y | NULL | NULL only where `seat_unlimited` is true |
| `seat_unlimited` | tinyint(1) | N | 0 | **Explicit** (R-XXVII-052) |
| `seat_enforcement_code` | varchar(16) | N | `hard` | FK → `enforcement_modes` |
| `seat_overage_policy_code` | varchar(16) | N | `block` | FK → `overage_policies` |
| `valid_from_on` | date | N | — | |
| `valid_to_on` | date | Y | NULL | NULL ⇔ `is_perpetual` |
| `is_perpetual` | tinyint(1) | N | 0 | |
| `support_from_on` | date | Y | NULL | Independent of validity (R-XXVII-011) |
| `support_to_on` | date | Y | NULL | |
| `grace_days` | smallint unsigned | N | — | Read and honoured (R-XXVII-042) |
| `read_only_after_days` | smallint unsigned | N | — | |
| `notice_lead_days` | smallint unsigned | N | — | |
| `offline_tolerance_days` | smallint unsigned | Y | NULL | NULL ⇒ proof-of-life not required (air-gapped) |
| `proof_of_life_interval_days` | smallint unsigned | Y | NULL | |
| `instance_mode_code` | varchar(16) | N | `bound` | FK → `instance_binding_modes` |
| `max_instances` | smallint unsigned | N | 1 | |
| `self_release_allowance` | smallint unsigned | N | — | Per rolling year; **enforced** (R-XXVII-027) |
| `jurisdiction_scope_json` | json | N | `[]` | |
| `notes_to_customer` | text | Y | NULL | Rendered verbatim to the customer |
| `sold_edition_code` | varchar(16) | Y | NULL | **Historical sales attribute only. Grants nothing** (§XXVII.3.4) |
| `revoked_at` | datetime(3) | Y | NULL | |
| `revocation_reason` | varchar(500) | Y | NULL | |
| `created_at`, `updated_at` | datetime(3) | N | — | |

PK `id` · Unique `(licence_uuid)` · Index `(customer_id, status_code)`, `(valid_to_on)`,
`(support_to_on)`, `(status_code, valid_to_on)`
Tenant scope: none (platform) · History: every change produces a new `licence_documents` row;
the licence row itself is the current commercial state and is fully audited · Soft delete: never
— a licence is `revoked` or `superseded` · Audit: **mandatory**, field-level · PII: `customers`
holds the contact PII; this table holds none directly.

### XXVII.8.3 `licence_documents` *(platform-scoped)*

*Purpose:* every signed licence document ever issued, immutable. This is what makes `issue_seq`
monotonicity enforceable and a re-issue auditable.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `licence_id` | bigint unsigned | N | — | FK → `licences` ON DELETE RESTRICT |
| `issue_seq` | int unsigned | N | — | Monotonic per licence (R-XXVII-046) |
| `claims_version` | varchar(16) | N | — | |
| `claims_json` | json | N | — | The exact canonical claims that were signed |
| `claims_digest` | char(64) | N | — | SHA-256 of the JCS canonicalisation |
| `signing_key_id` | bigint unsigned | N | — | FK → `signing_keys` ON DELETE RESTRICT |
| `signature` | varbinary(128) | N | — | Detached Ed25519 |
| `document_text` | mediumtext | N | — | The `.slic` file exactly as delivered |
| `issue_reason_code` | varchar(32) | N | — | FK → `licence_issue_reasons` (`initial`, `renewal`, `seat_change`, `entitlement_change`, `rebind`, `correction`) |
| `issued_by_user_id` | bigint unsigned | N | — | FK → `users` ON DELETE RESTRICT |
| `approved_by_user_id` | bigint unsigned | Y | NULL | Required for non-standard claims |
| `issued_at` | datetime(3) | N | — | |
| `delivered_at` | datetime(3) | Y | NULL | |
| `activated_at` | datetime(3) | Y | NULL | Reported back by the installation |

PK `id` · Unique `(licence_id, issue_seq)`, `(claims_digest)` · Index `(licence_id, issued_at DESC)`
Tenant scope: none · History: **append-only; no update path exists** · Soft delete: never ·
Audit: mandatory · PII: the claims carry the customer legal name and a contact reference —
classified **PII-Low**, business contact.

### XXVII.8.4 `licence_instances` *(platform-scoped)*

*Purpose:* the instances bound to a licence, their environment purpose and their liveness. The
successor to `licence_devices`, with the refusal actually enforced.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `licence_id` | bigint unsigned | N | — | FK → `licences` ON DELETE **CASCADE** (fixing the orphan at `I-…` DEF-40) |
| `installation_uuid` | char(36) | N | — | UUIDv7, generated on the installation |
| `instance_public_key` | varbinary(64) | N | — | Ed25519; the thing possession is proved of |
| `environment_purpose_code` | varchar(16) | N | `production` | FK → `environment_purposes` |
| `host_facts_digest` | char(64) | N | — | |
| `host_facts_json` | json | N | — | The weighted facts, for drift evaluation and support |
| `status_code` | varchar(16) | N | `active` | FK → `instance_statuses` (`active`, `rebinding`, `released`, `blocked`) |
| `bound_at` | datetime(3) | N | — | |
| `released_at` | datetime(3) | Y | NULL | |
| `released_by_user_id` | bigint unsigned | Y | NULL | FK → `users` |
| `last_seen_at` | datetime(3) | Y | NULL | |
| `last_reported_version` | varchar(32) | Y | NULL | |
| `drift_events_count` | int unsigned | N | 0 | |
| `last_drift_at` | datetime(3) | Y | NULL | |

PK `id` · Unique `(licence_id, installation_uuid)`, `(instance_public_key)` ·
Index `(licence_id, status_code)`, `(last_seen_at)`
Tenant scope: none · History: bindings are never deleted; a release sets `released_at` ·
Soft delete: n/a · Audit: mandatory · PII: hostname inside `host_facts_json` — **PII-Low**,
minimised and retained per the register's retention class.

### XXVII.8.5 `licence_events` *(platform-scoped)*

`licence_id`, `licence_instance_id` (nullable), `event_type_code` (FK → `licence_event_types`:
`issued`, `delivered`, `activated`, `bound`, `rebind_requested`, `rebind_granted`,
`binding_refused`, `released`, `renewed`, `suspended`, `revoked`, `proof_of_life`,
`verification_failed`, `duplicate_identity_detected`, `degraded`, `restored`), `detail_json`,
`source_ip`, `occurred_at`, `recorded_at`, `audit_entry_id`.
PK `id` · Index `(licence_id, occurred_at DESC)`, `(event_type_code, occurred_at DESC)` ·
Append-only · Audit: this **is** the licence audit trail and is additionally chained into the
platform ledger (spine S14) · PII: source IP — **PII-Low**, retention-classed.

### XXVII.8.6 `entitlement_sets`, `entitlement_set_versions`, `entitlement_features`, `entitlement_limits`, `entitlement_quotas` *(platform-scoped)*

Grammar per §XXVII.3.1; jointly owned with Part XXV §XXV.14.7, which states the same tables from
the subscription side. `entitlement_set_versions` carries the spine S4 envelope (`version_no`,
`status_code`, `effective_from`, `effective_to`, `published_at`, `superseded_by_version_id`,
`checksum`). Child rows: `entitlement_features(entitlement_set_version_id, feature_key,
is_granted, config_json)`, `entitlement_limits(…, limit_key, limit_value, enforcement_code)`,
`entitlement_quotas(…, quota_key, quota_value, window_code, on_exceed_code)`.
Unique `(entitlement_set_version_id, feature_key)` and equivalents on limits and quotas ·
`feature_key` FK → `platform_features`, the **generated** catalogue of R-XXVII-060, so an
entitlement cannot grant a feature no module declares.

### XXVII.8.7 `releases`, `release_artefacts`, `release_manifest_entries`, `release_signatures` *(platform-scoped)*

| Table | Key columns |
|---|---|
| `releases` | `release_uuid`, `product_code`, `version`, `build_id`, `channel_code` (FK → `release_channels`), `artefact_type_code`, `min_from_version`, `max_from_version`, `requires_php`, `requires_db_version`, `dependency_lock_digest`, `rule_pack_floor_json`, `security_severity_code`, `notes_md`, `soak_started_at`, `soak_completed_at`, `published_at`, `withdrawn_at`, `withdrawal_reason`, `supported_until_on`, `maintenance_until_on`. Unique `(product_code, version)`, `(build_id)` |
| `release_artefacts` | `release_id`, `filename`, `size_bytes`, `sha256`, `storage_ref`, `manifest_json`, `manifest_digest`. Unique `(release_id, sha256)`. **The checksum is computed from the bytes on disk and never accepted from a form** — SmartPRS 2 gets this right (`ReleaseController.php:109`) and it is preserved |
| `release_manifest_entries` | `release_artefact_id`, `path`, `sha256`, `mode`, `entry_kind_code` (`file`, `protected`, `removed`). Unique `(release_artefact_id, path)`. Index `(entry_kind_code)` |
| `release_signatures` | `release_artefact_id`, `signing_key_id`, `signature`, `signer_user_id`, `signed_at`, `signature_role_code` (`primary`, `counter`). Unique `(release_artefact_id, signature_role_code)`. **Two rows required before publication** (R-XXVII-073) |
| `release_channel_assignments` | `licence_id`, `channel_code`, `assigned_by_user_id`, `assigned_at`, `consent_recorded_at` (required for `canary`). Unique `(licence_id)` |
| `release_migrations` | `release_id`, `migration_name`, `phase_code` (`expand`/`contract`), `is_reversible`, `is_destructive`, `estimated_rows`. Unique `(release_id, migration_name)` |

All append-only except `releases`, whose `withdrawn_at`, `soak_*` and `published_at` transitions
are audited. Tenant scope: none. PII: none.

### XXVII.8.8 `installations` — the customer version register *(platform-scoped)*

*Purpose:* the register of §XXVII.6.5. One row per installation, updated by proof-of-life or by
an imported signed status export.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `installation_uuid` | char(36) | N | — | Matches `licence_instances.installation_uuid` |
| `licence_id` | bigint unsigned | N | — | FK → `licences` ON DELETE RESTRICT |
| `environment_purpose_code` | varchar(16) | N | `production` | |
| `reporting_mode_code` | varchar(16) | N | `online` | FK → `reporting_modes` (`online`, `manual_export`, `air_gapped`) — **expected silence is a declared state** (R-XXVII-134) |
| `installed_version` | varchar(32) | Y | NULL | Referenced by Part XXVI §XXVI.12.1 |
| `installed_build_id` | varchar(64) | Y | NULL | |
| `channel_code` | varchar(16) | Y | NULL | |
| `last_update_run_uuid` | char(36) | Y | NULL | |
| `last_update_result_code` | varchar(24) | Y | NULL | |
| `rule_packs_json` | json | N | `{}` | Held / activated / declined, per jurisdiction+statute |
| `seats_used`, `seats_licensed` | int unsigned | Y | NULL | |
| `instances_used`, `instances_licensed` | smallint unsigned | Y | NULL | |
| `health_json` | json | Y | NULL | Scheduler, queue, backup age, **last successful restore drill** |
| `audit_chain_head` | char(64) | Y | NULL | For the reconciliation of R-XXVII-055 |
| `last_issue_seq_seen` | int unsigned | Y | NULL | Anti-rollback reconciliation |
| `first_seen_at`, `last_seen_at` | datetime(3) | Y | NULL | |
| `report_overdue_since_at` | datetime(3) | Y | NULL | Derived; drives the silent-installations view |

PK `id` · Unique `(installation_uuid)` · Index `(licence_id)`, `(installed_version)`,
`(last_seen_at)`, `(reporting_mode_code, report_overdue_since_at)`
Tenant scope: none · History: `installation_reports` below holds every report; this row is the
current state · Soft delete: never · Audit: changes audited · **PII: none — the register holds
versions, counts, dates and health states only** (R-XXVII-136).

### XXVII.8.9 `installation_reports` *(platform-scoped)*

`installation_id`, `received_at`, `report_kind_code` (`proof_of_life`, `status_export`),
`payload_json`, `payload_signature`, `instance_public_key_id`, `signature_status_code`,
`anomalies_json`, `response_signature`. PK `id` · Index `(installation_id, received_at DESC)` ·
Append-only · Retention-classed. PII: none.

### XXVII.8.10 `security_advisories`, `advisory_exposures` *(platform-scoped)*

`security_advisories`: `advisory_ref`, `severity_code`, `title`, `summary_md`,
`affected_from_version`, `affected_to_version`, `fixed_in_version`, `workaround_md`,
`confirmed_at`, `published_at`, `disclosure_at`. Unique `(advisory_ref)`.
`advisory_exposures`: `advisory_id`, `installation_id`, `exposed_since_at`, `resolved_at`,
`deferral_reason`, `contact_task_id`. Unique `(advisory_id, installation_id)` ·
Index `(resolved_at, exposed_since_at)` — this index is the incident worklist of R-XXVII-140.

### XXVII.8.11 `licence_store` *(installation-scoped; single row)*

*Purpose:* the verified licence held by this installation. **It is a store of the signed
document, not of derived claims** — this is the direct replacement for
`settings(tenant_id=0, key='licence')`, whose blob is what SmartPRS 2 actually trusts.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | tinyint unsigned | N | 1 | PK; `CHECK (id = 1)` — one row by construction |
| `document_text` | mediumtext | Y | NULL | The `.slic` exactly as received |
| `claims_digest` | char(64) | Y | NULL | Recomputed on every verification |
| `signing_key_id_ref` | varchar(64) | Y | NULL | The `key_id` from the signature |
| `issue_seq` | int unsigned | Y | NULL | |
| `highest_issue_seq_seen` | int unsigned | N | 0 | **Never decreases.** The anti-rollback high-water mark (R-XXVII-046) |
| `imported_at`, `activated_at` | datetime(3) | Y | NULL | |
| `last_verified_at` | datetime(3) | Y | NULL | |
| `last_verification_result_code` | varchar(32) | Y | NULL | |
| `gate_state_code` | varchar(24) | N | `unlicensed` | FK → `licence_gate_states`; **derived, never authoritative** |
| `gate_state_since_at` | datetime(3) | Y | NULL | |
| `last_trusted_server_time_at` | datetime(3) | Y | NULL | From a signed acknowledgement (R-XXVII-035) |
| `last_proof_of_life_at`, `next_proof_of_life_due_at` | datetime(3) | Y | NULL | |

PK `id` · Tenant scope: **installation fact, not tenant-scoped**; a multi-tenant private-cloud
install has one licence covering the tenants named in `tenant_binding` · History: superseded
documents are retained in `licence_store_history` (same shape, append-only) · Soft delete: never
· Audit: mandatory · PII: customer legal name inside the claims — **PII-Low**.

> **Migration note.** `gate_state_code` exists for display and for cheap logging. **No gate
> reads it.** A gate calls the verifier, which reads `document_text`, verifies the signature and
> recomputes. Editing this row changes what a screen shows and nothing that is enforced
> (R-XXVII-053).

### XXVII.8.12 `licence_verifications` *(installation-scoped)*

`verified_at`, `trigger_code` (`boot`, `scheduled`, `gate`, `import`, `activation`,
`proof_of_life`), `result_code`, `failure_class_code` (`deterministic`/`transient`),
`detail`, `claims_digest`, `duration_ms`, `audit_entry_id`. PK `id` · Index
`(verified_at DESC)`, `(result_code, verified_at DESC)` · Append-only · Retention-classed.
This table is what makes fail-open events **countable and visible**, which SmartPRS 2's six
silent catches are not.

### XXVII.8.13 `update_runs` *(installation-scoped)*

*Purpose:* one row per update attempt. The state machine of §XXVII.4.5 is persisted here, and
this row is what the recovery console reads on boot.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `run_uuid` | char(36) | N | — | UUIDv7; the external identifier and the vendor-side correlation key |
| `from_version`, `to_version` | varchar(32) | N | — | |
| `release_uuid` | char(36) | N | — | |
| `artefact_sha256` | char(64) | N | — | |
| `manifest_digest` | char(64) | N | — | |
| `signature_verified_at` | datetime(3) | Y | NULL | |
| `state_code` | varchar(24) | N | `precheck` | FK → `update_run_states` |
| `state_since_at` | datetime(3) | N | — | Drives the stalled-run alert |
| `initiated_by_code` | varchar(16) | N | — | `operator` \| `policy` |
| `initiated_by_user_id` | bigint unsigned | Y | NULL | FK → `users` ON DELETE RESTRICT |
| `db_backup_ref` | varchar(500) | Y | NULL | |
| `db_backup_digest` | char(64) | Y | NULL | |
| `db_backup_asserted_at` | datetime(3) | Y | NULL | **Set only after the restore-readiness assertion passes** (R-XXVII-086) |
| `code_backup_ref` | varchar(500) | Y | NULL | |
| `staging_ref` | varchar(500) | Y | NULL | |
| `schema_digest_before`, `schema_digest_after` | char(64) | Y | NULL | Proves a rollback restored the schema (R-XXVII-087) |
| `migrations_planned`, `migrations_applied` | int unsigned | N | 0 | |
| `health_result_json` | json | Y | NULL | |
| `outcome_code` | varchar(24) | Y | NULL | `completed` \| `refused` \| `rolled_back` \| `rollback_failed` |
| `failure_reason` | varchar(1000) | Y | NULL | Operator-readable; **never raw migration output** (SEC-12) |
| `started_at`, `finished_at` | datetime(3) | Y | NULL | |

PK `id` · Unique `(run_uuid)` · Index `(state_code, state_since_at)`, `(started_at DESC)` ·
**Partial unique constraint: at most one run in a non-terminal state** (R-XXVII-079 precondition
10 becomes a database constraint, not a check) · Tenant scope: installation fact · History:
append-only per run; the state column advances and every advance is recorded in
`update_run_steps` · Soft delete: never · Audit: mandatory · PII: none.

### XXVII.8.14 `update_run_steps` *(installation-scoped)*

`update_run_id`, `step_code`, `attempt_no`, `started_at`, `finished_at`, `result_code`,
`detail`, `bytes_processed`, `audit_entry_id`. PK `id` ·
Unique `(update_run_id, step_code, attempt_no)` · Index `(update_run_id, started_at)` ·
Append-only. **Written before the step begins and again when it completes** (R-XXVII-082), which
is what makes an interrupted run identifiable.

### XXVII.8.15 `update_backups` *(installation-scoped)*

`update_run_id`, `backup_kind_code` (`database`/`code`), `storage_ref`, `size_bytes`, `digest`,
`encrypted`, `created_at`, `asserted_at`, `retained_until_on`, `pruned_at`, `prune_reason`.
PK `id` · Index `(retained_until_on)`, `(update_run_id)`. `retained_until_on` is computed from
the update policy's retention (Part XXVI R-XXVI-131) — **the direct fix for backups that are
never pruned** (`I-…` DEF-31).

### XXVII.8.16 `update_policies` *(installation-scoped; a spine S4 artefact)*

Stored as a `config_artefact` of type `update_policy` with typed child rows: `channel_code`,
`auto_update_enabled`, `eligible_classes` (value-set references), `publication_delay_hours`,
`maintenance_windows` (day, from, to, timezone), `blackout_dates`, `notification_audiences`,
`notification_lead_hours`, `backup_retention_count`, `backup_retention_days`,
`unattended_permitted`. Resolved via `resolve('update_policy', key, scope, as_of)` (spine S4).
Every item in §XXVII.7 rows 13–18 lives here.

### XXVII.8.17 Reference tables

`signing_key_purposes`, `signature_algorithms`, `deployment_models`, `licence_statuses`,
`licence_issue_reasons`, `licence_event_types`, `licence_gate_states`, `instance_binding_modes`,
`instance_statuses`, `environment_purposes`, `enforcement_modes`, `overage_policies`,
`reporting_modes`, `release_channels`, `artefact_types`, `update_run_states`, `update_step_codes`,
`update_outcome_codes`, `backup_kinds`, `severity_levels`, `platform_features` (generated —
R-XXVII-060).

All: `code` PK, `label`, `sort_order`, `is_active`, effective range where the vocabulary can
change. **No database `ENUM` anywhere** — spine S16, against the twelve live cases in SmartPRS 2
where code writes a value the column's `ENUM` forbids.

---

## XXVII.9 API surface

Two distinct surfaces. Spine S17 applies to both: OpenAPI-generated, additive within a major
version, idempotency keys on every unsafe method, cursor pagination, a consistent error
envelope, and an explicit policy binding on every route.

### XXVII.9.1 The Ametecs distribution service — consumed by customer installations

Machine-to-machine only, authenticated by the **instance key** (a signed request), never by a
bearer secret the installation stores in plaintext. Every response that carries a decision is
itself signed.

| Method | Path | Purpose | Auth / permission |
|---|---|---|---|
| `POST` | `/api/v3/distribution/activations` | Request a licence document for an installation | Signed activation request; licence key |
| `POST` | `/api/v3/distribution/activations/offline` | Submit an offline activation request block; returns the document (portal-mediated) | Portal session, entitled customer contact |
| `POST` | `/api/v3/distribution/proof-of-life` | Report state; returns a signed acknowledgement carrying server time, licence status, revocation digest, latest `issue_seq`, notices | Instance-key signature |
| `POST` | `/api/v3/distribution/rebindings` | Request a re-binding after a host change | Instance-key signature |
| `POST` | `/api/v3/distribution/instances/{uuid}/release` | Self-service release of a stale instance, within the allowance | Instance-key signature + customer contact |
| `GET` | `/api/v3/distribution/updates/check` | Offered release for this installation's channel and version; response signed and nonce-bound | Instance-key signature |
| `GET` | `/api/v3/distribution/updates/{release_uuid}/package` | Stream the artefact | Short-lived token bound to installation, release and nonce; **never the licence key in a URL** — SmartPRS 2 gets this right (`UpdateServerController.php:272-277`) and it is preserved |
| `GET` | `/api/v3/distribution/rulepacks/check` | Packs available for the bound jurisdictions | Instance-key signature; **licence validity, not support window** (R-XXVII-114) |
| `GET` | `/api/v3/distribution/rulepacks/{pack_uuid}` | Stream a pack | Same |
| `GET` | `/api/v3/distribution/keys` | The current signed key-set announcement and revocation list | Instance-key signature |
| `GET` | `/api/v3/distribution/advisories` | Advisories applicable to the reported version | Instance-key signature |
| `POST` | `/api/v3/distribution/status-export` | Import a signed status export from an air-gapped installation | Portal session, entitled customer contact |

### XXVII.9.2 Ametecs internal — the licence and release console

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET/POST` | `/api/v3/vendor/licences` | List, issue | `licence.view`, `licence.issue` |
| `POST` | `/api/v3/vendor/licences/{uuid}/reissue` | Renewal, seat change, entitlement change, correction | `licence.reissue` (+ `licence.approve` for non-standard claims) |
| `POST` | `/api/v3/vendor/licences/{uuid}/suspend` \| `/revoke` | Commercial enforcement | `licence.revoke` — maker-checker (spine S8) |
| `GET/POST/DELETE` | `/api/v3/vendor/licences/{uuid}/instances` | Inspect and release bindings | `licence.instance.manage` |
| `GET` | `/api/v3/vendor/licences/{uuid}/events` | The licence audit trail | `licence.view` |
| `GET/POST` | `/api/v3/vendor/entitlement-sets` | Author and publish entitlement set versions | `entitlement.publish` — maker-checker |
| `GET/POST` | `/api/v3/vendor/releases` | Register, publish, withdraw | `release.publish` — **two signers required** |
| `POST` | `/api/v3/vendor/releases/{uuid}/promote` | Channel promotion against the gates | `release.promote` |
| `GET` | `/api/v3/vendor/installations` | The version register and its derived views | `register.view` |
| `GET` | `/api/v3/vendor/installations/anomalies` | Duplicate identities, stalled chains, inconsistent counters | `register.view` |
| `GET/POST` | `/api/v3/vendor/advisories` | The advisory register and exposure worklist | `advisory.manage` |
| `GET` | `/api/v3/vendor/signing-keys` | Key register; **no create, no export, no delete** (R-XXVII-017) | `key.view` |

### XXVII.9.3 Customer-facing — on every installation

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/api/v3/licence` | The full verified claim set, gate state, next transition date, seat and instance usage | `licence.view`; **reachable in every degraded state** (R-XXVII-012) |
| `POST` | `/api/v3/licence/activate` | Online or offline activation | `licence.activate`; **allow-listed for the unlicensed and first-run states** (R-XXVII-023) |
| `GET` | `/api/v3/licence/activation-request` | Produce the offline activation request block | `licence.activate` |
| `POST` | `/api/v3/licence/rebind` | Request or complete a re-binding | `licence.activate` |
| `GET` | `/api/v3/licence/status-export` | The signed status export for an air-gapped install | `licence.view` |
| `GET` | `/api/v3/licence/verifications` | The verification log, including fail-open events | `licence.view` |
| `GET` | `/api/v3/entitlements` | Resolved features, limits and quotas, with the trace | authenticated |
| `GET` | `/api/v3/updates/available` | The offered release, its notes, its preconditions and their results | `update.view` |
| `POST` | `/api/v3/updates/runs` | Start an update | `update.apply` — **maker-checker configurable** |
| `GET` | `/api/v3/updates/runs/{uuid}` | Run state, steps, progress | `update.view` |
| `POST` | `/api/v3/updates/runs/{uuid}/resume` \| `/rollback` | Recovery actions | `update.apply` |
| `GET` | `/updates/progress?t=<token>` | **Framework-free**, token-gated progress, available before any framework is bootable | one-time token; no schema, table or connection detail (SEC-12) |
| `GET/PUT` | `/api/v3/updates/policy` | The update policy artefact | `update.policy.manage` |
| `GET` | `/api/v3/updates/history` | Every run, outcome and backup | `update.view` |
| `GET` | `/api/v3/rulepacks` | Held, activated, declined, announced-and-outstanding | `statutory.pack.view` |
| `POST` | `/api/v3/rulepacks/import` | Side-load a signed pack | `statutory.pack.import` |
| `POST` | `/api/v3/rulepacks/{uuid}/accept` \| `/decline` | Acceptance flow (Part XVII) | `statutory.pack.accept` |

- **R-XXVII-146** Every distribution endpoint MUST authenticate by instance-key signature, and
  no endpoint may accept a long-lived shared secret. *Test: no credential the installation
  stores can be replayed from another host.*
- **R-XXVII-147** Every response carrying a trust decision MUST be signed and MUST bind the
  request nonce and the installation identity. *Test: a captured response replayed to another
  installation is refused.*
- **R-XXVII-148** The update progress endpoint MUST NOT expose migration output, schema names,
  table names, file paths or connection strings. *Test: a failed migration's progress response
  contains a reference code only, and the detail is in the local log — the direct fix for
  `updater.php:307-311` + `update-status.php:44-52`, which serve 400 characters of migration
  failure verbatim to anyone holding the poll token (`I-…` SEC-12).*
- **R-XXVII-149** Every public and machine-facing endpoint in this part MUST be rate-limited.
  *Test: no unthrottled endpoint exists — against `I-…` SEC-11, where the signup, quote-complete
  and licence-complete endpoints carry no throttle while every other public POST in the same
  file does.*

---

## XXVII.10 Events

Emitted through the platform event bus; consumed as noted. Naming per Part XIX.

| Event | Emitted when | Consumed by |
|---|---|---|
| `licence.activated` | A licence document is verified and bound | Operations console, register, notification engine, audit |
| `licence.reissued` | A new document is imported | Entitlement cache invalidation, notification |
| `licence.entitlement_changed` | The resolved entitlement set differs from the previous | Navigation refresh, API capability document, customer notice naming what changed |
| `licence.expiring` | `notice_lead_days` reached | Notification (Part XIX), console banner |
| `licence.grace_entered`, `licence.restricted_entered`, `licence.read_only_entered` | Each degradation transition | Notification, audit, register |
| `licence.restored` | A degraded installation returns to `active` | Notification, audit |
| `licence.revoked` | Revocation observed locally | Degradation scheduler, audit |
| `licence.verification_failed` | Any verification failure, with its class | Alerting (Part XXXI), audit, register anomaly feed |
| `licence.fail_open` | A transient failure granted access | **Counted metric**, alert, register — the event SmartPRS 2's six silent catches do not emit |
| `licence.binding_mismatch` | Host drift above threshold | Operations console, register |
| `licence.duplicate_identity_detected` | Two instances report one identity | **Vendor anomaly report** (R-XXVII-055) |
| `licence.instance_released` | Self-service or vendor release | Audit, allowance counter |
| `licence.seat_threshold_reached` | Usage crosses a configured fraction of entitlement | Notification, register |
| `update.available` | A release is offered | Console, notification |
| `update.started`, `update.step_completed`, `update.completed` | Run progress | Console, audit, register |
| `update.failed` | Any failure that ends a run | Alerting, audit, register |
| `update.rolled_back` | A rollback completed | Alerting, audit, register |
| `update.rollback_failed` | The terminal state | **Critical alert locally and to Ametecs** |
| `update.stalled` | A run non-terminal beyond threshold | Critical alert |
| `update.health_check_failed` | The gate refused | Alerting, audit |
| `update.release_withdrawn` | Ametecs withdrew an applied release | Console, notification |
| `rulepack.available` | A pack is announced (Part XVII) | Console, notification |
| `rulepack.blocked_by_platform_version` | The pack needs a newer build | Console, register, vendor exposure register |
| `rulepack.activated`, `rulepack.declined` | Part XVII's flow | Tax configuration refresh (Part XXV consumes `rulepack.activated`), audit |
| `rulepack.deactivated_by_rollback` | An application rollback invalidated a pack | Console, audit, affected-period notice |
| `advisory.exposure_opened`, `advisory.exposure_resolved` | Register reconciliation | Vendor worklist |
| `proof_of_life.overdue` | Reporting past tolerance | Register, alerting |

---

## XXVII.11 Explainability *(spine S15)*

Two questions must be answerable in the UI, at the point the customer asks them.

**"Why can I not use this?"**

```
Feature            recruitment.volume_hiring
Decision           NOT AVAILABLE
Source             Licence 018f… (on-premise), issue_seq 7, imported 14-Aug-2026
Entitlement set    "Standard on-premise" v4  (effective 01-Apr-2026 →)
Feature grant      not present in this set version
Licence validity   01-Apr-2026 → perpetual        Support window  → 31-Mar-2027 (active)
Gate state         active
What would change  a licence re-issue naming an entitlement set that grants this feature
Contact            <from the licence's notes_to_customer / customer contact record>
```

**"Why is the product read-only?"**

```
Gate state         READ_ONLY since 02-Sep-2026 04:00
Reason             offline tolerance elapsed
Detail             last successful proof-of-life 19-Jul-2026; tolerance 45 days
                   (licence claim grace.offline_tolerance_days)
Announced          17-Aug-2026 (notice), 26-Aug-2026 (grace), 02-Sep-2026 (read-only)
Still available    sign in · view · search · report · export · download every payslip,
                   challan and return already produced · full data export · backup and
                   restore · raw punch ingestion · licence activation and renewal
Not available      hire · edit · approve · run payroll · apply leave · publish configuration
                   · import · ESS and MSS transactions
To restore         restore connectivity, or import a licence document (offline path)
```

**And for an update:**

```
Update run  9f2c…    3.2.4 → 3.3.0     started 03-Sep-2026 01:00
  precheck        pass   12 of 12
  download        pass   84.1 MB
  verify          pass   signature + countersignature, key smartprs-release-2026a
  stage           pass   2,914 files, all hashes matched
  backup_db       pass   1.2 GB, restore-readiness asserted, digest 4a7f…
  backup_code     pass   payload + version identity
  maintenance     pass
  swap_code       pass   2,914 written, 3 removed (manifest removed_paths)
  dependencies    skip   dependency_lock_digest unchanged
  migrate         FAIL   migration 2026_09_01_add_pt_band_index (expand) exit 1
  → rollback      pass   code restored (remove-then-restore), database restored from 4a7f…
                         schema digest after rollback == schema digest before update
  outcome         rolled_back — the installation is on 3.2.4 with its original schema
  reference       UPD-9f2c-014   (the migration output is in the local updater log)
```

- **R-XXVII-150** Every licence, entitlement and update decision MUST carry a trace naming the
  artefact, its version, the claim or grant consulted, the arithmetic where dates are involved,
  and what would change the outcome. A gate returning a decision without a trace is a
  programming error that throws (spine Law 4). *Test: every decision surface renders the trace.*
- **R-XXVII-151** The trace MUST be reachable in every degraded state, including `read_only` and
  `unlicensed`. *Test: an unlicensed installation explains why it is unlicensed.*

---

## XXVII.12 Migration implication

| SmartPRS 2 artefact | Disposition | Detail |
|---|---|---|
| `licences`, `licence_events`, `licence_devices` | **Migrate** as commercial history | Become `licences` / `licence_events` / `licence_instances`. The `SPRS-` key is retained as a lookup alias for support, and is **not** a credential in v3 |
| `onprem_clients`, `onprem_payments` | Migrate to the customer and commercial records (Part XXV owns the money side) | `edition` migrates as `licences.sold_edition_code` — a **historical sales attribute that grants nothing** (§XXVII.3.4) |
| `settings(tenant_id=0, key='licence')` | **Do not migrate** | It is the forgeable blob. **Every installation re-activates on cutover** and receives a signed document. Restates Part XXVI §XXVI.15 |
| `license.lic` files | **Do not carry forward** | v3 licences are Ed25519 `.slic` documents under a new key regime. Every live customer is issued a new document during the cutover window |
| `storage/app/.machine_fp` | **Delete** | Replaced by the instance keypair; the file is the SEC-09 bypass |
| The RSA key pair | **Retire** | Whether it was a development pair is D-XXVII-03. Either way, v3 does not use it. **If it was a development pair, the retirement is also a remediation and its window is not negotiable** |
| `SMARTPRS_LICENCE_SECRET` and the SPRSX1 code | **Delete, and treat as a shipped-secret incident if D-XXVII-04 confirms distribution** | `makeOfflineKey`, `verifyOfflineKey`, `offlineSecret`, `b64u`, `b64uDecode`, `OFFLINE_PREFIX`, `isOfflineKey`, `activateOfflineKey`, `offlineKey()`, the `licence_secret` config key and the `session('offline_key')` view block. No successor exists, by R-XXVII-002 |
| `Edition::{DNA, L2_MODULES, BLOCK_DNA, BLOCK_L2}` | **Delete** | Kept "for reference / easy revert" and describing a product that was not shipped. The entitlement catalogue replaces them, generated from the modules (R-XXVII-060) |
| `EditionGuard`, `LicenseGate` | **Replace** with one entitlement/licence middleware driven by route attributes | Half of `EditionGuard` is unreachable; `LicenseGate` has six fail-open paths, a session bypass and a missing allow-list entry |
| `releases`, `release_grants`, `client_updates` | **Migrate** as history | Historical version identity is retained even where it is inconsistent, with the inconsistency recorded rather than silently corrected |
| `storage/app/updates/backup_*` | **Do not migrate. Prune** | Unbounded accumulated code backups on customers' payroll servers |
| `version.json` + `config('smartprs.version')` | **Reconcile to one identity at cutover** | R-XXVII-090. Where the two disagree, the installation's true running code decides and the discrepancy is recorded |
| `updater/updater.php`, `update-status.php` | **Rewrite, preserving four properties** | The detached process, the protected-path discipline, remove-then-restore rollback, and the framework-free token-gated progress endpoint are all correct and are carried forward |

**Cutover gates.**

- **R-XXVII-160** No SmartPRS 3 installation may serve production traffic while holding a
  SmartPRS 2 licence artefact, a `license.lic`, a `.machine_fp` or a `settings(tenant_id=0,
  key='licence')` row. *Test: the migration verifier fails on the presence of any of the four.*
- **R-XXVII-161** Every live on-premise customer MUST have a v3 licence document issued,
  delivered and activated before their cutover completes, and the version register MUST show
  every one of them reporting. *Test: the register's silent-installations view is empty of
  cut-over customers, or every entry has a recorded reason.*

---

## XXVII.13 Edge cases and failure states

| # | Situation | Required behaviour |
|---|---|---|
| 1 | Licence file pasted with mangled line breaks | "The file was damaged in transfer" — distinguished from an invalid signature (R-XXVII-014), because the two send a support ticket to two different places |
| 2 | Clock set forward a decade, then back | Trusted time is `max(local, last signed server time)`; neither direction moves an expiry (R-XXVII-035) |
| 3 | Licence expires **during** a payroll run | The run completes. Degradation applies to *starting* new work, never to abandoning work in progress mid-transaction |
| 4 | Licence expires while a workflow instance is in flight | The instance completes on its own terms; approvals are not writes to business data for this purpose. Configurable per the degradation policy, with the default stated |
| 5 | Host restored from backup onto new hardware after a disaster | Rebinding window opens; the product runs throughout; the restore runbook (Part XXVI §XXVI.8.6) includes rebinding as a step |
| 6 | Two instances started from one VM image | Both report the same `installation_uuid`; the second is refused a binding, the duplicate is an anomaly at Ametecs, and the operator is told which instance holds the binding |
| 7 | Customer's egress proxy strips the signed response | Verification fails as **transient**, not deterministic; the tolerance applies; the operations console names the proxy symptom |
| 8 | Update begins, disk fills during `BACKUP_DB` | Refused before maintenance mode; nothing changed; the shortfall is named in bytes |
| 9 | Power loss between `SWAP_CODE` and `MIGRATE` | Recovery console on boot; default offered action is resume (code state is knowable from staging digests) |
| 10 | Power loss **during** `MIGRATE` | Recovery console; **default offered action is rollback**; the ledger names the migration in flight (R-XXVII-093) |
| 11 | Rollback's database restore itself fails | `ROLLBACK_FAILED`; maintenance stays on; critical alert both sides; runbook displayed with paths, digests and commands |
| 12 | Health check fails only intermittently | The check is retried to its configured attempt count before the gate decides; a flapping result is reported as flapping, not as pass |
| 13 | Update applied, then the release is withdrawn by Ametecs | The installation is notified within one polling cycle with the recommended action; a rollback-to-previous is offered where the release is still reversible |
| 14 | A pack arrives requiring a newer application | Announced, held, surfaced with the required version, reported to the vendor exposure register — **never silently skipped** (R-XXVII-108) |
| 15 | An application rollback drops below a held pack's floor | Pack deactivated, predecessor's range reopened, finalised periods untouched, affected periods listed (R-XXVII-109) |
| 16 | Both channels have work outstanding and the operator applies the pack first | Permitted; the channels share no lock (R-XXVII-110) |
| 17 | Seat usage exceeds entitlement after activation through bulk import | Detected within one scheduled cycle; behaviour per `overage_policy`; **never silently permitted** (R-XXVII-050, R-XXVII-051) |
| 18 | Licence claims a seat policy key the installation does not have | **Deterministic failure — refuse the licence.** A licence that cannot be evaluated is not a licence that grants |
| 19 | An entitlement is reduced at renewal | Data is retained, the module becomes unreachable, the customer is told exactly what changed, and restoring the entitlement restores the module (R-XXVII-047) |
| 20 | Customer's database is restored to a point before a renewal | `highest_issue_seq_seen` is restored too, so the old document is refused; the customer re-imports the current one, which is available for re-download |
| 21 | Air-gapped install has never reported and the licence approaches expiry | The renewal document is produced from the commercial record and delivered by the offline path; the register shows `air_gapped`, not `silent` |
| 22 | Signing key compromised mid-window | Revocation list distributed; new key announced by the outgoing key; already-accepted artefacts remain valid and explainable; the exposure window is recorded and published |
| 23 | An installation refuses every update for two years | It runs. It appears on the unsupported-versions view; the contact workflow tracks the conversation; it continues to receive statutory packs (R-XXVII-143) |
| 24 | Support engineer needs elevated access on a customer system | Opt-in, time-limited, per-incident, audited, disclosed (Part XXVI R-XXVI-054). **There is no standing vendor account** |
| 25 | A customer deliberately patches out the licence check | Detectable and dated, not preventable. §XXVII.2.11 states this plainly and the commercial instruments carry it |

---

## XXVII.14 Tests

SmartPRS 2 has **no test for any licensing path, the update flow, coupons, signup, the editions
or any middleware** (`I-…` §6.6). Every scenario below must have an exact-value regression test.

| # | Scenario | Asserts |
|---|---|---|
| T1 | A licence with one byte mutated in the claims | Refused, deterministic class, gate `read_only`, audited (R-XXVII-001) |
| T2 | A licence signed with an untrusted key | Refused; the key id is named in the log and not in the customer message |
| T3 | Grep of the release artefact, every installer script and every config default | No private key material, no `hash_hmac` in a licensing/update/rule-pack path, no shared-secret config key (R-XXVII-002) |
| T4 | Every configuration, env var, permission, role and flag attempted against every verification | None disables it (R-XXVII-003) |
| T5 | Corrupt licence store vs simulated database timeout | Corrupt blocks; timeout does not, increments a counter, opens an alert; a timeout past the window degrades (R-XXVII-004) |
| T6 | Every row mentioning the licence edited to grant a permanent licence | No gate decision changes (R-XXVII-053) — the direct regression test for `I-…` SEC-01 |
| T7 | Yesterday's licence replayed after today's renewal | Refused on `issue_seq` (R-XXVII-046) |
| T8 | Database restored to before a renewal, then the old document imported | Refused; the high-water mark survived the restore |
| T9 | System clock moved back one year | No expiry, grace or tolerance extends (R-XXVII-035) |
| T10 | Licence store + every cache file copied to a second machine | The copy does not produce a second working instance; a duplicate identity anomaly is raised (R-XXVII-024, R-XXVII-055) |
| T11 | Host machine replaced and restored from backup | Runs throughout the rebind window; rebinding completes without support contact (R-XXVII-025) |
| T12 | Rotate the signing key | Every previously issued licence, release and pack still verifies (R-XXVII-016) |
| T13 | An installation on N−2 receives a key announcement | Accepts artefacts signed by the new key with no application update (R-XXVII-018) |
| T14 | Revoke a licence on an installation with all egress blocked | Degrades within the declared tolerance (R-XXVII-034) |
| T15 | Revoked licence, then an administrator signs in | Signs in; opens a finalised run; downloads a payslip and a challan; exports the full dataset; **every write refused** (R-XXVII-038, R-XXVII-039) |
| T16 | Punches received throughout `read_only` | Present and derivable after re-licensing, with no gap (R-XXVII-040) |
| T17 | Full degradation sequence | Every transition announced before it occurs, at the licence's own lead times (R-XXVII-041, R-XXVII-042) |
| T18 | Fuzz sweep over session keys, headers, cookies and query parameters against the gate | Nothing grants (R-XXVII-043) |
| T19 | Fresh installation, activation then first-password | Completes without being signed out (R-XXVII-023) |
| T20 | Import a renewal licence while in `read_only` | Returns to `active` with no other intervention (R-XXVII-048) |
| T21 | Seat count across the SaaS counter, the licence check and every creation door, over a corpus including archived, exited, reactivated and bulk-imported people | Identical numbers everywhere (R-XXVII-049) |
| T22 | A licence with a malformed or absent seat claim | Refused at verification; **never treated as unlimited** (R-XXVII-052) |
| T23 | A gate on an undeclared feature key; a declared key nothing gates on | Both fail the build (R-XXVII-060) |
| T24 | For every feature key: navigation, endpoint and API capability document | All three agree; an unentitled endpoint refuses a direct `GET` (R-XXVII-063, R-XXVII-064) |
| T25 | Boot with neither a subscription gate nor a licence gate applicable | Refuses to boot, naming the combination (R-XXVII-068) — regression for `I-…` DEF-36 |
| T26 | Import a licence granting an additional module | Available within one request cycle, no restart (R-XXVII-070) |
| T27 | Package with the signature stripped; one manifest byte changed; signed by an untrusted key; one file added to the extracted tree; one signature only | Each refused, each with the specific reason (R-XXVII-071, R-XXVII-072, R-XXVII-073) |
| T28 | An install package offered to the update flow | Refused by signed type (R-XXVII-074) — regression for `I-…` DEF-30/DISC-22 |
| T29 | A rogue server offering a well-formed, differently signed package; a MITM with a trusted-but-different certificate | Both refused (R-XXVII-076, R-XXVII-077) |
| T30 | A captured check response replayed to another installation and for another version | Refused (R-XXVII-078) |
| T31 | An installation failing three preconditions | Told all three at once, each with its remedy (R-XXVII-079) |
| T32 | Unwritable backup destination; in-progress payroll run | Each refuses the update before maintenance mode (R-XXVII-080, R-XXVII-081) |
| T33 | Every step executed twice in a harness | Identical outcome (R-XXVII-083) |
| T34 | Any URL attempted during maintenance | Nothing reaches the application; no bypass exists (R-XXVII-084) |
| T35 | The updater killed at **each** state | Recovery console on boot; ordinary traffic refused; resume or rollback available; `MIGRATE` defaults to rollback (R-XXVII-098, R-XXVII-099) |
| T36 | Migration fails at 50% of a batch | Code and database both restored; `schema_digest_after == schema_digest_before`; the installation is on the previous version (R-XXVII-086, R-XXVII-087) |
| T37 | Rollback on both the customer and platform paths | Neither leaves any file the new version added; `public/` and `bootstrap/` are covered (R-XXVII-088) — regression for `I-…` DEF-28, DEF-29 |
| T38 | Forced rollback failure | `ROLLBACK_FAILED`, maintenance on, critical alert both sides, runbook displayed (R-XXVII-089) |
| T39 | Every file in a release artefact scanned for a version string | One identity; the register's record matches the installation's report (R-XXVII-090) — regression for `I-…` DEF-33 |
| T40 | A release mixing expand and contract phases for one change; a release with an undeclared destructive migration | Both fail CI (R-XXVII-091, R-XXVII-092) |
| T41 | An update that breaks a route, a queue connection or the scheduler | Rolled back automatically by the health gate (R-XXVII-096) |
| T42 | A release that silently changes a payroll result | Fails its own update on the golden re-render (R-XXVII-097) |
| T43 | A release with a destructive migration under an auto-update policy | Waits for a human (R-XXVII-101) |
| T44 | Applying a rule pack on a live installation under load | No dropped request, no restarted process, no migration (R-XXVII-103) |
| T45 | A rule pack signed with the release key; a release signed with the rule-pack key | Both refused (R-XXVII-104) |
| T46 | A pack requiring a newer application | Announced, held, surfaced with the version named, on the exposure register (R-XXVII-108) |
| T47 | Application rollback after a pack activation | Pack deactivated; predecessor reopened; every finalised payslip unchanged and still explainable (R-XXVII-109) |
| T48 | Installation in `ROLLBACK_FAILED` | Still receives and stores packs (R-XXVII-110) |
| T49 | Lapsed support window, one polling cycle | Receives the statutory pack; refused the feature release; the non-blocking notice appears (R-XXVII-114, R-XXVII-115, R-XXVII-116) |
| T50 | If Option A is selected instead | The withholding is displayed to the customer, recorded on the exposure register, and no pack is ever silently undelivered (R-XXVII-117) |
| T51 | Two independent CI runs of one commit | Identical artefact digests (R-XXVII-123) |
| T52 | An attempt to sign from a developer machine or with one signer | Refused (R-XXVII-126) |
| T53 | Promotion with an open severity-1 defect; promotion before the soak elapsed | Both refused by the pipeline (R-XXVII-131, R-XXVII-132) |
| T54 | Withdraw an applied release | Every affected installation notified within one polling cycle (R-XXVII-133) |
| T55 | An air-gapped customer and a decommissioned server | Distinguishable in the register (R-XXVII-134) |
| T56 | Register schema review | No employee, payroll or tenant business data (R-XXVII-136) |
| T57 | A critical advisory | A patch release exists on every supported minor line; every exposed installation is notified and shows it (R-XXVII-139, R-XXVII-140) |
| T58 | Every published support-matrix combination | Has a passing CI job; a combination with no job cannot be published (R-XXVII-144) |
| T59 | An end-of-life installation | Runs, receives packs, computes correctly (R-XXVII-143) |
| T60 | A failed migration's progress response | Contains a reference code only; no schema, table, path or connection string (R-XXVII-148) — regression for `I-…` SEC-12 |
| T61 | Every endpoint in §XXVII.9 | Explicit policy binding; rate limited; cross-tenant isolation asserted (R-XXVII-149, spine Laws 5 and 6) |
| T62 | Every decision surface | Renders its trace, including in `read_only` and `unlicensed` (R-XXVII-150, R-XXVII-151) |
| T63 | Migration verifier against a cut-over installation | Fails on any `license.lic`, `.machine_fp`, SmartPRS 2 licence settings row or SPRSX1 symbol (R-XXVII-160) |

---

## XXVII.15 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Decision | Recommendation | Trade-off if refused, or if the recommendation is not taken |
|---|---|---|---|
| **D-XXVII-01** | **Do editions exist in SmartPRS 3 at all, and what do existing L1/L2 customers own?** (§XXVII.3.4) | **Option A — drop editions**: one product, priced on seats, companies and individually purchased optional modules; every existing customer issued the full standard entitlement set; `edition` migrates as a historical sales attribute only; **and a separate commercial review, with a named owner and a date, of what L1 and L2 customers were sold and whether anything is owed** | Keeping three tiers (Option B) is viable if the price ladder is commercially important, and costs a permanently grandfathered population. Enforcing them against existing customers (Option C) withdraws function from paying customers during the highest-risk migration in the product's life. **This blocks the entitlement model, the licence claim set and the pricing model, and it is a commercial and possibly contractual question, not only a technical one** |
| **D-XXVII-02** | **Is rule-pack delivery gated on an active AMC?** (§XXVII.5.4) — **the same decision as Part XVII's D-XVII-02; answer it once** | **Option B — never gate statutory content.** Authorise the pack channel on licence validity, not support currency. Gate features, application updates, support SLAs and advisory services | Gating a statutory correction the vendor has already produced is a liability position and a reputational one; the market is small and well-connected. **If Option A is selected, R-XXVII-117 applies and is not optional: the withholding must be explicit, visible to the customer, and audited on both sides. Silence is unacceptable either way** |
| **D-XXVII-03** | **Is the shipped `.lic` key pair a development pair, and what is the rotation and re-issue window?** | **Verify against the real delivery tree, then rotate regardless.** SmartPRS 3 uses new Ed25519 key material under HSM/KMS custody, so v3 does not inherit the question — but if the pair was a development one, the v2 estate is forgeable until every install is cut over, and the cutover window becomes a remediation deadline rather than a project milestone | The reference documents assert it from a go-live checklist **not present in the supplied extract** (`I-…` DISC-02, Q-03). Leaving it unverified means the migration is planned without knowing whether it is also an incident response |
| **D-XXVII-04** | **Was `SMARTPRS_LICENCE_SECRET` ever shipped to customers, and to how many?** | **Verify, then scope.** If it was never shipped, the remediation is a code deletion. If it was, every affected build is a disclosed signing key and the affected customers must be identified even though the path is dead code | The literal value is reported at two `PKG:` paths not in the extract (`I-…` DISC-01, Q-04). This determines whether §XXVII.12's deletion is a cleanup or an incident |
| **D-XXVII-05** | **The offline tolerance for a licensed installation, and the proof-of-life cadence** (§XXVII.2.7) — referenced by Part XXVI §XXVI.10.1 | Proof-of-life every **7 days**; offline tolerance **45 days** standard; `null` for a licence explicitly issued as air-gapped, reconciled instead by the signed status export at each support interaction | A short tolerance makes revocation effective and makes a restrictive customer network a support burden. A long one is invisible to honest customers and gives a determined one a long window. Both are licence claims, so a specific customer can be issued different values with no code change |
| **D-XXVII-06** | **Is the read-only degradation position accepted as a commercial and contractual commitment?** (§XXVII.2.8) | **Yes, and state it in the contract.** A customer whose licence lapses retains sign-in, read, report, export and download of everything already produced; payroll already run stays readable and exportable; raw punch ingestion continues; every write is refused | The alternative — a hard lock-out — is what SmartPRS 2 approximates today, and it makes a commercial dispute into a payroll incident affecting employees who are not party to it. Refusing this recommendation should be a deliberate, recorded choice, not a default |
| **D-XXVII-07** | **The on-premise seat and instance model, and the self-service move allowance** | Seats are **explicitly licensed** with `seat_unlimited` as a deliberate claim value, never a default; `max_instances = 1` for production with `environment_grants` covering DR, test and training; a self-service instance-release allowance of **2 per rolling year**, displayed before it is used | Today the default is unlimited in the customer's favour on every install — `seats_licensed = 100000` at provisioning and a `.lic` seats field defaulting to 0 ⇒ no limit (`I-…` Q-17, DISC-12) — and the documented "1 machine move per year" cap does not exist (DEF-05). Whatever is chosen must be a stated claim, because "whatever the code happened to default to" is what is being replaced |
| **D-XXVII-08** | **Published security remediation targets per severity** (§XXVII.6.6) | Critical: notified within 48 h of confirmation, fix within 7 days. High: 30 days. Medium: next scheduled release. Low: next minor version | These are the numbers a customer's security review asks for. Publishing none is itself an answer, and it is the answer that loses enterprise deals |
| **D-XXVII-09** | **May Ametecs auto-apply an update to a customer installation, or is every update customer-initiated?** | **Customer-initiated by default, with opt-in auto-update** bounded by R-XXVII-101 (never a destructive migration, never a major version, never an unacknowledged precondition). Ametecs never pushes | Auto-apply on a payroll server without the customer choosing the window is how a vendor causes a month-end outage. The cost of the recommendation is a long tail of un-updated installs, which the version register and the contact workflow are designed to work |
| **D-XXVII-10** | **The supported-version window, and the end-of-life policy** (§XXVII.6.7) — **held jointly with Part XXVI D-XXVI-10; answer it once** | Current and previous minor **supported**; the one before **maintenance** for a published period; older is **end of life** — which still runs and still receives statutory packs. Every date published at release | Without published dates the version register has no meaning and "supported" is decided per support call. **End of life must never be a kill switch** (R-XXVII-143), and that too should be an explicit commitment |
| **D-XXVII-11** | **Signing key custody: HSM or cloud KMS, and who holds the quorum for rotation, revocation and compromise response?** | A cloud KMS with hardware backing, non-exportable keys, two-person authorisation on every signing operation, and a **named** three-person quorum of whom two are required for rotation or revocation | Today the private key is a `0600` file on one server, excluded from every backup, overwritable by a `--force` flag with a console warning (`I-…` SEC-16). The impact of losing it is that **no licence can ever be issued or renewed again for any existing customer.** The custody decision is the difference between a bad week and an unrecoverable business event |
| **D-XXVII-12** | **Is a licence required for a private-cloud instance Ametecs hosts for a single customer?** | **Yes** — private cloud is a `deployment_binding` value and carries a licence, so that entitlement, seats and instances resolve through one mechanism regardless of who operates the infrastructure | The alternative is a third gate for a third deployment shape, which is how SmartPRS 2 arrived at a configuration that is neither subscription-gated nor licence-gated (`I-…` DEF-36) |

---

## XXVII.16 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XXVII-001 – R-XXVII-004 | The four absolute rules: asymmetric only, no shared secret ever, verification cannot be disabled, deterministic vs transient failure (§XXVII.2.0) | 4 |
| R-XXVII-010 – R-XXVII-019 | The claim set, the artefact, the signature scheme, key custody, identity, rotation, revocation (§XXVII.2.1–2.3) | 10 |
| R-XXVII-020 – R-XXVII-032 | Online activation, instance binding, hardware change, offline activation (§XXVII.2.4–2.6) | 13 |
| R-XXVII-033 – R-XXVII-048 | Proof-of-life, offline tolerance, revocation, the gate's states, read-only degradation, renewal (§XXVII.2.7–2.9) | 16 |
| R-XXVII-049 – R-XXVII-052 | Seats: one counter, one definition, explicit unlimited (§XXVII.2.10) | 4 |
| R-XXVII-053 – R-XXVII-056 | Tamper resistance, audit visibility, and the honest limit (§XXVII.2.11) | 4 |
| R-XXVII-060 – R-XXVII-070 | The entitlement grammar, one declaration, resolution, editions, the upgrade path (§XXVII.3) | 11 |
| R-XXVII-071 – R-XXVII-085 | The release artefact, manifest, channel trust, preconditions, the state machine (§XXVII.4.1–4.5) | 15 |
| R-XXVII-086 – R-XXVII-089 | **Database backup and code + database rollback** (§XXVII.4.6) | 4 |
| R-XXVII-090 – R-XXVII-097 | One version identity, migration safety, the health check (§XXVII.4.7–4.8) | 8 |
| R-XXVII-098 – R-XXVII-102 | Interruption, resumption, auto-update policy (§XXVII.4.9–4.10) | 5 |
| R-XXVII-103 – R-XXVII-113 | The rule-pack channel, composition with the application channel, preview and acceptance (§XXVII.5.1–5.3) | 11 |
| R-XXVII-114 – R-XXVII-117 | The AMC gate on statutory content, and the visibility requirement under either option (§XXVII.5.4) | 4 |
| R-XXVII-120 – R-XXVII-133 | The pipeline, reproducible builds, artefact signing, staging, canary, rollout (§XXVII.6.1–6.4) | 14 |
| R-XXVII-134 – R-XXVII-136 | The customer version register (§XXVII.6.5) | 3 |
| R-XXVII-137 – R-XXVII-145 | Security-patch policy, end-of-life, the support matrix (§XXVII.6.6–6.7) | 9 |
| R-XXVII-146 – R-XXVII-149 | API constraints (§XXVII.9) | 4 |
| R-XXVII-150 – R-XXVII-151 | Explainability (§XXVII.11) | 2 |
| R-XXVII-160 – R-XXVII-161 | Migration gates (§XXVII.12) | 2 |
| **Total** | | **143** |

*(R-XXVII-005 – 009, 057 – 059, 118 – 119 and 152 – 159 are unallocated, reserved for insertion
within their sections. R-XXVII-063 restates Part XXV R-XXV-095 from the licence side and R-XXVII-068 restates
Part XXVI R-XXVI-003; both are counted here because their tests differ. R-XXVII-114 – 117 restate
Part XVII R-XVII-040 – 043 as channel-authorisation requirements; the underlying decision is one
decision — D-XXVII-02 / D-XVII-02.)*

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Grace days, read-only-after days, notice lead times, offline tolerance, proof-of-life cadence, seat limits and their basis, instance counts, the self-release allowance, entitlement sets, update channels, auto-update classes, publication delays, maintenance windows, backup retention counts and ages, health thresholds, soak periods, rollout pacing, remediation targets and support windows are **all** data — licence claims, S4 artefacts or reference rows. The constants in code are structural: state codes, step codes, algorithm identifiers, and the pinned public key set, all on the declared allow-list. Nothing resembling SmartPRS 2's `GRACE_DAYS = 7`, `TOKEN_TTL_MINUTES = 60`, the 24-hour revocation throttle, the protected-path array or the `SPRS-` key format survives as a literal | Static analysis over `Domain\Licensing` and `Domain\Distribution`; T17, T22, T31, T43, T53, T58 |
| **2 — no schema change at runtime** | Every table in §XXVII.8 is migration-owned. There is no successor to the **nineteen tables SmartPRS 2 creates on the request path inside a swallowed `try/catch`** (`I-…` §2.1), to `LicenseService::ensureTables()`, to the self-healed column blocks, or to `ApprovalService::safeRow()`'s silent key-dropping. The update flow is the **only** thing that changes schema, it does so through versioned migrations under maintenance mode with a proven database backup, and it declares every migration's phase and destructiveness in a signed manifest | CI grep for DDL outside migrations; `migrate:fresh` parity; T36, T40 |
| **3 — nothing is overwritten** | Licence documents are immutable and append-only; a change is a new document with a higher `issue_seq`. Signing keys are never edited except to set revocation. Releases, manifests, signatures, licence events, verifications, update runs, steps and installation reports are all append-only. A superseded licence is retained. A withdrawn release is retained. A deactivated rule pack is retained and every payslip keeps its pack reference | T7, T8, T47; append-only grants asserted in CI |
| **4 — every number explains itself** | Every licence, entitlement and update decision carries a trace naming the artefact, its version, the claim consulted, the date arithmetic and what would change the outcome (§XXVII.11). A gate returning a decision without a trace throws. The trace is reachable in `read_only` and `unlicensed` — the states in which a customer most needs it | R-XXVII-150, R-XXVII-151; T62 |
| **5 — authorisation fails closed** | Every route in §XXVII.9 carries an explicit policy binding. The licence gate and the entitlement gate fail closed on deterministic errors and fail open only on transient ones, bounded, counted and alerting. No session flag, header, cookie or query parameter grants a bypass — the `edition_demo` / `demo_team` shape is asserted absent. Entitlement enforcement is server-side on every method including `GET`; hiding is never the control. Verification cannot be disabled by any mechanism | T4, T5, T18, T24, T61; the fail-open counter asserted non-silent |
| **6 — tenancy is structural** | Licence and update state are **installation** facts and are marked so; where a row concerns a tenant it carries `tenant_id NOT NULL` in every unique index. Platform-scoped tables are enumerated in §XXVII.8 and hold no tenant business data. The version register holds versions, counts, dates and health states and **no customer business data at all**. A licence naming a `tenant_binding` resolves against real tenant ids, never against a device-supplied string | T56, T61; the generated cross-tenant suite |
| **7 — money is exact** | This part carries no money arithmetic — commercial computation is Part XXV's, in integer minor units through the Money value object. What this part owns is that a **commercial state** (validity, support window, seat entitlement) is never inferred from a rounded or approximated value: dates are `DATE`, counts are integers, and "unlimited" is an explicit boolean claim rather than the result of a zero or a null | T22; no float in any licence or entitlement path |

---

*End of Part XXVII.*
