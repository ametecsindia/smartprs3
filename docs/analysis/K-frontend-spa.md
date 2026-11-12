# AS-IS ANALYSIS — FRONT END (SmartPRS 2 SPA)

## 0. Scope and evidence base

### 0.1 Reference documentation read

| Doc | Lines read | What it covers |
|---|---|---|
| `refs/COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 36593–38741 | Section 15 — Frontend architecture, screen registry, master traceability matrix |
| same | 38742–43180 | Section 16 — SPA Part 1 (AppController lines 1–6500) |
| same | 43181–49450 | Section 17 — SPA Part 2 (AppController lines 6500–12533) |
| same | 49451–51123 | Section 18 — SPA Data API (`AppDataController`) and residual modules |
| `refs/BUSINESS_RULES_CATALOGUE.md` | 13462–13501 | "Rules implemented ONLY client-side" (21 rules) |

### 0.2 Source files read

| File | Lines | Read |
|---|---|---|
| `prs2/app/Http/Controllers/AppController.php` | 12,533 (1.2 MB) | COMPLETE, in slices |
| `prs2/app/Http/Controllers/AppDataController.php` | 3,090 | COMPLETE |
| `prs2/app/Http/Controllers/AppStateController.php` | 54 | COMPLETE |
| `prs2/app/Http/Controllers/TestController.php` | 292 | COMPLETE |
| `prs2/app/Http/Controllers/ScreenHelpController.php` | 2,206 | Structure + all keys; 801 array entries |
| `prs2/resources/prototype/app.html` | 986 lines / 234,234 bytes | COMPLETE (parsed programmatically — line 356 is a single 70,853-char line) |
| `prs2/resources/views/layouts/app.blade.php` | 91 | COMPLETE |
| `prs2/public/css/smartprs.css` | 353 | COMPLETE |
| `prs2/resources/css/smartprs-theme.css` | 36 | COMPLETE |
| `prs2/routes/web.php` | 696 | COMPLETE (cross-check for route existence / middleware) |
| `prs2/app/Http/Controllers/MasterController.php` | (cross-check only) | `def()` :30, `list()` :608–700, `save()` :703 |
| `prs2/app/Http/Controllers/SettingsController.php` | (cross-check only) | `defaults()`, `index()`, `save()` |

### 0.3 The single most important structural fact

**The entire SmartPRS 2 front end is one PHP file.**

`AppController::show()` (`AppController.php:63–12533`) reads a static HTML prototype off
disk (`resources/prototype/app.html`), splices three blocks into it as *strings*, and
returns the result. There is no build step, no bundler, no framework, no module system,
no `package.json`, no `vite.config.js`, no `resources/js/` directory in this tree.

The three injections:

| Injection | AppController lines | Size | Inserted at |
|---|---|---|---|
| CSS heredoc `<<<CSS` | 235–447 | 213 lines | before the **first** `</head>` |
| `window.__SMARTPRS = {$cfg}` | 453 | ~90 keys of JSON | start of `$boot` |
| JS heredoc `$boot = <<<HTML` | 452–12513 | **12,060 lines** | before the **last** `</body>` |

`AppController.php:232–234` documents why the insertion points are careful:

> "the prototype's JS builds HTML strings containing literal `</head>` and `</body>`,
> so we must inject ONLY at the real document tags (first `</head>`, last `</body>`) —
> never a blind replace-all."

Every page load ships the whole ~1.2 MB document. Response headers
(`AppController.php:12527–12531`) are `no-store, no-cache, must-revalidate, max-age=0`
— so **nothing is cacheable and the full 1.2 MB is re-downloaded and re-parsed on every
navigation to `/app/{screen}`**.

`$boot` is a PHP **interpolating** heredoc. Consequences that constrain every line of
JavaScript in the file:
- No bare `$` in the JS body (no jQuery, no `$el` variables). The only `$` in the body
  is `{$cfg}` at line 453.
- No ES6 template literals (backticks are legal but `${}` would interpolate).
- Every JS regex/string backslash escape must be doubled in the PHP source.

This is why the code is written in ES5-with-`let`/`const`-sprinkles style, uses
`String.prototype.split().join()` instead of regex in places (`esc()` at :2159), and
why almost every helper is `var x = function(){}` rather than a class.

---

## 1. Business capability inventory

### 1.1 Single-page application shell
One authenticated URL (`/app/{screen?}`) renders the whole product. Navigation between
~146 screens is client-side: `go(id, el)` sets `CURRENT`, then `render()` replaces
`#host.innerHTML` with a string built by a renderer function. The URL does not change on
navigation; there is no History API usage, so **browser Back leaves the application**.

### 1.2 Screen registry
A JavaScript object `SCREENS[id] = {title, type, coll, cols, fields, add, …}` declares
every screen. Seven `type` values drive seven generic renderers (`list`, `custom`,
`cards`, `lettered`, `form`, `empform`, `settings`). This is the closest thing SmartPRS 2
has to metadata-driven UI — and it is abandoned in practice (see §1.3).

### 1.3 Server-side "upgrade" of the prototype
`wireCustomScreens()` (`AppController.php:3185–3312`) and `wireRoadmapScreens()`
(`:2112–3183`) **re-register ~90 of the 114 prototype screens as `type:'custom'`**, which
means the declarative `cols`/`fields` metadata for those screens is discarded and their
UI is instead produced by a hand-written function inside a 58-branch `if/else` chain in
`renderCustom`. The declarative registry survives for 71 `list` screens *as declared*,
but ~53 of those are then overridden.

### 1.4 Client-side data store and persistence
An in-browser object `DB` holds every collection. It is loaded from two endpoints at boot
(`GET /app/state`, `GET /app/data`), mutated in place by every screen, and pushed back
wholesale to `POST /app/state/save`, which writes it as one JSON blob into
`settings.key='app_state'` for the tenant.

### 1.5 Client-side business logic
Statutory and money calculations (PF, ESI, TDS, GST, commission, increments, gratuity
displays, leave balances, ledger balances) are implemented **in browser JavaScript**, in
several cases with **no server equivalent at all**. See §3 and §4.

### 1.6 Access control presentation
Role-based nav hiding, module-permission hiding, plan gating, edition gating and demo
lockdown are all implemented as `element.style.display = 'none'`. See §1.10 / §7.1.

### 1.7 Per-screen contextual help
`GET /app/help/{screen}` returns `{how, why, mistakes}` from a 801-entry PHP array
(`ScreenHelpController.php`). Rendered into a modal by `wireScreenHelp()`
(`AppController.php:8737+`).

### 1.8 Bootstrap payload
`window.__SMARTPRS` — role, plan modules, CSRF token, ~70 endpoint URLs, the employee
field registry, branding, edition, subscription state, user identity. §5.

### 1.9 Import / export / reporting in the browser
CSV parsing, XLSX generation (SheetJS from CDN), PDF-adjacent print views, bulk upload
header mapping heuristics and CSV escaping are client-side.

### 1.10 A second, dead front end
`resources/views/layouts/app.blade.php` plus 17 Blade views that extend it. See §6.1.

---

## 2. Data model

The front end is not the owner of any table, but it **writes one table directly and
depends on the shape of several others**.

| Table | Column(s) the front end owns | PROVENANCE | Notes |
|---|---|---|---|
| `settings` | row `key='app_state'`, `value` = whole `DB` JSON blob | migration `create_settings_table` (see DB analysis) — but the *row* is created on demand by `AppStateController::save()` :14–24 via `updateOrInsert` | **The entire client state of the product is one untyped JSON column.** No schema, no size cap, no validation. |
| `settings` | `key='rates'` etc. | as above | Read by `AppDataController::bootstrap()` into `window.__RATES`; falls back to hard-coded defaults client-side (`spRates()` :11160). |
| `employees` | ~40 columns read into the client employee map | migration + **RUNTIME DDL** | `AppDataController::ensureEmployeeColumns()` :46–75 executes `Schema::table('employees', …)` **on every `GET /app/data`**. CRITICAL FINDING — see §6.2. |
| `payroll_runs`, `payslips` | read for `DB.payrollRuns`, `window.__PAYSLIPS` | migration + **RUNTIME INSERT** | `AppDataController::ensurePayrollHistory()` :2340–2431 **INSERTs 12 months of payroll runs and payslips during a GET request** if none exist. CRITICAL — see §6.3. |
| `test_questions`, `test_attempts` | read/written by the Tests screens | **RUNTIME DDL** — `TestController::ensureTables()` :24–57 `Schema::create(...)` on request | CRITICAL — see §6.4. |
| `statutory_settings` | statutory config screen | **RUNTIME DDL** — `SettingsController` self-creates | CRITICAL. |
| master tables (60 types) | read/written via `GET/POST /app/master/{type}` | `MasterController::def()` :30 | Client `MASTER_MAP` (`AppController.php:10756–10892`) has **61** keys; `def()` has 60 and does **not** include `letters-nda`. See §6.7. |

### 2.1 `settings.app_state` — the blast radius

`AppStateController.php` in full (54 lines) is the only writer:

```php
public function save(Request $request) {
    $state = $request->input('state');
    if (! is_array($state)) { return response()->json(['ok' => false], 422); }
    DB::table('settings')->updateOrInsert(
        ['tenant_id' => $this->tenantKey($request), 'key' => 'app_state'],
        ['value' => json_encode($state), 'updated_at' => now(), 'created_at' => now()]);
    return response()->json(['ok' => true]);
}
```

What is persisted here: every collection the SPA maintains that is **not** table-backed —
roles and their permission matrices (`DB.roles`), leave types, holidays, letters,
templates, points rules, KB entries, incentive schemes, assets, notices, policies,
awards, complaints, escalations, clawbacks, deductions, salary setup, pay cycles, and
approximately 60 more.

Blast radius if corrupted or overwritten:
- **One request replaces all of it.** `updateOrInsert` with a whole-blob `value`. There
  is no merge, no version, no `If-Match`, no optimistic locking.
- **Last write wins across users.** Two admins in two tabs each hold a full copy of `DB`
  in memory. Whoever saves last silently discards the other's work — including work done
  on entirely unrelated screens.
- **No role check.** Any authenticated user on any role can POST arbitrary JSON. §7.2.
- **No size limit.** `json_encode` of an attacker-supplied array into a text/JSON column.
- **No schema validation.** `is_array($state)` is the only check. A saved
  `{"roles":[...]}` with fabricated `perms` is loaded next boot and drives `applyPerms()`.
- **Recovery is manual.** There is no history table, no soft delete, no backup of the
  blob. Losing this row loses all non-table-backed configuration for the tenant.

`show()` strips only `employees` from the returned state; everything else round-trips.

---

## 3. Business rules register

Legend for **HARD-CODED**: Y = literal in JS with no configuration path;
P = partially (server-supplied default exists but client falls back to a literal).

