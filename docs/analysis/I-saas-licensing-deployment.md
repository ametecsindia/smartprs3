# AS-IS ANALYSIS — COMMERCIAL & DEPLOYMENT LAYER
*(signup · tenant provisioning · subscriptions · billing · coupons · licensing · editions · on-prem console · self-update · packaging & release · deployment · installation · demo access)*

---

## 0. Scope and evidence base

### 0.1 Reference documentation read

| Document | Lines | Content |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 20087–23888 | Section 12 — SaaS, Licensing, Billing, Updates & Distribution (3,802 lines, read in full) |
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 57344–57430 | Part VI §34 — Installation and Development Setup |
| `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 2666–2868 | §12 Deployment architecture (SaaS, on-prem, backup posture, release pipelines) |
| `SECURITY_AND_TECHNICAL_DEBT_REPORT.md` | 4108–4185 | §6 Secrets found in source (S-01 … S-27) |
| `BUSINESS_RULES_CATALOGUE.md` | 454–487, 900–920, 8756+ | BR-LIC-001…027, BR-LIC-102/103/120/604/700–703, BR-UPD-001…015, BR-UPD-700–705, and the BR-SUB / BR-BIL / BR-TEN index entries |

### 0.2 Source files read completely (under `/home/claude/prs2/`)

| File | Lines |
|---|---|
| `app/Services/LicenseService.php` | 559 |
| `app/Services/LicenseFile.php` | 192 |
| `app/Services/LicenseSigner.php` | 74 |
| `app/Services/Edition.php` | 181 |
| `app/Services/SubscriptionService.php` | 272 |
| `app/Services/CouponService.php` | 234 |
| `app/Services/UpdateClient.php` | 459 |
| `app/Http/Controllers/SignupController.php` | 1204 |
| `app/Http/Controllers/BillingController.php` | 1031 |
| `app/Http/Controllers/TenantBillingController.php` | 527 |
| `app/Http/Controllers/DemoAccessController.php` | 506 |
| `app/Http/Controllers/OnpremClientController.php` | 769 |
| `app/Http/Controllers/ClientUpdateController.php` | 879 |
| `app/Http/Controllers/UpdateServerController.php` | 377 |
| `app/Http/Controllers/ReleaseController.php` | 336 |
| `app/Http/Controllers/InstallController.php` | 247 |
| `app/Http/Middleware/LicenseGate.php` | 77 |
| `app/Http/Middleware/EditionGuard.php` | 61 |
| `app/Http/Middleware/EnsureSubscriptionActive.php` | 122 |
| `app/Http/Middleware/DemoWriteGuard.php` | 79 |
| `app/Console/Commands/IssueLicense.php` | 69 |
| `app/Console/Commands/MakeLicenseKeys.php` | 65 |
| `app/Console/Commands/ClientProvision.php` | 124 |
| `app/Console/Commands/RenewSubscriptions.php` | 36 |
| `app/Console/Commands/SubscriptionAlerts.php` | 144 |
| `app/Console/Commands/DemoReset.php` | 184 |
| `app/Console/Commands/ClearAppState.php` | 40 |
| `updater/updater.php` | 345 |
| `updater/update-status.php` | 52 |
| `routes/updates.php` | 61 |
| `bootstrap/app.php` | 46 |
| `config/smartprs.php` | 132 |
| `config/backup.php` | 92 |
| `.env.example` | 40 |
| `.env.production.example` | 87 |
| `version.json` | 4 |
| `database/seeders/StandardPlanSeeder.php` | 67 |
| `database/seeders/OnPremWorkspaceSeeder.php` | 111 |
| `database/seeders/DemoCompanySeeder.php` | 73 |
| `database/seeders/DatabaseSeeder.php` | 33 |
| `database/seeders/SuperAdminSeeder.php` | 48 |
| `resources/views/install.blade.php` | 98 |
| `resources/views/licence-activate.blade.php` | 67 |
| `resources/views/licence-pay.blade.php` | 88 |
| `resources/views/admin/onprem.blade.php` | 182 |
| `resources/views/admin/releases.blade.php` | 68 |
| `resources/views/invoice-pdf.blade.php` | 134 |
| `resources/views/onprem-invoice-pdf.blade.php` | 78 |
| `resources/views/quotation-pdf.blade.php` | 140 |
| `resources/views/quote-public.blade.php` | 138 |
| `tests/Feature/BillingTest.php` | 157 |
| `README.md` | 87 |

### 0.3 Files read in part (for targeted verification)

`routes/web.php` (696, lines 100–300 in full plus targeted greps) · `routes/console.php` (265, scheduling block 139–265) · `app/Http/Controllers/AuthController.php` (licence gate at login, 175–275; first-password 473–530) · `app/Http/Controllers/SaasController.php` (`provisionTenantRecord()` 125–235) · `app/Services/StatutoryConfig.php` (docblock + PT-slab handling) · `app/Http/Controllers/AppController.php` (`editionLevel` consumers).

### 0.4 Limits of this evidence base — READ THIS BEFORE TRUSTING ANY "SECRET" CLAIM

The provided source extract at `/home/claude/prs2/` is **not the full delivery tree**. Specifically **absent**:

- the entire packaging root the reference docs call `PKG:` — `client-package-tools/env.client.template`, `06_Build-and-Release-Scripts/ClientBuilder.bat`, `BUILD-RELEASE.bat`, `PACKAGE-CLIENT-EDITIONS.bat`, `DEPLOY-EDITIONS.bat`, `INSTALL-SmartPRS-CLIENT.bat`, `smartprs-config.json`, `SmartPRS-DnL-GoLive-Commit-List.md`, `CLIENTBUILDER-README.md`, `deploy/nginx-smartprs.conf`, the Supervisor/systemd units, `client-builder-tools/guard-runtime.php`;
- `public/` is trimmed to `.htaccess`, `css/`, `index.php`, `web.config` — **`public/update-status.php` is not present in this extract** (see §8, DISC-04);
- `database/migrations/` was not read for this area (all licensing/commerce tables are runtime-created anyway — §2);
- `resources/prototype/app.html`.

Consequently every claim in the reference documents that cites a `PKG:` path — including **FINDING-LIC-01 (the shipped shared licence secret)** and **FINDING-LIC-02 (the dev `.lic` key pair)** — **could not be verified against source in this pass**. What I *can* and do verify is the *design* that makes FINDING-LIC-01 possible, from `config/smartprs.php` and `LicenseService.php`. That distinction is carried explicitly through §7.

---

## 1. Business capability inventory

### 1.1 Two commercial shapes from one codebase

SmartPRS is sold twice: as a hosted multi-tenant subscription (**SaaS**) and as a customer-installed perpetual licence with an annual maintenance contract (**on-prem**). The two are separated by **two independent environment variables**, which is the single most consequential design decision in this area:

| Variable | Values | Decides |
|---|---|---|
| `SMARTPRS_EDITION` | `saas` \| `l1` \| `l2` \| `l3` | **What the app looks like** — whether the SaaS platform screens, Super Admin portal, signup, quotes and demo routes are registered at all |
| `SMARTPRS_DEPLOYMENT` | `saas` \| `onprem` | **Whether a licence file is demanded at login** |

`config/smartprs.php:12,22`. `Edition::current()` deliberately bypasses the cached config and parses `.env` line by line (`Edition.php:56-86`), because `php artisan config:cache` freezes the value and a cloned build folder would then report the wrong edition forever.

### 1.2 Self-serve signup and paid provisioning (SaaS)

`GET /signup` → plan picker with a live quote → `POST /signup/order` (server-side price, `signups` row, Razorpay order) → Razorpay checkout in the browser → `POST /signup/complete` (HMAC verify) → within one request the tenant, master company, admin user, industry starter content, subscription, GST invoice and payment row are created, the receipt is emailed with a PDF attached, and the buyer is auto-signed-in. `SignupController.php:144-460`.

### 1.3 Quotation and credit provisioning (sales/finance-led)

`POST /signup/quote` creates the same `signups` row with `status='quoted'`, a 48-char public token, a `PRS-Q-<FY>-<MM>-<nnnn>` number and a branded PDF, emailed with a pay link nominally valid 15 days (`SignupController.php:569-682`). Anyone with the link can pay (`/quote/{token}/order|complete`). Separately, the Super Admin can record an **offline** payment (Paid / Partial / Due-on-credit) against a quotation and the workspace is created immediately, on credit, with the public link kept alive for the balance (`adminPayment()` `:984-1058`, `provisionOfflineSignup()` `:1065-1203`).

### 1.4 Subscription lifecycle

Three published plans (Starter/Growth/Professional), seat-based, minimum quarterly advance, 10% off half-yearly and 25% off annual, ₹1,000/month per additional company. State machine `active → grace(7d) → locked`, with tenant-admin-only access when locked so they can renew. `SubscriptionService.php`, `EnsureSubscriptionActive.php`, `BillingController::priceFor()`.

### 1.5 Billing and GST invoicing

One consecutive financial-year invoice series `PRS-<FY>-<MM>-<nnnn>` shared between SaaS subscription invoices (`invoices.number`) and on-prem licence invoices (`onprem_clients.invoice_no`). Razorpay checkout plus a signed webhook safety net. DomPDF GST tax invoices with a per-customer CGST+SGST / IGST split. `BillingController.php`.

### 1.6 Discount coupons

Percent or flat, stacking on top of the cycle discount, with expiry / max-uses / min-cycle / plan-restriction / one-per-customer limits, plus email-exclusive `EXC-xxxxxx` codes that auto-apply when the buyer's email is caught. Redeemed only after a verified payment; the subscription always keeps the full rate so the next renewal bills full price. `CouponService.php`.

### 1.7 Licensing (on-prem)

Three mechanisms exist in code. Only two are wired:

| # | Mechanism | Format | Verified by | Live today? |
|---|---|---|---|---|
| A | Online activation key | `SPRS-XXXX-XXXX-XXXX-XXXX` | The vendor server (`POST /update/activate`) | **Yes — the primary path** |
| B | Offline signed License Code | `SPRSX1.<b64url>.<40 hex HMAC>` | Locally, with a **shared secret** | **No — unreachable dead code** |
| C | Offline RSA-signed `.lic` file | `<b64url payload>.<b64url RSA-SHA256 sig>` | Locally, with an embedded **public key** | Yes, but **disabled by default** |

Plus a fourth artefact — the **activation certificate** stored locally in `settings(tenant_id=0, key='licence')` — which is what every downstream gate actually reads.

### 1.8 The on-prem sales desk (`/admin/onprem`)

Record client → set price, AMC %, validity, expiry behaviour → invoice with a public Razorpay pay link → take money (gateway or manual NEFT/cheque/UPI/cash) → **the gate**: a key issues only on full payment, or with an explicit and audited "activate on partial" tick → issue an online key or a node-locked `.lic` → live management (renew, release binding, shift machine, revoke, delete) → per-client History with a fraud banner.

### 1.9 Releases and the on-prem self-update

`/admin/releases` registers a zip with its SHA-256, applies it to the SaaS platform itself, and publishes/grants it to AMC-active clients who are then emailed. Each client's admin uses Administration → Updates: `status → check → download (+sha256) → install`, where install hands a job file to `updater/updater.php` running as its own detached process outside Laravel.

### 1.10 Demo access

Five public entry points (`/demo`, `/app1`, `/app2`, `/app3`, `/teamdemo`) share one passkey gate: name + mobile + email in, a 6-digit passkey out by email and WhatsApp, valid for a Super-Admin-set window (default 2h), signing the visitor into one shared demo workspace that is wiped and reseeded by `demo:reset --if-due` every 15 minutes.

### 1.11 Installation

Two paths: a Windows `.bat` installer (not in this extract) and a browser first-run wizard `GET|POST /install` that runs a 20-item environment pre-flight and creates the company + admin login, self-disabling the moment any user row exists.

---

## 2. Data model

**Critical finding: every table in this area except `users`, `tenants`, `companies`, `plans`, `subscriptions`, `invoices`, `payments` and `payment_gateways` is created at RUNTIME by application code, inside a `try/catch` that swallows DDL failures silently. There are no migrations for the licensing, update, coupon, signup, renewal, alert or demo-gate tables.**

### 2.1 Runtime-created tables

| Table | Created by (file:line) | Purpose | Key columns |
|---|---|---|---|
| `onprem_clients` | `LicenseService::ensureTables()` — `LicenseService.php:26-45` | On-prem sales record | `company, contact_name, email, mobile, gstin, state, address, edition(l1\|l2\|l3), employee_band, price, amc_percent(default 18), paid_total, activate_on_partial, notes` |
| — additional columns | `OnpremClientController::ensureSaleCols()` — `OnpremClientController.php:29-50` | | `invoice_no(30)`, `invoice_token(64)`, `gateway_order_id(64)`, `licence_term_months`, `licence_expires_on`, `expiry_mode(renew\|notify)` |
| `onprem_payments` | `LicenseService.php:46-57` | Money received per client | `client_id, amount, mode(neft\|cheque\|upi\|gateway\|cash), reference, paid_on, entered_by` |
| `licences` | `LicenseService.php:58-76` | The issued licence | `client_id, edition, key_hash(64) UNIQUE, key_enc(text), key_last4, status(pending\|active\|suspended\|revoked), amc_expires_on, expiry_mode, activated_at, fingerprint, server_name, reactivations_used, last_seen_at` |
| — self-healed columns | `LicenseService.php:138-153` | | `expiry_mode`, `server_limit(default 1)`, `last_mismatch_at` |
| `licence_events` | `LicenseService.php:77-86` | Audit trail | `licence_id, type(30), detail(≤2000), ip(60)` |
| `licence_devices` | `LicenseService.php:87-100` | Server-seat register | `licence_id, device_uid(190), fingerprint, hostname, status, activated_at, deactivated_at, last_seen_at` |
| `releases` | `LicenseService.php:101-113` | Update packages | `version(30) UNIQUE, notes, file_path, checksum(64), size, published_at, applied_platform_at` |
| `release_grants` | `LicenseService.php:114-123` | Entitlement | `release_id, client_id, granted_by, emailed_at` |
| `client_updates` | `LicenseService.php:124-134` | Update audit (both sides) | `client_id, licence_id, version(30), action(check\|download\|applied\|failed), detail` |
| `signups` | `SignupController::ensure()` — `SignupController.php:33-54` | Signup / quotation | `uuid, company, admin_name, admin_email, mobile, plan_id, seats, companies, cycle, amount, tax, gateway_order_id, status(pending\|quoted\|provisioned), tenant_id, terms_accepted_at` |
| — self-healed columns | `SignupController.php:56-119` (six separate blocks) | | `terms_accepted_at`, `companies`, `gstin`, `state`, `subdomain`, `quote_token`, `quote_no`, `quoted_at`, `coupon_code`, `coupon_discount` |
| `renewals` | `TenantBillingController::ensure()` — `TenantBillingController.php:69-83` | Pending renewal orders | `uuid, tenant_id, plan_id, seats, companies, mode(renew\|upgrade), cycle, amount, tax, gateway_order_id, status(pending\|done)` |
| — self-healed columns | `TenantBillingController.php:46-63` | | `companies`, `mode`, `coupon_code`, `coupon_discount` |
| `coupons` | `CouponService::ensure()` — `CouponService.php:27-45` | Discount codes | `code(40) UNIQUE, type, value, valid_till, max_uses, used_count, min_cycle, plan_ids, once_per_customer, applies_to, exclusive_email, status, notes` |
| `coupon_redemptions` | `CouponService.php:46-58` | Redemption ledger | `coupon_id, code, context, tenant_id, email, company, amount_discounted` |
| `subscription_alerts` | `SubscriptionService::ensureAlertTable()` — `SubscriptionService.php:201-216` | Expiry-alert log | `tenant_id, kind(40), channel(20), status(20), detail(500), period_end` |
| `demo_requests` | `DemoAccessController::ensureGate()` — `DemoAccessController.php:81-98` | Demo passkey register | `name, phone(20), email(160), company, entry(10), **pin(12) plaintext, indexed**, status, expires_at, last_login_at, wiped_at` |
| `app_settings` | `DemoAccessController::putPinHours()` — `DemoAccessController.php:119-128` | Platform key/value | `tenant_id, ckey, value(longText)`, unique `(tenant_id, ckey)` |

### 2.2 Migration-backed tables this area writes

| Table | Written by |
|---|---|
| `tenants` | `SaasController::provisionTenantRecord()` `:143-158`, `InstallController.php:192-204`, `ClientProvision.php:66-78`, `DemoReset.php:57-71` |
| `companies` | same three provisioners + `DemoReset` |
| `users` | same, plus `DemoCompanySeeder`, `OnPremWorkspaceSeeder`, `SuperAdminSeeder` |
| `plans` | `StandardPlanSeeder.php:40-63` |
| `subscriptions` | `BillingController::saveSubscription()` `:262-267`, `SignupController.php:340-346,1115-1121`, `TenantBillingController::applyRenewal()` `:450-455`, `DemoReset.php:92-98` |
| `invoices` | `BillingController::createInvoiceForTenant()` `:318-323`, `TenantBillingController.php:465-471` |
| `payments` | `BillingController::recordPayment()` `:383-388`, `applyPayment()` `:425-430` |
| `payment_gateways` | `BillingController::saveGateway()` `:525-530` |
| `settings` | **`ClientUpdateController::saveState()` `:45-57` — row `tenant_id=0, key='licence'` holds the entire on-prem licence state as a JSON blob** |

### 2.3 Files that are part of the data model

| Path | Written by | Content | Survives an update? |
|---|---|---|---|
| `license.lic` (app root, or `config('smartprs.license_file')`) | `LicenseFile::write()` `:183-186` | The RSA-signed token | **Yes** — protected list, `updater.php:233` |
| `storage/app/.machine_fp` | `LicenseFile::machineFingerprint()` `:80` | 40 hex chars, the cached hardware fingerprint | Yes (`storage` protected) |
| `storage/app/keys/license_private.pem` | `MakeLicenseKeys.php:47-48` | **RSA-2048 private signing key — `/super` only** | Yes; **but excluded from every backup**, `config/backup.php:35` |
| `version.json` (app root) | `updater/updater.php:326-331` | `{product, version, channel, installed_at}` | Rewritten by the updater |
| `storage/app/updates/state.json` | `UpdateClient::writeState()` `:357-366`, `updater.php:66` | Live update phase/percent/log/poll_token | Yes |
| `storage/app/updates/job.json` | `UpdateClient::install()` `:317-318` | The updater's only input | Yes |
| `storage/app/updates/backup_<Ymd_Hi>/` | `updater.php:261-268` | Full copy of every replaced top-level entry | **Never pruned** |
| `storage/app/updates/updater.log` | `UpdateClient::spawn()` `:430-437` | stdout+stderr of the updater incl. `migrate --force` | Yes |
| `storage/app/releases/*.zip` | `ReleaseController::upload()` `:79-88` | Release packages (`/super` only) | Yes |

### 2.4 Provenance summary

- **19 tables in this area are created at runtime inside `try/catch`.** A DDL failure is silent (`LicenseService.php:154-156`: *"fail-soft: a broken DDL must never take the platform down"*). There is no version history, no rollback, and DDL executes on the request path.
- Because the live table shape is uncertain, nearly every insert/update goes through `ApprovalService::safeRow($table, $row)`, which **drops keys the live table does not have**. A business value written into a column that does not yet exist is discarded with no error.
- The critical on-prem licence state is not a table at all — it is a JSON blob in one `settings` row.

---

## 3. Business rules register

Legend for **HARD-CODED?**: Y = the value/behaviour is in PHP and needs a code change; N = configurable; P = partly.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? (how) | Could a customer want it different? |
|---|---|---|---|---|---|---|---|---|---|
| **BR-TEN-001** | Admin email is globally unique across the whole platform | `admin_email` | `LOWER(users.email)` already exists | Refuse before any write | Platform | `SignupController.php:186-188`, `:578-580`; `SaasController.php:136-138`; `InstallController.php:188-190`; `ClientProvision.php:57-61` | Y | No | N |
| **BR-TEN-002** | Workspace slug 3–10 lowercase alphanumerics, unique | `subdomain` | length or uniqueness fails | 422 at order/quote; silent auto-derive at provisioning | Platform | `SignupController.php:169-177`; `SaasController::resolveSubdomain()` | Y (3/10 literals) | No | Y — a customer may want longer/branded slugs |
| **BR-TEN-003** | The first company of a tenant is the MASTER company | provisioning | always | `companies.is_master = 1`, self-healing the column | Tenant | `SaasController.php:164-178`; `InstallController.php:206-218`; `ClientProvision.php:81-93` | Y | No | N |
| **BR-TEN-004** | Suspending a tenant disables every one of its user logins | super-admin action | `status=suspended` | cascade `users.status='disabled'` | Tenant | `SaasController::tenantStatus()`; effect at `EnsureSubscriptionActive.php:44-59` | Y | No | Y — reactivation re-enables users an admin had individually disabled |
| **BR-TEN-005** | Subscription-expiry suspension does NOT disable users | `billing:alerts` | `daysLeft < -7` | `tenants.status='suspended'` only | Tenant | `SubscriptionAlerts.php:60-69` | Y | No | N |
| **BR-TEN-006** | On-prem tenants are provisioned with 100,000 licensed seats | provisioning | deployment onprem | `seats_licensed = 100000` | Install | `ClientProvision.php:72`; `InstallController.php:198` | **Y** | No | **Y — this is why on-prem seat control depends entirely on the `.lic`** |
| **BR-SUB-001** | State machine `active → grace(7d) → locked`; no row = never gated | `tenant_id` | `today` vs `current_period_end` | return state | Tenant | `SubscriptionService::state()` `:45-78`; `GRACE_DAYS=7` `:34` | **Y (7)** | No | **Y — grace length is a commercial term** |
| **BR-SUB-002** | A seat = an active on-roll employee, tenant-wide across all companies | employees | `deleted_at IS NULL` AND `LOWER(status)` not in the free list | count | Tenant | `SubscriptionService::seatUsage()` `:81-121`; `SEAT_FREE_STATUSES` `:37` | **Y (the 6 statuses)** | No | **Y — customers define their own exit statuses** |
| **BR-SUB-003** | On an enforced on-prem install the `.lic` `device_limit` overrides subscription seats | `.lic` cert | on-prem AND `licence_enforce` AND `device_limit > 0` | `licensed = device_limit` | Install | `SubscriptionService.php:107-115`; `ClientUpdateController::seatLimit()` `:498-504` | Y | Per-`.lic` | N |
| **BR-SUB-004** | Seat ceiling blocks NEW employees only, never edits | `used`, `licensed`, `count` | `used + count > licensed` and `licensed > 0` | refuse with a worded error | Tenant | `canAddEmployees()` `:127-149`; doors `AppDataController.php:815,3042`, `RecruitmentController.php:602`, `TenantBillingController.php:292-295` | Y | No | Y |
| **BR-SUB-005** | Company ceiling strict from day one; each extra company ₹1,000/month | `companies` | `used + 1 > licensed` | refuse | Tenant | `companyUsage()` `:151-174`, `canAddCompany()` `:181-197`; `COMPANY_FEE = 1000.0` `BillingController.php:56` | **Y (₹1,000, and again as a literal in the error string `:194`)** | No | **Y — it is a price** |
| **BR-SUB-006** | Locked tenants: admin may renew, everyone else is signed out | role, path | `state === 'locked'` | admin gets `LOCKED_ALLOW` + single-segment GETs; others logged out | Tenant | `EnsureSubscriptionActive.php:82-120`; `LOCKED_ALLOW` `:26-30`; login door `AuthController.php:186-195` | Y | No | Y |
| **BR-SUB-007** | A disabled user is ejected on their very next request | `users.status` | `= 'disabled'` | 401 JSON or logout+redirect | Platform | `EnsureSubscriptionActive.php:44-59` | Y | No | N |
| **BR-SUB-008** | Grace is fully usable, warned by a banner only | state | `grace` | pass through | Tenant | `EnsureSubscriptionActive.php:71-73` | Y | No | Y |
| **BR-SUB-009** | Alerts at 15/7/3/1/0 days then daily through grace; auto-suspend after | `current_period_end` | matching `daysLeft` | email + WhatsApp, idempotent per tenant+kind+period+channel | Tenant | `SubscriptionAlerts.php:35-138`; `BEFORE_DAYS` `:35` | **Y (the ladder)** | No | **Y — cadence is a commercial choice** |
| **BR-SUB-010** | Renewing early never loses days | `current_period_end` | future | `end = current_period_end + months` | Tenant | `TenantBillingController::applyRenewal()` `:430-441` | Y | No | N |
| **BR-SUB-011** | A mid-term upgrade raises limits immediately, never moves the period end | seats/companies | upgrade mode | pro-rata charge, `current_period_end` untouched | Tenant | `prorataFor()` `:176-200`; `applyRenewal()` `:425-429` | Y | No | Y |
| **BR-SUB-012** | A renewal must cover current usage | `seats`, `companies` | below actual usage | 422 naming the counts | Tenant | `renewOrder()` `:286-295` | Y | No | N |
| **BR-SUB-013** | Renewal completion is idempotent per `renewals` row | `uuid` | `status='done'` | return success, change nothing | Tenant | `renewComplete()` `:378-381` | Y | No | N |
| **BR-SUB-014** | On-prem installs are never subscription-gated | edition | `isOnPrem()` | pass | Install | `EnsureSubscriptionActive.php:65-69` | Y | No | N |
| **NEW-SUB-01** | `trialing` is a valid subscription status but has no behaviour | `subscriptions.status` | `= 'trialing'` | falls into the normal date maths; `trial_ends_at` is never read | Tenant | `SubscriptionService.php:56` (only `cancelled`/`suspended` special-cased); accepted at `BillingController.php:245` | Y | No | **Y — there is no trial product** |
| **BR-BIL-001** | Invoice number `PRS-<FY>-<MM>-<nnnn>`, one consecutive FY series shared by SaaS and on-prem | existing numbers | always | MAX+1 over both tables | Platform | `nextInvoiceNumber()` `:79-99`; `finYear()` `:62-68` | **Y (format, FY boundary Apr)** | No | **Y — invoice format is a statutory/accounting preference** |
| **BR-BIL-002** | Quotation number `PRS-Q-<FY>-<MM>-<nnnn>`, its own series | `signups.quote_no` | always | MAX+1, re-implemented locally | Platform | `SignupController.php:614-624` | Y | No | Y |
| **BR-BIL-003** | Price = (base + per-user × extra + company fee) × months × (1 − cycle discount); GST 18% | plan, seats, cycle, companies | always | see §4.1 | Platform | `priceFor()` `:116-133`; `cycleMonths()` `:42-47`; `cycleDiscount()` `:49-53`; `GST=18.0` `:35` | **Y (18%, 10%, 25%, 3/6/12)** | Plan prices only, via the plans table | **Y — discounts and GST are commercial/statutory** |
| **BR-BIL-004** | Invoices are raised from the subscription's stored amount, due in 7 days | `subscriptions.amount` | `amount > 0` | insert `invoices` with `due_on = today + 7` | Tenant | `createInvoiceForTenant()` `:307-326`, `due_on` at `:321` | **Y (7 days)** | No | **Y — payment terms differ per customer** |
| **BR-BIL-005** | GST split decided per customer: GSTIN state code → state name → env | GSTIN, state | first-2 digits vs seller `(NN)` | intra ⇒ CGST 9 + SGST 9; else IGST 18 | Platform | `buyerIsIntraState()` `:810-830`; `invoice-pdf.blade.php:117-123` | P | `BILLING_SELLER_STATE`, `BILLING_GST_MODE` | Y |
| **BR-BIL-006** | Razorpay checkout signature must verify before anything is provisioned | order/payment/signature | HMAC mismatch or order mismatch | 422 | Platform | six copies: `BillingController.php:638-641`; `SignupController.php:276-279,859-862,906-909`; `TenantBillingController.php:389-392`; `OnpremClientController.php:635-638` | Y | No | N |
| **BR-BIL-007** | The Razorpay webhook is the safety net for a closed browser | raw body + `X-Razorpay-Signature` | valid HMAC and event in {payment.captured, order.paid} | resolve invoice → record → email receipt; **or** apply a pending renewal | Platform | `razorpayWebhook()` `:659-728` | Y | Webhook secret configurable | N |
| **BR-BIL-008** | Payments are idempotent on the gateway transaction id | `gateway_txn_id` | a success row already exists | skip | Platform | `recordPayment()` `:377-382`; `applyPayment()` `:416-424`; on-prem keys on `onprem_payments.reference` `:640` | Y | No | N |
| **BR-BIL-009** | Partial payment is computed from the ledger, never stored as a status | `payments` sum | `paid >= total − 0.01` | flip to `paid`, else leave `due` | Platform | `applyPayment()` `:413-446`; `invoicePaidSum()` `:397-401` | **Y (₹0.01 tolerance)** | No | N |
| **BR-BIL-010** | A workspace may be provisioned on credit (paid / partial / due) | `pay_status`, `amount`, `due_date` | super_admin | same chain minus Razorpay and auto-sign-in | Platform | `adminPayment()` `:984-1058`; `provisionOfflineSignup()` `:1065-1203` | Y | No | N |
| **BR-BIL-011** | Gateway secrets encrypted at rest; blank input preserves the stored value | secret, webhook_secret | non-empty | `Crypt::encryptString` | Platform | `saveGateway()` `:503-536`; `decryptOrRaw()` `:541-551` | Y | No | N |
| **BR-BIL-012** | Live mode requires a live key id, a secret and a webhook secret | gateway row | all three present | `liveReady = true` | Platform | `gateways()` `:479-496` | Y | No | N |
| **BR-BIL-013** | Invoices are emailed through the PLATFORM SMTP (tenant 0, company '0') | mail config | host + from present | send + stamp `emailed_at` + `mail_log` | Platform | `emailInvoice()` `:868-955` | Y | SMTP configurable in-app | N |
| **BR-BIL-014** | On-prem licence total = price + 18%; balance = total − paid_total | `onprem_clients.price`, `paid_total` | always | compute | Platform | `totals()` `:53-69`; `fullyPaid()` `:71-75` | **Y (literal `0.18`, not the GST constant)** | No | Y |
| **BR-BIL-015** | Auto-renewal invoicing exists but is NOT scheduled | `next_renewal` | `<= today` and `status='active'` and `amount > 0` | invoice + **advance the period** + email | Platform | `runRenewals()` `:986-1030`; command `billing:renew`; deliberately absent from `routes/console.php:171-178` | Y | Run manually | **Y — see DEF-11: it advances the period on invoice creation, not payment** |
| **BR-BIL-016** | Credit notes | — | — | **NOT IMPLEMENTED** — no table, model, controller, series, PDF or route | Platform | absence verified by grep; policy promise at `resources/views/policies/refund-policy.blade.php:47` | — | No | **Y — GST compliance requires them** |
| **BR-BIL-017** | A coupon stacks on the cycle discount and never touches the subscription rate | `price`, coupon | valid | discount the pre-GST amount, recompute GST | Platform | `CouponService::apply()` `:140-158`; writers `SignupController.php:340-360`, `TenantBillingController.php:443-486` | Y | No | Y |
| **BR-BIL-018** | Nine sequential coupon validations | code, plan, cycle, context, email, tenant | see §4.3 | `[coupon, null]` or `[null, message]` | Platform | `CouponService::validate()` `:75-133` | Y | Per-coupon limits are data | N |
| **BR-BIL-019** | Redemption only after a verified payment | coupon | payment recorded | insert redemption + `increment('used_count')` | Platform | `redeem()` `:219-233`; call sites `SignupController.php:364-373,1148-1157`, `TenantBillingController.php:489-499` | Y | No | N |
| **BR-BIL-020** | Email-exclusive coupons auto-apply when the buyer's email is caught | email | a valid exclusive exists | apply silently | Platform | `forEmail()` `:165-187` | Y | No | N |
| **BR-BIL-021** | The coupon box only appears when a public coupon is live | context | an active non-exclusive coupon exists | show the box | Platform | `publicCouponsExist()` `:195-216` | Y | Data-driven | N |
| **BR-BIL-022** | A redeemed coupon is disabled, never deleted | coupon | has redemptions | set `status='disabled'` | Platform | `CouponController::destroy()` `:161-175` | Y | No | N |
| **BR-LIC-001** | A licence key is `SPRS-` + four 4-char blocks from a 31-symbol unambiguous charset | — | issue | `random_int()` over `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (≈2^79) | Platform | `generateKey()` `:160-172`; `CHARSET` `:21` | Y | No | N |
| **BR-LIC-002** | One live licence per on-prem client | `licences` | a `pending\|active` row exists | refuse: "revoke it first" | Platform | `issueKey()` `:229-231`; auto-issue guard `:655` | Y | No | Y |
| **BR-LIC-003** | THE GATE: a key issues only on full payment, or with the explicit partial tick | `paid_total`, `price` | `! fullyPaid && ! activate_on_partial` | refuse, naming ₹paid of ₹total | Platform | `issueKey()` `:233-236`; `partialToggle()` `:209-221` | Y | Per-client tick | N |
| **BR-LIC-004** | Licence validity: explicit date wins, else term months, else one year | `licence_expires_on`, `licence_term_months` | date present and `>= today` | resolve an expiry date | Platform | `resolveExpiry()` `:84-100` | **Y (the 1-year fallback)** | Per-client | Y |
| **BR-LIC-005** | Online activation binds exactly one machine fingerprint | key, fingerprint, edition | already bound to a different fp | refuse + fraud incident | Platform | `UpdateServerController::activate()` `:45-100` | Y | No | N |
| **BR-LIC-006** | Machine fingerprint = `substr(sha256('SMARTPRS\|' + hardware UUID), 0, 40)` | OS/SMBIOS UUID | — | cache per-request AND to `storage/app/.machine_fp` | Install | `LicenseFile::machineFingerprint()` `:59-83`, `rawMachineId()` `:85-115` | Y | No | N |
| **BR-LIC-007** | Local licence state lives in `settings(tenant_id=0, key='licence')` as JSON | — | — | read/write the blob | Install | `ClientUpdateController::state()` `:31-43`, `saveState()` `:45-57` | Y | No | N |
| **BR-LIC-008** | `licenceValid()` requires deployment=onprem **AND** edition≠saas **AND** enforcement on **AND** an unexpired stored cert | `.env` + cert | any one fails | return `true` (allow) | Install | `licenceValid()` `:223-239` | Y | `SMARTPRS_LICENCE_ENFORCE` | **Y — see §7 B1/B2/B3** |
| **BR-LIC-009** | An RSA `.lic` must be signature-valid, edition-matched, unexpired, not locally revoked, node-locked, and within seats | token | any check fails | mapped customer-readable error | Install | `LicenseFile::verifyToken()` `:121-170`; `activateLicenceFile()` `:422-495` | Y | No | N |
| **BR-LIC-010** | A `.lic`'s node-lock is re-verified on EVERY request | `cert.source === 'file'` | verify fails | `state = 'none'` (blocked) | Install | `licenceStatus()` `:89-101` | Y | No | N |
| **BR-LIC-011** | Offline `.lic` activation is DISABLED by default; a `.lic` is routed ONLINE instead | `SMARTPRS_OFFLINE_LIC` | false (default) | extract `payload.key` **without verifying the signature** and activate online | Install | `config/smartprs.php:52`; `activateKey()` `:252-267`; `keyFromLicenceFile()` `:402-414` | N | `SMARTPRS_OFFLINE_LIC=true` | N |
| **BR-LIC-012** | Offline License Codes (SPRSX1): HMAC-SHA256 truncated to 160 bits, **shared secret** | payload, secret | signature mismatch | return null | Install | `LicenseService.php:179-286` | Y | `SMARTPRS_LICENCE_SECRET` | **NOT IMPLEMENTED — dead code (§6 DEF-01)** |
| **BR-LIC-013** | The offline signing secret has no working default in production | `licence_secret` | empty and `APP_ENV=production` | **throw** | Install | `offlineSecret()` `:191-205` | Y | env | — |
| **BR-LIC-014** | Offline-issued keys are recorded server-side so they stay revocable | token | issue | upsert the client's live licence row, index by the embedded plain key | Platform | `recordOffline()` `:293-326`; `keyInsideToken()` `:329-340` | Y | No | N |
| **BR-LIC-015** | Hybrid revocation sweeps at most once a day, at login, on-prem only | stored key | `last_revcheck` older than 24h | heartbeat; on revoked/suspended wipe the cert and blacklist the key hash | Install | `revocationSweep()` `:171-215`; caller `AuthController.php:206` | **Y (24h)** | No | **Y — and there is no scheduled heartbeat at all** |
| **BR-LIC-016** | A different machine on a bound key is an anti-fraud incident | fingerprint | mismatch | `rejected` event + `last_mismatch_at` + email sales (deduped 1/day) | Platform | `logRejection()` `:515-533`; `alertSalesMismatch()` `:535-558` | Y | `SMARTPRS_SALES_EMAIL` | N |
| **BR-LIC-017** | Server seats: one licence, `server_limit` devices (default 1) | `device_uid` | new device and count ≥ limit | `['ok'=>false,'reason'=>'device_limit_reached']` | Platform | `recordDevice()` `:449-482` | Y | `licences.server_limit` (no UI) | **Result is DISCARDED — §6 DEF-06** |
| **BR-LIC-018** | Machine moves: Release binding vs Shift machine | super-admin action | — | clear fingerprint, `reactivations_used++`; Shift also frees all device seats | Platform | `deactivate()` `:717-730`; `shiftMachine()` `:476-495` | Y | No | **The documented 1/year cap is NOT IMPLEMENTED — §6 DEF-05** |
| **BR-LIC-019** | Revocation blocks activation, heartbeat, update check and download — not a running app | `licences.status` | `revoked` | every server endpoint refuses | Platform | `revoke()` `:733-742`; `UpdateServerController.php:53-56,118-122,214-221,327-332` | Y | No | **Y — §6 DEF-08** |
| **BR-LIC-020** | AMC is active while `amc_expires_on >= today` (string date comparison) | date | — | boolean | Platform | `amcActive()` `:389-392` | Y | Per-licence | N |
| **BR-LIC-021** | Licence renewal extends from `max(current expiry, today)` | `renew_until` / `renew_months` | — | update `amc_expires_on` + `amc_renewed` event | Platform | `renewAmc()` `:692-714` | Y | Per-action | N |
| **BR-LIC-022** | LicenseGate allow-list is `app/activate`, `logout`, `app/change-password`; the gate FAILS OPEN | path, session | licence invalid | destroy the session and redirect to `/login` | Install | `LicenseGate.php:25,27-77` | Y | No | **Y — `app/first-password` is missing from the list** |
| **BR-LIC-023** | Expiry has no grace period on-prem — it is a hard cliff at midnight | `amc_expires_on` | `< today` | `state='expired'` | Install | `licenceStatus()` `:102-107`; `grace_days` signed at `LicenseSigner.php:52` and **never read** | Y | No | **Y — the `.lic` already carries a `grace_days` claim nobody honours** |
| **BR-LIC-024** | `notify` mode self-heals online; `renew` mode demands a code at the login form | `cert.expiry_mode`, `cert.offline` | expired | recheck stored key, or demand a `.lic` | Install | `AuthController.php:203-266`; mode resolution `ClientUpdateController.php:81` | Y | Per-client | N |
| **BR-LIC-025** | `/super` and the whole `/admin` tree are 404 on an on-prem edition | path | on-prem | `abort(404)` | Install | `Edition::BLOCK_SAAS` `:137-140`; `EditionGuard.php:35-40` | Y | No | N |
| **BR-LIC-026** | Editions are cosmetic — only the SaaS platform surfaces differ | edition | — | `level()` returns 3; `hiddenNavIds()`/`blockedPatterns()` return `SAAS_ONLY`/`BLOCK_SAAS` | Install | `Edition.php:93-97,119-127,169-174,176-180` | **Y** | No | **NOT IMPLEMENTED as a differentiator — §5, §6 DEF-02** |
| **BR-LIC-027** | The edition lock at activation IS enforced | `.lic`/key edition | ≠ `Edition::current()` | refuse with a named message | Both | `UpdateServerController.php:58-62`; `ClientUpdateController.php:324-327,441-444` | Y | No | N |
| **BR-UPD-001** | A release registers only if a client updater could install it | zip | no `app/`+`config/` at root (after unwrapping one folder) | reject and delete the upload | Platform | `upload()` `:62-119`; `inspectPackage()` `:132-189` | Y | No | N |
| **BR-UPD-002** | Publishing grants only to clients with an ACTIVE licence and live AMC | licences | `status='active'` and `amcActive()` | insert `release_grants` + email | Platform | `publish()` `:266-318` | Y | No | Y |
| **BR-UPD-003** | "Apply to this platform": maintenance → backup → extract → migrate → up, with rollback | release | any exception | restore the five code dirs, `up` | Platform | `applyPlatform()` `:195-263`; `CODE_DIRS` `:21` | Y | No | **Y — rollback is copy-over, §6 DEF-14** |
| **BR-UPD-004** | Version comes from `version.json`; config is a fallback; the channel survives an update | `version.json` | BOM-stripped decode | report version + channel | Install | `currentVersion()` `:44-49`; `channel()` `:51-60`; capture `updater.php:246-250` | Y | Edit `version.json` | N |
| **BR-UPD-005** | `/update/check` is read-only on licence identity | key, version, channel | — | touch only `last_seen_at` | Platform | `check()` `:199-295`, rationale `:186-196` | Y | No | N |
| **BR-UPD-006** | Package download uses a 60-minute cache token, never the key in a URL | token | valid in cache | re-check entitlement, stream the file | Platform | `:272-277`, `packageDownload()` `:304-341`; `TOKEN_TTL_MINUTES=60` `:28` | **Y (60)** | No | Y |
| **BR-UPD-007** | A downloaded package must match its sha256 or it is deleted | `package_hash` | **only when the server supplied one** | unlink + fail | Install | `download()` `:252-261`; repeated `updater.php:174-178` | Y | No | **Y — §6 DEF-09** |
| **BR-UPD-008** | Installation is handed to a detached standalone process | package, php binary | preconditions met | write `job.json`, spawn, return a `poll_url` | Install | `install()` `:280-329`; `spawn()` `:428-458` | Y | `SMARTPRS_PHP_BINARY` | N |
| **BR-UPD-009** | The updater refuses a payload that is not a SmartPRS tree before touching anything | extracted payload | no `app/`+`config/` | fail, change nothing | Install | `updater.php:196-241` | Y | No | N |
| **BR-UPD-010** | `.env`, `.env.bak`, `storage`, `license.lic`, `.git`, `node_modules` are never backed up or replaced | top-level entries | in the protected list | strip from the copy list | Install | `updater.php:233-234` | **Y (the list)** | No | **Y — `vendor/` is NOT protected** |
| **BR-UPD-011** | From the moment a backup exists, any failure rolls back the CODE | copy count, migrate exit, health exit | non-zero / zero files | remove each entry then restore from backup | Install | `updater.php:140-165,292-295,307-311,316-320` | Y | No | **Y — the database is never rolled back** |
| **BR-UPD-012** | Progress is read from a framework-free endpoint gated by a one-time token | `?t=` | `hash_equals(state.poll_token, t)` | JSON phase/percent/log | Install | `updater/update-status.php` | Y | No | N |
| **BR-UPD-013** | The successful backup is kept and never auto-pruned | — | success | message names the folder | Install | `updater.php:342-343` | Y | No | **Y — unbounded disk growth** |
| **BR-UPD-014** | Every update action is audited on both sides | action, detail | — | insert `client_updates` | Both | `UpdateServerController::log()` `:360-370`; `logLocal()` `:866-878` | Y | No | N |
| **NEW-UPD-01** | The update package is the ONLY delivery channel — application code, config defaults and statutory content ship together | release zip | — | full file replacement + `migrate --force` | Install | `updater.php:285-311`; no data-only path exists | **Y** | No | **Y — see §5(b) and Q4 in §10** |
| **NEW-UPD-02** | No queue worker is restarted after an update | — | — | nothing | Install | grep: no `queue:restart` anywhere in `updater.php` or `ReleaseController` | Y | No | **Y — workers keep running the pre-update code** |
| **NEW-UPD-03** | The post-update health check is `php artisan --version` only | exit code | non-zero | rollback | Install | `updater.php:316-320` | Y | No | **Y — it proves the CLI boots, not that HTTP or the DB work** |
| **BR-DEMO-01** | One passkey flow serves five entry points; `entry` is caller-supplied and public | name, mobile, email, `entry∈{demo,1,2,3,full}` | valid Indian mobile regex | 6-digit PIN emailed + WhatsApped, lead recorded | Platform | `requestPin()` `:231-315`; validation `:242` | Y | Validity hours 1–72 in the panel | **Y — §7 SEC-03** |
| **BR-DEMO-02** | `/app1-3` and `/teamdemo` sessions set `demo_team=1`, which bypasses DemoWriteGuard entirely | session | `demo_team` truthy | skip all 26 blocked write patterns | Platform | `enter()` `:402-406`; `DemoWriteGuard.php:57-59` | Y | No | **Y — §7 SEC-03** |
| **BR-DEMO-03** | Passkey window default 2h, clamped 1–72 | `app_settings.demo_gate` | — | expiry timestamp | Platform | `pinHours()` `:100-115` | N | Super Admin panel | N |
| **BR-DEMO-04** | A local/`.test`/`.local` host is never treated as the public demo | request host | matches | `isDemoTenant()` returns false | Platform | `DemoAccessController.php:61-68` | Y | No | N |
| **BR-DEMO-05** | The demo resets only when a window has ended and nobody with a live used passkey is inside | `demo_requests` | see `resetDue()` | wipe + reseed + stamp `wiped_at` | Platform | `DemoReset::resetDue()` `:161-183`; schedule `routes/console.php:204-211` | Y | No | N |
| **NEW-INS-01** | The browser installer self-disables the moment ANY user row exists | `users` | `exists()` | redirect to `/login` | Install | `InstallController::alreadyInstalled()` `:36-44` | Y | No | N |
| **NEW-INS-02** | 12 PHP extensions are hard-required; the SourceGuardian loader is only a warning | environment | error-level check fails | the admin form is withheld | Install | `REQUIRED_EXT` `:30-33`; SG check `:84-89`, level `'warn'` | **Y (the list)** | No | Y |

---

## 4. Calculations and formulas

### 4.1 Subscription price — the single source of pricing truth

`BillingController::priceFor($plan, int $seats, string $cycle, int $companies = 1): array` — `BillingController.php:116-133`, transcribed exactly:

```php
$included   = (int) ($plan->seat_max ?? 0);
$extra      = $included > 0 ? max(0, $seats - $included) : $seats;
$months     = self::cycleMonths($cycle);          // ['monthly'=>1,'quarterly'=>3,'halfyear'=>6,'annual'=>12][$cycle] ?? 3
$discount   = self::cycleDiscount($cycle);        // ['halfyear'=>0.10,'annual'=>0.25][$cycle] ?? 0.0
$companies  = max(1, $companies);
$companyFee = self::COMPANY_FEE * ($companies - 1);          // COMPANY_FEE = 1000.0
$perMonth   = (float) ($plan->base_price ?? 0)
            + (float) ($plan->per_user_price ?? 0) * $extra
            + $companyFee;
$amount     = round($perMonth * $months * (1 - $discount), 2);
$tax        = round($amount * self::GST / 100, 2);            // GST = 18.0
// total    = round($amount + $tax, 2)
```

**Divisor / basis:** the basis is a *per-month* rate multiplied by whole months. There is no daily proration on a new subscription. **Rounding:** `round(..., 2)` (PHP half-up on the 2nd decimal) applied twice — once to `amount`, once to `tax`; `total` is the rounded sum of two already-rounded values.

**Seeded plan values** (`StandardPlanSeeder.php:35-37`) — *the values the code currently uses; not verified as current commercial pricing*:

| Plan | `base_price` ₹/mo | `seat_max` included | `per_user_price` ₹/mo beyond |
|---|---|---|---|
| Starter | 1000 | 25 | 60 |
| Growth | 2500 | 75 | 50 |
| Professional | 5000 | 150 | 40 |

**Worked examples** (asserted green in `tests/Feature/BillingTest.php:17-35`):

- Growth, 80 seats, quarterly: `extra = 80 − 75 = 5`; `perMonth = 2500 + 50×5 = 2750`; `amount = 2750 × 3 × 1.00 = 8250.00`; `tax = 1485.00`; `total = 9735.00`.
- Growth, 80 seats, annual: `amount = 2750 × 12 × 0.75 = 24750.00`; `tax = 4455.00`; `total = 29205.00`.
- Growth, 40 seats, half-yearly: `extra = 0`; `amount = 2500 × 6 × 0.90 = 13500.00`; `tax = 2430.00`; `total = 15930.00`.
- Growth, 80 seats, quarterly, 3 companies: `companyFee = 1000 × 2 = 2000`; `perMonth = 4750`; `amount = 14250.00`.
- `cycle = 'monthly'` is rejected 422 by every validator (`in:quarterly,halfyear,annual`), but `cycleMonths()` still maps it to 1 for legacy rows.

**Legacy mode:** a plan with `seat_max` null or 0 makes `extra = $seats`, i.e. `base + per_user × ALL seats`. Silent behaviour change if `seat_max` is ever cleared.

### 4.2 Pro-rata mid-term upgrade

`TenantBillingController::prorataFor()` — `:176-200`:

```php
$end           = Carbon::parse($sub->current_period_end)->endOfDay();
$remainingDays = max(1, (int) ceil(now()->floatDiffInDays($end, false)));
$oldPm         = priceFor(currentPlan, $sub->seats,  $sub->cycle, $sub->companies)['per_month'];
$new           = priceFor($plan,       $seats,       $sub->cycle, $companies);
$diffPm        = max(0.0, $new['per_month'] - $oldPm);
$months        = $remainingDays / 30.44;
$amount        = round($diffPm * $months * (1 - $new['discount']), 2);
$tax           = round($amount * 18 / 100, 2);     // <-- literal 18, NOT BillingController::GST
```

**Divisor: `30.44` days per month — a hard-coded average**, not the actual month length. **Rounding:** `ceil()` on remaining days (favours the vendor), then `round(,2)` twice.

Worked example: Growth 40→100 seats, quarterly, 45 days remaining. `oldPm = 2500`; `newPm = 2500 + 50×25 = 3750`; `diffPm = 1250`; `months = 45 / 30.44 = 1.4783`; `amount = round(1250 × 1.4783 × 1.0, 2) = 1847.87`; `tax = 332.62`; `total = 2180.49`. The period end does not move.

Downgrades are refused mid-term: `diffPm` is floored at 0, `total` becomes 0, and `quote()` returns 422 *"Nothing extra selected — increase employees or companies (downgrades apply at renewal)"* (`:229-231`).

### 4.3 Coupon discount

`CouponService::apply(array $price, object $coupon)` — `:140-158`:

```php
$amount    = (float) $price['amount'];                       // ALREADY cycle-discounted
$disc      = $coupon->type === 'flat'
               ? min((float) $coupon->value, $amount)        // flat is capped at the bill
               : round($amount * (float) $coupon->value / 100, 2);
$disc      = max(0, round($disc, 2));
$newAmount = round($amount - $disc, 2);
$tax       = round($newAmount * BillingController::GST / 100, 2);   // GST recomputed on the NET
// total   = round($newAmount + $tax, 2)
```

Worked example: Growth 80 seats annual = `24750.00`; coupon `SAVE20` (percent 20) ⇒ `disc = 4950.00`; `newAmount = 19800.00`; `tax = 3564.00`; `total = 23364.00`. The `subscriptions.amount` row is still written as **24750.00**, so the next renewal bills full price (`SignupController.php:340-346`).

**Nine validation rules in order** (`CouponService::validate()` `:75-133`): (1) non-empty; (2) exists AND `status='active'` — identical message for both, so no enumeration; (3) `exclusive_email` must match the buyer email case-insensitively; (4) `applies_to ∈ {both, context}`; (5) `valid_till >= today` compared at `endOfDay`; (6) `used_count < max_uses`; (7) `CYCLE_RANK[$cycle] >= CYCLE_RANK[$min_cycle]` where `quarterly=1, halfyear=2, annual=3` (`:69`); (8) `plan_ids` CSV contains the plan; (9) `once_per_customer`: no prior redemption for this `LOWER(email)` **OR** this `tenant_id`.

**Unclamped at apply time:** a `percent` coupon with `value > 100` is refused at creation (`CouponController.php:53-55,104-106`) but `apply()` has no clamp, so a row edited directly in the database yields a negative `amount`, negative `tax`, negative `total` and a negative `paise` figure that Razorpay rejects — a loud failure at the customer.

### 4.4 GST split

`BillingController::buyerIsIntraState($tenant, ?array $seller): bool` — `:810-830`:

```php
$sellerState = $seller['state'] ?? env('BILLING_SELLER_STATE', 'Telangana (36)');
$sellerCode  = preg_match('/(\d{2})/', $sellerState, $m) ? $m[1] : '36';
$gstin       = strtoupper(trim((string) ($tenant->gstin ?? '')));
if (preg_match('/^(\d{2})/', $gstin, $g)) { return $g[1] === $sellerCode; }
$state = strtolower(trim((string) ($tenant->state ?? '')));
if ($state !== '') {
    $sellerName = strtolower(preg_replace('/[^a-z ]/i', '', $sellerState));
    return trim($sellerName) !== '' && str_contains($state, trim(explode(' ', trim($sellerName))[0]));
}
return env('BILLING_GST_MODE', 'inter') === 'intra';
```

Intra ⇒ **CGST 9% + SGST 9%**; inter ⇒ **IGST 18%** (`invoice-pdf.blade.php:117-123`, split computed as `round($tax / 2, 2)` — so an odd-paise tax splits unevenly by design, and CGST+SGST can differ from `$tax` by ₹0.01).

Seller defaults hard-coded in `sellerProfile()` `:741-754`: `Ametecs India Private Limited`, GSTIN `36AAHCT0971F1ZB`, the full registered address, `+91 96666 12424`, `Telangana (36)`, SAC `998314`. The on-prem PDF uses a **different SAC, `997331`**, and hard-codes `36AAHCT0971F1ZB` and `Telangana (36)` again as template fallbacks (`onprem-invoice-pdf.blade.php:41,74`).

### 4.5 Invoice numbering

`BillingController::nextInvoiceNumber()` `:79-99`:

```
prefix = 'PRS-' . finYear() . '-'
max    = MAX over invoices.number AND onprem_clients.invoice_no of the trailing -(\d+) where the value LIKE prefix%
number = prefix . now()->format('m') . '-' . str_pad(max + 1, 4, '0', STR_PAD_LEFT)
```

`finYear()` `:62-68`: `$y = $d->month >= 4 ? $d->year : $d->year - 1;  return $y.'-'.substr((string)($y+1), -2);` — Indian April–March. July 2026 ⇒ `2026-27`; February 2027 ⇒ `2026-27`; April 2027 ⇒ `2027-28`.

Example: `PRS-2026-27-07-0001`. The counter is FY-wide; the `<MM>` segment is the **current** month, which is not necessarily the month of `issued_on` if a row is edited later.

Quotations use a separate series computed by a **second copy** of the same algorithm over `signups.quote_no`: `PRS-Q-<FY>-<MM>-<nnnn>` (`SignupController.php:617-624`).

### 4.6 Seat count

`SubscriptionService::seatUsage()` `:88-97`:

```sql
SELECT COUNT(*) FROM employees
WHERE tenant_id = ?
  AND deleted_at IS NULL                       -- only if the column exists
  AND (status IS NULL OR LOWER(status) NOT IN
       ('exited','inactive','resigned','terminated','left','absconded'))
```

`ClientUpdateController::activeEmployeeCount()` `:507-529` is the *same rule for the `.lic`* **plus** `AND archived_at IS NULL`. **Two different definitions of "active employee" exist in one product.** See §6 DEF-04.

`licensed` resolution order (`:99-115`): `subscriptions.seats` → `tenants.seats_licensed` → on an enforced on-prem install, overridden by the `.lic` `device_limit` when `> 0` → `0` means unlimited.

### 4.7 On-prem licence money

`OnpremClientController::totals()` `:53-69`:

```php
$price = (float) $c->price;
$tax   = round($price * 0.18, 2);          // literal, not BillingController::GST
$total = round($price + $tax, 2);
$balance = max(0, round($price + $tax - (float) $c->paid_total, 2));
```

`fullyPaid()` `:71-75`: `return $c->price > 0 && (float) $c->paid_total >= self::totals($c)['total'];`

Worked example: price ₹250,000 ⇒ tax ₹45,000 ⇒ total ₹295,000. Paid ₹200,000 ⇒ balance ₹95,000, `fullyPaid = false`, so `issueKey()` refuses unless `activate_on_partial` is ticked. `amc_percent` defaults to **18** (`LicenseService.php:39`) but **is never used in any calculation** — it is a recorded sales figure only (grep confirms no consumer).

### 4.8 Licence expiry resolution

`OnpremClientController::resolveExpiry()` `:84-100`:

```
if licence_expires_on is set AND substr(it,0,10) >= today  -> that date
elseif licence_term_months > 0                             -> now()->addMonths(term)
else                                                       -> now()->addYear()
```

`renewAmc()` `:692-714`: explicit `renew_until` if `>= today`; else `base = max(amc_expires_on, today)` then `+ renew_months` or `+ client term_months`, else `+ 1 year`.

### 4.9 Machine fingerprint

`LicenseFile::machineFingerprint()` `:59-83`:

```
raw = Windows : wmic csproduct get uuid  → else powershell (Get-CimInstance Win32_ComputerSystemProduct).UUID
      Linux   : /etc/machine-id → /var/lib/dbus/machine-id
      macOS   : ioreg IOPlatformExpertDevice → IOPlatformUUID
      fallback: php_uname('n') . '|' . php_uname('s') . '|' . php_uname('m')
fp  = substr(hash('sha256', 'SMARTPRS|' . raw), 0, 40)
```

Cached in a per-request `static` **and** persisted to `storage/app/.machine_fp`; a file containing 40 hex characters is returned **before any hardware is read** (`:73-78`).

### 4.10 Version comparison

`UpdateServerController::numeric()` `:373-376`: `preg_replace('/[^0-9.]/', '', $v) ?: '0'`, then `version_compare($relNum, $curNum, '<=')` ⇒ up-to-date. The comment at `:256-257` explains the reason: string ordering would sort `2026.6.10` below `2026.6.9` and hide every tenth release.

---

## 5. Configuration surface today

### (a) What a customer CAN change without code

| # | What | How | Scope |
|---|---|---|---|
| 1 | Deployment model | `SMARTPRS_DEPLOYMENT=saas\|onprem` in `.env` | Install |
| 2 | Edition | `SMARTPRS_EDITION=saas\|l1\|l2\|l3` in `.env` (read directly from the file, so `config:cache` cannot stale it) | Install |
| 3 | Whether the licence gate runs at all | `SMARTPRS_LICENCE_ENFORCE` | Install |
| 4 | Whether offline `.lic` activation is permitted | `SMARTPRS_OFFLINE_LIC` | Install |
| 5 | Where the licence file lives | `SMARTPRS_LICENSE_FILE` | Install |
| 6 | The update/licence server URL | `SMARTPRS_UPDATE_URL` (default `https://smartprs.com/update`) | Install |
| 7 | The PHP CLI binary the updater spawns | `SMARTPRS_PHP_BINARY` | Install |
| 8 | Update channel | `version.json` → `"channel": "beta"` — **but the server ignores it (DEF-10)** | Install |
| 9 | Plan names, base price, per-user price, seat_max, status | `plans` table via `/app/saas/plans` (super_admin) | Platform |
| 10 | A tenant's plan / seats / companies / cycle / status | `/app/billing/subscriptions` (super_admin) | Tenant |
| 11 | Coupon code, type, value, expiry, max uses, min cycle, plan restriction, one-per-customer, applies-to, exclusive email | `/admin/coupons` (super_admin) | Platform |
| 12 | Razorpay mode, key id, secret, webhook secret | `/app/billing/gateways`, encrypted at rest; env fallback | Platform |
| 13 | Seller identity on invoices | `BILLING_SELLER_*` env vars — **but real values are hard-coded as defaults** | Platform |
| 14 | GST intra/inter fallback | `BILLING_GST_MODE` | Platform |
| 15 | On-prem client record: edition, employee band, price, AMC %, validity (months or exact date), expiry behaviour (`renew`/`notify`), activate-on-partial | `/admin/onprem` (super_admin) | Per client |
| 16 | Licence seat cap | the `seats` field on the "Generate .lic" form ⇒ `device_limit` in the token | Per licence |
| 17 | Server seat count | `licences.server_limit` — **no UI, SQL only** | Per licence |
| 18 | Demo passkey validity | `/app/saas/demo-requests` → hours 1–72 | Platform |
| 19 | Demo passkey resend / revoke | same screen | Per request |
| 20 | Release notes + which clients are granted | `/admin/releases` | Per release |
| 21 | Whether demo seed data is created | `SMARTPRS_DEMO_DATA` (+ hard block in production) | Install |
| 22 | The platform super-admin identity at seed time | `SMARTPRS_ADMIN_EMAIL` / `_NAME` / `_PASSWORD` | Install |
| 23 | Whether the WhatsApp welcome carries the temp password | `SMARTPRS_WA_SEND_PASSWORD` (default **true**) | Install |
| 24 | Fraud-alert recipient | `SMARTPRS_SALES_EMAIL` — **but `config('smartprs.sales_email')` does not exist, so the hard-coded personal address is the real default** | Platform |
| 25 | Backup archive password | `BACKUP_ARCHIVE_PASSWORD` (unset by default) | Install |

### (b) What a customer CANNOT change without code — the SmartPRS 3 requirements backlog for this area

**Pricing and billing**

1. The GST rate `18.0` (`BillingController.php:35`), and its two duplicate literals (`TenantBillingController.php:189`, `OnpremClientController.php:56`), plus the `9%`/`18%` literals rendered in `onprem-invoice-pdf.blade.php:60-63` and `quotation-pdf.blade.php:119-122`.
2. The advance-payment discount ladder `halfyear = 10%`, `annual = 25%` (`:49-53`).
3. The billing cycle set `{quarterly, halfyear, annual}` and their month counts `{3, 6, 12}` (`:42-47`); monthly is rejected everywhere.
4. The additional-company fee **₹1,000/month** (`COMPANY_FEE`, `:56`) — and a second time as a literal inside the error string at `SubscriptionService.php:194` and in three email/PDF strings.
5. The invoice-number format `PRS-<FY>-<MM>-<nnnn>` and the April FY boundary.
6. The quotation-number format `PRS-Q-…` and the 15-day validity constant `QUOTE_VALID_DAYS` (`SignupController.php:468`).
7. Invoice payment terms — `due_on = today + 7 days`, hard-coded at `BillingController.php:321`.
8. The settlement tolerance `₹0.01` (`:432`).
9. The SAC codes `998314` (SaaS) and `997331` (on-prem licence).
10. The sellable-plan whitelist — literally the names `['Starter','Growth','Professional']` (`SignupController.php:126`, `TenantBillingController.php:90`). **Renaming a plan removes it from signup silently.**
11. The pro-rata divisor `30.44`.
12. Credit notes — no mechanism at all.
13. Refunds — `payments.status` has a `'refunded'` value that no code writes.
14. Currency: `INR` and `money_scale = 2` (`config/smartprs.php`), plus `₹` hard-coded in ~40 message strings.

**Subscription lifecycle**

15. `GRACE_DAYS = 7` (`SubscriptionService.php:34`).
16. The seat-free status list `['exited','inactive','resigned','terminated','left','absconded']` (`:37`).
17. Whether `archived_at` frees a seat — hard-coded differently in two places.
18. The alert ladder `[15, 7, 3, 1, 0]` then daily through grace (`SubscriptionAlerts.php:35`).
19. What a locked tenant may still reach — the `LOCKED_ALLOW` array and the regex `#^app/[a-z0-9-]*$#`.
20. Trial length, trial behaviour, trial expiry — no implementation exists.
21. Whether an unpaid renewal invoice extends the period (it does — `runRenewals()` `:1005-1013`).

**Licensing**

22. Which of the three licence mechanisms is used, and their formats.
23. The RSA public key is a **PHP constant** (`LicenseFile.php:34-44`); rotating it is a code change plus a rebuild of every edition package plus a re-issue of every live `.lic`.
24. `grace_days` is signed into every `.lic` and **never read** — on-prem expiry is a hard cliff. Making it work is a code change.
25. The revocation-sweep interval (24h) and the fact that it only runs at login.
26. The `LicenseGate` allow-list (`app/activate`, `logout`, `app/change-password`) — notably **`app/first-password` is absent**, so a freshly provisioned admin on an unactivated on-prem box is signed out instead of reaching the mandatory create-password screen.
27. `reactivations_used` — the documented "1 machine move per year" cap does not exist.
28. `server_limit` has no UI.
29. The fingerprint algorithm and its sources.
30. `seats_licensed = 100000` on every on-prem provision.
31. The licence key charset and format `SPRS-XXXX-XXXX-XXXX-XXXX`.
32. Edition capability sets — `DNA`, `L2_MODULES`, `BLOCK_DNA`, `BLOCK_L2` are compiled-in constants that are no longer returned by anything. **Restoring per-edition gating is a code change; changing what is in each edition is a code change.**
33. `Edition::label()` returns the literal `'SmartPRS'` for every edition.

**Updates and packaging**

34. There is exactly **one** update artefact — a zip of the application tree. There is **no separate channel for configuration, statutory content or master data**. See §10 R-30.
35. The protected-path list `['.env','.env.bak','storage','license.lic','.git','node_modules']` — `vendor/` is deliberately *not* protected, so a package can replace it.
36. The post-update health check (`artisan --version`).
37. The download token TTL (60 minutes) and the package-hash-optional behaviour.
38. Backup retention for `storage/app/updates/backup_*` — none.
39. Which directories `applyPlatform()` backs up (`['app','config','database','resources','routes']` — **`public/` is overwritten but never backed up**).

**Demo and installation**

40. The five demo entry points and the fact that `entry=full` is publicly requestable.
41. The demo tenant is identified solely by `subdomain = 'demo'`.
42. The `DemoWriteGuard` blocked-path list (26 regexes).
43. The demo account emails and the default demo password `smartprs-demo`.
44. The installer's required-extension list and PHP floor (8.2 hard, 8.3 advisory).
45. The `team_pin` config key (`'ametecs'`) that nothing reads.

**Deployment**

46. Only the `mail` queue is worked (per the shipped Supervisor/systemd units); anything dispatched to `default` is never processed.
47. `billing:renew` is not scheduled; there is no scheduled licence heartbeat and no scheduled update check.
48. There is no Windows scheduler or Windows service artefact for the on-prem product at all — the only scheduler artefact in the delivery is a UNIX crontab line.

---

## 6. Defects, conflicts and technical debt

Severity scale: **CRITICAL** (money or licence enforcement is void) · **HIGH** (a customer-visible promise is false, or revenue leaks) · **MEDIUM** · **LOW**.

### 6.1 Licensing

**DEF-01 · HIGH · The whole SPRSX1 offline-licence path is unreachable dead code, and the customer-facing email still promises it works offline.**
Evidence: `LicenseService::makeOfflineKey()` `:240-269` is called only from `OnpremClientController::offlineKey()` `:272-319`; that method has **no route** — `routes/web.php:254` says the `.lic` route *"replaces the retired SPRSX1 offline-key route"*. `ClientUpdateController::activateOfflineKey()` `:311-382` has **no caller** — `activateKey()` `:269-271` states *"the legacy SPRSX1 HMAC offline path is retired"*. Verified by grep across `app/`, `routes/`, `resources/`, `config/`, `database/`, `tests/`. The orphaned UI block at `admin/onprem.blade.php:166-175` can never fire.
*Business impact:* the email text at `OnpremClientController.php:302` — *"It works offline — no internet needed"* — can never be sent, and if it somehow were, `activateKey()` would ship the entire SPRSX1 string to `POST /update/activate` as a key, i.e. it would need internet. Air-gapped customers have exactly one path (`.lic` with `SMARTPRS_OFFLINE_LIC=true`), and the default configuration disables it.

**DEF-02 · HIGH (commercial) · Editions L1/L2/L3 are byte-identical products.**
```php
// app/Services/Edition.php:93-97
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int { return 3; }

// app/Services/Edition.php:119-127
public static function hiddenNavIds(): array
{
    return self::current() === 'saas' ? [] : self::SAAS_ONLY;
}

// app/Services/Edition.php:169-174
public static function blockedPatterns(): array
{
    return self::current() === 'saas' ? [] : self::BLOCK_SAAS;
}

// app/Services/Edition.php:176-180
public static function label(): string { return 'SmartPRS'; }
```
`BLOCK_DNA` (`:149-155`), `BLOCK_L2` (`:158-167`), `DNA` (`:105`) and `L2_MODULES` (`:108-117`) are declared and **never returned by any method** (grep). Three price points, one product.
*Business impact:* the sales desk records `onprem_clients.edition` and `/app1` `/app2` `/app3` are marketed as three different products, but an L1 customer receives everything L3 does. Sales-integrity and revenue-recognition risk.

**DEF-03 · MEDIUM · `EditionGuard`'s "friendly 403 upsell" is unreachable.**
`Edition::platformPatterns()` `:142-146` and `Edition::blockedPatterns()` `:169-174` return the **identical** array. `EditionGuard.php:35-40` runs `abort(404)` on the first list before ever reaching the 403 branch at `:42-53`. The upsell message naming `ejaz@ametecsindia.com · WhatsApp 9000098877` can never be produced.

**DEF-04 · HIGH · Two contradictory definitions of "active employee", and the on-prem error message states the wrong one.**
`SubscriptionService::seatUsage()` `:88-97` does **not** exclude `archived_at`. `ClientUpdateController::activeEmployeeCount()` `:515-520` **does**:
```php
if (Schema::hasColumn('employees', 'archived_at')) { $q->whereNull('archived_at'); }
```
Yet `canAddEmployees()` tells the on-prem customer *"moving an employee to Inactive or **Old-data** frees a seat"* (`SubscriptionService.php:141`) — false on the SaaS counter, true on the `.lic` counter.
*Business impact:* on SaaS a tenant can be blocked from hiring by archived people they cannot see on any screen; on-prem, archiving is an unlimited seat-cap bypass through the normal UI.

**DEF-05 · MEDIUM · `reactivations_used` is written and never read.** The schema comment says *"Q5: 1/year self-service"* (`LicenseService.php:72`). `shiftMachine()` `:484` and `deactivate()` `:724` both increment it; grep finds **no comparison, no cap, no annual reset**. Machine moves are unlimited.

**DEF-06 · MEDIUM · The server-seat cap is computed and then discarded on both paths that matter.**
`LicenseService::recordDevice()` `:449-482` correctly returns `['ok'=>false,'reason'=>'device_limit_reached']`. `UpdateServerController::activate()` `:92` and `heartbeat()` `:145` call it and **ignore the return value** — the licence activates and the certificate issues anyway. Only `POST /update/device/activate` `:159-172` honours it, and **no shipped client code ever calls that endpoint** (grep across `app/`, `resources/`). Worse, any exception returns `['ok' => true, 'reason' => 'store_error']` (`:479-481`) — a DB error silently grants a seat.

**DEF-07 · MEDIUM · The activation certificate's HMAC signature is produced and never verified.**
```php
// app/Services/LicenseService.php:410-422
$payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));
```
Grep for a consumer of `cert['sig']` finds none. `licenceStatus()` `:74-108` reads `cert.amc_expires_on` and `cert.expiry_mode` directly. And even if it were checked, the HMAC key is the licence key, which the client already holds encrypted in `key_enc` — it proves nothing against a party on the client machine. **Consequence: a single `UPDATE settings SET value = '{"cert":{"amc_expires_on":"2099-12-31"},"activated_at":"2026-01-01"}' WHERE tenant_id=0 AND key='licence'` grants a permanent licence.**

**DEF-08 · HIGH (commercial) · A node-locked `.lic` on an internet-isolated machine is effectively un-revokable.**
`revoke()` `:733-742` only sets `licences.status='revoked'` on the vendor's server. The client only learns of it through `revocationSweep()` `:171-215`, which (a) runs only on-prem, (b) only from `AuthController::login()` `:206`, (c) at most once per 24 hours, and (d) returns early when `serverLicenceStatus()` is `null` — which is what happens when the client firewalls `smartprs.com`. There is **no scheduled heartbeat** anywhere in `routes/console.php`. A `.lic` is verified entirely offline. So a non-paying customer who blocks one outbound host keeps running until `expires_at`. `destroy()` `:750-768` is worse — its own docblock warns that deleting the client record does nothing to the running install.

**DEF-09 · MEDIUM · Package integrity verification is conditional on both sides.**
```php
// app/Services/UpdateClient.php:254-261
if (! empty($available['package_hash'])) { ... hash_equals ... }
// updater/updater.php:174
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) { ... }
```
A server reply with no `package_hash`, or a `releases` row whose `checksum` is null, silently skips verification end to end. `ReleaseController::upload()` `:109` always computes the checksum on upload, so a null is unlikely in practice — but nothing enforces it.

**DEF-10 · LOW · The beta channel is a client-side illusion.** `UpdateClient::check()` sends `'channel' => $this->channel()` (`:127`); `UpdateServerController::check()` reads it into `$channel` (`:203`) and never uses it. There is no `releases.channel` column.

### 6.2 Billing and subscriptions

**DEF-11 · HIGH · `runRenewals()` advances the billing period when the invoice is CREATED, not when it is PAID.**
```php
// app/Http/Controllers/BillingController.php:1003-1013
$inv    = self::createInvoiceForTenant((int) $sub->tenant_id);
$months = (new self())->cycleMonths($sub->cycle);
$base   = $sub->next_renewal ? Carbon::parse($sub->next_renewal) : now();
$next   = $base->copy()->addMonths($months);
DB::table('subscriptions')->where('id', $sub->id)->update([
    'current_period_end' => $next->toDateString(),
    'next_renewal'       => $next->toDateString(), ...]);
```
This is asserted as correct behaviour by `tests/Feature/BillingTest.php:140-156`.
*Business impact:* if anyone ever schedules or manually runs `billing:renew`, **every tenant's subscription is extended by a full cycle whether or not the invoice is paid**, so `SubscriptionService::state()` can never reach `grace` or `locked` and the entire lock-out mechanism becomes inert. The command is deliberately unscheduled (`routes/console.php:171-178`), which is the only thing preventing this — a documentation-level control over a code-level defect.

**DEF-12 · MEDIUM · Two payment recorders write to one ledger with different semantics.**
`recordPayment()` `:375-394` always inserts a payment of the **full** `amount + tax`. `applyPayment()` `:413-446` inserts the **given** amount. A credit client who part-pays offline (`applyPayment`) and then completes online through the webhook (which always calls `recordPayment`, `:716`) gets a full-amount row **on top of** the partial one, so `invoicePaidSum()` exceeds the invoice total and bank reconciliation fails.

**DEF-13 · MEDIUM · Idempotency on `payments.gateway_txn_id` is a SELECT-then-INSERT race with no unique index.** Both recorders do `->exists()` then `->insert()`. A webhook retry concurrent with the browser `verify` call double-records.

**DEF-14 · MEDIUM · Quotation expiry is displayed but never enforced.** `showQuote()` `:742` computes `$expired` and passes it to the view; `quoteOrder()` `:779-834` and `quoteComplete()` `:837-915` never check it. An expired quotation is still payable at the locked-in price.

**DEF-15 · MEDIUM · Provisioning is not transactional.** `provisionPaidSignup()` `:293-460` and `provisionOfflineSignup()` `:1065-1203` run a linear chain of inserts with **no `DB::transaction()`**. Failure matrix:

| Failure point | State left behind |
|---|---|
| `provisionTenantRecord()` throws | Nothing created, `signups.status` stays `pending`, **the customer has paid and received nothing** — and the webhook has no signup-recovery path (DEF-16) |
| `subscriptions` insert throws | Tenant live and usable, no subscription ⇒ `state()` returns `none` ⇒ **never gated, never billed** |
| Invoice creation throws | Tenant + subscription live, the money is untraceable in-app |

**DEF-16 · MEDIUM · The Razorpay webhook recovers a pending renewal but not a pending signup.** `:698-709` handles `renewals`; there is no equivalent `signups WHERE gateway_order_id = ? AND status='pending'` lookup. The webhook logs *"no matching invoice"* and returns 200 (`:711-713`), so nothing self-heals.

**DEF-17 · MEDIUM · The invoice PDF is not a historical record.** `buildInvoicePdf()` `:769-770` reads the **current** `subscriptions` row for the plan name and seat count. After an upgrade, reprinting an old invoice shows the new figures. Tax values are safe (they come from the `invoices` row); the description is not.

**DEF-18 · MEDIUM · `payComplete()` records the wrong amount.** `OnpremClientController.php:643` inserts `'amount' => $t['balance']` — the balance computed at completion time, not the amount Razorpay actually captured. The quotation flow solves exactly this by reading the order back from the Razorpay API (`SignupController.php:865-876`); this path does not.

**DEF-19 · MEDIUM · The auto-issue licence email states the wrong AMC date.** `OnpremClientController.php:656` issues with `resolveExpiry($c)`; `:669` then emails *"AMC (updates & support) valid till: "*`.now()->addYear()->toDateString()`. A 3-month or 5-year term is mis-stated to the customer.

**DEF-20 · MEDIUM · Invoice numbering is not atomic.** `nextInvoiceNumber()` reads MAX, the caller inserts separately, no lock and no transaction. `invoices.number` is UNIQUE so a race throws loudly — but the customer sees a raw 422 SQL message **after** their payment was captured. `onprem_clients.invoice_no` has **no unique index**, so a race there duplicates silently. The scan is also O(all FY invoices) via `pluck()` on every invoice creation.

**DEF-21 · MEDIUM · Coupon `max_uses` is a check-then-increment race.** `validate()` `:98` reads `used_count`; `redeem()` `:229` increments. Two simultaneous checkouts of a `max_uses=1` coupon both pass and both redeem.

**DEF-22 · LOW · The quotation number series can collide silently.** `signups.quote_no` has no unique index and the MAX+1 scan at `SignupController.php:617-624` is unlocked.

**DEF-23 · MEDIUM · Trial is schema-only.** `subscriptions.status` accepts `trialing` (`BillingController.php:245`) and `tenants.trial_ends_at` exists, but `SubscriptionService::state()` `:56` only special-cases `cancelled`/`suspended`, nothing ever sets `trialing`, `trial_ends_at` is never read, and `SubscriptionAlerts` `:56` only warns when `status === 'active'` — so a `trialing` subscription gets **no expiry warnings at all** and then drops straight into grace/locked.

**DEF-24 · MEDIUM · Credit notes do not exist.** No table, model, controller, number series, PDF or route. `payments.status` has a `'refunded'` value no code writes. `resources/views/policies/refund-policy.blade.php:47` publicly promises *"GST on refunded invoices is adjusted by credit note as per GST rules"*.

### 6.3 The update system

**DEF-25 · HIGH · There is no database backup and no database rollback anywhere in the update flow.**
`updater.php:252-269` backs up **files only**. Step 9 runs `migrate --force` (`:307`). `rollback()` `:140-165` restores **code only** — its own comment acknowledges the risk (`:134-138`). If a migration partially applies and then fails, the tree is rolled back to the old code against a schema that has already moved forward, and the app is brought back up in that state. The only DB safety net is the nightly spatie `backup:run`, which the updater does not trigger and which an on-prem Windows install typically does not even run (no Windows scheduler artefact ships — see DEF-31).

**DEF-26 · MEDIUM · The post-update health check does not prove the application works.** `updater.php:316-320` runs `artisan --version`. That proves the CLI autoloader boots. It does not touch the database, does not exercise a route, and does not confirm the web SAPI can serve a page. An update that boots the CLI but 500s every HTTP request is reported as `done, 100%`.

**DEF-27 · MEDIUM · No worker restart, and caches are cleared but never rebuilt.** Grep finds **no `queue:restart`** in `updater.php` or `ReleaseController`. Long-running `queue:work` processes keep executing the pre-update code until their `--max-time=3600` expires. The updater runs `optimize:clear` twice (`:306`, `:333`) but never `config:cache route:cache view:cache`, so a platform that was config-cached loses that optimisation permanently after its first update.

**DEF-28 · MEDIUM · `applyPlatform()` rolls back by copying over, not by removing first — the exact hazard the client updater documents.**
```php
// app/Http/Controllers/ReleaseController.php:249-254  (catch branch)
foreach (self::CODE_DIRS as $d) {
    if (is_dir($bk.'/'.$d)) { $this->copyDir($bk.'/'.$d, base_path($d)); }
}
```
versus `updater/updater.php:146-152`, which removes each entry before restoring, with the comment *"Copying alone would leave behind every file the new version ADDED — including a stray new migration in database/migrations, which would then run on the next update and damage the database this rollback exists to protect."* The platform path has the bug the client path was fixed for.

**DEF-29 · MEDIUM · `applyPlatform()` overwrites `public/` but never backs it up.** `CODE_DIRS = ['app','config','database','resources','routes']` (`:21`), yet the extraction loop at `:224-241` writes every zip entry whose top-level name is not `.env`/`storage`/`vendor`/`node_modules` — which includes `public/`, `bootstrap/`, `updater/`, `artisan` and `version.json`. Those are replaced with no backup and are not restored on rollback.

**DEF-30 · MEDIUM · `applyPlatform()` does not unwrap a single-folder package.** `inspectPackage()` `:163-178` *accepts* a wrapped archive (it looks one level deeper for `app/`+`config/`), but the apply loop uses the raw entry name: `$dest = base_path($clean)` (`:234`). A wrapped package therefore creates `base_path('SmartPRS-l2/…')`, **changes nothing**, stamps `applied_platform_at` and reports success. This is precisely the failure mode the client updater guards against at `updater.php:199-206`, and precisely what the reference doc predicts an install package would do (its own shape test cannot tell an install package from an update package — `ReleaseController.php:180-186`).

**DEF-31 · MEDIUM · Update backups are never pruned.** `updater.php:342-343` tells the operator the folder *"can be deleted once everything looks right"*. No code deletes it. Every update leaves a full copy of `app/`, `config/`, `database/`, `public/`, `resources/`, `routes/` (and `vendor/` if the package ships it) under `storage/app/updates/backup_*`.

**DEF-32 · MEDIUM · `canSpawn()` tests the wrong function.**
```php
// app/Services/UpdateClient.php:420-425
return ! in_array('popen', $disabled, true) || ! in_array('proc_open', $disabled, true);
```
The `||` passes when **either** is enabled, but `spawn()` `:440-450` uses `popen` on Windows and `proc_open` elsewhere unconditionally. A host that disabled only the one actually used passes the pre-flight and then fails silently, leaving the state file stuck at `installing`.

**DEF-33 · MEDIUM · Version identity is inconsistent in this very tree.** `version.json` says `2026.6.2`; `config/smartprs.php:43` says `'version' => '2026.6.1'`. `UpdateClient::currentVersion()` `:44-49` reads `version.json`; `ReleaseController::index()` `:34` displays the config value; `logLocal()` `:872` writes the config value into `client_updates.version`. A release built from the config value registers as `2026.6.1` while every client reports `2026.6.2`, so `UpdateServerController::check()` `:258` answers `up_to_date` and the release never reaches anyone.

**DEF-34 · MEDIUM · `public/update-status.php` is absent from this extract, so the progress poll URL 404s.** `ClientUpdateController::install()` `:834` returns `poll_url = url('/update-status.php?t=...')`. The file present here is `updater/update-status.php`, whose own header claims it is *"Shipped in TWO places on purpose"*. `updater.php:299-301` copies `updater/update-status.php` → `public/` only **after** an install has already begun, so the first update on a fresh install has no progress reporting. *(The reference doc states the opposite — that `updater/update-status.php` is the missing one. See §8 DISC-04; one of the two copies is missing either way, and the provided `public/` is trimmed, so this needs confirming against the real repository.)*

**DEF-35 · LOW · Release upload cannot work through the shipped web server.** `ReleaseController::upload()` validates `'package' => 'max:512000'` (500 MB) while the shipped nginx config sets `client_max_body_size 20M` (per `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md:2672`, file not in this extract). The controller anticipates this and documents an SFTP "attach by name" workaround at length (`:46-60`) — the primary path is decorative.

### 6.4 Editions, gating and deployment-model conflicts

**DEF-36 · HIGH · A cloud-hosted tenant on an L-edition is both unbillable and unenforceable.**
`EnsureSubscriptionActive.php:65-69` skips the subscription lock-out whenever `Edition::isOnPrem()` — which is *edition*-derived. `ClientUpdateController::licenceValid()` `:231-236` returns `true` unless `deployment === 'onprem'`. So `SMARTPRS_EDITION=l2` + `SMARTPRS_DEPLOYMENT=saas` produces a tenant with **no subscription gate and no licence gate**, who additionally cannot reach `/app/my-subscription` because it is in `BLOCK_SAAS` (`Edition.php:138`) and 404s. The comment at `ClientUpdateController.php:225-230` implies this configuration has occurred in production.

**DEF-37 · MEDIUM · The Interakt webhook is registered on every edition.** `routes/web.php:200` reads `if (! $spOnPrem || \App\Services\Edition::level() >= 3)`; `level()` is the literal `3`, so the condition is always true. The route is described as L3-only.

**DEF-38 · LOW · A third `level()` consumer exists that the reference doc misses.** `AppController.php:7566` — `return !cfg.editionLevel || cfg.editionLevel >= 3;` gates the Volume-Hiring tabs in the recruitment screen. Always true, so those tabs render on every edition. (The reference doc lists only two consumers.)

**DEF-39 · MEDIUM · `.lic` files minted from the CLI cannot be activated under the default configuration.** `IssueLicense.php:44-54` signs a token and writes the file but **never calls `LicenseService::recordOffline()`**, so no `licences` row exists. With `SMARTPRS_OFFLINE_LIC=false` (the default), `activateKey()` `:262-266` extracts the embedded key and activates **online**, where `findByKey()` returns null and the client is told *"Licence not recognised"*. The panel button (`licFile()` `:384`) does record it; the CLI command does not.

**DEF-40 · LOW · `destroy()` orphans `licence_devices`.** `OnpremClientController.php:756-762` deletes `licence_events`, `licences`, `onprem_payments` and `onprem_clients` but not `licence_devices`.

**DEF-41 · LOW · `onprem_clients.amc_percent` is collected, defaulted to 18, and never used in any calculation.** Grep finds no consumer. AMC renewal pricing is entirely manual.

### 6.5 Demo

**DEF-42 · HIGH · `config('smartprs.team_pin')` is dead configuration that documents a control that no longer exists.**
```php
// config/smartprs.php:24-32
| Team demo PIN (rev 105)
| Unlocks the UNRESTRICTED personal demos at /teamdemo, /app1, /app2,
| /app3 (no demo write-guard, no hidden screens). Known to the Ametecs
| sales team only; the public /demo stays OTP-gated and restricted.
'team_pin' => env('SMARTPRS_TEAM_PIN', 'ametecs'),
```
`DemoAccessController.php:28` says the opposite: *"The old instant-OTP flow and the team PIN (config smartprs.team_pin) are gone."* Grep confirms the controller: the key is read **nowhere**. See §7 SEC-03 for the consequence.

**DEF-43 · MEDIUM · `demo_requests.pin` is stored in plaintext and indexed, by explicit design comment** (`DemoAccessController.php:91`: *"readable by the admin for manual sharing / resend"*), while every other secret in the product is hashed or encrypted.

### 6.6 Technical debt

- **Fail-soft as a house style.** Sixty-plus `catch (\Throwable $e) { }` blocks with empty or comment-only bodies across the in-scope files, e.g. `LicenseService.php:154,324,402,480,507,531,556`; `ClientUpdateController.php:40,55,98,157,213,412,526,546,605,745,876`; `OnpremClientController.php:48,61,177,260,312,408,441,490,548,676,763`; `SignupController.php:62,72,80,90,101,107,118,371,381,401,446,668,872,882,965,1014,1156,1163,1190`; `SubscriptionService.php:75,116,169,229,245,268`; `Edition.php:35,82`; `LicenseFile.php:109`. It is why the system is hard to break and equally hard to enforce.
- **Schema by convention, not migration** — 19 tables (§2.1), all created lazily on the request path inside a swallowed `try/catch`, forcing `ApprovalService::safeRow()` everywhere.
- **Duplicated implementations:** invoice numbering (3 copies); "active employee" (2 mutually contradictory definitions); GST 18% (1 constant + 2 PHP literals + 4 template literals); Razorpay signature verification (6 identical copies); machine fingerprint (1 real + 1 legacy fallback at `ClientUpdateController.php:550`); rollback (2 implementations with different, incompatible semantics).
- **Missing indexes** on `payments.gateway_txn_id` (idempotency), `onprem_clients.invoice_no`, `signups.quote_no`, `signups.quote_token`, `onprem_clients.invoice_token`; `subscriptions.tenant_id` is indexed but not unique.
- **O(n) hot spots:** `nextInvoiceNumber()` plucks every FY invoice number on every creation; `OnpremClientController::index()` loads **all** licences and **all** payments and groups in PHP (`:121-122`); `admin/onprem.blade.php:122` performs a `Crypt` decrypt per row.
- **Test coverage:** `tests/Feature/BillingTest.php` is 157 lines / 8 tests, covering pricing, invoice generation, mark-paid, authorization, gateway encryption, webhook signature + replay, PDF rendering and auto-renewal. **There is no test for any licensing path, the update flow, coupons, signup, quotations, the credit path, seat counting, the editions or any middleware.**
- **Dead code slated for deletion:** `LicenseService::{makeOfflineKey, verifyOfflineKey, offlineSecret, b64u, b64uDecode, OFFLINE_PREFIX, isOfflineKey}`, `ClientUpdateController::activateOfflineKey()`, `OnpremClientController::offlineKey()`, `Edition::{DNA, L2_MODULES, BLOCK_DNA, BLOCK_L2}`, the `session('offline_key')` block, `config('smartprs.team_pin')`, `EditionGuard`'s upsell branch, `certificate()`'s `sig`.
- **`README.md` is a Phase 0 scaffolding document** that describes overlaying files onto a fresh Laravel skeleton, lists Livewire 3 as the UI stack, and **publishes five seeded logins with a shared literal password** (`README.md:56-64`).

---

## 7. Security findings

### SEC-01 · CRITICAL · Licence enforcement can be defeated by a single SQL UPDATE, because the local certificate is unsigned in practice

**What:** every downstream licence check reads `settings(tenant_id=0, key='licence')` and trusts it. `licenceStatus()` `:82-107` requires only that `cert` and `activated_at` are non-empty and that `cert.amc_expires_on >= today`. The `cert.sig` HMAC produced by `LicenseService::certificate()` `:410-422` is **never verified anywhere** (DEF-07), and even if it were, its key is the licence key the client already holds.
**Evidence:** `ClientUpdateController.php:31-57, 74-108, 410-422`.
**Exploitability:** trivial for anyone with database access on their own server — which is every on-prem customer.
**Business impact:** the perpetual-licence product has no technical enforcement against its own customer.
**Severity: CRITICAL.**

### SEC-02 · CRITICAL (design) · The offline License Code scheme is symmetric, and its signing key is specified to be present in every customer build

**What:** `LicenseService::makeOfflineKey()`/`verifyOfflineKey()` sign and verify with `HMAC-SHA256` keyed by `config('smartprs.licence_secret')`. The config file states the requirement explicitly:
```php
// config/smartprs.php:56-58
| Shared secret for OFFLINE self-contained License Codes (rev146). The
| Super Admin signs a key with it; the client verifies the signature
| locally — no server needed. It MUST be identical on the Super Admin and
| on every client install.
```
and again at `:66-67`: *"set the SAME value on the licensing server and in each client's .env"*, and inside the exception text at `LicenseService.php:202`: *"identical on the licensing server and every client install"*.
**Evidence:** `LicenseService.php:191-205, 240-269, 272-286`; `config/smartprs.php:54-69`.
**Exploitability:** anyone holding a client `.env` can mint unlimited, unexpiring, unlocked codes for any edition and any company — **if the path is re-enabled**. It is dead code today (DEF-01), which is the only reason this is not live.
**Additional exposure:** in any non-production environment with the variable unset, `offlineSecret()` `:204` returns the source-visible literal `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'`. The shipped `.env.example:2` sets `APP_ENV=local`.
**Verification limit:** the reference documents report the literal 64-hex secret at `PKG:client-package-tools/env.client.template:16` and `PKG:.../ClientBuilder.bat:114`. **Those files are not in this extract, so I could not confirm the value — only the design that requires it.**
**Business impact:** every perpetual-licence sale becomes retroactively unenforceable the moment this path is re-enabled, for every client already shipped.
**Severity: CRITICAL (as a design decision to eliminate, not as a live exploit today).**

### SEC-03 · HIGH · The "sales-team only" unrestricted demo is self-service to the public

**What:** `POST /demo/request` validates `'entry' => ['required','in:demo,1,2,3,full']` (`DemoAccessController.php:242`) and is public, unauthenticated, `throttle:8,1`. Submitting `entry=full` emails a passkey to any address. Entering it at `/teamdemo/start` sets:
```php
// app/Http/Controllers/DemoAccessController.php:402-406
$request->session()->put('demo_team', 1);   // team-driven pages stay unrestricted
```
which `DemoWriteGuard.php:57-59` treats as a **complete bypass of all 26 blocked write patterns**, and which `LicenseGate.php:36-38` treats as a complete licence-gate bypass.
**Exploitability:** three form fields and a working email address.
**Business impact:** a competitor obtains the full unguided product tour that `config/smartprs.php:29-30` still describes as *"Known to the Ametecs sales team only"*; and from inside, the visitor can rewrite the demo tenant's SMTP credentials (`app/mail-settings`), WhatsApp/SMS provider settings, users and roles, and delete companies, employees and masters — destroying the shared demo every other prospect is looking at until the next 15-minute reset.
**Severity: HIGH.**

### SEC-04 · HIGH · Hardcoded default credentials in source

| Location | Credential | Gated? |
|---|---|---|
| `database/seeders/OnPremWorkspaceSeeder.php:70,79` | `admin@smartprs.local` with `Hash::make('password')`, granted the `admin` role | **No** — run manually with `db:seed --class=OnPremWorkspaceSeeder`; not covered by `SMARTPRS_DEMO_DATA` or `APP_ENV`. The confirmation message at `:101` prints the password. |
| `database/seeders/DemoCompanySeeder.php:69` | five accounts (`superadmin@`, `admin@`, `hr@`, `field@`, `employee@smartprs.local`) all with `Hash::make('password')`; **`superadmin@smartprs.local` is a platform `super_admin` with `tenant_id = null`** | Yes — `DatabaseSeeder.php:19-26` requires `! production && SMARTPRS_DEMO_DATA=true` |
| `app/Console/Commands/DemoReset.php:35` | `{--password=smartprs-demo}` for `demo-admin@smartprs.in`, `demo-hr@`, `demo-field@` | **No** — runs on the **live** platform every 15 minutes and nightly at 03:30 (`routes/console.php:204-211`); also echoed to stdout at `:152` |
| `config/smartprs.php:32` | `'team_pin' => env('SMARTPRS_TEAM_PIN', 'ametecs')` — a working literal default | Dead code (DEF-42), but neither `.env` template mentions the variable |
| `README.md:56-64` | publishes all five seeded logins and the shared password in a table | — |

**Severity: HIGH.**

### SEC-05 · HIGH · The demo passkey gate is bypassable with the published demo password

`demo-admin@smartprs.in` / `smartprs-demo` are ordinary `users` rows with `status='active'` (`DemoReset.php:100-123`, `DemoAccessController::demoUser()` `:427-438`). `POST /login` authenticates them normally; nothing requires a `demo_pass` session. A direct login has no `demo_pass`, so `expiredRedirect()` returns null (`:161-163`) and **the session never expires**. It also has no `demo_team`, so `DemoWriteGuard` does still apply — which is what limits the damage relative to SEC-03. The lead capture and time-boxing the whole demo programme is built on can be skipped entirely.
**Severity: HIGH.**

### SEC-06 · HIGH · A malicious or compromised update server is remote code execution on every client

**What:** there is **no code signing** on update packages. The only integrity control is a SHA-256 hash **supplied by the same server in the same JSON response** (`UpdateServerController::check()` `:287`, consumed at `UpdateClient.php:180,254-261`), and the check is skipped entirely when the hash is absent (DEF-09). The package is then extracted and copied over the application root and `php artisan migrate --force` is executed (`updater.php:285-311`).
**Threat model:**
- *Malicious/compromised release server, or a compromised `/super` super-admin account:* can push arbitrary PHP to every AMC-active client that presses "Check for updates". The hash matches because the attacker computes it. **Complete RCE as the web user on every customer's HR and payroll server, with maintenance mode and a backup, delivered by the product's own mechanism.**
- *Network MITM:* mitigated only by TLS. Laravel's `Http` client verifies certificates by default and `allow_redirects` is capped at 5 with `strict => true` (`UpdateClient.php:120,226`). There is **no certificate pinning**, so a client whose trust store is polluted (corporate TLS interception, a rogue CA on a Windows box) is fully exposed, and `SMARTPRS_UPDATE_URL` is a plain `.env` value anyone on the client machine can repoint.
- *Downgrade/replay:* the token is a random cache key with no binding to a version the client asked for beyond the check response.
**Remediation direction:** detached signature over the package with the same RSA key pair already used for `.lic` (verified with the embedded public key), made **mandatory**, plus a manifest of file hashes.
**Severity: HIGH.**

### SEC-07 · MEDIUM · Six deliberate fail-open catches around licence and subscription enforcement

`LicenseGate.php:73-75` · `AuthController.php:267-269` · `EditionGuard.php:55-57` · `SubscriptionService::state()` `:75-77` · `SubscriptionService::seatUsage()` `:116-118` · `LicenseService::recordDevice()` `:479-481`. Each is documented and intentional (*"a licence check must never take a client's HR system down"*). Collectively, **any exception grants an unlicensed, unmetered session.** An attacker who can provoke one — a corrupt `settings` row, a missing openssl extension, a DB timeout — is through.
**Severity: MEDIUM** (availability is correctly prioritised; enforceability is correspondingly zero).

### SEC-08 · MEDIUM · Session flags grant a full licence-gate bypass on an on-prem install

```php
// app/Http/Middleware/LicenseGate.php:36-39
if ($request->hasSession()
    && ($request->session()->has('edition_demo') || $request->session()->get('demo_team'))) {
    return $next($request);
}
```
No check that the session belongs to a demo tenant, and no check that this is the SaaS server. `Edition::current()` `:26-34` applies the same override *only* for demo-tenant users; `LicenseGate` does not. Anything that can write those session keys on a client install unlocks the product.
**Severity: MEDIUM.**

### SEC-09 · MEDIUM · The `.lic` node-lock is defeated by copying one cache file

```php
// app/Services/LicenseFile.php:72-78
$cacheFile = storage_path('app/.machine_fp');
if (is_readable($cacheFile)) {
    $c = trim((string) @file_get_contents($cacheFile));
    if (strlen($c) === 40 && ctype_xdigit($c)) { return $fp = $c; }
}
```
The cache is trusted whenever it holds 40 hex characters, **before any hardware is read**. Copy `license.lic` plus `storage/app/.machine_fp` to a second machine and both verify. This defeats "one licence, one machine" — the central commercial control of the on-prem product. The cache exists for a real reason (per-request `wmic` was slow and intermittently bounced valid admins to `/login`, `:62-67`), so any fix must preserve that.
**Severity: MEDIUM.**

### SEC-10 · MEDIUM · The standalone updater is unauthenticated by design

`php updater/updater.php <job.json>` will copy an arbitrary zip over the application root and run `migrate --force`, with no authentication. Its only input is `storage/app/updates/job.json`, writable by the web user. Its `sha256` field is optional and, in this scenario, is the attacker's own input. On Laravel, write access to `storage/framework/views` is already close to RCE, so this widens rather than creates the hole — but it converts "attacker can write to storage" into "attacker replaces the entire application cleanly, with maintenance mode and a backup".
**Severity: MEDIUM.**

### SEC-11 · MEDIUM · No rate limit on the signup, quote-complete or licence-complete endpoints

`routes/web.php:127-128` (`/signup/order`, `/signup/complete`), `:159` (`/quote/{token}/complete`), `:195` (`/licence/{token}/complete`), `:164-166` (`/webhooks/razorpay`) carry **no `throttle:`** while every other public POST in the file does. `POST /signup/order` performs a DB write **and** an outbound Razorpay `POST /v1/orders` on every call, so it is both a resource-exhaustion vector and a way to brute-force coupon codes at full speed, defeating the 20/min limit on `/signup/coupon-check`.
**Severity: MEDIUM.**

### SEC-12 · MEDIUM · Migration errors are exposed through the poll endpoint

`updater.php:307-311` writes the first 400 characters of a failed `migrate --force` into `state.json` as the rollback reason; `update-status.php:44-52` serves it verbatim to anyone holding the poll token. `storage/app/updates/updater.log` holds the full output including any DSN in a connection failure.
**Severity: MEDIUM.**

### SEC-13 · MEDIUM · Weak randomness in generated credentials

| Location | Issue |
|---|---|
| `CouponController.php:111` | `str_shuffle(str_repeat($chars,3))` — PHP's internal Mt19937, not a CSPRNG. Exclusive coupon codes are predictable. |
| `SaasController.php:185` | `Str::password(12, true, true, **false**)` — symbols disabled, so the temporary admin password is letters+digits only. |
| *(not in this extract)* `PKG:client-package-tools/INSTALL-SmartPRS-CLIENT.bat:60` | reported as `Amt-%RANDOM%%RANDOM%-%RANDOM%` — `cmd`'s 15-bit time-seeded LCG — for the **Ametecs support super-admin password on every client server** |

**Severity: MEDIUM.**

### SEC-14 · MEDIUM · Production identity and a personal address hardcoded as PHP defaults

`BillingController::sellerProfile()` `:741-754` hard-codes the vendor's legal name, GSTIN `36AAHCT0971F1ZB`, registered address, phone, state and SAC as **code defaults, not just env fallbacks** — and `.env.production.example:80-86` ships the same GSTIN, so a customer deploying without editing it issues GST tax invoices carrying the vendor's GSTIN. `onprem-invoice-pdf.blade.php:41` repeats the GSTIN as a template fallback. `LicenseService::alertSalesMismatch()` `:539` routes every fraud alert to `config('smartprs.sales_email') ?: env('SMARTPRS_SALES_EMAIL', 'ejaz@ametecsindia.com')` — and **`sales_email` does not exist in `config/smartprs.php`** (verified: no such key in the 132-line file), so the personal address is always the effective default. The same address and `WhatsApp 9000098877` appear in ~11 user-facing strings across `EditionGuard.php:45`, `OnpremClientController`, `SubscriptionService` and `AuthController`.
**Severity: MEDIUM.**

### SEC-15 · LOW · The `.lic` public key ships as a PHP constant, and rotation is a code release

`LicenseFile.php:34-44` embeds the PEM. That is correct design — the verifier must ship. But it means (a) a client can replace the constant with their own key and self-sign any payload (only SourceGuardian encoding raises the cost), and (b) rotation invalidates every issued `.lic` (`MakeLicenseKeys.php:31`) and requires rebuilding every edition package. The reference documents state the shipped pair is a **development** key pair that the go-live checklist says to rotate; **I could not verify that claim — the go-live document is not in this extract.**
**Severity: LOW as shipped; HIGH if the dev-key claim is confirmed.**

### SEC-16 · LOW · The private signing key is excluded from every backup and can be destroyed without confirmation

`config/backup.php:34` excludes `base_path('storage/app')`, which is where `storage/app/keys/license_private.pem` lives (`LicenseSigner.php:21`). `MakeLicenseKeys.php:29-33` will overwrite it with `--force`, warning only in console text. **Losing it means no `.lic` can ever be issued or renewed again for any existing customer without rotating the embedded public key and re-issuing every licence.**
**Severity: LOW likelihood, CATASTROPHIC impact.**

### SEC-17 · LOW · `.env` is inside the nightly backup, unencrypted, on the same server

`config/backup.php:31-40` includes `base_path()` and excludes only `vendor`, `node_modules`, `storage/app`, `storage/framework`, `storage/logs` — so `.env` (with `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `RAZORPAY_SECRET`) is in every archive. `'password' => env('BACKUP_ARCHIVE_PASSWORD')` is **unset by default** (`:89` region) and `'disks' => ['local']` writes to `storage/app` on the machine being backed up. `APP_KEY` decrypts `licences.key_enc` among six other encrypted columns.
**Severity: LOW in isolation, compounding.**

### SEC-18 · INFORMATIONAL · No SQLi, XSS, path traversal or unsafe deserialization found in this subsystem

Verified by inspection: every query uses the builder with bound parameters (the only `whereRaw` uses are `LOWER(email) = ?` / `LOWER(exclusive_email) = ?` with bindings); `DB::raw('LOWER(status)')` is a column expression against a bound `whereNotIn` list; `ReleaseController::upload()` applies `basename(str_replace('\\','/',…))` to the filename field (`:82`) and `applyPlatform()` skips any zip entry containing `..` (`:227`); `UpdateClient` sanitises the version into the filename (`:211`); `updater.php` extracts with `ZipArchive::extractTo()` into a dedicated directory and then copies entry-by-entry from the extracted tree; `shell_exec` appears only in fingerprint/hardware-id collection with fully literal commands; `popen`/`proc_open`/`exec` in the update path `escapeshellarg` every server-generated argument. **One latent hazard:** `updater.php::artisan()` `:81-89` does **not** escape `$command` — every call site today passes a literal, but a future caller that interpolates would create injection.

---

## 8. Documentation-vs-source discrepancies

### 8.1 Where the reference documents are wrong or unverifiable against this source

| # | Doc claim | Source finding | Verdict |
|---|---|---|---|
| **DISC-01** | `COMPLETE…md` §12.6.3.2 / FINDING-LIC-01 and `SECURITY…md` S-01/S-02 report a literal 64-hex `SMARTPRS_LICENCE_SECRET` at `PKG:client-package-tools/env.client.template:16` and `PKG:.../ClientBuilder.bat:114` | **Neither file exists in `/home/claude/prs2/`.** The entire `PKG:` packaging root is absent from this extract | **UNVERIFIABLE from this source.** The *design* that requires a shared secret on every client is confirmed (`config/smartprs.php:56-67`, `LicenseService.php:202`), and that is the finding that matters for SmartPRS 3. The literal value claim must be confirmed against the real delivery tree. |
| **DISC-02** | FINDING-LIC-02 / S-10: *"the shipped `.lic` public key is a **development** key"*, sourced from `PKG:.../SmartPRS-DnL-GoLive-Commit-List.md:62` | That document is not in this extract. `LicenseFile.php:34-44` contains a syntactically valid RSA-2048 PEM; nothing in source marks it dev or prod | **UNVERIFIABLE.** Must be confirmed with the vendor. |
| **DISC-03** | §12.10, §12.11 and Part VI §34 describe `BUILD-RELEASE.bat`, `PACKAGE-CLIENT-EDITIONS.bat`, `ClientBuilder.bat`, `INSTALL-SmartPRS-CLIENT.bat`, `guard-runtime.php`, `deploy/nginx-smartprs.conf`, the Supervisor/systemd units and `smartprs-config.json` in detail | **None of these are in this extract** | **UNVERIFIABLE.** Everything I record about packaging, the Windows installer, the "second parallel licensing system" (`guard-runtime.php`), the back-door `support@ametecsindia.com` account and the nginx hardening is *reported*, not *verified*. |
| **DISC-04** | §12.9.6 DEFECT-26 and BR-UPD-012: *"`updater/update-status.php` **does not exist** in this tree"*, citing `ls updater/` | **The opposite is true here.** `ls updater/` shows `update-status.php` (52 lines) **and** `updater.php`. `public/` in this extract contains only `.htaccess`, `css/`, `index.php`, `web.config` — **`public/update-status.php` is the missing one**, and it is the one the poll URL points at (`ClientUpdateController.php:834`) | **DOC WRONG / INVERTED — but `public/` here is trimmed**, so this needs confirming against the real repository. Either way, one of the two copies is missing and the "shipped in TWO places on purpose" claim in the file's own header is false in both readings. |
| **DISC-05** | §12.7.2: *"Every consumer of `level()`… There is no third caller."* | There **is** a third: `AppController.php:7566` — `return !cfg.editionLevel \|\| cfg.editionLevel >= 3;` gating the Volume-Hiring tabs (`recruitHasVolumeHiring()`), always true | **DOC INCOMPLETE.** |
| **DISC-06** | §12.13.5 BR-LIC-015 and §12.6.6 describe revocation as a working control | Confirmed, but the doc understates it: `revocationSweep()` is called from **exactly one place** (`AuthController.php:206`), is 24h-throttled, and **there is no scheduled heartbeat command anywhere** (verified: `routes/console.php` has 12 scheduled tasks, none licence-related). On an install whose admin rarely signs in, or that firewalls the vendor, revocation never lands | **DOC UNDERSTATES SEVERITY.** Raised to DEF-08 / HIGH here. |
| **DISC-07** | BR-BIL-015 records `runRenewals()` neutrally as *"exists but is not scheduled"* | The doc does not state the consequence: the period is advanced **on invoice creation, not on payment** (`BillingController.php:1005-1013`, asserted by `BillingTest.php:155`). Running it would make the entire `grace → locked` lock-out unreachable for every tenant | **DOC MISSES THE DEFECT.** Raised to DEF-11 / HIGH here. |
| **DISC-08** | §12.16.1 D-13 flags the `applyPlatform()` copy-over rollback | Correct, but the doc misses two adjacent defects: `public/` and `bootstrap/` are **overwritten but never backed up** (`CODE_DIRS` at `ReleaseController.php:21` lists only five dirs while the extract loop at `:224-241` writes everything not in `['.env','storage','vendor','node_modules']`), and `applyPlatform()` **does not unwrap a single-folder package** although `inspectPackage()` accepts one | **DOC INCOMPLETE.** Recorded as DEF-29, DEF-30. |
| **DISC-09** | §12.9 describes the update flow as *"backup → maintenance → install → migrate → check → up, with rollback"* and BR-UPD-011 says *"any failure rolls back"* | Accurate for **files**. The doc's own "Limitation" note concedes the DB is not rolled back, but the flow diagram and the release-email text (*"backs itself up automatically"*, `ReleaseController.php:302`) present it as a complete safety net. There is also **no `queue:restart`** and **no cache rebuild**, neither of which the doc mentions | **DOC OVERSTATES.** Recorded as DEF-25, DEF-27, NEW-UPD-02. |
| **DISC-10** | §12.4.6 says the seller defaults are *"defaults, not secrets"* | Agreed, but the doc misses that `onprem-invoice-pdf.blade.php:41,74` **repeats the GSTIN and state as template fallbacks** and uses a **different SAC code (`997331` vs `998314`)** — two invoice documents from one company with two SACs | **DOC INCOMPLETE.** |
| **DISC-11** | §12.8.4 describes `licFile()` recording the licence so it stays revocable | Correct for the panel. The doc does **not** note that `IssueLicense.php` (the CLI twin) omits `recordOffline()` entirely, so a CLI-minted `.lic` has no server-side row and, under the default `SMARTPRS_OFFLINE_LIC=false`, cannot be activated at all | **DOC MISSES.** Recorded as DEF-39. |
| **DISC-12** | §12.13.1 BR-TEN-006 records `seats_licensed = 100000` | Correct. The doc does not connect it to the fact that `licFile()`'s `seats` field defaults to `0` (`OnpremClientController.php:341`) ⇒ `device_limit = null` ⇒ **the default on-prem licence is unlimited-seat** | **DOC INCOMPLETE.** |

### 8.2 Where the source contradicts its own comments, labels and UI

| # | Comment / label / UI says | Code does |
|---|---|---|
| **DISC-13** | `licence-activate.blade.php:64`: *"Activation with the .lic file is instant and **fully offline — no internet needed**."* | `config/smartprs.php:52` ships `offline_lic = false`, so `activateKey()` `:256-266` routes a `.lic` through a 20-second HTTP call to `smartprs.com/update/activate` and **fails without internet**. |
| **DISC-14** | `OnpremClientController.php:302` (licence-code email): *"It works offline — no internet needed."* | The SPRSX1 path that email describes is unreachable dead code (DEF-01). |
| **DISC-15** | `EditionGuard.php:9-18`: *"on an on-prem edition the modules outside the licence are blocked for EVERY method… The friendly 403 doubles as an upsell."* | Both pattern lists are identical and the 404 branch runs first — the 403 is unreachable (DEF-03), and no module is outside any licence (DEF-02). |
| **DISC-16** | `config/smartprs.php:27-31`: the team PIN *"Unlocks the UNRESTRICTED personal demos… Known to the Ametecs sales team only"* | `DemoAccessController.php:28`: *"the team PIN… are gone."* Nothing reads the key; anyone can request an unrestricted passkey (SEC-03). |
| **DISC-17** | `LicenseService.php:72`: `reactivations_used` — *"Q5: 1/year self-service"* | Incremented, never read (DEF-05). |
| **DISC-18** | `LicenseSigner.php:52` signs `grace_days` into every `.lic`; `ClientUpdateController.php:26` docblock describes offline grace | `grace_days` is never read; on-prem expiry is a hard cliff at midnight (BR-LIC-023). |
| **DISC-19** | `OnpremClientController.php:732`: *"Per Q4: blocks activation + updates, **never locks the running app**"* | `revocationSweep()` `:199-211` **wipes the local cert**, which does lock the running app — but only if the client is online and an admin signs in. The comment and the behaviour are both partly true and mutually inconsistent. |
| **DISC-20** | `ClientUpdateController.php:22-25` docblock: *"Apply (FR-9.2): download → sha256 verify → backup code folders → extract (never .env / storage / **vendor**) → migrate → clear caches."* | `updater.php:233` protects `['.env','.env.bak','storage','license.lic','.git','node_modules']` — **`vendor` is not protected** and is replaced whenever the package contains it. |
| **DISC-21** | `public/update-status.php` header (via the `updater/` copy, `:15-17`): *"Shipped in TWO places on purpose."* | Only one copy exists in this extract (DISC-04 / DEF-34). |
| **DISC-22** | `ReleaseController.php:183-185` rejection text: *"It looks like an installer/Setup zip, not an update package"* | `inspectPackage()` cannot distinguish them — both have `app/` and `config/` after unwrapping one folder, so an install package is accepted. |
| **DISC-23** | `README.md` (whole file) | Describes a Phase 0 scaffold, a Livewire UI that does not exist, `CompanyScope` as the working isolation mechanism, and publishes five seeded logins with a shared password. It does not describe the shipped application at all. |
| **DISC-24** | `config/smartprs.php:43`: `'version' => '2026.6.1'` | `version.json` says `2026.6.2`. Two version identities in one tree (DEF-33). |
| **DISC-25** | `SubscriptionService.php:141`: *"moving an employee to Inactive or **Old-data** frees a seat"* | False on the SaaS counter — `seatUsage()` never excludes `archived_at` (DEF-04). |
| **DISC-26** | `LicenseFile.php:11-12`: *"Verification is fully OFFLINE — no network."* | True of `verifyToken()` itself; but the default activation path never calls it (`offline_lic=false`), so the customer's experience contradicts the class docblock. |
| **DISC-27** | `config/smartprs.php:60-63` presents the production throw as a security control: *"so codes can never be forged with a default lifted from the source"* | The throw is caught by `LicenseGate`'s fail-open `catch` (`:73-75`) — which the config comment itself anticipates two lines later (`:65`). It prevents forging, not access. |

---

## 9. Migration verdict per capability

| # | Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|---|
| 1 | Self-serve signup + Razorpay checkout | **RETAIN (harden)** | Server-side pricing, timing-safe HMAC, idempotent completion and duplicate-email guards are all correct. The defects are transactionality (DEF-15), webhook signup recovery (DEF-16) and missing throttles (SEC-11) | Migrate `signups` as-is; add a unique index on `quote_no`/`quote_token` |
| 2 | Quotation + public pay link | **RETAIN (harden)** | Sound design; enforce the 15-day expiry (DEF-14) and give the number series a real sequence | Migrate `signups` rows with `status='quoted'` |
| 3 | Credit / offline provisioning (rev 186) | **RETAIN** | Genuinely needed by the Indian market; partial-aware `applyPayment()` is the correct model | Migrate `payments` ledger; recompute partial state |
| 4 | Tenant provisioning (`provisionTenantRecord`) | **REDESIGN** | No transaction, no auth guard of its own, three provisioners with divergent slug rules (`SaasController` caps at 10 and loops for uniqueness; `InstallController.php:202` and `ClientProvision.php:76` do neither), and it creates almost no configuration (§10 R-24) | Tenants/companies/users migrate 1:1; **the missing configuration is the real work** |
| 5 | Plans + pricing engine (`priceFor`) | **RETAIN as behaviour, REPLACE as implementation** | The formula is correct and tested, but every rate is a PHP constant and the sellable set is a hard-coded name whitelist | `plans` rows migrate; add rate/discount/fee/term as first-class configurable entities |
| 6 | Subscription state machine | **RETAIN (harden)** | The `active → grace → locked` model is right; fail-soft to `none` and the `runRenewals` period-advance defeat it | `subscriptions` migrate 1:1; audit for tenants with `state='none'` |
| 7 | Seat counting | **REDESIGN** | Two contradictory definitions, four enforced doors and at least three unguarded ones | Re-derive counts on cutover; expect them to move |
| 8 | Company counting / multi-company fee | **RETAIN** | Simple and consistent | 1:1 |
| 9 | Trial | **REPLACE (build it)** | Exists only as enum values | Nothing to migrate |
| 10 | Invoice numbering | **REDESIGN** | Correct format, wrong mechanism — MAX+1 scan, three duplicate implementations, one unindexed column, O(n) on every issue | **Preserve every historical number verbatim**; seed a real sequence at MAX |
| 11 | Invoice + payment ledger | **REDESIGN** | Two recorders with different semantics against one ledger, no unique index on the idempotency key, no `partial` status, no credit notes | Migrate `invoices`/`payments`; **reconcile any invoice where `SUM(payments) > amount+tax`** before cutover |
| 12 | GST computation and split | **RETAIN (externalise)** | The intra/inter logic is right; the rate, the SAC codes and the seller identity must become configuration | Snapshot the tax treatment onto each historical invoice |
| 13 | Credit notes | **REPLACE (build it)** | Absent, and publicly promised | Nothing to migrate; backfill may be needed for past refunds |
| 14 | Coupons | **RETAIN** | Well-scoped, correctly re-validated at order time, redeemed only after payment | `coupons` + `coupon_redemptions` 1:1; add a uses counter with a real constraint |
| 15 | Online activation (`SPRS-` key + fingerprint bind) | **RETAIN** | Server-authoritative, neutral errors, throttled, fraud tripwire, audit trail — this is the strongest part of the area | `licences`, `licence_events`, `licence_devices` migrate 1:1 |
| 16 | Offline SPRSX1 License Codes | **REMOVE** | Unreachable dead code whose design requires shipping a signing key to every customer | Delete `makeOfflineKey`, `verifyOfflineKey`, `offlineSecret`, `activateOfflineKey`, `offlineKey()`, the `licence_secret` config key and the `session('offline_key')` view block |
| 17 | RSA `.lic` files | **RETAIN (harden)** | Asymmetric, private key never ships, signature over the exact base64url payload, node-locked, floating tokens refused. Harden the fingerprint cache (SEC-09) and honour `grace_days` | `license.lic` files stay valid only if the key pair is unchanged — **plan a key rotation and a re-issue window** |
| 18 | The local activation certificate | **REPLACE** | Unsigned in practice, forgeable by one SQL UPDATE — this is what actually gates access | Do not migrate the blob; re-activate every install on cutover |
| 19 | `LicenseGate` | **REDESIGN** | Right idea, six fail-open paths, a session-flag bypass, and `app/first-password` missing from the allow-list | None |
| 20 | `EnsureSubscriptionActive` | **RETAIN (harden)** | Correct matrix; the per-request un-cached `users.status` SELECT should be cached | None |
| 21 | Editions L1/L2/L3 | **REPLACE** | Claimed and not enforced. SmartPRS 3 must decide whether entitlements exist at all; if they do, they must be data, not constants | `onprem_clients.edition` migrates as a *sales* attribute; entitlement sets must be authored fresh |
| 22 | `EditionGuard` | **MERGE into a single entitlement/feature-flag middleware** | Half of it is unreachable | None |
| 23 | On-prem sales desk (`/admin/onprem`) | **RETAIN (rebuild UI)** | The business workflow is right; the screen is N+1 heavy and decrypts a key per row | `onprem_clients`, `onprem_payments` 1:1 |
| 24 | Revocation | **REDESIGN** | Login-only, 24h-throttled, no scheduled heartbeat, trivially defeated by a firewall rule | `revoked_hashes` is inside the settings blob — rebuild from `licences.status` |
| 25 | Server-seat cap | **REDESIGN** | Computed correctly and then discarded on the only two paths that call it | `licence_devices` 1:1 |
| 26 | Release registration + publish/grant | **RETAIN (harden)** | Checksum computed from bytes on disk (never accepted from the form) is exactly right; the shape test cannot distinguish an install package from an update package | `releases`, `release_grants` 1:1 |
| 27 | `applyPlatform()` (self-update of the SaaS host) | **REPLACE** | Copy-over rollback, unbacked-up `public/`, no wrapper unwrap, no DB safety | None |
| 28 | Client self-update (check → download → verify → detached updater) | **REDESIGN** | The architecture (standalone process, protected paths, remove-then-restore rollback, framework-free progress endpoint) is genuinely good. The gaps are signing, DB safety, worker restart and a real health check | `client_updates` 1:1; `storage/app/updates/backup_*` should be pruned, not migrated |
| 29 | Configuration / statutory content delivery | **REPLACE (build it)** | **Does not exist.** Everything ships in the application zip | New capability |
| 30 | Demo access | **REDESIGN** | The passkey mechanism, lead capture, timed window and 15-minute reset are all good. The `entry=full` self-service hole and the published demo password are not | `demo_requests` need not migrate |
| 31 | Browser installer (`/install`) | **RETAIN (extend)** | Genuinely useful; the pre-flight is the best-designed piece of operator UX in the area | None |
| 32 | CLI provisioning (`client:provision`) | **MERGE into the installer** | Duplicates `InstallController::store()` with divergent slug handling | None |
| 33 | Backup | **REPLACE** | Local-disk-only, excludes the signing key and all uploads, includes `.env` unencrypted, one try, no monitoring, no restore | Backup archives are not migrated; **the restore procedure must be authored and proven** |
| 34 | Packaging / release pipeline | **REPLACE** | Windows `.bat` with hardcoded developer paths, no CI, two version identities, and a build that does not rewrite the file the client reads | None |

---

## 10. SmartPRS 3 requirements implied

### 10.1 Direct answers to the ten commissioning questions

These decide the distribution architecture, so they are stated first and in full.

---

#### Q1 — Licence signing: symmetric or asymmetric? Where does the signing key live, and is it present in customer builds?

**Answer: SmartPRS 2 contains BOTH, and the one that actually gates access is NEITHER — it is an unverified JSON blob in a database row.**

**(A) The `.lic` file path is ASYMMETRIC and correct.** RSA-2048, signature over the exact base64url payload string:

```php
// app/Services/LicenseSigner.php:34-62
public function sign(array $data): string
{
    $priv = openssl_pkey_get_private((string) @file_get_contents($this->privateKeyPath()));
    ...
    $b64 = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);
    return $b64 . '.' . $this->b64url($sig);
}
// :19-22
public function privateKeyPath(): string { return storage_path('app/keys/license_private.pem'); }
```
```php
// app/Services/LicenseFile.php:26-33
/**
 * SmartPRS Central licence PUBLIC key. Its matching PRIVATE key lives ONLY on the
 * /super server (storage/app/keys/license_private.pem) and is never shipped.
 */
