# SaaS Platform section — SmartPRS 2 parity notes

Sidebar key `platform`, permission `tenant.settings.update` on every item, screen and action.

## What SmartPRS 2 had

`PERM_NAV.saas` + the `/admin/*` Laravel pages: platform-dashboard, tenants, plans, subscriptions,
invoices, payments, gateways, feature-flags, demo-requests / admin-leads, admin-quotations,
admin-coupons, admin-staff, admin-onprem, admin-releases, admin-landing. `LeadController` worked
leads new → contacted → closed; plans priced as base ₹/month including `seat_max` employees plus
`per_user_price` per extra; invoices split CGST+SGST when the buyer's state code was 36, else IGST.

## What is built here

All arithmetic is `surface/SaasPlatform.php` over `$platform` (the unscoped store): plan monthly
price, MRR, GST split, coupon validity and discount, quotation totals, invoice numbering and
balance, flag resolution, expiring licences, the dashboard counts, the tenant book (employee count
via `$platform->forTenant($id)->count('employees')`). Every amount is `Money` via `Amount::of()`.

Rules live in config: `config/plans.json` (cycles → months, every status vocabulary, invoice
prefix, on-prem editions/bands/AMC months), `config/gst.json` (rate, home state, state codes),
`config/feature-flags.json` (the flag keys and defaults), `config/leads.json` (pipeline, sources).

| Item | Kind | Files |
|---|---|---|
| Platform Dashboard | screen | `screens/platform-dashboard.php` |
| Tenants | screen + action | `screens/tenants.php`, `actions/tenant.php` (save, suspend, reactivate) |
| Plans & Pricing | screen + action | `screens/plans.php`, `actions/plan.php` — modules ticked from the module registry into `features` JSON |
| Subscriptions | screen + action | `screens/subscriptions.php`, `actions/subscription.php` (save with computed amount, renew, cancel) |
| Invoices | screen + action | `screens/invoices.php`, `actions/invoice.php` (raise with GST, mark paid → payment row, cancel) |
| Payments | screen + action | `screens/payments.php`, `actions/payment.php` (record; settles the invoice at zero balance) |
| Payment Gateways | module | `config/modules.d/platform.json` `payment-gateways` — key/secret as password fields |
| Feature Flags | screen + action | `screens/feature-flags.php`, `actions/feature-flag.php` (global / per-tenant / clear) |
| Leads | screen + action | `screens/leads.php`, `actions/lead.php` (save, status, from live-demo entry, convert to tenant) |
| Quotations | screen + action | `screens/quotations.php`, `actions/quotation.php` (price with coupon, accept → tenant + subscription + redemption) |
| Discount Coupons | module | `config/modules.d/platform.json` `coupons` — code unique, `used_count` read-only |
| Platform Staff | screen (list only) | `screens/platform-staff.php` |
| On-Prem Clients & Licences | screen + action | `screens/onprem.php`, `actions/onprem.php` (client, payment, issue key, extend AMC, revoke) |
| Releases & Updates | screen + action | `screens/releases.php`, `actions/release.php` (save, publish, mark applied on cloud, grant) |

Migration `2026_03_02_000900_platform_tables.php`: new `quotations` and `feature_flags` (both
declared platform-scoped in `TableRegistry::PLATFORM_TABLES`; `feature_flags.tenant_ref` is the
override target, NULL = global), plus `invoices.subscription_id/period_from/period_to/notes`,
`coupons.valid_from`, `leads.converted_tenant_id`.

Tests: `tests/platform_section_test.php` — 67 assertions over price, MRR, GST, coupons,
quotations, invoice numbering and balance, flags, licence expiry, the dashboard over a store.

## Deliberately not ported

* **Landing Page CMS (`admin-landing`)** — edits the public website's copy, which does not exist in
  SmartPRS 3; there is nothing for it to publish to.
* **Platform Staff add/edit/disable** — list only. User Management already creates, re-roles and
  disables accounts under the password floor and the audit trail; a second path is a second place
  to get it wrong.
* **Gateway webhooks, pay links, quotation PDFs, welcome emails, WhatsApp/email renewal alerts** —
  outbound integrations. The registers they would write to (payments, subscription_alerts) exist;
  the sending is not a screen.
* **Licence key encryption** — `licences.key_enc` is left empty; the key is shown once on issue and
  only its SHA-256 hash and last four characters are kept. Encrypting at rest needs a platform
  secret nobody has configured.
* **Release upload / apply** — a release row is the record of a build; the operator deploys. Grants
  go to on-prem clients (`release_grants.client_id`); cloud tenants run this server's
  `config/version.json`.
* **Subscriptions/invoices/payments as module screens** — the shipped modules in `modules.json`
  still exist but sit behind `$store`, so a platform operator would see only its own tenant's rows.
  The hand-built screens read `$platform` and are the ones in the sidebar.

## TODO for the lead

* ~~`tests/schema_test.php` hard-codes the table count (186)~~ — DONE, it already asserts 189 in both
  places (`same(189, count(Schema::$blueprints), ...)` and `same(189, count($tables), ...)`). Doc was stale.
* `docs/build-journal.json` / `docs/heartbeat.json` not touched (shared files).