### 3.1 Catalogue rules verified in source (client-only)

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-705 | TDS computed on the New Regime slabs with 87A rebate and cess | annual taxable | `taxable <= rebate_87a_limit` → 0 | slab-walk then × (1+cess) | Global | `AppController.php:11167–11177` (`tdsTax`) | P — defaults at :11160 | Only if `settings.rates` supplies overrides; slab array itself only via `rates.tds_slabs` | Y — old regime, 80C, HRA all absent |
| BR-PAY-715 | Standard deduction subtracted before slab tax | `std_deduction` | always for salary base | `salBase - STDDED` | Global | `AppController.php:12098–12107` | P (default 75000 at :11162) | via `rates.std_deduction` | Y |
| BR-PAY-726 | PF on 50% of gross as "basic", capped at PF wage ceiling | monthly gross | always | `basic = gross*0.5; pfW = min(basic, cap)` | Global | `AppController.php:12080–12081` | **Y — the 0.5 is a literal** | No | **Y — critical**: basic is a contractual figure, not 50% |
| BR-PAY-727 | ESI applies when monthly gross ≤ threshold | monthly gross | `gross <= esi_threshold` | employee 0.75%, employer 3.25% | Global | `AppController.php:12083–12086` | P | via `rates.*` | Y |
| BR-PAY-728 | Commission TDS at 5%, raised to 20% when PAN absent | gross commission, PAN | `!pan` → max(rate, 20%) | `commTds = round(base*rate)` | Global | `AppController.php:12104–12106` | P | via `rates.comm_tds_rate`/`no_pan_tds_rate` | Y |
| BR-EMP-701 | Employee form field validation (format + mandatory) | form values | per-field regex/length | block save, show inline error | Global | `AppController.php:1645–1721` (`EMP_VALID_IDS`, `empFieldError`) | Y | No | **Y — every customer wants different mandatory fields** |
| BR-EMP-701b | DRA certificate and PCC mandatory **only on new hire** | isNew, dra, pcc | `isNew && (!dra \|\| !pcc)` | block save | Global | `AppController.php:1917–1928` | Y | No | Y |
| BR-ATT-713 | Attendance-report column heuristics for bulk upload | uploaded header row | fuzzy name match | map column → field | Global | `AppController.php` (`abHeaderMap`) | Y | No | Y |
| BR-ATT-714 | Default month for payroll/attendance screens | today's date | — | previous/current month rule | Global | `AppController.php` (`pgDefaultMonth`) | Y | No | Y |
| BR-AUTH-702 | Role → allowed screen list | role label | `ROLES[role].indexOf(id)` | show/hide nav | Global | `resources/prototype/app.html` (`ROLES`, `roleAllows`) | **Y — literal arrays in the prototype HTML** | No | **Y** |
| BR-SEC-702 | Permission matrix hides nav for non-Admin roles | `DB.roles[].perms` | `!spCan(mk,'view')` | `display:none` | Global | `AppController.php:764–783` | N (data-driven) | Roles screen (persists to `app_state`) | — |
| BR-SEC-707 | Add/Edit/Delete buttons hidden per role matrix | perms | `!spCan(mk,'create'\|'edit'\|'del')` | hide buttons | Global | `AppController.php:796–…` (`gateActions`) | N | as above | — |
| BR-LIC-019 | Edition hides a fixed set of nav ids | `cfg.editionHide` | id in list | `display:none` | Global | `AppController.php:8805, 8823` (`wireEditionLockdown`) | N client-side (server `Edition::hiddenNavIds()`) | Only by editing `Edition` service | Y |
| BR-BIL-043/044/045 | Subscription banner / lock-out states | `cfg.subState/subDays` | active\|grace\|locked\|none | banner or lock UI | Global | `AppController.php:1503–1530` (`wireSubBanner`, `injectMySubNav`) | Y (thresholds) | No | Y |
| BR-CMP-707 | Company switcher scoping of lists | `COMPANY` global | `companyKey` on screen | filter rows client-side | Global | prototype `renderList`; `AppController.php:510` sets `COMPANY='ALL'` | Y | No | Y |
| BR-LVE-702 | Leave balance shown = entitlement − taken − **pending** | balances API + pending requests | always | subtract pending from available | Global | `AppController.php` leave screens | Y | No | Y |
| BR-NOT-700 | Notification bell polls every 60 s | timer | — | `setInterval(rmBell, 60000)` | Global | `AppController.php:3182` | **Y — literal 60000** | No | Y |
| BR-REC-043/047/048 | Recruitment pipeline stage list and transitions | stage | client list | render columns / move card | Global | `AppController.php:8158+` recruitment screens | Y | No | **Y — every customer has different stages** |

### 3.2 Rules the catalogue MISSED (new IDs)

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-FE-001 | GST on a bank-pack invoice is split CGST+SGST when supplier and buyer GSTINs share the first two characters (state code), else IGST | supplier GSTIN, buyer GSTIN, line sum | `sg.slice(0,2)===bg.slice(0,2)` | intra: 9%+9%; inter: 18% | Global | `AppController.php:7200–7211` (`bpInvCalc`) | **Y — 9 and 18 are literals** | No | **Y — GST rates change by statute and by service** |
| NEW-FE-002 | Commission request net amount = gross − TDS at rate, defaulting to 5% and clamped to 0–100 | gross, rate field | `!(0<=rt<=100)` → 5 | `net = round((g − g*rt/100)*100)/100` | Global | `AppController.php:4161–4168` (`rqCommCalc`) | **Y — default 5 literal** | No | Y |
| NEW-FE-003 | Incentive scheme base → gross = base × rateValue / 100 | base amount, scheme rate | base > 0 | `gEl.value = round(base*s.rateValue)/100` | Global | `AppController.php:4654–4663` (`schemeBaseCalc`) | N (rate from scheme) but the /100 basis is fixed | Scheme master | Y (flat amount, slab, tiered all unsupported) |
| NEW-FE-004 | Increment: new salary ↔ percentage cross-calculation | old, pct or new | either direction | `new = old*(1+pct/100)`; `pct = ((new−old)/old)*100` rounded to 2dp | Global | `AppController.php:5315–5327` (`rqIncCalc`) | Y | No | Y (fixed-amount increments unsupported) |
| NEW-FE-005 | Commission percentage default when salary type is "Salary + Commission" | `e.commPct` | missing | **default 30%** | Global | `AppController.php:12098` (`\|\| 30`) | **Y — literal 30** | No | **Y** |
| NEW-FE-006 | Monthly TDS deduction = annual TDS ÷ 12, rounded | annual TDS | always | `m = Math.round(tot/12)` | Global | `AppController.php:12107` | **Y — divisor 12 literal** | No | Y (remaining-months proration is the norm) |
| NEW-FE-007 | Monthly gross derived from CTC as CTC ÷ 12 | `e.ctc` | always | `gross = ctc/12` | Global | `AppController.php:12080` | **Y** | No | **Y — CTC includes employer PF/gratuity; this double-counts** |
| NEW-FE-008 | Statutory bonus percentage default 8.33% | — | when `rates.bonus_pct` absent | 8.33 | Global | `AppController.php:11164` | P | via `rates` | Y (min/max bonus %, eligibility wage cap all absent) |
| NEW-FE-009 | Client-side statutory rate defaults used whenever the server omits a key | `window.__RATES` | key `== null` | fall back to literal default | Global | `AppController.php:11160–11166` (`spRates`) | **Y — 17 literals + a 7-band slab table** | Partially via `settings.rates` | **Y — this is the statutory config surface and it is duplicated client-side** |
| NEW-FE-010 | Payroll-run ledger running balance computed in the browser | ledger rows | ordered | cumulative add/subtract | Global | `AppController.php` `pay-ledger` renderer | Y | No | Y |
| NEW-FE-011 | Bulk-upload row cap | parsed rows | count > cap | reject/truncate | Global | `AppController.php` (`commBulkGo`, attendance bulk) | Y | No | Y |
| NEW-FE-012 | Import header mapping heuristics (fuzzy match of spreadsheet headers to fields) | header row | substring/alias match | assign column | Global | `AppController.php` (`commBulkGo`, `abHeaderMap`) | Y | No | Y |
| NEW-FE-013 | Salary line acknowledgement is offered only when the row belongs to the current user | line.empCode vs cfg | equality | show Acknowledge button | Global | `AppController.php` salary-lines renderer | Y | No | Y |
| NEW-FE-014 | Unknown Spatie role maps to **Admin** (full access) | `getRoleNames()->first()` | not in `ROLE_MAP` | `?? 'Admin'` | Global | `AppController.php:93` | **Y** | No | **Y — this is fail-open and must invert** |
| NEW-FE-015 | Missing permission matrix means "allow" | `window.spPerms` | null | `spCan()` returns `true` | Global | `AppController.php:758–763` | **Y** | No | **Y — must fail closed** |
| NEW-FE-016 | Super Admin and Admin bypass the permission matrix entirely | `cfg.role` | equals either | `return` before any gating | Global | `AppController.php:769` | **Y** | No | **Y** |
| NEW-FE-017 | Saved-screen restore is gated on DOM visibility, not on authority | `localStorage.sp_screen` | nav item present and `offsetParent !== null` | `go(saved)` else fall back to home | Global | `AppController.php:596–610` | Y | No | Y |
| NEW-FE-018 | Landing screen by role | `cfg.role` | Super Admin → `platform-dashboard`, else `dashboard` | `go(home)` | Global | `AppController.php:607` | **Y** | No | Y |
| NEW-FE-019 | Attendance Correction nav is offered to **every** role; the other roadmap screens only to Super Admin/Admin/HR Manager | `cfg.role` | `isAdmin` flag | `addNav(...)` | Global | `AppController.php:3162–3176` | **Y — role labels compared as strings** | No | Y |
| NEW-FE-020 | Plan gating is disabled | — | — | `applyPlanGate()` returns immediately | Global | `AppController.php:709–712` | **Y** | No | Y — the SaaS plan product promise is not enforced in the UI |
| NEW-FE-021 | Plan-gate safety net: abort gating if it would hide >70% of visible nav | nav counts | `toHide/visibleNow > 0.7` | return without hiding | Global | `AppController.php:737–738` (dead code) | Y | No | Y |
| NEW-FE-022 | Demo passkey session self-terminates client-side every 30 s | `cfg.demoExp` | `now > exp` | `location.replace(back + '?expired=1')` | Global | `AppController.php:459–465` | Y (30000 literal) | No | Y |
| NEW-FE-023 | Employee date parsing accepts a fixed set of formats | date string | format match | normalise or reject | Global | `AppController.php:1655–1666` (`empParseDate`) | Y | No | Y (dd/mm vs mm/dd is a real locale decision) |
| NEW-FE-024 | Directory Export is redirected from the prototype's 17-column client CSV to the server export | — | always | override `window.expCsv` | Global | `AppController.php:555–561` | Y | No | — (this one is a fix, not a defect) |
| NEW-FE-025 | Notification bell / approvals deep-link target is hard-coded to `approvals-inbox` | — | click | `go('approvals-inbox', …)` | Global | `AppController.php:11824, 11828` | Y | No | Y |
| NEW-FE-026 | Sidebar hidden-state remembered per browser | user toggle | — | `localStorage.sp_navhide` | Global | `AppController.php:887, 893` | Y | No | — |
| NEW-FE-027 | Screen memory: last visited screen stored per browser | `go(id)` | id truthy | `localStorage.setItem('sp_screen', id)` | Global | `AppController.php:639–647` | Y | No | — |
| NEW-FE-028 | Test pass mark and unlimited re-attempts | attempt marks | `pct >= passMark` | mark passed | Global | `TestController.php:215+` | Y (no attempt cap at all) | No | **Y** |

**Counts: 18 catalogue rules confirmed in source + 28 new rules recorded = 46 business
rules in this area.** Three catalogue rules (BR-BIL-043/044/045) are recorded as one row.

---

## 4. Calculations and formulas

Every formula below is transcribed exactly from source. **None of the constants below
should be read as legally correct values — they are the values the code currently uses
and every one requires statutory verification.**

### 4.1 Client-side statutory rate table — `spRates()` — `AppController.php:11160–11166`

```js
function spRates() {
  var d = { pf_wage_cap: 15000, pf_rate: 12, esi_threshold: 21000,
            esi_employee_rate: 0.75, esi_employer_rate: 3.25, pt_amount: 200,
            std_deduction: 75000, rebate_87a_limit: 1200000, cess_rate: 4,
            comm_tds_rate: 5, no_pan_tds_rate: 20, conveyance_enabled: 0,
            conveyance_rate: 0, lop_basis: 'working', payslip_show_ytd: 1,
            sandwich_rule: 0, bonus_pct: 8.33,
            tds_slabs: [{upto:400000,rate:0},{upto:800000,rate:5},{upto:1200000,rate:10},
                        {upto:1600000,rate:15},{upto:2000000,rate:20},
                        {upto:2400000,rate:25},{upto:0,rate:30}] };
  var r = window.__RATES || {}; var out = {};
  for (var k in d) { out[k] = (r[k] != null) ? r[k] : d[k]; }
  if (!out.tds_slabs || !out.tds_slabs.length) { out.tds_slabs = d.tds_slabs; }
  return out;
}
```