private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqlK8Q6CYHa4+TIH9YDGw
... (RSA-2048 PEM, 6 more lines) ...
-----END PUBLIC KEY-----
PEM;
```
```php
// app/Services/LicenseFile.php:140-148
$pub = openssl_pkey_get_public(self::PUBLIC_KEY);
if ($pub === false) { return ['ok' => false, 'reason' => 'bad_public_key']; }
if (openssl_verify($b64, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
    return ['ok' => false, 'reason' => 'invalid_signature'];
}
```
The private key is generated once on the vendor's `/super` server by `smartprs:make-license-keys` at `storage/app/keys/license_private.pem`, `chmod 0600` in a `0700` directory (`MakeLicenseKeys.php:35-49`), and `storage/` is on the updater's protected list, so it is never packaged. **For this path the signing key is NOT present in customer builds.** Verified.

**(B) The SPRSX1 "offline License Code" path is SYMMETRIC, and its key is specified to be in every customer build.**

```php
// app/Services/LicenseService.php:265-268   (signing, on the vendor's server)
$b = self::b64u((string) json_encode($payload));
$sig = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);
return self::OFFLINE_PREFIX.'.'.$b.'.'.$sig;

// app/Services/LicenseService.php:278-281   (verification, on the customer's server)
[, $b, $sig] = $parts;
$expect = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);
if (! hash_equals($expect, $sig)) { return null; }

