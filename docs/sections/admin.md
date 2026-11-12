# Administration (section `admin`)

## What SmartPRS 2 had

Sidebar "Settings": companies, departments, designations, branches, banks, users, roles
(a checkbox matrix per role × module × view/create/edit/approve/delete — with a footnote that
enforcement was "the next phase"), branding (per-company logo / colour / display name plus a
per-account app logo), company-emails (Email / SMTP: host, port, encryption, username,
password, from), fin-year, my-subscription (plan, seats, renewal), settings, sys-updates
(version, edition, AMC, "Download & Install"), probation-config (default months, reminder
milestones), mobile-devices (phones registered by the attendance app: approve / reject with
reason / revoke), greetings (owned by the communication agent — skipped here).

## What was built

Drop-ins only: `config/navigation.d/admin.json`, `config/modules.d/admin.json`,
`config/screen-help.d/admin.json`, `config/how-it-works.d/admin.json`.

| Item | Route | What |
|---|---|---|
| Companies / Departments / Designations / Branches / Financial Year | `setup-*` | existing Organization Setup screens, linked |
| User Management / Activity Logs / System Health / API Keys | `users` / `trail` / `health` / `api-keys` | existing routes, linked |
| Banks | module `banks` | supersedes the one-column module: company, account holder, number (edit-permission), IFSC, branch, purpose, status |
| Email / SMTP Settings | module `smtp-settings` | new table `smtp_settings`, one row per company (`unique`), password typed as password, nothing sent |
| Roles & Permissions | `surface/screens/roles.php` | READ-ONLY matrix: permission module × role, derived by `surface/RoleMatrix.php` from `Authorisation::allows()` over `PermissionCatalogue::seeded()->declaredCodes()`; says plainly that changes are a reviewed edit to `surface/Authorisation.php` (Law 5) |
| Company Branding | `surface/screens/branding.php` | per company: colour / logo / logo text from the companies row, sidebar-header and document-heading previews, link to the companies edit form. No second logo store |
| General Settings | `surface/screens/general-settings.php` | tenant identity (read-only) + every `config/*.json` with its `customisation_key`, customised/as-shipped, version, by whom (`surface/RuleFiles.php` over `Customisation::live()/history()`); links to Configuration |
| My Subscription | `surface/screens/my-subscription.php` | plan (from `plans`), seats bought vs active employments, companies, period end, days left (`surface/Licensing.php`); read-only |
| Updates & Licence | `surface/screens/sys-updates.php` | version from `config/version.json` (new), licence row for this installation (`licences.client_id` = tenant; the table is platform-scoped), AMC days left, `licence_events`; read-only |
| Probation Settings | `surface/screens/probation-settings.php` | default months / milestones / notify manager over `config/probation.json`; posts the EXISTING `probation-config` action (`Probation::saveConfig` → `Customisation::saveRaw`), shows every version |
| Mobile Devices | `surface/screens/mobile-devices.php` + `surface/actions/mobile-device.php` | list with employee, model/OS, code, registered/seen, status; per-row Approve / Reject (reason required) / Revoke, moves declared in `config/mobile-devices.json` (`surface/MobileDevices.php`); hand-registration form; audited |

Migration `database/migrations/2026_03_02_000700_admin_tables.php` (applied): creates
`smtp_settings`; adds nullable `employer_company_id, account_holder, account_number, ifsc,
bank_branch, purpose, status, deleted_at` to `banks`; adds nullable `employee_id, model,
os_version, deleted_at` to `mobile_devices`.

Tests: `tests/admin_section_test.php` — 45 assertions (matrix agrees with the decider,
device transitions, licence/subscription arithmetic, rule-file inventory).

## Deliberately not ported

* **Editable role matrix.** Law 5: authorisation is declarative in `surface/Authorisation.php`;
  a runtime permission table would be a second authorisation implementation.
* **Per-account app logo upload** (branding). The company logo brands every document; a second
  logo store was a second thing to go missing.
* **"Download & Install" update button.** A release is an operator's deployment with
  `migrate.php` and the gates; the screen shows the version and licence and says so.
* **Subscription alerts table.** `subscription_alerts` has no tenant column, so it is not shown
  under a tenant.
* **Sending a test mail** from SMTP settings — no mailer in the product yet; settings are stored only.
* **Greetings** — communication agent.

## TODO

* When a mailer lands, `smtp_settings.password` should be encrypted at rest (it is a plain text
  column today, shown back never).
* The mobile app's own registration endpoint (PRS2 `mobileDevBase`) is not built; devices land
  via the hand-registration form until it is.