`window.__RATES` is populated from `AppDataController::bootstrap()` (`live.rates`,
`AppController.php:508`). **If that fetch fails — `catch (e) { /* fall back to demo data */ }`
at :512 — every statutory figure silently reverts to these literals and the user is not
told.** `pt_amount: 200` is a single flat figure; there is no state slab structure
client-side at all.

### 4.2 Income tax — `tdsTax(taxable)` — `AppController.php:11167–11177`

```js
function tdsTax(taxable) {
  var r = spRates();
  if (taxable <= Number(r.rebate_87a_limit)) { return 0; }
  var t = 0, prev = 0;
  r.tds_slabs.forEach(function (s) {
    var cap = Number(s.upto) > 0 ? Number(s.upto) : 1e15;
    var rate = Number(s.rate) / 100;
    if (taxable > prev) { t += (Math.min(taxable, cap) - prev) * rate; prev = cap; }
  });
  return Math.round(t * (1 + Number(r.cess_rate) / 100));
}
```

- **Basis:** annual taxable income after standard deduction.
- **Rounding:** single `Math.round` at the very end, after cess. No intra-slab rounding.
- **87A behaviour — DEFECT:** the rebate is implemented as a **cliff**, not a rebate.
  `taxable = 1,200,000` → `0`. `taxable = 1,200,001` → full slab tax on the whole amount.
  There is no marginal-relief handling. Worked example:
  - taxable 1,200,000 → returns **0**
  - taxable 1,200,001 → slabs: 0–400k @0 = 0; 400k–800k @5% = 20,000; 800k–1.2m @10% = 40,000; 1.2m–1,200,001 @15% = 0.15. Sum 60,000.15; × 1.04 cess = 62,400.16 → **62,400**.
  - **A one-rupee increase in income costs the employee ₹62,400.**
- The final slab `{upto:0, rate:30}` relies on `upto > 0 ? upto : 1e15`. Any slab row
  saved with `upto: 0` in the middle of the array silently becomes the terminal band.

### 4.3 PF and ESI — `statutoryScreen('pf-esic')` — `AppController.php:12080–12086`

```js
var gross = (Number(e.ctc) || 0) / 12, basic = gross * 0.5;
var pfW = Math.min(basic, Number(rr.pf_wage_cap)),
    pe  = Math.round(pfW * Number(rr.pf_rate) / 100),
    pr  = pe;
var elig = gross <= Number(rr.esi_threshold),
    ee = elig ? Math.round(gross * Number(rr.esi_employee_rate) / 100) : 0,
    er = elig ? Math.round(gross * Number(rr.esi_employer_rate) / 100) : 0;
```

- **Basis:** `gross = CTC / 12`. **`basic = gross × 0.5` is a hard-coded assumption**,
  unrelated to the employee's actual salary structure.
- **Divisor:** 12 (literal).
- **Rounding:** `Math.round` to the nearest rupee on each of the four figures.
- **Employer PF = employee PF (`pr = pe`).** The EPS/EPF split, admin charges and EDLI
  are absent entirely.
- **ESI eligibility is evaluated on `gross`, not on ESI wages**, and is a plain
  `<=` with no "continue to end of contribution period" rule.
- Worked example, CTC 600,000: gross 50,000; basic 25,000; pfW = min(25,000, 15,000)
  = 15,000; pe = pr = 1,800. ESI: 50,000 > 21,000 → not eligible → 0/0.
- Worked example, CTC 240,000: gross 20,000; basic 10,000; pfW = 10,000; pe = pr = 1,200.
  ESI eligible: ee = round(20,000 × 0.0075) = 150; er = round(20,000 × 0.0325) = 650.

### 4.4 TDS screen — `statutoryScreen('tds')` — `AppController.php:12098–12107`

```js
var commBase = st === 'Commission' ? annual
             : (st === 'Salary + Commission' ? annual * ((Number(e.commPct) || 30) / 100) : 0);
var salBase  = Math.max(0, annual - commBase);
var salTds   = tdsTax(Math.max(0, salBase - STDDED));
var effRate  = e.pan ? COMM_RATE : Math.max(COMM_RATE, NOPAN);
var commTds  = Math.round(commBase * effRate);
var tot = salTds + commTds, m = Math.round(tot / 12);
```

- `COMM_RATE` = `rates.comm_tds_rate / 100` (default 5% → 0.05); `NOPAN` = 20% → 0.20.
- **No-PAN handling:** `Math.max(COMM_RATE, NOPAN)` — so if a tenant configures a
  commission TDS rate above 20%, the no-PAN provision has no effect. Correct direction,
  but see DEFECT §6.9.
- **Default commission share 30%** when `e.commPct` is unset — a literal
  that silently determines how much of an employee's pay is treated as 194H commission.
- **Monthly TDS = annual ÷ 12**, regardless of month in the financial year. No
  remaining-months proration, no already-deducted carry-forward.
- Worked example: annual 1,500,000, type "Salary + Commission", `commPct` unset, PAN
  present. commBase = 1,500,000 × 0.30 = 450,000. salBase = 1,050,000.
  salTds = tdsTax(1,050,000 − 75,000 = 975,000) → 975,000 ≤ 1,200,000 → **0**.
  commTds = round(450,000 × 0.05) = 22,500. tot = 22,500; monthly = 1,875.

### 4.5 Commission request net — `rqCommCalc` — `AppController.php:4161–4168`

```js
var g  = Number((document.getElementById('rqf_gross_amount')||{}).value || 0);
var rEl = document.getElementById('rqf_tds_rate');
var rt = (rEl && rEl.value !== '') ? Number(rEl.value) : 5;
if (!(rt >= 0 && rt <= 100)) { rt = 5; }
var net = g > 0 ? Math.round((g - (g * rt / 100)) * 100) / 100 : 0;
```
Rounding: 2 decimal places (`round(x*100)/100`). Silent clamp to 5% on any invalid rate
(including `NaN` from a text paste) — the user is not told their rate was ignored.

### 4.6 Incentive scheme base — `schemeBaseCalc` — `AppController.php:4654–4663`

```js
if (gEl) { gEl.value = base > 0 ? Math.round(base * s.rateValue) / 100 : ''; }
rqCommCalc();
```
Basis: `rateValue` is a percentage; the `/100` is applied **after** rounding the product,
i.e. `round(base × rate) / 100`, not `round(base × rate / 100)`. For base 123,456 and
rate 2.5: `round(308,640) / 100 = 3,086.40`. Equivalent here, but the ordering means the
result is always quantised to whole paise of `base × rate` rather than to the rupee.

### 4.7 Increment cross-calculation — `rqIncCalc` — `AppController.php:5315–5327`

```js
if (fromPct) { if (o > 0 && p !== 0) { nEl.value = Math.round(o * (1 + p/100) * 100) / 100; } }
else         { if (o > 0 && n > 0)   { pEl.value = Math.round(((n - o) / o) * 10000) / 100; } }
```
New salary rounded to 2 dp; percentage rounded to 2 dp. `p !== 0` means a 0% increment
cannot be entered from the percentage side.

### 4.8 GST on bank-pack invoice — `bpInvCalc` — `AppController.php:7200–7211`

```js
var intra = sg.length >= 2 && bg.length >= 2 && sg.slice(0,2) === bg.slice(0,2);
var half  = Math.round(sum * 9) / 100;
var gst   = intra ? Math.round(half * 2 * 100) / 100 : Math.round(sum * 18) / 100;
```
- **Place-of-supply is inferred from the first two characters of the two GSTINs.**
  A missing or short GSTIN silently produces IGST.
- **9% and 18% are literals.** There is no rate master, no HSN/SAC, no exempt/nil rating,
  no reverse charge, no cess.
- CGST and SGST are each `round(sum × 9)/100`; the total intra-state GST is
  `round(half × 2 × 100)/100` — i.e. exactly `2 × half`, so any rounding difference
  between `2 × round(sum×9)/100` and `round(sum×18)/100` produces a **1-paisa divergence
  between the intra-state and inter-state paths on the same amount**.

### 4.9 Tests scoring — `TestController.php:215+`

```php
$pct = round($earned * 100 / $totalMarks, 2);
$passed = $pct >= $passMark;
```
`$totalMarks` is not guarded against 0 (division by zero when a test has no marked
questions). No attempt limit; no check that the test belongs to the caller's tenant
(`TestController.php:215`); no check that the test was assigned to the caller.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Item | How |
|---|---|
| Statutory rate values (PF cap/rate, ESI threshold & rates, PT amount, standard deduction, 87A limit, cess, commission TDS, no-PAN TDS, bonus %, conveyance, LOP basis, YTD flag, sandwich rule) | Settings → statutory; stored in `settings` and served as `live.rates`; the client overlays them over `spRates()` defaults (`AppController.php:508, 11160–11166`) |
| TDS slab bands | Only if `rates.tds_slabs` is supplied as an array of `{upto, rate}` — there is no UI in the SPA that edits it as a table |
| Role → module permission matrix | Roles & Permissions screen → persisted into `settings.app_state` (`DB.roles[].perms`) |
| Master data for 60 types (departments, designations, branches, banks, leave types, holidays, letters, templates, points rules, incentive schemes, …) | `GET/POST /app/master/{type}` |
| Company branding, logo, colours | Branding screen → `app.branding.save` |
| Email/SMTP settings | Company Emails screen |
| WhatsApp templates | `wa-templates` screens |
| Per-screen help text | **No** — it is a PHP array (`ScreenHelpController.php`); listed here only to be explicit that it is NOT configurable |
| Employee field labels/panels | **Partly** — `EmployeeFieldRules::formPanels()` is PHP, not data. Not customer-configurable. |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

Exhaustive for this area:

1. **The screen registry itself.** 146 screen ids, their titles, their types, their
   columns and their form fields are literals in `resources/prototype/app.html` (line 356)
   and `AppController.php:3185–3312`.
2. **Which screens exist for which role.** `ROLES` arrays in the prototype; `PERM_NAV`
   at `AppController.php:655–672`; `isAdmin` string comparison at `:3162`.
3. **The sidebar itself** — 16 groups, ~107 static items, in prototype HTML; a further
   ~11 items injected by ad-hoc DOM code at `AppController.php:972, 978, 986, 992, 1016,
   1032, 1046, 1063, 1492, 1508, 5289` and 10 more by `addNav`/`addNavLink` at `:3165–3176`.
   Adding one menu item requires editing PHP that manipulates the DOM by CSS selector.
4. **Nav ordering.** Determined by which anchor selector matches first in an `afterIds`
   array (`AppController.php:3130–3143`). Reordering is not expressible.
5. **The landing screen** (`:607`).
6. **The `basic = 50% of gross` assumption** (`:12081`).
7. **`gross = CTC / 12`** (`:12080`).
8. **Employer PF = employee PF**, no EPS/EDLI/admin split (`:12082`).
9. **Monthly TDS = annual / 12** (`:12107`).
10. **Default commission share 30%** (`:12098`).
11. **GST rates 9/9 and 18** and the state-code inference (`:7200–7211`).
12. **Commission TDS default 5% and its 0–100 clamp** (`:4161–4168`).
13. **The 87A cliff** with no marginal relief (`:11169`).
14. **PT as a single flat amount** with no state slabs client-side (`:11160`).
15. **Employee-form validation rules and mandatory fields** (`:1645–1721`).
16. **DRA/PCC mandatory-on-new-hire rule** (`:1917–1928`).
17. **Accepted date formats** (`empParseDate`, `:1655–1666`).
18. **Import header aliases** for employee, commission and attendance bulk uploads.
19. **Bulk-upload row caps.**
20. **Recruitment pipeline stages.**
21. **Leave balance display formula** (entitlement − taken − pending).
22. **Notification poll interval, 60 s** (`:3182`).
23. **Demo expiry poll interval, 30 s** (`:461`).
24. **Subscription banner thresholds and copy** (`:1503–1530`).
25. **Edition hidden-nav lists** — `Edition::hiddenNavIds()`, a PHP service.
26. **SaaS plan → module-group mapping** (`PLAN_GROUP_NAV`, `:694–706`) and the
   never-gated list `PLAN_ALWAYS` (`:708`).