// app/Services/LicenseService.php:191-205
private static function offlineSecret(): string
{
    $secret = (string) config('smartprs.licence_secret');
    if ($secret !== '') { return $secret; }
    if (app()->environment('production')) {
        throw new \RuntimeException('SMARTPRS_LICENCE_SECRET is not set. Set a strong, secret value (identical on the licensing server and every client install) before issuing or verifying License Codes.');
    }
    return 'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION';
}
```

The requirement is stated three times in source, unambiguously:

```php
// config/smartprs.php:56-58 and :66-67
| It MUST be identical on the Super Admin and on every client install.
| ...
| Generate a strong random value (e.g. `php artisan key:generate --show`) and
| set the SAME value on the licensing server and in each client's .env.
```

**HMAC is a symmetric primitive: the key that verifies is the key that signs.** A design that puts the verifying key on every customer's disk necessarily puts the *signing* key there too. Any customer who reads their own `.env` can mint unlimited, unexpiring, unlocked License Codes for any edition and any company. That is the single most important architectural fact in this assignment.

**Mitigation today:** the SPRSX1 path is **unreachable dead code** — no route reaches `offlineKey()`, no caller reaches `activateOfflineKey()` (DEF-01, verified by exhaustive grep). The exposure is latent, not live.
**Verification limit:** the reference documents report the literal secret at two `PKG:` paths. **Those files are not in this extract (DISC-01).** I confirm the design, not the value.
**Additional exposure:** in any non-production environment with the variable unset, the source-visible literal `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'` is used — and the shipped `.env.example:2` sets `APP_ENV=local`.