27. **CSS design tokens, breakpoints and the whole visual theme** (`AppController.php:235–447`).
28. **The `MASTER_MAP` type list** (`:10756–10892`) and its server counterpart
   `MasterController::def()`.
29. **The `RQ_MAP` request-module list** (`:3902–3947`) — 9 modules, fixed.
30. **Per-screen help content** — 801 entries in `ScreenHelpController.php`.
31. **Test pass marks, attempt limits, question types** (`TestController.php`).
32. **Which fields the employee bootstrap exposes** (`AppDataController.php:321–426`).
33. **Which collections are considered "state" versus table-backed** — hard-wired in
   `boot()` at `AppController.php:492–509`.
34. **The `ROLE_MAP` from Spatie role slug to display label** (`AppController.php:76–80`)
   — adding a role requires a PHP edit in at least four places.
35. **Approvals deep-link target** (`:11824`).
36. **The 70% nav safety-net threshold** (`:738`, currently dead).
37. **Which top-bar buttons are removed** — a regex on Font Awesome class names
   (`AppController.php:626`).
38. **Which CDNs are loaded** — Leaflet, SheetJS, Razorpay, Font Awesome, Google Fonts.

---

## 6. Defects, conflicts and technical debt

### 6.1 DEFECT (High) — there are two front ends and one of them is dead
`resources/views/layouts/app.blade.php` (91 lines) is a full application layout with a
sidebar referencing `route('dashboard')`, `route('employees.index')`,
`route('departments.index')`, `route('attendance.index')`, `route('leave.index')`,
`route('salary-runs.index')`, `route('payslips.index')`, `route('loans.index')`,
`route('devices.index')`, `route('tenants.index')`. **None of these named routes exist in
`routes/web.php`.** Any request that renders this layout throws
`RouteNotFoundException`. 17 Blade views extend it. `public/css/smartprs.css` (353 lines)
exists only to style it. `resources/css/smartprs-theme.css` (36 lines) says "Import
alongside Tailwind" — Tailwind is not present in the tree and nothing imports the file.
**Business impact:** ~500 lines of dead code plus 17 dead views, and a maintenance trap —
anyone editing "the layout" will edit the wrong one. **Severity: High (debt).**

### 6.2 CRITICAL — schema DDL executed inside a GET request
`AppDataController::ensureEmployeeColumns()` :46–75 runs `Schema::table('employees', …)`
on **every** `GET /app/data`, i.e. on every page load of the SPA. **Impact:** an ALTER
TABLE on the largest table in the product on a read path; no migration history; the
production schema is whatever the last deploy's PHP happened to add. **Severity: Critical.**

### 6.3 CRITICAL — data fabrication inside a GET request
`AppDataController::ensurePayrollHistory()` :2340–2431 **INSERTs 12 months of
`payroll_runs` and their `payslips`** if none exist, during `GET /app/data`.
**Impact:** a brand-new tenant's payroll history is invented by a page load. These rows
are indistinguishable from real payroll. **Severity: Critical.**

### 6.4 CRITICAL — runtime table creation
`TestController::ensureTables()` :24–57 `Schema::create()`s `test_questions` and
`test_attempts` on request. `SettingsController` self-creates `statutory_settings`.
`MasterController::ensureCols()` adds columns at runtime. **Severity: Critical.**

### 6.5 HIGH — every boot step swallows its own exception
`AppController.php:530`:
```js
var safe = function (fn) { try { fn(); } catch (e) { /* keep booting */ } };
```
followed by **33 `safe(...)` calls** (`:531–590`). A failure in `wireEmployeeSave`,
`applyPerms`, `wireDemoLockdown` or `wireEditionLockdown` produces **no console error, no
telemetry, and no user-visible signal** — the application simply comes up without that
behaviour. `applyPerms()` at `:590` and `wireEditionLockdown()` at `:577` are both inside
`safe()`. **A silent exception in either disables access control for that session.**
**Severity: High.**

### 6.6 HIGH — decorator chaining on globals
The boot script monkey-patches prototype globals by wrapping and setting a sentinel:
`render.__permWrapped` (:786), `go.__memWrapped` (:640), `go.__helpWrapped`,
`renderCustom.__wrapped` / `.__rmWrapped` (:3126), `persist.__stateWrapped`,
`saveEmp.__wired`, `saveAdd.__wrapped`, `setCompany.__brandWrapped`. `reqApplyOpen` is
wrapped **four separate times**. Behaviour depends on the *order* the wrappers were
installed, and the sentinels mean a wrapper installed twice is a no-op while a wrapper
installed after a re-assignment is silently lost. **Severity: High (unmaintainable).**

### 6.7 CONFLICT — `letters-nda` has a client screen and no server type
`MASTER_MAP` (`AppController.php:10756–10892`) contains 61 keys including `letters-nda`.
`MasterController::def()` :30 enumerates 60 types and does **not** include it, but does
include an orphan `recruitment` with no `MASTER_MAP` entry. **Impact:** the NDA Letters
screen loads, renders a toolbar, and every save fails. **Severity: Medium.**

### 6.8 DEFECT — `idcardScreen()` references an undefined variable
`AppController.php:9043` uses `heads.length` on a branch where `heads` is never
declared → `ReferenceError` in the empty-data path, which (because of §6.5) is swallowed
and shows a blank screen. **Severity: Medium.**

### 6.9 DEFECT — 87A implemented as a cliff
See §4.2. A ₹1 income increase over the rebate limit costs ₹62,400 at current values.
**Severity: High (money).**

### 6.10 DEFECT — the CGST/SGST and IGST paths can differ by a paisa
See §4.8. **Severity: Low, but it is an invoice.**

### 6.11 DEFECT — plan gating is dead code
`applyPlanGate()` returns at `AppController.php:712` before doing anything; 29 lines
below it are unreachable. The comment says "TEMPORARILY DISABLED (rev 34e)". The SaaS
product sells plans by module group; the UI does not restrict by plan. **Severity: High
(commercial).**

### 6.12 DEFECT — no History API; Back exits the application
`go()` never calls `pushState`. **Verified: `pushState` appears 0 times and `history.` 0
times as an API call across `AppController.php` and `app.html`** (the two `history.`
matches in `AppController.php` are the English word inside a comment at :3470 and an error
message at :4931). The address bar keeps `/app` (or the initial
`/app/{screen}`) regardless of navigation. Back navigates away from the SPA; refresh
relies on `localStorage.sp_screen` (`:643`) to approximate where the user was. Deep
links, bookmarks and "open in new tab" do not work for 145 of the 146 screens.
**Severity: High (usability).**

### 6.13 DEBT — no caching, 1.2 MB per load
`:12527–12531` sets `no-store, no-cache, must-revalidate, max-age=0`. Every entry to
`/app` re-downloads and re-parses the full document. On a metered mobile connection this
is the whole product per session start.

### 6.14 DEBT — CDN dependencies with no SRI and no fallback
Leaflet 1.9.4 (unpkg), SheetJS/XLSX 0.18.5 (cdnjs), Razorpay `checkout.js`, Font Awesome
6.5.0 (prototype) / 6.5.1 (Blade layout — **two different versions**), Google Fonts.
None carry `integrity` attributes. An on-prem L1/L2/L3 install behind a firewall loses
maps, Excel export and payments with no degradation path.

### 6.15 DEBT — 58-branch dispatch
`renderCustom` after `wireCustomScreens()` is an `if/else if` chain of 58 branches
(`AppController.php:3185–3312` registers them; the chain is the wrapper installed at
`:3126`), plus a second independent 10-branch chain installed by `wireRoadmapScreens()`.
Adding a screen means editing both a registry entry and a dispatch chain in a heredoc.

### 6.16 CONFLICT — screen counts disagree everywhere
- Prototype `SCREENS` registry: **114** entries (71 list, 27 custom, 6 cards, 4 lettered,
  4 form, 1 empform, 1 settings).
- Boot JS registers **32 additional ids** not in the prototype registry → **146 total**.
- Prototype static sidebar: **107** real `data-id` items in 16 groups (110 matches minus
  3 template artefacts).
- **39 registered screens have no static nav entry**; 21 of those get an injected nav
  item, leaving ~18 reachable only by code (`go('id')` from another screen) or not at all.
- The `AppController` class docblock claims "113 screens".
- The reference documentation claims 134 distinct navigable ids.

### 6.17 DEFECT (High) — 26 screens have no permission mapping
`PERM_NAV` (`AppController.php:655–672`) maps 16 module keys to nav ids and covers 120 of
the 146 registered screens. The other 26 (listed under Q1) return `null` from
`navModule()`, so `applyPerms()` never hides them and `gateActions()` never gates their
buttons — **even for a tenant that has fully configured its permission matrix**. Among
them: `statutory-config`, `sys-updates`, `account-settings`, `coa`, `vouchers`,
`settlement`, `recovery-reports`, `col-dashboard`. **Severity: High.**

### 6.18 CONFLICT — `selfScope()`'s comment inverts its behaviour
`AppDataController.php:226–228` describes the bootstrap default as "the stricter
employee-only default"; it is in fact the *unscoped* branch for every non-privileged role
that is not literally `employee`. See §7.5. **Severity: High (the comment will mislead the
next maintainer into thinking Field Agents are scoped).**

### 6.19 DEBT — 19 incompatible escape helpers
19 separate escape functions are defined in the boot script, with four different escape
sets, at `:2159, 4938, 5170, 6097, 6777, 7117, 8158, 8737, 9156, 9284, 9323, 9564, 9603,
9778, 9882, 11420, 11430, 12299` (+1). **None escapes `'`.** Several escape only `"`
(`:4938, 6097, 9156, 9882`). One (`:11420`) is a CSV quoter, not an HTML escaper, but is
named `esc` like the others.

---

## 7. Security findings

### 7.1 CRITICAL — access control is presentational
There is **no role middleware on `/app`**. `routes/web.php` declares
`/app/{screen?}` last in the auth group with middleware
`['auth', LicenseGate, EnsureSubscriptionActive, DemoWriteGuard, EditionGuard]`.
Every access decision in the SPA is `element.style.display = 'none'`:
- prototype `roleAllows()` → nav visibility only;
- `applyPerms()` (`:764–783`) → `el.style.display = 'none'`;
- `gateActions()` (`:796+`) → hides Add/Edit/Delete buttons;
- `wireDemoLockdown()` (`:8805`) and `wireEditionLockdown()` (`:8823`) → `display='none'`;
- `applyPlanGate()` → dead.

The **only** place a gate influences a decision rather than a pixel is the saved-screen
restore at `:596–610`, and even that is a DOM-visibility test:
```js
var usable = el && el.offsetParent !== null && el.style.display !== 'none';
```

**Quantification:** of the 146 screens, **zero** are protected by server-side
authorisation at the SPA route, and even the cosmetic gate does not reach all of them —
`PERM_NAV` covers 120 of 146, leaving **26 screens with no permission mapping whatsoever**
(enumerated under Q1), including `statutory-config`, `sys-updates`, `coa`, `vouchers`,
`settlement` and `recovery-reports`. Whether the *data* behind a screen is protected depends
entirely on the individual API controller. Confirmed unprotected read path:
`MasterController::list()` :608–700 has **no role check at all** (only `helpdesk` and
`posh` are self-scoped) — so any authenticated employee can read all 60 master types.
`MasterController::save()` :703 does gate on `canManage()`.

**Exploitability:** trivial. Open DevTools, run `go('users')` or `go('roles')` or
`go('plans')`. Or `document.querySelectorAll('.nav-item').forEach(e=>e.style.display='')`.
Or simply request `/app/users` — the server serves it.
**Business impact:** an Employee-role login can reach Payroll, Statutory, Users, Roles,
Tenants and Plans screens, and whatever their APIs return. **Severity: Critical.**

### 7.2 CRITICAL — `POST /app/state/save` is unauthenticated beyond login
`AppStateController::save()` (full text in §2.1) has no role check, no size limit, no
schema validation. Any authenticated user — including an Employee — can replace the
tenant's entire `app_state` blob.

Specific escalation: `DB.roles[].perms` lives in this blob and is the input to
`currentPerms()` (`AppController.php:675–683`) and `spCan()`. A user can POST a state
containing a `roles` entry for their own role label with all permissions true, and the
UI unlocks on next load. Combined with §7.1 (no server-side enforcement), the UI *is* the
enforcement, so this is a privilege escalation.

Second impact: **destruction**. A single POST wipes leave types, holidays, letters,
templates, points rules, incentive schemes, KB content and ~60 other collections, with no
history and no undo.
**Severity: Critical.**

### 7.3 HIGH — unescaped server data into `innerHTML`

**Counts:**
- `AppController.php` boot JS: **142 `innerHTML =` assignments** (144 `innerHTML`
  references total).
- `resources/prototype/app.html`: **13 `innerHTML=` assignments**.
- **134 renderer functions** return HTML built by string concatenation
  (`return pghead(...)` count).
- **19 escape helpers**, all incomplete, none covering `'` (§6.19).
- The prototype contains **zero escape functions** — verified: no `function esc` exists in
  `resources/prototype/app.html`.

**The prototype's generic list renderer is the worst case.** `cell(row, col)` (offset
131,006 in `app.html`) returns the raw value; `renderList` concatenates it into a `<td>`;
`render()` assigns the result to `host.innerHTML`. Every `list`-type screen therefore
renders **server-supplied employee, master and request data as raw HTML**. Employee name,
address, remarks, designation, department, complaint text, helpdesk ticket body — all of
it.

**Where the data comes from:** `AppDataController::bootstrap()` and the master/request
APIs. Server-side sanitisation exists only as `stripHtmlDeep()`
(`AppDataController.php:83–93` (`stripHtmlDeep`, declared at :83)), and it is called in exactly **four** places:
`RecruitmentController.php:352`, `MasterController.php:723`, `AppDataController.php:666`,
`AppDataController.php:2740`. **It is not applied to `POST /app/state`**, not to leave
applications, not to helpdesk, not to expenses, not to the ESS self-update path
(`/app/ess/update`).

**Worked exploitation path (stored XSS, no admin needed):**
1. Employee updates their own name/address via `POST /app/ess/update` to
   `<img src=x onerror="fetch('/app/state/save',{method:'POST',...})">`.
2. Admin opens Employee Directory. `renderList` → `cell()` → `innerHTML`.
3. Script runs with the admin's session and CSRF token (`cfg.csrf` is in
   `window.__SMARTPRS`, readable from the same context).

**Severity: Critical.**

### 7.4 HIGH — the CSRF token is in a global JavaScript object
`window.__SMARTPRS.csrf` (`AppController.php:104`). Any XSS (§7.3) has the token
immediately. This is a normal SPA pattern, but it converts every XSS into a full
state-changing compromise rather than a read-only one.

### 7.5 HIGH — bootstrap over-sharing
`AppDataController::bootstrap()` :247–588 returns, for every employee in scope
(`:321–426`): `ctc`, `pan`, `uan`, `bankName`, `bankAcc`, `ifsc`, `dob`, `addr`,
`permanentAddress`, `emergencyPhone`, `esicNo`, `nationalId`, `homeLat`, `homeLng`,
`deviceUserId`.

Scoping is `AppDataController::selfScope()` :221–245:
```php
$user = $request->user();
if (! $user || $user->hasAnyRole(['super_admin', 'admin', 'hr_manager'])) {
    return null;
}
// rev190 — attendance callers pass $allNonPrivileged=true so a Field Agent
// (not just the 'employee' role) is also scoped to their own punches; the
// Directory/bootstrap callers keep the stricter employee-only default.
if (! $allNonPrivileged && ! $user->hasRole('employee')) {
    return null;
}
```
`return null` means **no scope, i.e. everything**. So:
- super_admin / admin / hr_manager → all employees, all fields. Expected.
- **`field_agent` → also unscoped**, because the second condition only narrows for the
  `employee` role and returns `null` (= unrestricted) for every other non-privileged role
  when `$allNonPrivileged` is false — which is exactly the bootstrap/Directory case.

**INTENDED vs ACTUAL.** The comment at :226–228 calls the bootstrap default "the stricter
employee-only default". It is the *opposite*: with `$allNonPrivileged = false` a Field
Agent falls straight through to `return null` and receives **everything**, whereas the
attendance callers that pass `true` are the ones that actually scope them. The author's
stated intent and the code's behaviour are inverted.

**Business impact:** a Field Agent login receives every employee's PAN, UAN, bank account
number, IFSC, date of birth, home address, home GPS coordinates and CTC in a single
JSON response on page load. **Severity: Critical (PII / financial data).**

### 7.6 MEDIUM — unknown role becomes Admin
`AppController.php:93`:
```php
$role = self::ROLE_MAP[$user->getRoleNames()->first() ?? 'admin'] ?? 'Admin';
```
Two fail-open defaults on one line: a user with **no** Spatie role gets the `'admin'` key
lookup, and a user with an **unrecognised** role falls through `?? 'Admin'`. Since
`applyPerms()` returns immediately for `'Admin'` (`:769`), such a user is completely
ungated in the UI. **Severity: Medium** (needs a mis-provisioned account) **but the
consequence is total**.

### 7.7 MEDIUM — `spCan()` fails open
`AppController.php:758–763`: no matrix → `return true`. A tenant that has never opened the
Roles screen has no matrix, so every role is unrestricted.

### 7.8 MEDIUM — the Roles screen tells the truth
`rolesScreen()` (`AppController.php:3328–3372`) renders a footer reading:

> "This defines each role's permissions. Enforcement on real logins is the next phase."

The product ships a permissions UI that its own footer says is not enforced. Recording it
as a security finding rather than a documentation issue because customers are configuring
access control that does not exist.

### 7.9 MEDIUM — client-side demo expiry
`AppController.php:459–465` polls `cfg.demoExp` every 30 s and redirects. The comment says
the server also enforces it; the client check is trivially removable and is the only thing
stopping an idle tab. Low impact given the server check, recorded for completeness.

### 7.10 MEDIUM — CDN supply chain
§6.14. Five third-party origins, no SRI, all executing in the authenticated application
context with access to `window.__SMARTPRS` (CSRF token, user email, all endpoint URLs).

### 7.11 LOW — `addNav` builds an onclick from an id
`AppController.php:3141`: `d.setAttribute('onclick', "go('" + id + "',this)")`. Ids are
literals today, so not currently exploitable, but the pattern is a code-injection sink if
ids ever become data-driven — which is exactly what SmartPRS 3 intends.

---

## 8. Documentation-vs-source discrepancies

| # | Doc claim | Source reality | Evidence |
|---|---|---|---|
| 8.1 | Section 15 asserts `resources/prototype/app.html` **does not exist** and treats the prototype as unavailable | It exists: **986 lines, 234,234 bytes** | `prs2/resources/prototype/app.html`; `AppController.php:88–91` loads it and `abort_unless(is_file($path), 404)` |
| 8.2 | Section 15 cites `resources/js/app.js`, `resources/js/bootstrap.js`, `vite.config.js`, `package.json` as evidence | **None of these files exist in `/home/claude/prs2`** | filesystem |
| 8.3 | Section 15 lists sidebar labels, the prototype role filter, the base `SCREENS` registry and the base CSS as "NOT DETERMINED FROM SOURCE" | All four are fully determinable and are documented here (§1.2, §3.1 BR-AUTH-702, §6.16, §1.6) | `app.html` line 356; `ROLES`/`roleAllows` in `app.html` |
| 8.4 | Doc counts **134** distinct navigable screen ids | **146** registered ids; **107** static nav items | §6.16 |
| 8.5 | `AppController` class docblock says "113 screens" | 114 in the prototype registry alone, 146 after boot | §6.16 |
| 8.6 | Catalogue records 21 client-only rules | 18 of the 21 confirmed as stated; **28 further client-only rules found** (§3.2) | §3 |
| 8.7 | Catalogue does not record the client-side GST split, the 30% default commission share, the `basic = 50% of gross` assumption, the `CTC/12` gross, or the 87A cliff | All present and all money-affecting | §4 |
| 8.8 | Docs describe plan-based module gating as a working SaaS feature | `applyPlanGate()` returns at line 712 — dead | §6.11 |
| 8.9 | Docs do not flag `selfScope()` leaving `field_agent` unscoped | Confirmed | §7.5 |
| 8.10 | Docs do not identify the dead Blade front end | 17 views + a layout referencing 10 non-existent named routes | §6.1 |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| SPA shell (single 1.2 MB PHP heredoc) | **REPLACE** | Unmaintainable by construction: no build, no modules, no tests, escaping hazards baked in by the heredoc | None — it is code |
| Screen registry (`SCREENS`) | **REDESIGN** | The idea is right (metadata-driven screens) and is the seed of SmartPRS 3's configuration-driven UI; the implementation is a JS literal that is then overridden for 90 of 114 screens | Screen definitions become configuration rows; the 114 entries are a migration input, not a migration output |
| `renderCustom` 58-branch chain + roadmap 10-branch chain | **REMOVE** | Each branch is a bespoke screen that exists because the declarative model could not express it. Every one is a requirement for the SmartPRS 3 screen engine | None |
| Navigation / sidebar | **REPLACE** | Static HTML + 21 DOM-injection sites | Menu structure becomes configuration |
| Client-side statutory calculations (`spRates`, `tdsTax`, PF/ESI/TDS screens) | **REMOVE** | Must not exist on the client. Server is the only acceptable home for statutory computation | None — but every value in §4.1 must be re-derived and verified against statute before being seeded into the SmartPRS 3 rate tables |
| Client-side money calculations (commission, increment, GST, scheme) | **REMOVE** | Same | None |
| `settings.app_state` blob | **REPLACE** | ~60 collections in one untyped JSON column with last-write-wins and no authorisation | **HIGH EFFORT.** Each collection inside the blob must be reverse-engineered into a real table. This is the single largest data-migration task in the front-end area. Per-tenant blobs must be parsed, typed and split. |
| Client permission model (`PERM_NAV`, `spCan`, `gateActions`) | **REPLACE** | Presentational only; fails open in three places | `DB.roles[].perms` inside `app_state` is the only existing permission data and must be extracted, validated and re-expressed against a server-enforced model |
| Bootstrap payload (`window.__SMARTPRS`) | **REDESIGN** | Concept retained; content must be minimised (§7.5) and endpoint URLs must not be enumerated to the client | None |
| `GET /app/data` bootstrap | **REDESIGN** | Must not do DDL or INSERTs; must be paginated and scoped | Remove `ensureEmployeeColumns` and `ensurePayrollHistory`; any fabricated payroll rows already in customer databases must be identified and quarantined |
| Per-screen help (`ScreenHelpController`) | **MIGRATE** | 801 curated entries are genuine content and worth keeping | Move the PHP array into a content table keyed by screen id |
| Blade front end (`layouts/app.blade.php` + 17 views + `public/css/smartprs.css`) | **REMOVE** | Dead; references 10 non-existent routes | None |
| `resources/css/smartprs-theme.css` | **RETAIN** (tokens only) | 30 lines of design tokens are the only durable artefact | None |
| CSS heredoc (`AppController.php:235–447`) | **MIGRATE** | The responsive rules (§ below) encode real mobile requirements | Extract to a stylesheet |
| CDN dependencies | **REDESIGN** | Must be self-hosted for on-prem editions | None |
| Client state persistence (`localStorage` screen/nav memory) | **RETAIN** | Correct use of the platform; harmless | None |

---

## 10. SmartPRS 3 requirements implied