**(C) The activation certificate — what actually gates access — is symmetric AND unverified.**

```php
// app/Services/LicenseService.php:410-422
public static function certificate(object $licence, string $key, string $fingerprint): array
{
    $payload = ['edition' => ..., 'amc_expires_on' => ..., 'expiry_mode' => ...,
                'fingerprint' => $fingerprint, 'issued' => now()->toDateTimeString()];
    $payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));
    return $payload;
}
```
Keyed by the licence key, which the client already holds encrypted in `key_enc` — so it could never prove anything against the client. And it does not even try: **grep finds no consumer of `cert['sig']` anywhere.** `licenceStatus()` `:82-107` reads `cert.amc_expires_on` straight out of the `settings` row. One `UPDATE` grants a permanent licence (SEC-01).

**Verdict for SmartPRS 3.** Adopt exactly one licensing primitive: **asymmetric only**. The signing key must never leave the vendor's HSM/KMS. Every artefact the client evaluates — the licence file, the local entitlement cache, and the update package — must be signed with it and verified against an embedded public key, with **no symmetric fallback and no unsigned cache**. Delete the SPRSX1 scheme and the `licence_secret` configuration key entirely.

---

#### Q2 — Are update packages signed? How is a package verified? What could a MITM or a malicious release server do?

**Answer: packages are NOT signed. There is only an integrity hash supplied by the same server in the same response, and the check is conditional.**

Verification is exactly two things:

```php
// app/Services/UpdateClient.php:252-261
if (! empty($available['package_hash'])) {
    $got = hash_file('sha256', $target);
    if (! hash_equals(strtolower((string) $available['package_hash']), strtolower((string) $got))) {
        @unlink($target);
        return $this->fail('The downloaded package failed its integrity check and was deleted. Please try again.');
    }
}
```
```php
// updater/updater.php:174-178
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) {
    @unlink($package);
    step('failed', 100, 'The update package failed its integrity check and was deleted. ...');
    exit(1);
}
```

`$available['package_hash']` comes from `UpdateServerController::check()` `:287` — the **same JSON response** that supplies `download_url`. It proves the bytes arrived intact from whoever answered; it proves nothing about *who* answered. `$sha` in the updater comes from `job.json`, which in an attacker-writes-storage scenario is the attacker's own input. The only unconditional gate is the shape test: `app/` and `config/` must exist at the payload root (`updater.php:218-223`).

**What a malicious or compromised release server can do:** push arbitrary PHP into every AMC-active client that presses "Check for updates". The package is extracted over the application root and `php artisan migrate --force` runs (`updater.php:285-311`). That is **full remote code execution as the web user on every customer's HR and payroll server**, delivered by the product's own mechanism, with maintenance mode and a backup, and reported to the admin as a successful update. The blast radius includes every client with a live AMC.