**Screen and navigation engine**
1. Screens must be defined as configuration (id, title, data source, columns, form
   fields, actions, permissions), not code — SmartPRS 2 has 146 screen ids as literals
   across two files.
2. The engine must support at least the seven presentation types SmartPRS 2 needed
   (`list`, `cards`, `lettered`, `form`, `empform`, `settings`, `custom`) **and** must
   make the 58 escapes to `custom` unnecessary — each of those 58 is a capability the
   declarative model lacked. Enumerate and specify them before designing the engine.
3. Menu structure, grouping, ordering, icons and per-role visibility must be
   configuration. SmartPRS 2 injects 21 menu items by DOM manipulation at runtime.
4. Deep-linkable URLs per screen with History API support. 145 of 146 SmartPRS 2 screens
   are not addressable.
5. Screen-level and field-level help text must be configurable content, not a PHP array.

**Authorisation**
6. Every screen and every API must be authorised **on the server**. No behaviour may
   depend on `display:none`. A screen with no permission mapping must be **unreachable**,
   not unrestricted — SmartPRS 2 leaves 26 of 146 screens (including statutory
   configuration and licence management) outside `PERM_NAV` entirely, and the engine must
   make an unmapped screen a startup error rather than an open door.
7. Authorisation must **fail closed**: unknown role → no access (SmartPRS 2 → Admin);
   no permission matrix → no access (SmartPRS 2 → allow); exception during permission
   setup → no access (SmartPRS 2 → full access, because of `safe()`).
8. Permission model must be role × module × action, and must be persisted in a typed,
   authorised store — not in a user-writable JSON blob.
9. Plan/edition/licence gating must be enforced server-side and must be verifiable; the
   SmartPRS 2 UI gate is dead code.
10. Field-level visibility must be part of the permission model — SmartPRS 2 sends PAN,
    bank account and GPS coordinates to every non-`employee` role.

**Rules and formula engines**
11. The formula engine must express **basis selection**: "basic" must be selectable as a
    contractual amount, a percentage of gross, a percentage of CTC, or a slab — SmartPRS 2
    hard-codes `basic = gross × 0.5`.
12. The formula engine must express **annualisation and de-annualisation divisors** as
    configuration: SmartPRS 2 hard-codes `gross = CTC/12` and `monthlyTDS = annual/12`.
13. The rule engine must support **threshold rules with marginal relief**, not cliffs —
    SmartPRS 2's 87A rebate costs an employee ₹62,400 for one extra rupee of income.
14. The rule engine must support **slab/band tables as first-class configuration** with
    open-ended terminal bands, effective-dating and per-state variants — SmartPRS 2 has a
    7-band array with a magic `upto: 0` terminator and PT as a single flat number.
15. Statutory contribution rules must model **employer and employee legs separately**,
    including sub-components (EPS/EPF/EDLI/admin) — SmartPRS 2 sets `employerPF = employeePF`.
16. Eligibility rules must support **"once eligible, remains eligible until the end of the
    contribution period"** semantics — SmartPRS 2 re-evaluates ESI eligibility as a plain
    `<=` on every render.
17. Tax-rate rules must support **conditional rate escalation on missing data** (no-PAN
    higher rate) as a rule, not a `Math.max` in a renderer.
18. The engine must support **effective-dated rate sets** so a mid-year statutory change
    does not require a code deploy; SmartPRS 2 has one current value per key.
19. Indirect-tax rules (GST) must model place-of-supply, rate by HSN/SAC, exempt/nil,
    reverse charge and cess — SmartPRS 2 infers place of supply from two characters of a
    GSTIN and hard-codes 9/9 and 18.
20. Commission/incentive schemes must support flat amount, percentage, slab and tiered
    structures — SmartPRS 2 supports only `base × rate / 100`.
21. Increment rules must support fixed-amount as well as percentage increments, and a 0%
    increment must be expressible — SmartPRS 2's `p !== 0` guard forbids it.
22. Every calculation must declare its **rounding rule and rounding point** as
    configuration. SmartPRS 2 mixes whole-rupee `Math.round`, 2-dp `round(x*100)/100` and
    a `round(base*rate)/100` ordering that quantises differently again.
23. All calculation must be server-side and auditable — a stored, replayable calculation
    trace per payslip. SmartPRS 2's figures are computed in the browser and are not
    reproducible.