**What a MITM can do:** the only barrier is TLS. Laravel's `Http` client verifies certificates by default and redirects are capped (`UpdateClient.php:120,226`), but there is **no certificate pinning**, so a client whose trust store is polluted — corporate TLS interception, a rogue CA on a Windows box, an operator-added certificate — is fully exposed. And `SMARTPRS_UPDATE_URL` is a plain `.env` value: anyone with file access on the client can repoint the whole update channel at their own server, which the client will then trust completely.

**Verdict for SmartPRS 3.** Signing is mandatory and non-optional: a detached signature over the package, made with the same private key as the licence system, verified with the embedded public key **before extraction and again before install**, plus a signed manifest of per-file hashes so a partial or tampered extraction is detectable. Refuse to install an unsigned package under any configuration. Pin the update host's certificate or at minimum pin the expected public key. Bind the signed manifest to the target version and licence so a package cannot be replayed to a different client or downgraded.

---

#### Q3 — The update process step by step: which steps exist and which are claimed but absent?

| Step | Claimed | Actually exists | Evidence |
|---|---|---|---|
| 1. Detect current version | Yes | **Yes** — `version.json` first, config fallback, BOM-stripped | `UpdateClient.php:44-77` |
| 2. Ask the server | Yes | **Yes** — entitlement, AMC and version compared server-side | `UpdateServerController::check()` `:199-295` |
| 3. Download | Yes | **Yes** — 900 s timeout, streamed to `storage/app/updates`, sanitised filename, <1000 bytes rejected | `UpdateClient.php:195-271` |
| 4. Verify package | Yes | **PARTIAL** — SHA-256 only, **conditional**, no signature | `:252-261`; `updater.php:174` |
| 5. Prove it is a SmartPRS tree | Yes | **Yes** — unwrap single-folder wrappers, require `app/`+`config/`, non-empty after stripping protected paths | `updater.php:196-241` |
| 6. **Code backup** | Yes | **Yes** — every replaced top-level entry plus `version.json` copied to `storage/app/updates/backup_<Ymd_Hi>` | `updater.php:252-269` |
| 6b. **Database backup** | Implied by "backs itself up automatically" | **ABSENT** — no dump, no snapshot, no call to `backup:run` anywhere in the update path | grep across `updater.php`, `UpdateClient.php`, `ReleaseController.php` |
| 7. Maintenance mode | Yes | **Yes** — `artisan down --retry=60`, with a direct `storage/framework/down` fallback if artisan is unbootable, `secret => null` so there is no bypass URL | `updater.php:271-283` |
| 8. Install files | Yes | **Yes** — entry-by-entry copy, `copied === 0` triggers rollback | `updater.php:285-301` |
| 8b. `composer install` | Not claimed | **ABSENT** — a release that adds a dependency must ship `vendor/`, and `vendor/` is **not** on the protected list, so it is silently replaced by the build machine's copy | `updater.php:233` |
| 8c. `storage:link` | Not claimed | **ABSENT** | grep |
| 9. **Database migration** | Yes | **Yes** — `optimize:clear` then `migrate --force`; non-zero exit rolls back | `updater.php:303-311` |
| 10. **Cache handling** | "clear caches" | **PARTIAL** — `optimize:clear` twice (`:306`, `:333`); **never `config:cache` / `route:cache` / `view:cache`**, so a config-cached install permanently loses that optimisation after its first update | `updater.php:306,333` |
| 10b. **Queue-worker restart** | Not claimed anywhere | **ABSENT** — no `queue:restart`. Long-running workers keep executing pre-update code until `--max-time=3600` expires | grep |
| 11. **Health check** | "post-update checks" | **PRESENT BUT WEAK** — `artisan --version` only. Proves the CLI autoloader boots. Does not touch the database, does not exercise a route, does not confirm the web SAPI serves | `updater.php:313-320` |
| 12. **Rollback (code)** | Yes | **Yes, and correctly implemented** — removes each replaced entry *before* restoring, precisely so files the new version added cannot survive | `updater.php:140-165` |
| 12b. **Rollback (database)** | Implied by "any failure rolls back" | **ABSENT** — no `migrate:rollback`, no snapshot. A migration that half-applies then fails leaves the schema ahead of the restored code, and the app is brought back **up** in that state | `updater.php:307-311` |
| 13. Bring back online | Yes | **Yes** — write `version.json` (keeping the captured channel), `optimize:clear`, `artisan up`, remove the down flag | `updater.php:322-335` |
| 14. Progress reporting | Yes | **Yes in design** — a framework-free `update-status.php` gated by a one-time `hash_equals` token; **but one of its two copies is missing** (DEF-34) | `updater/update-status.php`; `ClientUpdateController.php:822-834` |
| 15. Backup retention | "can be deleted once everything looks right" | **ABSENT** — never pruned, unbounded growth | `updater.php:342-343` |
| 16. Audit | Yes | **Yes** — `client_updates` on both sides, though the client writes the *config* version, not `version.json` | `:866-878`, `UpdateServerController::log()` `:360-370` |

**The platform-side twin (`applyPlatform()`) is materially worse:** its rollback copies over instead of removing first (DEF-28), it overwrites `public/` and `bootstrap/` without backing them up (DEF-29), and it does not unwrap a single-folder package so a wrapped archive installs nothing while reporting success (DEF-30).

**Summary: backup (code), maintenance mode, migration and code rollback genuinely exist and are well built. Database backup, database rollback, worker restart, cache rebuild, dependency install and a meaningful health check do not.**

---

#### Q4 — Are application updates and configuration/statutory content updates separated at all?

**Answer: no. There is exactly one delivery artefact — a zip of the application tree — and a PT slab change therefore requires a full application release.**

There is one update object (`releases`), one grant object (`release_grants`), one client flow (`check → download → install`) and one install action: copy files over the application root and run `migrate --force`. Grep finds **no** data-only, config-only, seed-only or content-only channel; no `releases.kind` column; no per-artefact type anywhere.

**What that means for a statutory change, concretely.** `StatutoryConfig` (`app/Services/StatutoryConfig.php`) *does* provide a genuine per-tenant override layer for PF/ESI/PT/TDS, and its own docblock is explicit about the split:

> *"EDITABLE PT SLABS — the **16 state slabs and 10 PT-free states are compiled into the engine**. A state changing its slab, or a state that is not built in at all, needed a code change. `pt_state_slabs` / `pt_free_states` overrides now ride through this same layer."* (`StatutoryConfig.php:31-35`)

So there are exactly two ways to reflect a real PT slab change:

1. **Per install, by hand** — an operator opens the Statutory Rate Settings screen on every tenant and every on-prem install and re-enters the new slab as an override. This does not scale and produces divergence.
2. **For everyone, by code** — edit the compiled default slab table and ship a **full application release**: bump the version, build the zip, upload to `/admin/releases`, publish, grant to every AMC-active client, then wait for each client's admin to notice the email, sign in, press Check, press Download, press Install, and accept a maintenance window on their payroll server. On an install whose AMC has lapsed, the update is refused outright (`UpdateServerController::check()` `:238-246`), so **a customer who has not paid AMC cannot receive a statutory correction at all** — even though the statute applies to them regardless.

There is also no mechanism to ship master data, letter templates, report definitions, alert templates or plan catalogues independently of code.

**Verdict for SmartPRS 3.** This is a first-order architectural requirement, not a nice-to-have. Statutory and configuration content must be a **separately versioned, separately signed, separately delivered artefact** with its own effective-dating, its own applicability rules and its own delivery channel that does **not** require a maintenance window, does **not** require file replacement, and is **not** gated behind a commercial AMC — because a wrong PT deduction is the vendor's liability whether or not the customer is current on support.

---

#### Q5 — Editions L1/L2/L3: claimed capability gating versus what is actually enforced

**Claimed** (`Edition.php:5-17`, `EditionGuard.php:9-18`, `DemoAccessController::EDITION_DEMOS` `:203-208`, `admin/onprem.blade.php:22`, and three price points on the sales desk):

- `l1` = on-prem **Core** — Core HR only
- `l2` = on-prem **Advanced** — core + the nine L2 modules
- `l3` = on-prem **DNA** — everything except the SaaS Platform
- *"module off = invisible + locked, NEVER deleted — upgrading is just a new `SMARTPRS_EDITION` value in `.env`"*
- *"the modules outside the licence are blocked for EVERY method (GET too — unlicensed data must stay invisible, not just read-only). The friendly 403 doubles as an upsell."*

**Actually enforced — nothing edition-specific. The enforcement code, quoted in full:**

```php
// app/Services/Edition.php:93-97
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int
{
    return 3;
}

// app/Services/Edition.php:119-127
public static function hiddenNavIds(): array
{
    // 8 Aug 2026 (Ejaz — FINAL: single product, all features, licensed by user
    // count). Every on-prem edition (l1/l2/l3) now shows ALL modules; only the
    // SaaS-platform surfaces (tenant billing + Super Admin) stay hidden on a
    // client install. The old per-edition DNA / L2_MODULES gating is retired
    // (constants kept for reference / easy revert).
    return self::current() === 'saas' ? [] : self::SAAS_ONLY;
}

// app/Services/Edition.php:169-174
public static function blockedPatterns(): array
{
    // 8 Aug 2026 (Ejaz — single product): on-prem clients block only the SaaS
    // platform / Super-Admin endpoints; every product module is reachable.
    return self::current() === 'saas' ? [] : self::BLOCK_SAAS;
}

// app/Services/Edition.php:176-180
/** Friendly label. 8 Aug 2026 (Ejaz — single product): one name, no L1/L2/L3. */
public static function label(): string
{
    return 'SmartPRS';
}
```

```php
// app/Http/Middleware/EditionGuard.php:34-53
// rev147: platform / Super-Admin surfaces are HIDDEN, not upsold
foreach (Edition::platformPatterns() as $re) {     // == BLOCK_SAAS
    if (preg_match($re, $path)) { abort(404); }
}
foreach (Edition::blockedPatterns() as $re) {      // == BLOCK_SAAS — SAME LIST
    if (preg_match($re, $path)) { ... 403 upsell ... }   // UNREACHABLE
}
```

`BLOCK_DNA` (`:149-155`), `BLOCK_L2` (`:158-167`), `DNA` (`:105`) and `L2_MODULES` (`:108-117`) are declared and returned by **nothing** (verified by grep).

**Enforcement scorecard:**

| Claim | Reality | Status |
|---|---|---|
| L1 = Core HR only | L1 shows and serves every module | **NOT IMPLEMENTED** |
| L2 = core + nine advanced modules | Identical to L1 and L3 | **NOT IMPLEMENTED** |
| L3 = everything except SaaS Platform | True — but so are L1 and L2 | **NOT IMPLEMENTED as a differentiator** |
| Modules outside the licence are invisible and locked | No module is ever off | **NOT IMPLEMENTED** |
| Friendly 403 upsell | Unreachable dead branch | **NOT IMPLEMENTED** |
| SaaS/Super-Admin surfaces hidden on-prem | **Yes — `abort(404)`, so no trace is hinted** | **IMPLEMENTED** |
| Edition lock at activation | **Yes, on all three activation paths** | **IMPLEMENTED** |
| `/app1` `/app2` `/app3` demonstrate three products | All three show the same app; only the marketing blurb differs | **DEFECT — sales-integrity risk** |
| Volume Hiring is L3-only | `cfg.editionLevel` is always 3, so the tabs render everywhere | **NOT IMPLEMENTED** |
| Interakt webhook is L3-only | `level() >= 3` is always true | **NOT IMPLEMENTED** |

**The one enforced edition behaviour is the lock at activation**, and it is enforced against a distinction that does not exist:
```php
// app/Http/Controllers/ClientUpdateController.php:441-444
$ed = strtolower((string) ($p['edition'] ?? $p['plan'] ?? ''));
if ($ed !== '' && $ed !== Edition::current()) {
    return ['ok' => false, 'error' => 'This licence file is for SmartPRS-'.strtoupper($ed).', but this installation is SmartPRS-'.strtoupper(Edition::current()).'.'];
}
```
A client sold L1 who is mistakenly issued an L2 `.lic` is **blocked from an application that would behave identically either way**. Same check at `UpdateServerController.php:58-62` and `ClientUpdateController.php:324-327`.

---

#### Q6 — Seat/user limits: how they are enforced, and every path that bypasses them

**The one enforcement function:**
```php
// app/Services/SubscriptionService.php:127-149
public static function canAddEmployees(?int $tenantId, int $count = 1): array
{
    $u  = self::seatUsage($tenantId);
    $ok = ['ok' => true, 'error' => null, 'used' => $u['used'], 'licensed' => $u['licensed']];
    if (! $tenantId || $u['licensed'] <= 0) { return $ok; }   // no limit on record -> allow
    if ($u['used'] + $count <= $u['licensed']) { return $ok; }
    $ok['ok'] = false;  ...
}
```

**Enforced doors — four, all confirmed by grep:**

| Door | Location | Behaviour |
|---|---|---|
| Add-employee form | `AppDataController.php:3042` | Blocks |
| Inline CSV / paste import | `AppDataController.php:815` | Blocks mid-import, returns a partial count |
| Recruitment → hire | `RecruitmentController.php:602` | Blocks |
| Renewal sizing (must cover current usage) | `TenantBillingController.php:292-295` | 422 |

**Every bypass:**

| # | Bypass | Evidence | Effort |
|---|---|---|---|
| B1 | **Bulk employee import** — `EmployeeImportController` / `EmployeeImportService` never call `canAddEmployees()` or `seatUsage()` | grep: the only four call sites are listed above | **Trivial, through the UI** |
| B2 | **Self-onboarding portal** — `SelfOnboardingController` / `OnboardingController` never call them either | same grep | **Trivial, through the UI** |
| B3 | **Archive the employee (on-prem)** — the `.lic` counter excludes `archived_at` (`ClientUpdateController.php:515-520`) while the person stays fully usable in payroll | `AppDataController` archives by setting `archived_at` without touching `status` | **Trivial, through the UI** ("Backed up / Old data") |
| B4 | **`licensed <= 0` means unlimited** — a tenant with no `subscriptions` row and `tenants.seats_licensed = 0` is never capped | `SubscriptionService.php:131` | Configuration |
| B5 | **On-prem installs are provisioned with `seats_licensed = 100000`**, so with no `device_limit` in the `.lic` the effective cap is 100,000 | `ClientProvision.php:72`, `InstallController.php:198` | Default |
| B6 | **A `.lic` issued with `seats = 0`** (the form default, `OnpremClientController.php:341`) writes `device_limit = null` ⇒ `seatLimit()` returns null ⇒ no override ⇒ B5 applies | `LicenseSigner.php:48`, `ClientUpdateController.php:498-504` | Default |
| B7 | **Fail-soft to unlimited** — any exception inside `seatUsage()` returns `licensed = 0` | `SubscriptionService.php:116-118` | Provoke any DB error |
| B8 | **Turn enforcement off** — `SMARTPRS_LICENCE_ENFORCE=false` removes the `.lic` seat override entirely (`SubscriptionService.php:109-110`) | one line in a plaintext file the client owns | **Trivial** |
| B9 | **Change the deployment/edition switch** — `SMARTPRS_DEPLOYMENT=saas` or `SMARTPRS_EDITION=saas` removes the on-prem branch | `SubscriptionService.php:109`, `Edition.php:88-91` | **Trivial** |
| B10 | **Edits are never checked** — the cap applies only to *new* employees. Re-activating an exited employee, or changing a status from `inactive` back to `active`, crosses the cap with no check | only `canAddEmployees` exists; no `canReactivate` | **Trivial** |
| B11 | **The `.lic` seat cap is checked at activation only** — `activateLicenceFile()` `:459-469` refuses a licence covering fewer seats than are currently active, then never re-checks. Growth past the cap after activation is blocked only at the Add door | `ClientUpdateController.php:459-469` | — |
| B12 | **Edit the local certificate** — `device_limit` lives in the unsigned `settings` blob | `ClientUpdateController.php:498-504`, SEC-01 | **Trivial with DB access** |
| B13 | **Server-seat cap is discarded** — a second and third server can run the same licence because `activate()`/`heartbeat()` ignore `recordDevice()`'s refusal | `UpdateServerController.php:92,145` | None required |
| B14 | **Off-roll commission agents never count** — they live in a separate table | `SEAT_FREE_STATUSES` applies to `employees` only | By design |

---

#### Q7 — Tenant provisioning: what a new tenant gets, how much is manual, and how long a real implementation takes today

**What is created automatically** (`SaasController::provisionTenantRecord()` `:132-228`, plus the caller):

1. `tenants` — uuid, name, `plan_id`, `status='active'`, `seats_licensed`, `mrr=0`, `deployment`, `owner_email`, `subdomain` (3–10 chars, unique, auto-derived on conflict), `gstin`, `state`.
2. `companies` — exactly **one**, the MASTER company (`is_master = 1`), self-healing that column if absent.
3. `users` — exactly **one** admin. With `email_credentials=true` a `Str::password(12, true, true, false)` temporary password (letters + digits, **no symbols**); otherwise `Str::random(40)` (unusable). `syncRoles(['admin'])`, and `must_set_password = 1` so the admin is forced to create their own password on first entry — a deliberate fallback for when the credentials email fails.
4. `user_password_resets` — a `sha256`-hashed token, 72-hour expiry, raw token in the emailed URL.
5. A branded welcome email (sent `sync`, so it does not wait on a queue worker) naming the branded sign-in page `/c/{slug}`.
6. `SeedIndustryContent::seedForTenant()` — training programmes, FAQs, knowledge base, code of conduct. Best-effort, exceptions swallowed.
7. By the **caller**: a `subscriptions` row at the full rate, `tenants.mrr`, a GST invoice, a payment row, coupon redemption, the receipt email with the PDF, and (online path only) auto-sign-in plus two WhatsApp messages.

**What is NOT created — this is the answer to "how much is manual":**

- **No payroll configuration whatsoever** — no salary structure, no components, no earnings/deductions, no salary schedule, no pay cycle.
- **No statutory configuration** — no PF/ESI/PT/TDS position; the compiled defaults apply until someone opens the Statutory Rate Settings screen.
- **No financial year** — `app/fin-year/set` is a separate, manual action.
- **No attendance configuration** — no shifts, no roster, no late policy, no leave types, no holiday calendar.
- **No organisational structure** — no branches, no departments, no designations, no grades. The tenant gets one company and nothing inside it.
- **No approval hierarchy, no additional users, no roles beyond the seeded five, no biometric devices, no branding, no per-tenant SMTP, no WhatsApp/SMS provider, no report or letter templates beyond the industry starter pack, no employees.**

**How long a real implementation takes today.** The *technical* provisioning is sub-second and fully automated. Everything that makes the product usable for a specific employer is manual data entry through the SPA, screen by screen: company structure, then policies, then salary structures, then statutory positions, then employees (via the CSV importer — which does not check seats, B1), then approval routing, then device mapping. **Source does not record an elapsed time, and I will not invent one** — but the *shape* is unambiguous: automation ends at "a tenant exists with one company and one admin", and everything a Product Owner would call "implementation" is manual and unassisted. There is no configuration template, no industry preset beyond content, no copy-from-another-tenant, no import/export of configuration, and no completeness check that tells the implementer what is still missing.

**This is the strongest argument in this whole analysis for SmartPRS 3's configuration-first premise.**

---

#### Q8 — Cloud vs on-prem: every place the BUSINESS LOGIC actually differs

Infrastructure differences excluded. Twenty-one logic branches, each verified in source:

| # | Location | Branches on | On-prem behaviour |
|---|---|---|---|
| 1 | `routes/web.php:22` | `Edition::isOnPrem()` → `$spOnPrem` | Gates the entire public marketing/SaaS route block |
| 2 | `routes/web.php:27-37` | `$spOnPrem` | `/` redirects to `/app` instead of the marketing landing page |
| 3 | `routes/web.php:45-55` | `! $spOnPrem` | `/lead` and the nine public policy pages are **not registered** |
| 4 | `routes/web.php:125-167` | `! $spOnPrem` | `/signup*`, `/demo*`, `/app1-3`, `/teamdemo`, `/quote/*`, `/webhooks/razorpay` **not registered** |
| 5 | `routes/web.php:172-196` | `! $spOnPrem` | The entire update-server + licence-authority + `/licence/{token}` pay family **not registered** |
| 6 | `routes/web.php:200` | `! $spOnPrem \|\| Edition::level() >= 3` | Interakt webhook — **always registered** because `level()` is the literal 3 (DEF-37) |
| 7 | `routes/updates.php:37` | `config('smartprs.deployment') !== 'onprem'` | `/update/package/{token}` not registered on a client box |
| 8 | `EditionGuard.php:25-53` | `Edition::isOnPrem()` | `/` → `/app`; `admin/*`, `super/*`, `signup*`, `quote*`, `demo*`, `lead` → **404** |
| 9 | `LicenseGate.php:45` | `Edition::isOnPrem()` | The licence gate runs **only** on-prem |
| 10 | `EnsureSubscriptionActive.php:65-69` | `Edition::isOnPrem()` | **The subscription lock-out never applies** — *"on-premise editions are PERPETUAL licences"* |
| 11 | `ClientUpdateController.php:231-236` | `deployment==='onprem'` **AND** `isOnPrem()` **AND** `licence_enforce` | Only then is a licence demanded (the two-switch design) |
| 12 | `AuthController.php:21-25` | both switches + `! alreadyInstalled()` | `/login` redirects to `/install` on a virgin box |
| 13 | `AuthController.php:128` | `Edition::isOnPrem()` | `/super` (Super Admin portal) `abort(404)` |
| 14 | `AuthController.php:203-266` | licence state | The **entire licence gate at the login door** — LC field, notify-mode self-heal, non-admin hold message |
| 15 | `AppController.php:96-98` | `Edition::isOnPrem()` | `subState` forced to `active`, `daysLeft = 9999` → no renewal banner ever |
| 16 | `SubscriptionService.php:107-115` | `isOnPrem()` + `licence_enforce` | The `.lic` `device_limit` **overrides** subscription seats |
| 17 | `SubscriptionService.php:138-146` | `isOnPrem()` | A different, licence-worded seat-limit error message |
| 18 | `ClientUpdateController.php:171-176` | `isOnPrem()` | `revocationSweep()` no-ops off on-prem |
| 19 | `ClientUpdateController.php:665,686` | `isOnPrem()` | The pre-login `/activate` screen 404s on SaaS |
| 20 | `licence-activate.blade.php:37-39` | `isOnPrem()` | Cloud tenants see *"nothing to activate"*; only on-prem gets the `.lic` upload form |
| 21 | `InstallController.php:241-243` | `isOnPrem()` | A different post-install message telling the admin to activate |

**Plus three provisioning differences:** on-prem tenants get `seats_licensed = 100000` and `deployment='onprem'` (`ClientProvision.php:71-74`, `InstallController.php:197-200`); on-prem money is a one-time licence price + AMC % recorded in `onprem_clients` rather than a recurring `subscriptions` row; and on-prem code updates are client-pulled and client-applied rather than vendor-applied.

**And the conflict this creates:** because #10 keys off *edition* while #11 keys off *deployment*, a cloud-hosted tenant running an L-edition (`SMARTPRS_EDITION=l2`, `SMARTPRS_DEPLOYMENT=saas`) has **no subscription gate, no licence gate, and no reachable My Subscription screen** (it is in `BLOCK_SAAS`). It is unbillable and unenforceable (DEF-36).

---

#### Q9 — Every credential, key or token present in source, config examples, or seeded data

Verified directly in this extract:

| # | What | Location | Kind | Gated? |
|---|---|---|---|---|
| 1 | `'ametecs'` — the team demo PIN default | `config/smartprs.php:32` | Working `env()` default for a control documented as unlocking the unrestricted demos | Dead code, but neither `.env` template mentions the variable |
| 2 | `'smartprs-demo'` — the demo admin/HR/field-agent password | `app/Console/Commands/DemoReset.php:35`; echoed at `:152`; repeated in the docblock `:26` | Live-platform account password | **No** — runs every 15 min and nightly on the live platform |
| 3 | `'password'` for `admin@smartprs.local` (role `admin`) | `database/seeders/OnPremWorkspaceSeeder.php:70,79`; printed at `:101` | Default admin credential | **No** — run manually, not covered by `SMARTPRS_DEMO_DATA` or `APP_ENV` |
| 4 | `'password'` for five accounts, incl. **`superadmin@smartprs.local` (platform `super_admin`, `tenant_id = null`)** | `database/seeders/DemoCompanySeeder.php:57-61,69` | Default credentials | **Yes** — `DatabaseSeeder.php:19-26` requires `! production && SMARTPRS_DEMO_DATA=true` |
| 5 | The same five logins and their shared password, published in a table | `README.md:56-64` | Documentation disclosure | — |
| 6 | `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'` | `app/Services/LicenseService.php:204` | Licence signing secret fallback for any non-production environment; `.env.example:2` sets `APP_ENV=local` | Production only |
| 7 | `SMARTPRS_LICENCE_SECRET` — the shared HMAC signing key, **specified to be identical on the vendor server and every client install** | `config/smartprs.php:56-58,66-69`; `LicenseService.php:191-205` | **Symmetric signing key by design** | Value not in this extract (DISC-01) |
| 8 | The RSA-2048 **public** key for `.lic` verification | `app/Services/LicenseFile.php:34-44` | Cryptographic material in source (correct by design; but reported elsewhere to be a **dev** key pair — unverifiable here) | — |
| 9 | The RSA **private** key path — `storage/app/keys/license_private.pem` | `LicenseSigner.php:21`; `MakeLicenseKeys.php:26,47` | The signing identity for every perpetual licence. **Excluded from every backup** (`config/backup.php:34` excludes `storage/app`) and overwritable without confirmation via `--force` | — |
| 10 | `ejaz@ametecsindia.com` — hardcoded fraud-alert recipient | `LicenseService.php:539` (`config('smartprs.sales_email')` **does not exist**, so this is the effective default) | Production routing identity | — |
| 11 | `ejaz@ametecsindia.com · WhatsApp 9000098877` in ~11 user-facing strings | `EditionGuard.php:45`; `OnpremClientController.php:255,307,403,467,...`; `SubscriptionService.php:141`; `AuthController.php:225,251` | Production contact identity, unchangeable after `config:cache` | — |
| 12 | GSTIN `36AAHCT0971F1ZB`, legal name, registered address, `+91 96666 12424`, `Telangana (36)`, SAC `998314` — as **PHP defaults**, not just env fallbacks | `BillingController::sellerProfile()` `:741-754` | Production tax identity in source | — |
| 13 | The same GSTIN and address shipped in the env template | `.env.production.example:80-86` | A customer who deploys without editing it issues GST invoices carrying the **vendor's** GSTIN | — |
| 14 | GSTIN + state repeated as template fallbacks, and SAC `997331` | `resources/views/onprem-invoice-pdf.blade.php:41,74` | Same, plus a SAC inconsistent with #12 | — |
| 15 | `demo-admin@smartprs.in`, `demo-hr@smartprs.in`, `demo-field@smartprs.in` | `DemoReset.php:102-104`; `DemoAccessController.php:435` | Hardcoded account identifiers | — |
| 16 | `admin@smartprs.local` | `OnPremWorkspaceSeeder.php:70` | Hardcoded account identifier | — |
| 17 | `demo_requests.pin` — 6-digit passkeys **stored in plaintext and indexed** | `DemoAccessController.php:91`, by explicit design comment | Live credential at rest | — |
| 18 | `licences.key_enc` — the plaintext licence key, `Crypt`-encrypted so the panel can re-show it | `LicenseService.php:64,367`; `reveal()` `:380-387` | Recoverable by anyone with the Super Admin panel **and** `APP_KEY` | — |
| 19 | Razorpay key/secret/webhook-secret env fallbacks | `BillingController.php:560-563,574`; `.env.production.example:70-73` (commented placeholders only) | No live value present in this extract | — |
| 20 | `BACKUP_ARCHIVE_PASSWORD` — **unset by default**, so backups (which include `.env` with `APP_KEY` and `DB_PASSWORD`) are unencrypted | `config/backup.php`; `.env.production.example:52` | — | — |
| 21 | eTimeOffice `corp_id`/`username`/`password` env keys | `config/smartprs.php` | No values present | — |