**Validation and data entry**
24. Mandatory fields, formats, and conditional mandatory rules ("DRA and PCC required on
    new hire only") must be configuration — SmartPRS 2 hard-codes them at
    `AppController.php:1645–1721` and `:1917–1928`.
25. Accepted date formats and locale must be tenant configuration.
26. Import definitions — accepted headers, aliases, row caps, and the mapping heuristics —
    must be configuration per import type. SmartPRS 2 has three separate hand-written
    header-matching heuristics.
27. Validation must be defined **once** and enforced on both client and server from the
    same definition. SmartPRS 2 maintains `empFieldError` (JS) and
    `EmployeeFieldRules::formatErrors` (PHP) separately.

**Workflow**
28. Approval workflows and their stage lists must be configuration — SmartPRS 2's
    recruitment pipeline stages and 9 `RQ_MAP` request modules are literals.
29. Notification poll/push cadence must be configuration, not a literal `60000`.

**Platform**
30. Output escaping must be **centralised and contextual** (HTML text, attribute, URL,
    JS, CSV). SmartPRS 2 has 19 partial escapers, none of which escapes `'`, and a
    generic list renderer with none at all.
31. No schema DDL, no data creation, and no side effects on any GET path.
32. Client state must be typed and per-collection, with optimistic concurrency. SmartPRS 2
    persists ~60 collections as one blob with last-write-wins.
33. Assets must be self-hostable for on-prem editions; no unpinned, unverified CDN
    execution in the authenticated context.
34. Responses must be cacheable; the shell must not be re-downloaded per navigation.

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **DECISION REQUIRED — statutory values.** Every constant in §4.1 (PF cap 15000, PF
   rate 12, ESI threshold 21000 / 0.75 / 3.25, PT 200, standard deduction 75000, 87A limit
   1,200,000, cess 4, commission TDS 5, no-PAN 20, bonus 8.33, and the seven TDS bands)
   is *the value the code currently uses*. None has been verified against statute in this
   analysis and none should be carried into SmartPRS 3 without independent verification
   and an effective-date.

2. **DECISION REQUIRED — the `basic = 50% of gross` assumption.** Is this a deliberate
   product rule, a placeholder, or a bug? It determines PF for every employee. If any
   customer has been running payroll on it, the exposure is retrospective.

3. **DECISION REQUIRED — `gross = CTC / 12`.** CTC conventionally includes employer PF and
   gratuity. Dividing CTC by 12 and then computing employer PF on 50% of that figure
   double-counts. Confirm the intended CTC definition.

4. **DECISION REQUIRED — the 87A cliff.** Is marginal relief expected? (§4.2, §6.9)

5. **DECISION REQUIRED — fabricated payroll history.** `ensurePayrollHistory()` has been
   inserting 12 months of payroll runs and payslips into tenant databases on page load
   (§6.3). Which existing tenants have fabricated rows, and what is the remediation?

6. **DECISION REQUIRED — `app_state` migration.** ~60 collections must be reverse-
   engineered from per-tenant JSON blobs into typed tables. This needs a per-tenant
   inventory before scoping. Some tenants' blobs may already be corrupted by last-write-
   wins conflicts, with no way to detect it.

7. **DECISION REQUIRED — the permissions the customers configured.** Customers have been
   configuring roles in a UI whose own footer says enforcement "is the next phase"
   (§7.8). When SmartPRS 3 enforces those matrices, users who work today will stop
   working. Is the saved matrix the intent to honour, or does every tenant need to
   re-do access control?

8. **DECISION REQUIRED — `field_agent` data exposure.** Field Agents currently receive all
   employees' PAN, bank details, DOB, home address and GPS (§7.5). Is this a defect or an
   operational necessity for the field-collections product? It changes the SmartPRS 3
   field-level permission requirement materially.

9. **OPEN — the 58 `custom` screens.** Each is a screen the declarative registry could not
   express. Someone must enumerate what each one does that a `list`/`form` could not, or
   SmartPRS 3's screen engine will be designed with the same gap.

10. **OPEN — is a fully offline (no-CDN) on-prem install a supported configuration?**
    L1/L2/L3 editions currently lose maps, Excel export and payments without internet.

11. **OPEN — `letters-nda`.** Should the NDA letter type exist (add to
    `MasterController::def()`) or be removed from `MASTER_MAP`? (§6.7)

12. **OPEN — the orphan `recruitment` master type** in `MasterController::def()` with no
    client counterpart.

13. **OPEN — mobile.** See §12 below. Is a phone a supported primary device for HR/payroll
    admin work, or only for employee self-service and punch? SmartPRS 2 answers this
    ambiguously and the answer drives a large share of SmartPRS 3's UI budget.

---

## 12. Direct answers to the nine assignment questions

### Q1 — Complete screen registry

**Registry composition:** 114 screens declared in `resources/prototype/app.html` (line
356), plus 32 registered only by the boot script = **146 screen ids**.

Full breakdown by declared type (prototype registry):

| Type | Count | Renderer | Data source |
|---|---|---|---|
| `list` | 71 | `renderList` (prototype) | `DB[screen.coll]`, filtered by `screen.companyKey` against the `COMPANY` global |
| `custom` | 27 | `renderCustom` dispatch | per-screen function |
| `cards` | 6 | `renderCards` | `screen.cards` + `screen.stats` |
| `lettered` | 4 | prototype lettered renderer | `DB.letters`, filtered by `screen.ltype` |
| `form` | 4 | `renderForm` | `screen.fields` |
| `empform` | 1 | employee form renderer | `cfg.empFields` (server registry) |
| `settings` | 1 | settings renderer | `GET /app/settings` |

Registry keys actually used across the 114 entries: `title` (114), `type` (114),
`add` (79), `coll` (75), `cols` (75), `fields` (73), `companyKey` (29), `cards` (6),
`stats` (6), `ltype` (4), `groups` (4), `filterField` (2), `filterOpts` (2), `view` (1),
`msg` (1), `addGoto` (1), `help` (1).

**After boot, 90 of the 114 are overwritten to `{title, type:'custom'}`** — losing
`coll`, `cols`, `fields`, `add` and `companyKey` — at `AppController.php:3188–3253` and
`:2142`.

**The 32 ids that exist only because the boot script registers them:**
`absence-config`, `account-settings`, `att-correction`, `audit-reports`, `bank-pack`,
`biometric-setup`, `bonus`, `calc-logic`, `commission-calc`, `demo-requests`, `dra-certs`,
`emp-archived`, `emp-import`, `fin-year`, `greetings`, `greetings-log`, `letters-nda`,
`min-wages`, `mobile-devices`, `my-subscription`, `notice-board`, `overtime`, `pay-ledger`,
`policies`, `posh`, `probation`, `probation-config`, `shifts`, `statutory-config`,
`sys-updates`, `transfers`, `wa-templates`.

**Permission gate per screen** is not a per-screen property. It is derived from
`PERM_NAV` (`AppController.php:655–672`), which maps **16 module keys** to nav id lists:

| Module key | Nav ids gated |
|---|---|
| `dashboard` | dashboard, platform-dashboard, how-it-works, notifications |
| `employees` | emp-list, emp-archived, emp-add, idcard, teams, onboarding-board, recruitment, bgv, documents, roster, offroll-agents, transfers |
| `attendance` | att-daily, att-report, att-manual, att-zkteco, biometric-devices, biometric-setup, geofence, geofence-list, shifts, late-policy, overtime |
| `leave` | leave-apply, leave-types, holidays |
| `payroll` | pay-cycle, salary-schedules, salary-setup, salary-gen, salary-approval, payslip, deductions, payout-recon, live-salary, pay-ledger, calc-logic |
| `commissions` | commissions, incentive-schemes, clawbacks, bonus-enc, bank-pack |
| `expenses` | expenses, advance |
| `loans` | loans |
| `statutory` | pf-esic, tds, pt, gratuity, bonus, tds-returns, min-wages, audit-reports |
| `performance` | performance, increments, exits, awards, points-scores, points-ledger, points-rules, tests, test-results, test-reports, attrition |
| `field` | escalations, agent-auth, dra-certs, complaints, compliance-alerts |
| `kb` | kb, faqs, training-programs, training-records, training-content, code-of-conduct, policies, helpdesk, posh, letters-offer, letters-increment, letters-warning, letters-relieving, letters-nda, letters-templates, notice, messages, send-message |
| `reports` | reports, activity-logs |
| `assets` | assets |
| `settings` | companies, departments, designations, branches, banks, users, roles, settings, fin-year, my-subscription, branding, company-emails, wa-settings, wa-templates, sms-settings, sms-templates, approvals-inbox, mobile-devices |
| `saas` | tenants, plans, subscriptions, invoices, payments, gateways, feature-flags, demo-requests |

**Screens not covered by any `PERM_NAV` module → ungated even when a matrix exists.**
`navModule()` returns `null`, so `applyPerms()` skips them (`:775–776`) and
`gateActions()` returns early (`:799–800`). Computed exactly: of the **146** registered
ids, `PERM_NAV` covers **120** and leaves **26** ungated:

`absence-config`, `acc-reports`, `account-settings`, `ai-assistant`, `att-correction`,
`cases`, `coa`, `col-dashboard`, `commission-calc`, `emp-import`, `field-agents`,
`greetings`, `greetings-log`, `notice-board`, `portfolio`, `probation`,
`probation-config`, `procReq`, `projects`, `recovery-reports`, `settlement`,
`statutory-config`, `sys-updates`, `tasks`, `vendors`, `vouchers`.

Note that this list includes **`statutory-config`** (edits statutory rates),
**`account-settings`**, **`sys-updates`** (licence and updates), **`coa`** /
**`vouchers`** / **`settlement`** / **`recovery-reports`** (financial), and
**`col-dashboard`** — i.e. the permission matrix has no opinion at all about the
collections-and-recovery module or about statutory configuration.

The SBB items `api-keys` and `pending-punches` are `addNavLink` anchors to standalone
Blade pages (`AppController.php:3169–3170`), not SPA screens, and are therefore outside
this model entirely.

**Live / dead:**

| Category | Count | Notes |
|---|---|---|
| Registered and reachable from the static sidebar | 107 | prototype `data-id` items |
| Registered, nav injected at runtime | 21 | 11 ad-hoc DOM sites + 10 `addNav`/`addNavLink` |
| Registered, no nav entry at all | ~18 | reachable only via `go('id')` from another screen, or unreachable |
| Confirmed broken | 2 | `letters-nda` (no server type, §6.7); `idcard` empty branch (`ReferenceError`, §6.8) |
| Dead code paths | 1 whole function | `applyPlanGate()` body, `:713–741` |
| Entire dead front end | 17 Blade views + layout | §6.1 |

### Q2 — Business rules implemented only in browser JavaScript

Recorded in full in §3 (46 rules) and §4 (formulas). The money/statutory subset, which is
the critical answer:

| # | Rule | Server equivalent? |
|---|---|---|
| 1 | Income-tax slab computation + 87A + cess (`tdsTax`, `:11167`) | Server has `SettingsController::defaults()` values but the **slab walk with the 87A cliff is client-only in this screen** |
| 2 | PF wage = min(50% of CTC/12, cap) (`:12080`) | **No client-server parity** — this exact derivation is client-only |
| 3 | ESI eligibility on monthly gross (`:12083`) | Client-only in this screen |
| 4 | Employer PF = employee PF (`:12082`) | Client-only |
| 5 | Commission share defaults to 30% (`:12098`) | **Client-only literal** |
| 6 | No-PAN TDS via `Math.max` (`:12106`) | Client-only in this screen |
| 7 | Monthly TDS = annual / 12 (`:12107`) | Client-only |
| 8 | Commission request net-of-TDS with 5% fallback (`:4161`) | Client-only |
| 9 | Incentive scheme base × rate / 100 (`:4654`) | Client-only |
| 10 | Increment ↔ percentage cross-calc (`:5315`) | Client-only |
| 11 | GST CGST/SGST vs IGST split (`:7200`) | **Client-only** — the invoice total the customer sees is computed in the browser |
| 12 | Statutory bonus 8.33% default (`:11164`) | Client fallback |
| 13 | Leave balance minus pending | Client-only presentation |
| 14 | Ledger running balance | Client-only |
| 15 | The whole `spRates()` default table (17 values + 7 bands) | **Duplicated** — the server has its own defaults in `SettingsController::defaults()` |

**Divergence — CRITICAL FINDING:** items 2–7 and 11 have **no server-side counterpart at
all**, so there is nothing to diverge *from* — the browser is the system of record for
those figures. Item 15 is a genuine duplication: two independent default tables, one in
`SettingsController::defaults()` (PHP) and one in `spRates()` (JS, `:11160–11166`). If a
value is changed in one and not the other, the PF/ESI/TDS screens will show one number
and the payroll run will produce another, with no error and no reconciliation.

### Q3 — Rendering model, heredoc usage, escaping hazards

**Rendering model.** Every screen renderer returns an **HTML string**. `render()` in the
prototype does:
```js
const host = document.getElementById('host');
const s = SCREENS[CURRENT];
… host.innerHTML = renderCustom(CURRENT); …
```
There is no virtual DOM, no templating engine, no `createElement` for content, no data
binding. State changes are effected by re-running the whole renderer and replacing
`innerHTML`. Event handling is inline `onclick="…"` attributes constructed as strings.

**Heredoc usage.** Two heredocs (`<<<CSS` at :235, `<<<HTML` at :452), both interpolating,
together 12,273 lines. Constraints imposed: no `$` in the JS body, no `${}`, doubled
backslashes. Interpolation surface: exactly one — `{$cfg}` at :453.

**Is server data ever injected into `innerHTML` unescaped? — YES. Count and list:**

| Site | Count | Escaping |
|---|---|---|
| `innerHTML =` in `AppController.php` boot JS | **142** | mixed; see below |
| `innerHTML=` in `resources/prototype/app.html` | **13** | **none — the prototype has zero escape functions** |
| Renderer functions returning concatenated HTML (`return pghead(`) | **134** | mixed |
| Escape helper definitions | **19** | 4 different, all incomplete escape sets |
| Escape helpers that handle `'` | **0** | — |
| Escape helpers that handle only `"` | **4** (`:4938, 6097, 9156, 9882`) | attribute-only, unsafe for text |
| Escape helpers that handle `& < >` but not `"` | **4** (`:5170, 8158, 8737, 9323, 9778`) | text-only, unsafe in attributes |
| Server-side sanitisation (`stripHtmlDeep`) call sites | **4** (`RecruitmentController.php:352`, `MasterController.php:723`, `AppDataController.php:666, 2740`) | does not cover `POST /app/state`, ESS self-update, leave, helpdesk, expenses |

The **generic list path is entirely unescaped**: `cell(row, col)` → `renderList` →
`host.innerHTML`. That path renders all 71 `list`-type screens (as declared) and is the
fallback for anything not overridden. Employee names, addresses, remarks, master values,
helpdesk and complaint text all flow through it. §7.3 gives the exploitation path.

### Q4 — Permission enforcement, UI vs server

**Quantified: 100% of the SPA's access control is UI-only.**

- Screens protected by server-side authorisation at the `/app/{screen}` route: **0 of 146**.
  `routes/web.php` applies `auth`, `LicenseGate`, `EnsureSubscriptionActive`,
  `DemoWriteGuard`, `EditionGuard` — **no role middleware**.
- Mechanisms that produce a `display:none` and nothing else: **5**
  (`roleAllows`, `applyPerms`, `gateActions`, `wireDemoLockdown`, `wireEditionLockdown`).
- Mechanisms that influence a real decision: **1** — the saved-screen restore at `:596–610`,
  and it decides by reading `offsetParent`/`style.display` off the DOM.
- Fail-open defaults on the access-control path: **4** — unknown role → Admin (`:93`);
  no matrix → allow (`:760`); Super Admin/Admin bypass (`:769`); a thrown exception inside
  `safe(function(){ applyPerms(cfg); })` (`:590`) leaves the session ungated.
- Dead gates: **1** — `applyPlanGate()` (`:712`).

Server-side authorisation exists **per API controller and is inconsistent**:
`MasterController::save()` :703 checks `canManage()`; `MasterController::list()` :608–700
checks **nothing** for 58 of 60 types; `SettingsController::save()` does
`abort_unless(hasAnyRole(['super_admin','admin']), 403)`; `SettingsController::index()` is
an open read; `AppStateController::save()` checks nothing; `TestController::take()` :215
does not even check tenancy.

### Q5 — Bootstrap payload: every field, and over-sharing

`window.__SMARTPRS`, built at `AppController.php:101–229`, encoded with
`JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP`.

| Group | Fields | Over-sharing assessment |
|---|---|---|
| Identity | `role`, `user`, `userEmail`, `company`, `portalCompany` | `userEmail` is unnecessary for rendering; it is used only for display |
| Security token | `csrf` | Standard, but see §7.4 |
| Entitlement | `planModules`, `edition`, `editionLevel`, `editionHide`, `isDemo`, `demoTeam`, `demoExp`, `demoBack`, `subState`, `subEnd`, `subDays`, `appVersion` | **`editionHide` publishes exactly which features the customer has not bought, by nav id.** `planModules` likewise. Both are gating hints that the client does not enforce (§6.11), so they leak commercial configuration for no functional benefit |
| Branding | `appLogo` | fine |
| Form metadata | `empFields` (= `EmployeeFieldRules::formPanels()`) | Legitimate, and the closest thing in SmartPRS 2 to configuration-driven UI |
| Navigation state | `screen`, `tour` | fine |
| **Endpoint catalogue** | **~70 URL keys** — `logoutUrl`, `dataUrl`, `kbUrl`, `attReportUrl`, `attLogsUrl`, `attRatingUrl`, `attPdfUrl`, `settingsUrl`, `settingsSaveUrl`, `kbReorderUrl`, `punchUrl`, `punchStatusUrl`, `mailUrl`, `mailSaveUrl`, `mailTestUrl`, `brandingUrl`, `brandingSaveUrl`, `brandingLogoUrl`, `appLogoUrl`, `empImportUrl`, `empTemplateUrl`, `empExportUrl`, `stateUrl`, `stateSaveUrl`, `empStoreUrl`, `leavesUrl`, `leaveApplyUrl`, `leaveBalancesBase`, `leaveDecideUrl`, `approvalsUrl`, `requestsBase`, `schemesBase`, `mobileDevBase`, `masterBase`, `cocUrl`, `bioUrl`, `apiKeysUrl`, `pendingUrl`, `docBase`, `incentiveBase`, `finYearBase`, `mySubBase`, `transfersBase`, `incrementsBase`, `liveSalaryUrl`, `liveSalaryAllUrl`, `attBulkBase`, `empBulkDeleteUrl`, `salaryRunsUrl`, `salaryRunsBase`, `payslipBase`, `salaryLinesBase`, `complianceUrl`, `complianceRunUrl`, `payrollPreviewUrl`, `salarySimUrl`, `bankPackBase`, `calcGuideUrl`, `payrollGenUrl`, `reportsPreviewUrl`, `reportsExportUrl`, `statutoryReportBase`, `lettersBase`, `computedBase`, `idcardBase`, `sendMessageUrl`, `pointsAutoApplyUrl`, `testsBase`, `onboardingBase`, `performanceBase`, `myPhotoUrl`, `essMeUrl`, `essUpdateUrl`, `deviceBase`, `recruitmentBase`, `offrollAgentBase`, `waTplBase`, `helpBase`, `billingBase`, `dashboardStatsUrl`, `searchUrl`, `usersUrl`, `usersBase`, `changePasswordUrl`, `saasTenantsUrl`, `demoReqUrl`, `saasPlansUrl`, `saasPlanDeleteUrl`, `updatesBase` | **The complete API surface of the product is enumerated to every logged-in user, including `saasTenantsUrl`, `saasPlansUrl`, `saasPlanDeleteUrl` and `billingBase`, which only a Super Admin can use.** An Employee's browser is handed the platform-administration endpoints. This is the reconnaissance half of §7.1. |

**Actual API call inventory:** `fetch(` appears **187 times** in the boot script and **0
times** in the prototype (the prototype is entirely offline/demo-data). The most-used
endpoints are `requestsBase` (17 call sites), `recruitmentBase` (13), `masterBase` (10),
`bioUrl` (8), `waTplBase` (7), then `testsBase`/`schemesBase`/`offrollAgentBase`/
`docBase`/`attBulkBase` (6 each). There is no shared HTTP client, no retry policy, no
central error handling, no loading-state abstraction, and no `AbortController` usage — 187
independently written `fetch` calls.

### Q6 — Client-side state and `app_state`

Three distinct stores:

| Store | Contents | Persistence | Blast radius |
|---|---|---|---|
| `DB` (JS object) | ~60 collections: roles+perms, leave types, holidays, letters, templates, points rules, KB, incentive schemes, assets, notices, policies, awards, complaints, escalations, clawbacks, deductions, salary setup, pay cycles, … plus `employees`, `companies`, `branchesC`, `departments`, `designations`, `teams`, `shifts`, `salarySchedules`, `tenants`, `payrollRuns`, `archivedEmployees`, `tdsReturns` overlaid from the server (`:492–509`) | `POST /app/state/save` → `settings.app_state` (whole blob) | **Total** — see §2.1 |
| `window.__XXX` globals (~40) | `__RATES`, `__PAYSLIPS`, `__BRANDMAP`, `__KB`, `__SMARTPRS`, `spPerms`, `_role`, `COMPANY`, `CURRENT`, screen-specific caches | in-memory only | Lost on reload; `__RATES` loss silently reverts statutory figures to `spRates()` literals (`:512`) |
| `localStorage` / `sessionStorage` | `sp_screen` (`:599, 605, 643, 8711`), `sp_navhide` (`:249, 887, 893`), `sp_tour_mute` (sessionStorage) | per browser | Trivial |

**What is persisted server-side:** everything in `DB` except `employees`, which
`AppStateController::show()` strips on read and `boot()` deletes on load (`:480`) so
prototype sample rows can never resurface.

**Seeding:** if `GET /app/state` returns no state, `seedState = true` (`:482`) and
`syncState(cfg)` is called at `:532` — **the prototype's demo data is written into the
tenant's `settings.app_state` on first login**. A new tenant's leave types, holidays,
letters and roles are therefore whatever the prototype's sample data contained.

**If corrupted or overwritten:** the tenant loses all ~60 collections at once. There is no
version, no history, no undo, no schema to validate against on read, and any authenticated
user can trigger it with one request (§7.2). Because `DB.roles[].perms` lives here, a
corrupted blob is simultaneously a data-loss event and an access-control event.

### Q7 — One front end or two? What is dead?

**Two front ends exist in the tree. One of them is entirely dead.**

| | Live | Dead |
|---|---|---|
| Entry point | `AppController::show()` → `resources/prototype/app.html` + 12,273 injected lines | `resources/views/layouts/app.blade.php` |
| Views | 1 (the prototype) | 17 Blade views extending the layout |
| CSS | heredoc at `AppController.php:235–447` + the prototype's own `<style>` | `public/css/smartprs.css` (353 lines) |
| Design tokens | duplicated in both | `resources/css/smartprs-theme.css` (36 lines) — imported by nothing |
| Routes | `/app/{screen?}` | `route('dashboard')`, `route('employees.index')`, `route('departments.index')`, `route('attendance.index')`, `route('leave.index')`, `route('salary-runs.index')`, `route('payslips.index')`, `route('loans.index')`, `route('devices.index')`, `route('tenants.index')` — **none exist in `routes/web.php`** |
| Font Awesome | 6.5.0 | 6.5.1 |

Rendering any of the 17 Blade views throws `RouteNotFoundException`. The legacy paths that
would have reached them are redirected away: `routes/web.php` has
`Route::get('/'.$legacy, fn () => redirect('/app'))` for each.

**Other dead code inside the live front end:**
- `applyPlanGate()` body, `AppController.php:713–741` (29 unreachable lines after an
  unconditional `return`).
- The prototype's top-bar upload/download/reset/CSV buttons, deliberately removed at
  runtime by a Font-Awesome-class regex (`:626`).
- The prototype's `expCsv()` 17-column export, overridden at `:555–561`.
- The prototype's demo login screen, hidden by the injection at `:231`.
- `resources/css/smartprs-theme.css` — "Import alongside Tailwind"; Tailwind is not in the
  tree.

### Q8 — Responsiveness, accessibility and mobile behaviour (factually)

**Responsiveness — implemented, and reasonably thorough.**
The CSS heredoc (`AppController.php:235–447`, 213 lines) contains **12 `@media` blocks**:
`(min-width:901px)`, `(max-width:1350px) and (min-width:901px)`,
`(max-width:1120px) and (min-width:901px)`, `(max-width:900px)` ×4, `(max-width:600px)`,
`(max-width:560px)`, `(max-width:430px)`, `(prefers-reduced-motion: no-preference)` and
`print`. The prototype's own `<style>` adds only 2 (`max-width:860px`, `max-width:1200px`)
— so essentially all responsive behaviour was retrofitted by the server injection.
Concretely:
- Sidebar collapses and becomes an overlay below 900px; `wireResponsive()` (`:582`) wires
  the toggle and `localStorage.sp_navhide` remembers the state.
- `table.mc-table` **card-stacking**: below the small breakpoints, table rows re-flow into
  stacked label/value cards. This is a real mobile-table solution, not `overflow:auto`.
- A themed prompt (`.sp-prompt-*`) replaces `window.prompt` on small screens.
- `wireMobilePageHelp()` (`:580`) mirrors the ⓘ help icon into the page heading on phones,
  because the top-bar icon is not reachable there.
- `declutterTopbar()` (`:616–637`) removes dead top-bar icons, explicitly annotated
  "Mobile-safe".

**But:** the responsive CSS applies to the *prototype's* class names. The ~90 screens
rebuilt as `custom` by the boot script emit their own markup; `wireRoadmapScreens()`
injects a **separate** stylesheet scoped to `.rm-screen` (`:2118–2130`) that styles
cards, inputs, buttons and tables — **and that stylesheet contains no media queries at
all**. So the newest screens (Attendance Correction, Probation, Greetings, Import
Employees, Absence Alerts, Statutory Configuration, and the roadmap set) inherit the
prototype's table card-stacking only where they happen to use `mc-table`.

**Accessibility — effectively absent. Factually:**
- Every screen is rendered by `innerHTML` replacement with **no focus management**. After
  `go()`, focus stays wherever it was (or is lost to `<body>`); there is no
  `aria-live` region and no announcement that the page changed. A screen-reader user is
  never told they navigated.
- Interaction is via inline `onclick` on `<div>` elements (`d.className = 'nav-item'`,
  `d.setAttribute('onclick', "go('" + id + "',this)")` — `:3138–3141`). These are not
  focusable, have no `role`, no `tabindex` and no keyboard activation. **The sidebar
  cannot be operated by keyboard.**
- **Measured ARIA usage across the entire front end: `aria-*` attributes = 0** (0 in
  `AppController.php`, 0 in `app.html`). `role="..."` = **1** in total
  (`role="dialog"` on the themed prompt, `AppController.php:3825`). `tabindex` = **1**
  in total (`tabindex="-1"` on a read-only amount input, `:4121`). `alt=` in the
  prototype = **0**.
- Modals are `<div>`s shown by `style.display`; there is no focus trap, no `Escape`
  handler convention, and no return-focus.
- Icons are Font Awesome `<i>` elements with no accessible name; several buttons are
  icon-only.
- `textContent` is used 46 times against 142 `innerHTML` assignments, so most text is
  inside constructed markup rather than semantic elements.
- Colour is used as the sole status indicator throughout (`--green/--amber/--red/--blue`
  status pills).
- There is no skip link, no landmark structure beyond the prototype's initial markup, and
  no documented contrast validation.

- One positive: `@media (prefers-reduced-motion: no-preference)` is honoured, so
  animations are opt-in by OS setting.

**Mobile behaviour beyond CSS:**
- The full ~1.2 MB uncacheable document is downloaded on every entry (§6.13).
- Geolocation punch and Leaflet maps require network access to unpkg.
- No touch-specific interactions, no swipe, no pull-to-refresh, no offline capability, no
  service worker, no PWA manifest.
- Excel export requires loading SheetJS from cdnjs at the moment of export.

**Verdict:** the product is *responsive* (layout adapts) but not *mobile-ready*
(payload, accessibility, offline, keyboard) and not accessible by any standard.

### Q9 — Maintainability: cost to change one screen today

**To change an existing screen** you must determine, in order:
1. Whether the screen is declared in `resources/prototype/app.html` line 356 (a single
   70,853-character line) — editing it means editing a minified-looking JSON literal
   inside a 234 KB HTML file.
2. Whether the boot script overwrites that declaration at `AppController.php:3188–3253`
   or `:2142` — for **90 of the 114** prototype screens it does, so step 1 is often moot.
3. Which of the two independent `renderCustom` dispatch chains handles it (the 58-branch
   chain installed at `:3126`, or the 10-branch roadmap chain).
4. Which of the 19 escape helpers is in scope at that point in the file, and which
   characters it actually escapes.
5. Whether any of the wrapper sentinels (`__wrapped`, `__rmWrapped`, `__permWrapped`,
   `__memWrapped`, `__helpWrapped`, `__stateWrapped`, `__wired`, `__brandWrapped`) affect
   your function, and in what order the wrappers were installed by the 33 `safe()` calls.
6. Whether the change requires a nav item, and if so which of the 21 DOM-injection sites
   to add it to and which `afterIds` anchor will actually match.
7. Whether the screen needs a `PERM_NAV` entry (`:655–672`) — if you forget, the screen is
   silently ungated.
8. Whether the screen has a help entry among the 801 in `ScreenHelpController.php`.
9. Whether `MASTER_MAP` (`:10756–10892`) and `MasterController::def()` both need the type.
10. That every `$` you write is escaped for the PHP heredoc and every `\` is doubled.

**There is no test of any kind for any of this.** A mistake surfaces as a silently
swallowed exception (`safe()`, `:530`) that leaves the feature missing with no error.

**Realistic cost:** adding one list screen with a master type, nav entry, permissions and
help touches **6 files and at least 8 distinct locations**, three of which are inside a
12,000-line PHP heredoc, and cannot be verified except by manual click-through as five
different roles. Changing a statutory constant means changing it in **two** places
(`SettingsController::defaults()` and `spRates()` at `:11160`) or accepting divergence.

**Implication for SmartPRS 3's configuration-driven UI:**
The 58 `custom` screens are the specification. Each one exists because someone needed
something the declarative `SCREENS` model could not express — a computed column, a
cross-collection join, a chart, a wizard, a bulk action, a nested list, a calculated
footer. SmartPRS 3's screen engine will regress to exactly the same escape hatch unless
those 58 are enumerated and each one's missing capability is added to the engine's
requirements. **The single highest-value follow-up task in this area is to classify all
58 `custom` renderers by the capability they needed.**

The positive precedents worth carrying forward are two: `cfg.empFields`
(`EmployeeFieldRules::formPanels()`) — a server-owned field registry that already drives
five separate consumers (the form's five tabs, the import template, the accepted import
headers, the export, and the sample file) from one definition, with the code comment at
`:127–136` explicitly recording the bugs that existed while those five lists were
maintained separately; and the `SCREENS` registry itself, which is the right idea executed
as a literal.