**Reported by the reference documents but NOT verifiable in this extract** (all `PKG:` paths — see DISC-01/02/03): the literal 64-hex `SMARTPRS_LICENCE_SECRET` in `env.client.template:16` and `ClientBuilder.bat:114`; the cleartext admin and **MySQL root** passwords in `smartprs-config.json:11,19`; the default baked-in build password at `ClientBuilder.bat:54`; the weakly generated `Amt-%RANDOM%…` password for a **`support@ametecsindia.com` super-admin created on every client server**; the portable-MariaDB `root` account with an **empty password** on port 3307; and `setup.json` holding the admin password in cleartext. **Every one of these needs verification against the real delivery tree before the rotation exercise is scoped.**

---

#### Q10 — Backup and restore: what exists, whether restore has ever been proven, and what the on-prem operator can see

**What exists — backup:**

- `spatie/laravel-backup`, two scheduled commands: `backup:clean` at 01:30 and `backup:run` at 02:00, both `withoutOverlapping()->onOneServer()` (`routes/console.php:156-164`).
- Scope (`config/backup.php:31-44`): files = `base_path()` **including `.env`**, excluding `vendor`, `node_modules`, `storage/app`, `storage/framework`, `storage/logs`; databases = `['mysql']`, dumped uncompressed.
- Destination: `'disks' => ['local']` — i.e. `storage/app` **on the same server being backed up**. The config comment itself says: *"For real safety add a remote 'backups' disk (S3/Spaces)… so backups survive a server loss."*
- `'password' => env('BACKUP_ARCHIVE_PASSWORD')` — **unset by default**.
- `'tries' => 1, 'retry_delay' => 0` — one attempt, no retry.
- `notifications`, `monitor_backups` and `cleanup` are **deliberately omitted** from the config (`:87-91`), so the package defaults apply and no failure email is configured. `backup:monitor` is **not scheduled**.

**Three consequences that matter commercially:**

1. **`storage/app` is excluded** — so `storage/app/keys/license_private.pem` (the signing identity for every perpetual licence ever issued) and **every user-uploaded file under `storage/app`** are in **no backup at all**.
2. **`.env` is included, unencrypted, on the same disk** — `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` and any `RAZORPAY_SECRET` travel in the archive. `APP_KEY` decrypts `licences.key_enc` and six other encrypted columns.
3. **A backup that silently stops working is undetectable** — no monitor, no notification, one try. The only detection is a human listing the directory and noticing the newest zip is old.

**What exists — restore: nothing.**

- Grep across `app/`, `database/`, `routes/` finds **no restore command, no restore controller, no restore route and no restore UI**. `app/Console/Commands/` contains 18 commands; none of them restores.
- There is **no documented restore procedure** anywhere in the source tree.
- **Has restore ever been proven?** There is **no evidence in source that a restore has ever been attempted**: no restore test, no restore runbook, no restore log, no drill artefact, no `RESTORE.md`. I record this as *unproven*, not as *known-broken* — but an untested restore is, operationally, not a backup.
- Restoring therefore means an engineer manually unzipping the archive and running `mysql < database.sql` by hand, on a system whose schema is partly created at runtime by application code (§2), so the schema in the dump reflects whatever the application had lazily created at dump time.

**What the on-prem operator can see: essentially nothing.**

- There is **no backup screen, no backup status, no last-run indicator and no restore button** anywhere in the on-prem UI. `Edition::BLOCK_SAAS` 404s `/admin` entirely on a client install, and no `/app/*` backup surface exists.
- The only backup-adjacent thing an on-prem admin ever sees is the **update** backup: the final updater message *"The previous version is kept in storage/app/updates/backup_&lt;stamp&gt; and can be deleted once everything looks right"* (`updater.php:342-343`) — which is a code backup, not a data backup, and is never pruned.
- **Worse for the typical on-prem install:** the reference architecture documents record that **no Windows scheduler artefact ships at all** — the only scheduler artefact in the delivery is a UNIX crontab line. On a Windows/Laragon client install with no Task Scheduler entry, `schedule:run` never fires, so **`backup:run` never runs, and nothing says so**. The `zip` extension is also a hard requirement for backups to work at all, and the installer checks it only as part of the general extension list.

**Verdict: backup is configured but fragile, incomplete in exactly the places that matter most (the signing key and user uploads), unmonitored, and stored on the machine it protects. Restore does not exist as a feature and has not been proven. The on-prem operator has no visibility into either.**

---

### 10.2 Numbered, testable requirements for SmartPRS 3

**Licensing and entitlement**

- **R-01** The licensing engine MUST use asymmetric signing only. Every artefact a client evaluates — licence file, entitlement cache, update package, configuration package — MUST carry a detached signature verifiable with an embedded public key, and there MUST be no symmetric code path and no unsigned fallback. *Test: no HMAC-with-shared-secret primitive exists anywhere in the licensing subsystem; a build with a tampered public key fails every verification.*
- **R-02** The private signing key MUST live only in vendor-controlled key management, MUST be backed up separately from the application, and key rotation MUST be a supported operation that does **not** require re-issuing every live licence — i.e. licences MUST carry a key identifier and the product MUST be able to hold multiple trusted public keys with validity windows. *Test: rotate the key; previously issued licences continue to verify until their stated key-retirement date.*
- **R-03** The locally cached entitlement MUST be signed by the vendor and its signature MUST be verified on every evaluation. Editing the cache MUST invalidate it. *Test: any single-field edit to the stored entitlement blocks access.*
- **R-04** Entitlements MUST be data, not code: a named set of feature/module/limit grants, versioned, attached to a licence, and changeable by re-issuing a licence without a code release. *Test: move a module between two entitlement tiers with no deployment.*
- **R-05** The rule engine MUST support **an entitlement-scoped capability check at both the navigation layer and the endpoint layer, from one declaration** — because BR-LIC-026 shows that maintaining two parallel lists (`hiddenNavIds` and `blockedPatterns`) is what allowed them to silently diverge into a no-op.
- **R-06** Node-locking MUST NOT be defeatable by copying a cached identity file: the fingerprint cache MUST be keyed by, or cryptographically bound to, the hardware identity it caches, and a mismatch MUST invalidate it and raise an event. *Test: copying licence + cache to a second machine fails.*
- **R-07** Licence expiry MUST support a configurable grace period per licence, and the grace value MUST be carried in the licence and honoured — SmartPRS 2 signs `grace_days` into every `.lic` and never reads it.
- **R-08** Seat limits MUST be defined once, in one place, with one definition of "counts against the limit", and that definition MUST be configurable per customer (which employee statuses free a seat, whether archived people count). *Test: `seatUsage()` and the licence-side count return identical numbers for every dataset.*
- **R-09** Every path that creates a person MUST pass through the seat check, including bulk import and self-onboarding, and the check MUST also run on reactivation and status change. *Test: bulk-importing past the cap is refused.*
- **R-10** Server/device seat decisions MUST be enforced, not advisory: a refusal MUST block activation. A storage error MUST fail closed for the seat decision while remaining fail-soft for the session.
- **R-11** Enforcement MUST distinguish **transient** failures (database unreachable → fail open, log loudly, emit a metric, alert) from **deterministic** ones (malformed entitlement, missing crypto extension, signature mismatch → fail closed). Every fail-open event MUST be counted and visible to the vendor. *Test: a corrupt entitlement blocks; a simulated DB timeout does not.*
- **R-12** Revocation MUST NOT depend on a human logging in: there MUST be a scheduled proof-of-life with a configurable interval and a configurable offline tolerance, after which the product degrades to a defined state. *Test: revoke a licence; an idle install blocks within the stated tolerance.*
- **R-13** Machine-move limits (the documented "1/year") MUST be enforced if they are promised, or removed from the documentation. *Test: the N+1st move in a period is refused.*
- **R-14** The licence system MUST NOT rely on local system time alone for expiry. *Test: moving the clock back does not extend a licence.*

**Updates and content delivery**

- **R-15** Update packages MUST be signed and signature verification MUST be mandatory and unconditional. An unsigned or unverifiable package MUST be refused under every configuration. *Test: stripping the signature or the hash refuses the install; there is no configuration that permits it.*
- **R-16** The package MUST carry a signed manifest of per-file hashes, verified after extraction and before installation.
- **R-17** The update client MUST pin the vendor's signing identity, so repointing `UPDATE_URL` at another host cannot deliver installable code.
- **R-18** The update MUST take a **database backup or snapshot** before running migrations, and rollback MUST restore both code and schema to a consistent point. *Test: a migration that fails halfway leaves the system on the previous version with the previous schema.*
- **R-19** Migrations MUST be forward-only-safe or reversible, and the updater MUST verify reversibility before applying.
- **R-20** The post-update health check MUST exercise the real request path: boot the framework, reach the database, serve a health endpoint, and verify the queue connection. Failure MUST roll back. *Test: an update that breaks a route is rolled back automatically.*
- **R-21** The updater MUST restart queue workers and rebuild caches (config/route/view) after a successful install, and MUST leave the maintenance flag set until the health check passes.
- **R-22** Backups created by the updater MUST have a retention policy with a configurable count and age.
- **R-23** One version identity only. The version the build registers, the version the client reports and the version displayed MUST come from a single source. *Test: no two files in the tree state different versions.*
- **R-24** The rollback implementation MUST be identical for the platform and the client — remove-then-restore, covering every directory the package can write. *Test: a rollback leaves no file the new version added.*
- **R-25** The package validator MUST distinguish an *update* package from an *install* package by a signed type field in the manifest, not by guessing at directory shape.
- **R-26** Update progress reporting MUST work on a fresh install with no prior update, and MUST NOT expose migration output, schema names or connection strings to the polling client.

**Configuration and statutory content — the separation SmartPRS 2 does not have**

- **R-27** Configuration, statutory content and master data MUST be delivered as **separately versioned, separately signed artefacts**, independent of the application release. *Test: publishing a PT slab change reaches every install without a code deployment and without a maintenance window.*
- **R-28** Statutory content MUST be effective-dated end-to-end: an artefact states the dates from which each value applies, and a recomputation of a past period uses the values that were in force then. *Test: regenerating March payroll after an April rate change reproduces the original March result.*
- **R-29** Statutory content delivery MUST NOT be gated behind a commercial support contract. A compliance correction is a liability, not a feature. *Test: an install with a lapsed AMC still receives statutory updates while being refused feature updates.*
- **R-30** The statutory rule set MUST be data, including the PT state slab tables and the PT-free state list, which SmartPRS 2 compiles into the engine (`StatutoryConfig.php:31-35`). *Test: adding a state's slab table requires no code change.*
- **R-31** The engine MUST distinguish **vendor-supplied** content, **customer-overridden** content and **customer-authored** content, and MUST show the implementer which is which, so a vendor update never silently overwrites a deliberate customer position.

**Provisioning and configuration-first onboarding**

- **R-32** Tenant provisioning MUST be atomic. *Test: an induced failure at any step leaves no partially provisioned tenant and no captured payment without a workspace.*
- **R-33** Provisioning MUST create a **complete, working configuration** from a named template, not just a tenant shell: organisation structure, pay cycle, financial year, salary structure, statutory position, attendance and leave policy, approval routing, letter and report templates. *Test: a newly provisioned tenant can run a payroll for one employee without opening a configuration screen.*
- **R-34** Configuration templates MUST be first-class, versioned, exportable and importable objects — industry presets, and copy-from-an-existing-customer. *Test: exporting one tenant's configuration and importing it into another reproduces identical behaviour.*
- **R-35** The system MUST expose a **configuration completeness model** that tells an implementer what is still unset and what depends on it. *Test: a fresh tenant reports its unmet configuration prerequisites for running payroll.*
- **R-36** There MUST be exactly one provisioning implementation shared by self-serve signup, the vendor console, the CLI and the on-prem installer — SmartPRS 2 has three with divergent slug rules and divergent seat defaults.

**Commercial configuration**

- **R-37** Every commercial rate MUST be configuration: tax rate(s), advance-payment discounts, billing cycles and their month counts, per-company fees, payment terms, settlement tolerance, SAC/HSN codes, currency and the seller identity. Rates MUST be effective-dated. *Test: changing GST from 18% to another value affects new documents only, with no code change and no reprint of historical invoices.*
- **R-38** Invoice and quotation numbering MUST use a single, atomic, gap-free-per-series sequence with a configurable format and FY boundary, implemented once. *Test: 100 concurrent invoice creations produce 100 distinct consecutive numbers.*
- **R-39** The subscription grace period, the alert ladder and the lock-out policy MUST be configurable per plan or per customer.
- **R-40** A billing period MUST advance only on **payment**, never on invoice issue. *Test: an unpaid renewal invoice does not extend service.*
- **R-41** There MUST be exactly one payment-recording path, partial-aware, idempotent on a **uniquely indexed** gateway transaction id. *Test: a webhook retry concurrent with a browser confirmation records one payment.*
- **R-42** Credit notes MUST exist with their own number series and PDF, and refunds MUST be recordable against an invoice. *Test: the published refund policy is executable in the product.*
- **R-43** An invoice PDF MUST be reproducible: line items, plan name and quantities MUST be snapshotted at issue. *Test: reprinting an invoice after an upgrade reproduces the original document byte-for-byte in content.*
- **R-44** Quotation validity MUST be enforced at order and completion, not only displayed.
- **R-45** Trial MUST either be implemented as a first-class, configurable state with its own alerts and expiry, or removed from the schema.
- **R-46** Which plans are sellable MUST be a data flag, not a hard-coded name whitelist. *Test: renaming a plan does not remove it from signup.*

**Deployment, operations and demo**

- **R-47** Backups MUST support a remote destination, MUST include the signing key and user uploads or explicitly declare them out of scope with a separate protected process, MUST be encrypted by default, MUST be monitored with alerting, and MUST retry. *Test: a failed backup raises an alert within one cycle.*
- **R-48** A **restore** capability MUST exist as a documented, scripted and periodically exercised procedure, with a recorded successful restore drill. *Test: a restore drill from a production-shaped archive succeeds and is logged.*
- **R-49** The on-prem operator MUST have a visible operations surface: last backup, last successful restore drill, licence status and expiry, seat usage, scheduler health, queue health and update status. *Test: an on-prem admin can answer "when was my last good backup?" without a support call.*
- **R-50** Both background processes (scheduler and queue worker) MUST ship as installable services for **every** supported platform, including Windows, and MUST be health-monitored with a visible indicator. *Test: a fresh on-prem install reports scheduler and worker health on day one.*
- **R-51** No default or shared credential may exist in any seeder, installer, build script or configuration default. Every account MUST be created with a generated secret shown once. *Test: a full grep for a literal password across the tree returns nothing.*
- **R-52** Any vendor support account on a customer system MUST be opt-in, time-limited, per-incident, and disclosed in the contract. *Test: no permanent vendor account exists after installation.*
- **R-53** Demo access tiers MUST be separated by authorisation, not by a caller-supplied parameter: an unrestricted demonstration MUST require a vendor-authenticated, signed, expiring invitation. *Test: a public visitor cannot obtain an unrestricted session.*
- **R-54** Demo accounts MUST NOT be authenticable by password; entry MUST be only through the gated flow, and every demo session MUST be time-boxed and reset.
- **R-55** Every public POST that writes, spends or calls a third party MUST be rate limited. *Test: no unthrottled public write endpoint exists.*
- **R-56** The build and release pipeline MUST be CI-driven, platform-neutral, reproducible, and MUST fail on a version mismatch or an unsigned artefact. *Test: a release cannot be published without a passing test suite and a valid signature.*
- **R-57** Every table MUST be created by a versioned migration. Runtime `Schema::create`/`Schema::table` on the request path MUST NOT exist. *Test: a fresh database built only from migrations passes the full test suite.*

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it must be answered | What is known | What is needed |
|---|---|---|---|---|
| **Q-01** | **Do editions exist in SmartPRS 3 at all?** | SmartPRS 2 sells three price points and delivers one product (DEF-02). Either the entitlement engine is built and every existing L1/L2 customer must be told their product is changing, or the tiers are abandoned and pricing is restated. **This blocks the entitlement model, the licence payload and the pricing model.** | `Edition::level()` returns 3; the DNA/L2 constants survive *"for reference / easy revert"* | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-02** | **What did existing L1/L2 customers actually buy?** | If entitlements are enforced in v3, customers currently receiving everything will lose function. Is that a downgrade, a grandfathered grant, or an upsell conversation? | `onprem_clients.edition` records what was sold | **PRODUCT OWNER + LEGAL DECISION REQUIRED** |
| **Q-03** | **Is the shipped `.lic` key pair a development pair?** | If yes, every perpetual licence is forgeable the moment the private key leaks, and rotation is required before any v3 licence is issued — and rotation invalidates every existing `.lic`. | The reference docs assert it from a go-live checklist **not present in this extract** (DISC-02) | **VERIFY against the real repository, then decide the rotation window** |
| **Q-04** | **Was `SMARTPRS_LICENCE_SECRET` ever shipped to customers, and to how many?** | It determines whether a rotation and re-issue exercise is needed at all, or only a code deletion. | The design requires it (`config/smartprs.php:56-67`); the value is reported at two `PKG:` paths **not in this extract** (DISC-01) | **VERIFY, then scope the rotation** |
| **Q-05** | **Does `guard-runtime.php` exist in delivered client builds, and what does it do?** | The reference docs describe a **second, parallel licensing system** running before every request via `router.php`, checking an HMAC-signed `storage/licence.lic` plus a MAC lock — with the README and the build script disagreeing on whether `SMARTPRS_LICENCE_ENFORCE` ends up true or false. If it exists, there are two licence systems in production that may contradict each other. | Not in this extract | **OBTAIN `client-builder-tools/` and analyse** |
| **Q-06** | **Is `support@ametecsindia.com` a real super-admin on customer servers?** | A permanent vendor super-admin inside every customer's payroll database is a contractual and DPDP-Act exposure, and it is created by a `db:seed --force` the installer runs. | Reported from `PKG:` installer scripts not in this extract; `SuperAdminSeeder.php` in this tree only creates from env | **VERIFY, then decide the access model (R-52)** |
| **Q-07** | **Are production client builds actually SourceGuardian-encoded?** | Without encoding, `LicenseFile::PUBLIC_KEY` is one editable line and the licence system offers essentially no technical protection. The build script reportedly warns and ships readable PHP when the encoder is unset. | `InstallController.php:84-89` checks the loader at **warn** level, so a plain-source install is permitted | **VERIFY build logs; decide whether encoding is mandatory in v3** |
| **Q-08** | **Has a restore ever been performed from a SmartPRS backup?** | Backup exists; restore does not exist as a feature and no drill artefact is in the tree. An untested restore is not a backup. | No restore command, route, UI or runbook anywhere (§10 Q10) | **PRODUCT OWNER DECISION REQUIRED: fund a restore capability and a drill schedule** |
| **Q-09** | **Which on-prem installs currently have a working scheduler?** | Without it, backups, subscription alerts, compliance scans, transfers, probation and absence notifications all stop **silently**, and no Windows scheduler artefact ships. | Reported in the architecture document; no Windows service artefact in this extract | **AUDIT the installed base** |
| **Q-10** | **Should statutory content delivery be free of the AMC gate?** | Today `/update/check` refuses everything when AMC has lapsed, so a lapsed customer cannot receive a PT correction. This is a liability question as much as a commercial one. | `UpdateServerController::check()` `:238-246` | **PRODUCT OWNER + LEGAL DECISION REQUIRED (R-29)** |
| **Q-11** | **Is the archived-employee seat behaviour intended?** | On SaaS, archived people consume seats while being invisible; on-prem they do not. The error message states the on-prem behaviour to both. Whichever is chosen changes what customers are billed. | `SubscriptionService.php:88-97` vs `ClientUpdateController.php:515-520` vs the message at `:141` | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-12** | **Is `runRenewals()` ever run in production?** | If it is, every unpaid tenant has been silently extended and the lock-out has never fired. | Not scheduled by design (`routes/console.php:171-178`), but runnable manually, and the behaviour is asserted by a green test | **AUDIT: check for `subscriptions` whose period was extended without a matching successful payment** |
| **Q-13** | **Are there tenants with no `subscriptions` row?** | `state()` returns `none` for them and they are **never gated and never billed** — a silent revenue leak that a failed provisioning step (DEF-15) creates. | `SubscriptionService.php:53-54` | **AUDIT production data** |
| **Q-14** | **Are there invoices where `SUM(payments) > amount + tax`?** | DEF-12 double-credits any invoice that went through both recorders. This must be reconciled before any migration. | `recordPayment()` vs `applyPayment()` | **AUDIT production data** |
| **Q-15** | **Does Ametecs operate any cloud tenant on an L-edition?** | That configuration is unbillable and unenforceable (DEF-36), and the code comment at `ClientUpdateController.php:225-230` implies it has happened. | Source comment only | **AUDIT the deployment inventory** |
| **Q-16** | **Which copy of `update-status.php` is actually missing?** | It decides whether on-prem update progress reporting works at all. The reference doc and this extract disagree (DISC-04), and the `public/` directory here is trimmed. | `updater/update-status.php` present here; `public/update-status.php` absent here | **VERIFY against the real repository** |
| **Q-17** | **What is the intended on-prem seat model?** | Today the default is unlimited: `seats_licensed = 100000` plus a `.lic` `seats` field defaulting to 0 ⇒ `device_limit = null`. If on-prem is genuinely licensed by user count, the default is wrong in the customer's favour on every install. | `ClientProvision.php:72`, `InstallController.php:198`, `OnpremClientController.php:341` | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-18** | **What is the real target implementation time for a new customer?** | §10 Q7 establishes that automation ends at "tenant + one company + one admin" and everything else is manual. The v3 configuration-template requirement (R-33/R-34) should be sized against a stated target. | Source records no elapsed time | **PRODUCT OWNER MUST STATE THE TARGET** |
| **Q-19** | **Should an on-prem customer be able to see and manage their own backups?** | Today they cannot see anything (§10 Q10). Exposing it is an operations feature; not exposing it makes the vendor responsible for every customer's data recovery. | No backup surface exists on-prem | **PRODUCT OWNER DECISION REQUIRED (R-49)** |
| **Q-20** | **What is the acceptable offline tolerance for a licensed install?** | Revocation, expiry and proof-of-life all depend on it. Today it is effectively infinite for a `.lic` on an isolated machine (DEF-08), and there is no scheduled heartbeat at all. | `revocationSweep()` 24h throttle, login-only | **PRODUCT OWNER DECISION REQUIRED (R-12)** |

---

*End of analysis.*
