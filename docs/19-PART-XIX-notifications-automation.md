# PART XIX — NOTIFICATIONS, TEMPLATES AND AUTOMATION

*Covers brief Sections 58, 59, 60, and the operational alerting of Sections 92 and 93.*
*Conforms to spine S3 (org model, scope, precedence), S4 (effective dating, draft/publish),
S5 (SPX), S6 (rule engine), S8 (workflow engine), S9 (entity metadata), S13 (security),
S14 (audit ledger), S15 (explainability), S16 (data conventions), S17 (API and webhooks),
S18 (deployment parity and the operations dashboard).*
*Depends on: Part VI (configuration lifecycle), Part VII (rules), Part VIII (formulas),
Part IX (workflow), Part X (employee schema engine — which owns the field metadata this
part derives its placeholder catalogue from), Part XVI (payslip template, a specialised
document template family), Part XXIII (audit ledger).*
*Depended on by: every module that communicates — attendance (Part XI), leave (Part XIII),
payroll (Part XIV), statutory (Part XVII), loans and commissions (Part XVIII), recruitment,
compliance, and platform operations.*
*Evidence base: `analysis/E-leave-requests-notifications.md` and
`analysis/L-background-ops-observability.md`, with corroborating citations from
`analysis/G-security-tenancy-api.md`, `analysis/H-recruitment-hrops-reports.md` and
`analysis/I-saas-licensing-deployment.md`. Every claim about SmartPRS 2 in this part
carries a `file.php:line` citation carried through from those files.*

---

## XIX.0 Purpose

This part decides four things.

1. **That there is exactly one template engine**, serving email, WhatsApp, SMS, push,
   in-app, letters and generated PDFs, whose placeholder vocabulary is *derived* from the
   entity metadata of Part X rather than maintained by hand, so a template cannot silently
   go stale when a field is renamed or removed.
2. **That there is exactly one notification automation engine** — an event catalogue, a
   subscription model, a rule-based audience resolver, per-channel delivery and per-user
   preference — and that **no code path anywhere in SmartPRS 3 composes or sends a message
   outside it.**
3. **That delivery is guaranteed, tracked per recipient, and never fabricated.** A message
   is recorded `sent` **only** on provider acknowledgement. This is not a refinement. It is
   the direct replacement for a SmartPRS 2 behaviour under which the system positively
   asserts that an email was delivered at the instant a row was inserted, before any
   transport was touched (`MailService.php:119`; consumed as truth at
   `SubscriptionAlerts.php:107-108`).
4. **That operational alerting is the same engine**, so that the scheduler heartbeat, queue
   depth, failed jobs, mail and webhook queues, biometric sync freshness, backup status and
   licence expiry are ordinary registered events with configurable audiences, thresholds
   and escalation — with one structural exception, stated as a requirement in §XIX.7.2:
   **an alert path may not depend on the subsystem whose failure it reports.**

What this part does **not** own: the payslip template designer and its closed section
catalogue (Part XVI); the workflow engine's approval routing, which merely *emits* events
this engine consumes (Part IX §IX.9, `NOTIFY` node); the rule engine's `NOTIFY` action,
which emits an event key and an audience reference and composes nothing (Part VII);
the audit ledger (Part XXIII).

---

## XIX.1 What SmartPRS 2 does today, and why it must change

SmartPRS 2 has four notification rails, two of which are wired to workflows, one of which
is a fully seeded catalogue with no consumers, and one of which has storage and no sender
at all (`E-…` §1.6). The failures below are ordered by consequence, not by code.

### XIX.1.1 The delivery audit is fabricated

`MailService::queue()` inserts a `mail_log` row and **returns that row's id at the moment
of insertion, before any delivery attempt** (`MailService.php:119`). Callers treat the
returned id as proof of despatch. `SubscriptionAlerts.php:107-108` writes
`subscription_alerts.status = 'sent'` on the strength of it (`L-…` §Q9). The system
therefore records, in a durable table a customer would reasonably read as an audit trail,
that a message was sent when it was only enqueued.

This is not theoretical. The queue is a separate process the product does not install and
does not check for. The repository's own quickstart tells the operator to run
`php artisan queue:work` and `php artisan schedule:work` in *"separate terminals"* by hand
(`README.md:50-51`), and the worker unit and cron entry live in a `deploy/` directory that
is absent from the repository (`L-…` §Q9, marked UNVERIFIED there). When the worker is not
running, rows accumulate in `jobs` indefinitely: **no error, no log line, no `failed_jobs`
row, no alert** (`L-…` §Q9B).

The compounding facts make this the worst failure mode in the product:

| Fact | Evidence | Consequence |
|---|---|---|
| The auto-suspend write is **direct, not queued** | `SubscriptionAlerts.php:65` | The tenant is suspended synchronously |
| The five warning emails at 15/7/3/1/0 days **are** queued | `SubscriptionAlerts.php:107-108` → `MailService::queue` → `SendNotification::dispatch` | They never leave |
| The milestone match is **exact day equality** | `in_array($days,[15,7,3,1,0],true)`, `SubscriptionAlerts.php:35, 56` | A missed run loses that reminder permanently |
| The alerts table records them as `sent` | `SubscriptionAlerts.php:107-108` | The record contradicts reality |
| The mail an administrator would *test* still works | credential mail is inline via `'sync' => true`, `MailService.php:107-116` | The diagnostic returns green |

The net effect, stated plainly: **a paying customer is locked out of a live HR system
having received none of the five warning emails that were supposed to precede it, while
the system's own records assert that all five were sent** (`L-…` §Q9, DEF-L-13). The
in-code comment at `MailService.php:102-106` is direct evidence that at least one
production install has already run with no worker and locked a paying client out of their
workspace (`L-…` OQ-L-02).

**Design response:** §XIX.4 — the outbox pattern, a five-state delivery model in which
`queued` and `sent` are different states, and **R-XIX-061**, the absolute rule that
`sent` is written only on provider acknowledgement.

### XIX.1.2 WhatsApp is a catalogue with seventeen templates and zero senders

`WaTemplateController::tenantLibrary():130-185` seeds a per-tenant library of seventeen
ready-to-submit WhatsApp template bodies — `leave_decision`, `approval_pending`,
`request_decision`, `commission_approved`, `transfer_order`, `payslip_ready` and eleven
more — against eighteen declared tenant purposes (`TENANT_PURPOSES:46-65`). Templates can
be created, edited, validated for variable arity, test-sent and marked approved
(`WaTemplateController.php:268, 276-281, 384-433`).

**Not one of them is wired to a sender.** Grepping the callers of
`WaService::sendTemplate` finds only `WaTemplateController::testSend` and the platform
signup/billing/OTP flows. Every HR workflow sends email only — leave at
`LeaveController.php:446`, requests at `RequestController.php:578` and `:1504`
(`E-…` NEW-NOT-114, DEF-NOT-04). Of the seventeen in-scope HR triggers enumerated in
`E-…` §7 Q7, **every one uses in-app and/or email exclusively**; zero send WhatsApp
(`E-…` §7 Q7, "Channel reality check").

The channel that does exist is India-only by construction: `WaService::sendTemplate()`
strips non-digits, takes **the last ten**, and posts with `countryCode` as the literal
`'+91'` (`WaService.php:116-117, 143-148`). `+44 20 7946 0958` is despatched as
`+91 2079460958` — a wrong or non-existent Indian number — and fails **silently**,
returning `false` and never throwing (`E-…` §4.11).

**Design response:** §XIX.3.4 (channel as configuration per event per tenant), §XIX.4.3
(provider adapters with a declared payload contract), **R-XIX-057** (country code and
phone normalisation are configuration, never a literal).

### XIX.1.3 Push has token storage and no sender anywhere in the codebase

`MobileDeviceController::pushToken():173-189` writes `mobile_devices.push_token` with
**no tenant scope, no ownership check and no `LIMIT`**, and returns `{ok:false}` with
HTTP 200 on any exception. An exhaustive grep for `fcm|firebase|push_token|sendPush`
across `app/`, `config/`, `routes/` and `composer.json` returns **three lines, all inside
that one controller** (`:47`, `:183`, `:210`) (`E-…` BR-NOT-021, SEC-11). The device gate
these tokens belong to is marketed as an anti-fraud layer and is **not enforced anywhere
on the server**: no middleware, controller or route ever reads `mobile_devices.token`
(`E-…` BR-NOT-020, SEC-09).

**Design response:** push is a first-class channel adapter in §XIX.4.3 with the same
delivery ledger as every other channel, or it is not offered — **PO-XIX-04**.

### XIX.1.4 Every trigger, audience and body is a literal at a call site

The complete enumeration in `E-…` §7 Q7 was built by grepping every `'kind' =>` literal in
`app/` and reading each call site. The summary is unambiguous:

| Aspect | Configurable today? |
|---|---|
| Whether an event notifies at all | **No** — hard-coded call site |
| Who receives it | **No** — hard-coded per call site |
| Which channels it uses | **No** — `in_app`/`email` booleans are literals per call site |
| Subject / heading / body wording | **No**, except greetings and the late-arrival template |
| Placeholders available | **No** — a fixed set of five (`{{name}} {{first_name}} {{company}} {{years}} {{date}}`), plus four late-only (`GreetingService::render():252-257`; `GreetingController.php:34-36`) |
| Language / localisation | **No** — English literals throughout |
| Per-user opt-out or unsubscribe | **No** — no preference store, no unsubscribe link (`E-…` BR-NOT-024) |
| Retry / queue behaviour | **No** — `tries=3`, `backoff=30`, queue name `'mail'` are literals (`SendNotification.php:33-34, 41`) |

Two consequences are worth naming because they are what a customer notices. **Leave
submission notifies nobody at all** — there is no notification of any kind on submit,
while requests do notify their approver (`E-…` §7 Q7 row 3; `RequestController.php:583-588`
versus `LeaveController`). And the **disciplinary late-arrival warning template lives
inside the Greetings configuration object** (`GreetingService::defaults():54-58`), so HR
editing "Greetings" is also editing a warning letter, whose *sending* is controlled by an
entirely different setting in Statutory Rates (`E-…` NEW-NOT-111).

Broadcast audiences are eight hard-coded filters (`all`, `company`, `department`, `team`,
`designation`, `branch`, `type`, `employee`) resolved by loading **every** active employee
of the tenant and filtering in PHP with `strcasecmp` (`SendMessageController.php:19-203`),
with the labels `Field / FOS` and `Office` as literals, and with the department,
designation, branch and team label maps loaded **without a `tenant_id` predicate** at
`:165-168` (`E-…` BR-NOT-007, NEW-NOT-105).

**Design response:** §XIX.3.1 (event catalogue), §XIX.3.2 (subscriptions), §XIX.3.3
(audience as a rule), §XIX.2 (templates as data).

### XIX.1.5 The message log permanently stores OTPs and a plaintext temporary password

`wa_log.body_values` stores the full JSON array of template variables for every send
(`WaService.php:171`). For the `smartprs_welcome` template the fifth variable is the new
tenant administrator's **temporary password** — the template body is literally
`Temporary password: {{5}}` (`WaTemplateController.php:103-104`). It is written in clear
and retained forever, because **no retention or pruning exists for any log table in the
product** (`E-…` NEW-NOT-116 / SEC-01; NEW-NOT-117; `L-…` SEC-L-03). OTP values reach the
same column by the same route.

This sits against a **published Privacy Policy that commits to 90-day retention**
(`PolicyController.php:12`), which nothing implements (`E-…` NEW-NOT-117, SEC-18).
Provider API keys are stored as plain `TEXT` in `wa_settings.api_key` and
`sms_settings.api_key` with no `Crypt::encryptString`, in a codebase that *does* encrypt
other secrets such as the Razorpay webhook secret (`E-…` NEW-NOT-115 / SEC-14).

**Design response:** §XIX.6 — an explicit prohibition list, a redaction stage that runs
before persistence rather than after, encrypted provider credentials, and an enforced
retention class per table.

### XIX.1.6 The automation is forty-five literals

`L-…` §Q8 catalogues **forty-five values embedded in scheduled-task code that a customer
would want to configure**. They are not edge cases; they are the product's operating
policy. A representative selection, with the customer requirement each denies:

| # | Rule | Hard-coded value | Location | Customer need |
|---|---|---|---|---|
| 1 | Compliance alert window | **30 days** | `ComplianceController.php:31` | An NBFC with a 90-day DRA renewal cycle needs 90 |
| 3 | Compliance digest size | **top 25 items** | `ComplianceController.php:206` | A 2,000-agent client sees 25 of 300 expiries |
| 4 | Compliance digest audience | `['super_admin','admin','hr_manager']` | `ComplianceController.php:256` | Customers have a Compliance Officer role |
| 6 | Subscription reminder ladder | `[15, 7, 3, 1, 0]` | `SubscriptionAlerts.php:35` | A reseller's contract says 30/14/7/1 |
| 8 | Auto-suspend after grace | **always, unconditionally** | `SubscriptionAlerts.php:60-69` | An enterprise customer will not accept automatic suspension at all |
| 12 | Probation reminder audience | `['admin','hr_manager','hr','super_admin']` | `ProbationService.php:250-253` | Not configurable at all |
| 14 | Overdue probation nudge fires **once** | dedupe base `probation:{empId}:{end}:overdue`, `ProbationService.php:215` | Customers expect escalating chasing |
| 17 | Default working week | `[1,2,3,4,5,6]` (Mon–Sat) | `AbsenceService.php:44-45` | A five-day office client is defaulted to marking every Saturday absent |
| 20 | Anniversary `min_years` | **1** | `GreetingService.php:47` | Some clients celebrate only 5/10/15-year milestones |
| 30 | Error-digest severity set | `['ERROR','CRITICAL','ALERT','EMERGENCY']` | `ErrorDigest.php:48` | The customer's ops team wants WARNING |
| 41 | Queue name | `'mail'` | `SendNotification.php:41` | A customer wanting a priority lane for payslips cannot have one |
| 45 | WhatsApp welcome carries the plaintext password | default **`true`** | `SignupController.php:413` | Security-conscious customers must be able to default it off |

Two structural defects run through the set. **Milestone matching uses exact day equality**
across four services — `ProbationService.php:190`, `SubscriptionAlerts.php:56`,
`GreetingService.php:145`, `AbsenceService.php:105` — so a missed scheduler run loses that
reminder permanently (`L-…` DEF-L-05). And **`send_hour` is evaluated in the server's
timezone, not the tenant's**, despite a docblock at `SendGreetings.php:11-13` claiming that
"every company fires at its own local time" (`E-…` NEW-NOT-106).

**Design response:** §XIX.8 (scheduled communications as configuration), §XIX.3.5
(tenant-local scheduling), **R-XIX-050** (catch-up semantics replace exact-day equality).

### XIX.1.7 The one alerting mechanism is structurally blind

`errors:digest` (`ErrorDigest.php`), daily at 07:45, is the product's entire alerting
capability. `L-…` §Q7 records seven independent defects in it. The load-bearing one:

> Every swallowed failure in the scheduled services logs at `Log::warning`
> (`AbsenceService.php:134`, `GreetingService.php:157`, `ProbationService.php:165`,
> `MailService.php:112, 121`, `NotifyLateArrivals.php:52`, `LateArrivalService.php:296`),
> and `ErrorDigest.php:48` scans only for `ERROR|CRITICAL|ALERT|EMERGENCY`, while
> `.env.production.example:24` sets `LOG_LEVEL=warning`. **The warnings are written and
> never reported** (`L-…` DEF-L-06).

Of 83 `Log::` calls in `app/`, **64 are `warning`** and six are `error` (`L-…` §Q6). The
digest is therefore blind to the failure mode the product actually exhibits. It is also
**delivered through the queue whose failure it cannot detect** (`ErrorDigest.php:82` →
`MailService::queue` → `SendNotification::dispatch`) (`L-…` DEF-L-07); it returns SUCCESS
and sends nothing if `SMARTPRS_OPS_EMAIL` is unset (`:25-30`); it matches
`str_contains($line, $lvl.':')`, so any business string containing "ERROR:" inflates the
count (`:62`); it reads only the last 2 MB / 4000 lines of an **unrotated, unbounded** log
file (`:46, :108`); it has no dedupe; and it has **no `try/catch` at all** — `filesize`,
`fopen`, `fseek` and `fread` are unguarded at `:105-112`, so a permissions problem aborts
the task and the error goes to a discarded stream.

Separately, `.env.production.example:62-63` describes `SMARTPRS_OPS_EMAIL` as the
*"backup-failure + daily error-digest recipient"*. Grep confirms it is read **only** by
`ErrorDigest.php:25`. `config/backup.php` omits the `notifications` block entirely and
`backup:monitor` is not scheduled, so **backup failures are emailed to nobody**
(`L-…` DOC-L-12, §Q5, OQ-L-04).

**Design response:** §XIX.7 — health signals as first-class registered events with
configurable thresholds, audiences and escalation, plus **R-XIX-088**, the out-of-band
alert path.

### XIX.1.8 Authorisation on the messaging surfaces fails open

`ApprovalService::denyUnlessRole()` returns `null` — meaning *allowed* — from its `catch`,
with the in-code comment *"fail open — availability over strictness on pivot errors"*
(`ApprovalService.php:365-380`). It is used at **71 call sites across 30 files**
(`G-…` §0, counted by grep; `E-…` requirement 93). The broadcast screen is one of them
(`SendMessageController.php:19`, `E-…` BR-NOT-007). During any fault that reaches that
`catch` — a Spatie cache store outage, a missing `model_has_roles` table after a partial
migration, a database blip — a plain `employee` may broadcast to the entire tenant, and
**no log line is written** (`G-…` SEC-01).

The correct pattern exists in the same codebase: `WaTemplateController::scope():205-213`
uses `abort_unless` and fails **closed** (`E-…` BR-NOT-013). SmartPRS 3 adopts the latter
universally under spine Law 5; this part restates it for its own surfaces as
**R-XIX-093**.

### XIX.1.9 The remaining messaging defects, in one table

| Ref | Defect | Evidence |
|---|---|---|
| DEF-NOT-03 | The late-arrival email **bypasses the mail engine**: `Mail::raw` on the *global* mailer, so it uses the wrong SMTP, has no branding, no queue, no retry, and **writes no `mail_log` row** | `LateArrivalService.php:289`; `L-…` §Q4 |
| NEW-NOT-101 | A broadcast makes one `Http::timeout(15)` POST **per recipient, synchronously, inside the HTTP request** — a 500-employee WhatsApp broadcast can occupy the PHP worker for roughly two hours | `SendMessageController.php:72-95, 234-266` |
| NEW-NOT-102 / SEC-12 | The provider POST is a "shotgun payload" carrying the API key under **three** field names (`apikey`, `authkey`, `api_key`) to whatever host an admin typed, with **no scheme or host allow-list** | `SendMessageController::postProvider():234-266` |
| NEW-NOT-103 | Broadcast logging is **one aggregate row** in `messages_log`; a failed SMS to one employee cannot be found, retried or proven | `SendMessageController.php:109-125` |
| BR-NOT-014 / SEC-04 | The Interakt delivery-status webhook is **unauthenticated, unsigned, unthrottled and cross-tenant**, and infers status by substring-scanning the serialised payload for `"read"`, `"delivered"`, `"sent"`, `"failed"` | `routes/web.php:198-204`; `RecruitMessagingController::interaktWebhook():380-417` |
| BR-NOT-010 | A 2xx from a test send **auto-approves** a WhatsApp template, and the error lookup at `:414` is not tenant-scoped | `WaTemplateController::testSend():384-433`, auto-approve at `:417` |
| BR-NOT-001 | Every notification path is best-effort by design: `Log::warning` and `return null` on any exception, with callers wrapping again | `NotificationService.php:130-134`; `MailService.php:120-124`; `WaService.php:161-163` |
| DEF-L-12 | `NotificationService::send()` returns `null` both for a suppressed duplicate and for a failure — the call site cannot distinguish them | `NotificationService.php:92-94, 142-160` |
| DEF-L-11 | A message with no SMTP configured is marked `skipped` and the method returns without throwing; the job succeeds having sent nothing and nothing alerts | `MailService.php:148-152` |
| NEW-NOT-112 / SEC-13 | `GET /app/greetings/config` and `/log` have **no role guard**; any authenticated user reads the tenant's templates and the delivery log, which discloses which `employee_id` had a birthday on which date | `GreetingController::config():25-41`, `log():169-188` |
| NEW-NOT-107 | The greeting candidate query is **non-sargable** — `DATE_FORMAT(dob,'%m-%d') = ?` full-scans `employees` per tenant per matching hour | `GreetingService::candidates():164-177` |
| NEW-NOT-108 | 29-February birthdays are never greeted in a common year | `GreetingService.php:134, 172-173` |
| NEW-NOT-110 | `hide_birth_year` is declared, surfaced on the configuration screen, and **never read** — a visible setting that does nothing | `GreetingService.php:36` |
| NEW-NOT-118 | `notifications_feed` is created by migration and referenced **nowhere** in `app/` | migration `…000600…:199+` |
| SEC-L-07 | Attachments travel as `attach_b64` inside the queue job payload | `L-…` requirement 33 |
| TD (schema) | `notification_log`, `mail_log`, `wa_log`, `wa_templates`, `app_settings`, `notices` and `mobile_devices` are **created at runtime by application code**, not by migrations | `NotificationService::ensureTable():50-70`; `MailService::ensureTable():49-66`; `WaService::ensureLog():25-39`; `WaTemplateController::ensure():72-92`; `ConfigController::ensureTable():30-37`; `MasterController::ensureCols():501-507`; `MobileDeviceController::ensure():36-54` |

### XIX.1.10 Defect-to-design map

| SmartPRS 2 behaviour | SmartPRS 3 response | Where |
|---|---|---|
| `sent` written before despatch | `sent` only on provider acknowledgement; `queued` is a distinct state | §XIX.4.2, R-XIX-061 |
| Queue worker absent and undetected | Installer refuses without a worker; worker liveness is a health signal with an out-of-band alert | §XIX.7, R-XIX-085, R-XIX-088 |
| Seventeen WhatsApp templates, zero senders | Channel is a per-event, per-tenant configuration; a registered channel with no adapter fails publish-time validation | §XIX.3.4, R-XIX-044 |
| Push tokens, no sender | Push is an adapter with the same ledger, or the capability is withdrawn | §XIX.4.3, PO-XIX-04 |
| Trigger, audience, body all literals | Event catalogue + subscription + audience rule + template artefact | §XIX.3, §XIX.2 |
| Five placeholders | Placeholder catalogue derived from Part X field metadata | §XIX.2.4 |
| OTPs and passwords in `wa_log.body_values` | Prohibited classes; redaction before persistence; enforced retention | §XIX.6 |
| Forty-five hard-coded automation values | All forty-five are configuration; §XIX.9 tests each against the Section 127 standard | §XIX.8, §XIX.9 |
| Error digest blind to `warning` | Health signals are typed events, not log-file greps; thresholds and severities are configuration | §XIX.7 |
| One aggregate broadcast row | One delivery row per recipient per channel | §XIX.4.2, R-XIX-063 |
| `Mail::raw` bypass | No path may compose or send outside the engine, asserted by CI | R-XIX-002 |
| Unsigned inbound webhook | HMAC over raw body + timestamp + nonce, freshness window, replay rejection, tenant-scoped | §XIX.4.5, spine S17 |
| SSRF shotgun payload | Per-provider payload contract; egress allow-list; credential in one declared position | §XIX.4.3, R-XIX-058 |
| Best-effort everywhere | Best-effort **delivery** is retained where correct, but every failure is recorded and surfaced | §XIX.4.6 |

---

## XIX.2 The Template Engine *(brief Section 58)*

### XIX.2.1 One engine, seven output families

**R-XIX-001** There is exactly **one** template engine in SmartPRS 3. It renders every
message and every generated document the product produces, across seven **output
families**:

| Family | Output | Notes |
|---|---|---|
| `email` | subject + HTML body + plain-text alternative | per-company SMTP identity resolved at send |
| `whatsapp` | a registered provider template name + an ordered variable list | body text is authored here **and** registered with the provider; arity is validated against the registration |
| `sms` | a single plain-text body, with DLT template id and entity id where the provider requires them | segment count computed and shown at authoring time |
| `push` | title, body, deep link, data payload | payload size validated per platform |
| `in_app` | title, body, deep link, icon, severity | the in-app feed is the delivery row itself (§XIX.10.6) |
| `letter` | a paginated rich document rendered to PDF | includes offer letters, appointment, confirmation, transfer, show-cause, warning, experience, relieving |
| `document` | a generated PDF or spreadsheet artefact bound to a data set | statutory registers and report exports bind here; the **payslip** is a specialised family owned by Part XVI |

**R-XIX-002** **No code path may compose or send a message outside this engine.**
*Test:* a CI sweep fails the build on any use of `Mail::`, `Mail::raw`, `Notification::`,
an HTTP call to a messaging provider host, or direct construction of a message body,
outside `Domain\Communication\Adapters`. *This exists because SmartPRS 2's late-arrival
email uses `Mail::raw` on the global mailer, producing the wrong SMTP identity, no
branding, no queue, no retry and no delivery record at all (`LateArrivalService.php:289`).*

**R-XIX-003** The payslip template (Part XVI) is a **member of the `document` family**,
not a parallel engine. It carries its own closed section catalogue and its own field
binding rules; it shares this part's artefact lifecycle, placeholder catalogue, preview,
validation and versioning. No second template store exists.
*This exists because SmartPRS 2 has at least four independent sources of letter body text
— database templates, two NDA defaults, a seeded HTML offer template, and seven
`LETTER_SAMPLES` JS constants used to pre-fill the editor (`AppController.php:10711-10723`,
`H-…` NEW-DEFECT-05) — plus a separate greetings config object and per-call-site PHP
literals.*

### XIX.2.2 The template artefact

A template is a spine S4 configuration artefact of `artefact_type = 'template'`, stored
through `config_versions` (Part VI, kind **K8**), with typed child rows. It has the full
draft → testing → pending_approval → published → superseded → archived lifecycle,
maker-checker where declared `high_risk`, and a publish-time diff.

```
template                        identity
  tenant_id, template_key (immutable), family, subject_entity, is_system,
  pack_ref (nullable — a starter shipped by Ametecs), status

template_version                the effective-dated, scoped definition
  version envelope per S4: version_no, status, scope_json, specificity_weight,
  priority, effective_from, effective_to, created_by, approved_by, published_at,
  superseded_by_version_id, reason, change_note, checksum
  + display_name, description, language, direction,
    variable_manifest_json      -- RESOLVED at publish, see XIX.2.4
    render_budget_json          -- node, loop and size budgets actually applied
    sample_dataset_ref          -- the dataset the preview and the assertions used

template_channel_body           one row per channel this version supports
  channel, part (subject|body_html|body_text|title|deep_link|provider_template_name|
                 dlt_template_id|data_payload),
  content_source (the authored text with placeholders and blocks),
  content_ast (the compiled, immutable parse tree — the thing that is rendered),
  provider_registration_ref, provider_arity, escaping_profile

template_block                  reusable partials: header, footer, signature,
                                grievance-officer block, legal footer
template_asset                  logos and images, referenced by id, never inlined bytes
template_assertion              stored expected-output cases; publishing runs them
```

**R-XIX-004** The **compiled AST is the stored artefact**, alongside the source text
(spine S5 execution model). A published template cannot be re-parsed differently by a later
parser release. *Test:* rendering a three-year-old template version on a current build
reproduces the archived expected output byte-for-byte.

**R-XIX-005** `template_key` is **immutable**. The display name lives on the version, so a
rename is a version and history keeps the name it was called at the time.
*(Spine Law 3; the same discipline Part XIII applies to leave types.)*

**R-XIX-006** A template version may **not** be edited after publication. Editing produces
a new version whose `effective_from` closes the prior version's `effective_to` the day
before (spine S4). *This exists because SmartPRS 2's issued letters have no body of their
own — the PDF is merged on demand from the current template, so editing a template
silently rewrites every letter ever issued from it (`H-…` §1, `letters` table).*

**R-XIX-007** Every **rendered artefact that has legal or evidentiary weight** — an issued
letter, a payslip, a statutory register, a signed acknowledgement — is **stored as
rendered**, with the `template_version_id`, the `variable_manifest` hash and a content
hash. Regeneration is a comparison, never a substitution.

### XIX.2.3 Scoping, precedence and resolution

**R-XIX-008** Template resolution is `resolve('template', template_key, scope, as_of_date,
language)` and uses the **spine S3.2 precedence rules without modification**: specificity
weight, then declared `priority`, then **publication is blocked** by the configuration
validator naming both artefacts and the employees affected. There is no third tie-break.

**R-XIX-009** The scope dimensions available to a template are the full S3.1 set. In
practice customers use `company`, `branch`, `department`, `employee_category`,
`employment_type`, `legal_entity` and `state`; the engine constrains none of them.
*This replaces SmartPRS 2, where the only template scoping that exists is the WhatsApp
registry's tenant-first-then-platform ordering (`WaService::templateNameFor():74-103`).*

**R-XIX-010** **Language is a resolution dimension, not a scope dimension.** Resolution
picks the artefact by scope, then the language variant by the recipient's language
preference, falling back through a declared ladder: recipient preference → employment
scope default → company default → tenant default → the template's `is_fallback` variant.
A resolution that reaches the end of the ladder with no variant is a **publish-time
error**, not a runtime one — an event may not be subscribed on a channel for which no
resolvable template exists.

**R-XIX-011** Resolution is a single indexed query per `(family, template_key, scope hash,
language)` and is **cached per resolution date**, invalidated on publish. A bulk send
resolves once per distinct scope hash, not once per recipient. *This exists because
SmartPRS 2's broadcast loads every active employee of the tenant and filters in PHP
(`SendMessageController.php:19-203`).*

### XIX.2.4 The placeholder catalogue — derived, so it cannot go stale

This is the mechanism that makes Section 58 more than a text editor.

**R-XIX-012** The placeholder catalogue is **derived**, not maintained. It is assembled
from four sources, all of which are already authoritative elsewhere:

| Source | Contributes | Owner |
|---|---|---|
| `field_definitions` (spine S9, Part X) | every field of every entity — system and customer-defined — with its `data_type`, `label`, `pii_class`, `masking` and `value_set_ref` | Part X |
| The SPX symbol table (spine S5.3) | `employee.*`, `org.*`, `service.*`, `period.*`, `shift.*`, `day.*`, `attendance.*`, `leave.*`, `salary.*`, `statutory.*`, `policy.*` | spine |
| The event definition (§XIX.3.1) | the event's own typed payload — `subject`, `actor`, `decision`, `amount`, `due_on`, `deep_link`, and the event-specific fields it declares | this part |
| Platform context | `company.*` (including the grievance officer, phone and email that merge into every letter), `tenant.*`, `now`, `recipient.*` | this part |

**R-XIX-013** A placeholder that does not resolve against the catalogue for the template's
declared `subject_entity` and event **blocks publication**, naming the placeholder and the
nearest valid alternatives. *SmartPRS 2 renders unknown tokens as the empty string
(`GreetingService::render():252-257`), so a typo produces a silently blank letter.*

**R-XIX-014** Each published template version stores a **resolved variable manifest**: for
every placeholder used, the field id or symbol, its data type, its PII class and the
masking profile applied. The manifest is what makes the dependency visible in both
directions.

**R-XIX-015** **Publishing a field change consults the manifest.** When a Part X field is
renamed, retyped, restricted or retired, the configuration validator lists **every
published template version whose manifest references it**, with the affected scopes and
the impacted employee count, and:

| Field change | Effect on templates |
|---|---|
| Label changed | none — templates bind to `field_key`, never to a label |
| `field_key` renamed | **blocked** unless every referencing template version has a queued successor; the successor is offered pre-rewritten |
| Data type changed | blocked if any referencing template applies a type-incompatible format or condition |
| `pii_class` raised | referencing templates are re-validated against §XIX.2.9; those that would now emit an over-exposed value are listed and must be revised |
| Field retired | blocked while any **currently effective** template version references it |

*This is the whole point of the derivation. In SmartPRS 2 the merge vocabulary is a
literal set of five tokens in one service and four more in another
(`GreetingController.php:34-36`), unconnected to the fifty-six-field employee registry, so
the two drift by construction.*

**R-XIX-016** A customer-defined field added through Part X is available as a placeholder
**immediately**, with no code change and no template-engine release.
*Section 127 test: "add a LinkedIn Profile field and put it in the offer letter" is two
configuration actions.*

**R-XIX-017** The catalogue is **surfaced in the builder** as a searchable, grouped,
type-annotated picker with a one-line description and a live sample value per variable
(§XIX.5). A variable the current actor is not permitted to see is not offered.

### XIX.2.5 The template body language

**R-XIX-018** Template bodies support exactly four constructs beyond literal text.
Nothing else is executable. Customer-supplied PHP, JavaScript or Python is **never**
accepted (spine S5).

```
{{ variable }}                          substitution
{{ variable | format(...) }}            a whitelisted formatter
{% if <SPX Boolean> %} … {% elseif … %} … {% else %} … {% endif %}
{% for line in <declared List variable> %} … {% empty %} … {% endfor %}
```

**R-XIX-019** Conditions are **SPX expressions** (spine S5), validated at publish against
the same symbol table as rules and formulas. There is one expression language in the
product; learning it once is enough.

```
{% if leave.units > policy.long_leave_threshold_days %}
  A medical certificate is required for absences longer than
  {{ policy.long_leave_threshold_days | number }} days.
{% endif %}
```

**R-XIX-020** Loops iterate **only** over `List<T>` variables the event declares. Loop
depth is limited to a platform constant (recommended 2), iteration count to a declared
budget, and total rendered size to a per-channel limit. Exceeding a budget is a **render
failure that is recorded and surfaced**, never a truncation. *Test:* a template that
iterates a 50,000-row list fails visibly rather than producing a 40 MB email.

**R-XIX-021** The formatter set is a **closed, versioned whitelist**, and every formatter
that could vary by locale or policy takes its parameters explicitly:

```
number(decimals, mode)          money(format, symbol)      -- Indian grouping: ₹1,50,000.00
date(format)                    datetime(format, tz)       duration(unit)
days(precision)                 hours(precision)           percent(decimals, mode)
upper  lower  title  trim       truncate(n, ellipsis)
mask(profile)                   ordinal                    list(separator, last_separator)
value_set_label(set_key)        -- renders a Code as its label in the recipient's language
```

There is **no default rounding and no default date format** anywhere. *Consistent with
spine S5.4, where `ROUND` requires an explicit mode.*

**R-XIX-022** **Escaping is automatic and per channel**, applied by the renderer from the
`escaping_profile` on the channel body: HTML-escape for `email`/`in_app`, provider-specific
escaping for `whatsapp`, transliteration-safe handling for `sms`, JSON escaping for `push`
payloads, and XSS-safe sanitisation for `letter`/`document`. A template author cannot
disable escaping. *This exists because SmartPRS 2's `stripHtmlDeep()` sanitiser destroys
the seeded HTML offer template on first save (`H-…` requirement 27, DEFECT-DOC-05) — the
sanitisation is applied at the wrong layer, to the source rather than to the output.*

**R-XIX-023** Rich text is preserved end to end for the `letter` and `document` families:
the authoring surface, the stored source, the AST and the renderer all carry the same
structured document model. Sanitisation happens **at render, against an allow-list of
elements and attributes**, never against the stored source.

### XIX.2.6 Channel-specific constraints, declared and enforced

**R-XIX-024** Each channel declares its constraints as **platform data**, and the
publish-time validator enforces them:

| Channel | Enforced at publish |
|---|---|
| `email` | a plain-text alternative exists (generated if not authored); subject ≤ configured length; every link is absolute and signed where it addresses private content; an unsubscribe mechanism is present for every non-mandatory class (§XIX.3.8) |
| `whatsapp` | the body matches the **provider-registered** template exactly; variable numbering is contiguous from `{{1}}` with no gaps; declared arity equals registered arity; category and language match the registration |
| `sms` | segment count is computed and displayed; DLT template id and entity id are present where the provider requires them; the body contains no character outside the declared encoding |
| `push` | title and body within platform limits; the data payload is within the size budget; the deep link resolves to a registered route |
| `in_app` | severity is from the value set; the deep link resolves |
| `letter` / `document` | page setup declared; every asset resolves; pagination and page-break rules validate; the signature and grievance-officer blocks resolve for every scope in the template's scope set |

**R-XIX-025** For WhatsApp, **approval status is recorded from the provider, never
inferred**. A successful test send is evidence of reachability, not of approval.
*SmartPRS 2 sets `status='approved'` on any 2xx from a test send
(`WaTemplateController::testSend():417`).* Where the provider exposes a template-status
API, the adapter polls it and records the provider's own verdict with a timestamp; where
it does not, the status is `submitted` and an administrator confirms it explicitly, with
that confirmation audited.

**R-XIX-026** Editing a published WhatsApp body invalidates its registration and requires
re-submission. *SmartPRS 2 does this correctly (`WaTemplateController.php:298-304`) and it
is retained.*

**R-XIX-027** Template arity is validated against the provider's registered template
**before a campaign or a subscription can be activated**, not at first send.
*(`H-…` requirement 63, BR-REC-028.)*

### XIX.2.7 Preview, test send and publish-time validation

**R-XIX-028** Every template version offers **three** kinds of preview, all available to
the author before any send:

| Preview | Data source | Purpose |
|---|---|---|
| **Sample data** | a named sample dataset — synthetic, or a real employee the actor is permitted to see | wording and layout |
| **Live subject** | a real employee, real period, real event payload, rendered read-only and never despatched | catching a placeholder that is empty in real data |
| **Multi-scope** | the template rendered once per distinct scope in its scope set | catching a variant that breaks for one company or one language |

**R-XIX-029** **Test send** despatches to a recipient the actor is permitted to reach —
themselves, a named test address, or a designated test employee — through the **real
adapter and the real delivery ledger**, marked `is_test = 1`. A test send is never
delivered to a live audience, never counted in analytics, and is audited.
*SmartPRS 2's test greeting sends to the acting HR user (`E-…` §7 Q7,
`greeting.test`) — the right instinct, generalised here.*

**R-XIX-030** **Publish-time validation** must pass in full. It is a gate, not a warning
list. It asserts:

1. Every placeholder resolves against the catalogue for the declared subject entity and
   every event the template is subscribed to (R-XIX-013).
2. Every SPX condition parses, type-checks, and references only declared namespaces.
3. Every loop iterates a declared `List<T>`.
4. Every formatter is whitelisted and its parameters are complete.
5. Every channel body satisfies its channel constraints (R-XIX-024).
6. Every language variant declared by the resolution ladder exists (R-XIX-010).
7. Every asset and block reference resolves in every scope in the scope set.
8. Every stored assertion (`template_assertion`) renders to its expected output.
9. No placeholder emits a value the template's declared audience is not permitted to see
   (R-XIX-033).
10. No placeholder belongs to a **prohibited class** (§XIX.6, R-XIX-070).
11. Scope precedence is unambiguous against every other published version of the same key
    (spine S3.2).

**R-XIX-031** A template that fails validation **cannot be published**. There is no
override flag. *This exists because every configurable communication artefact in
SmartPRS 2 is saved unvalidated — `GreetingController::saveConfig()` accepts any array
shape and merges arbitrary keys into the persisted JSON with no key whitelist and no length
limits (`GreetingController.php:51-57`).*

**R-XIX-032** Publishing a template runs its stored assertions. A failed assertion blocks
publication and names the assertion, the expected output and the actual output.

### XIX.2.8 Masking, PII and the rendered artefact

**R-XIX-033** A placeholder inherits the `pii_class` and `masking` profile of its source
field (spine S9). The renderer applies the masking profile **for the resolved recipient's
role and relationship to the subject**, not for the author's. A template that would emit a
value the declared audience may not see fails publication.

**R-XIX-034** **A masked value is masked in every channel** — email, WhatsApp, SMS, push,
in-app, PDF and the preview. There is no channel on which masking is relaxed.
*(Consistent with Part XVI R-XVI-018.)*

**R-XIX-035** A message may **never** carry a full sensitive identifier (Aadhaar, PAN,
bank account number, biometric template) in its body. Where the business need is
recognition rather than disclosure, the masked form is used
(`XXXX XXXX 4821`). Where the need is genuine disclosure — a payslip, a Form 16 — the
document is **not** attached to the message; the message carries a signed, expiring,
authorised link (spine S13).

**R-XIX-036** No message payload carries file bytes. Attachments are stored, referenced by
id, and served through the document service. *This exists because SmartPRS 2 puts
`attach_b64` inside the queue job payload (`L-…` SEC-L-07, requirement 33).*

---

## XIX.3 The Notification Automation Engine *(brief Section 59)*

### XIX.3.1 The event catalogue

**R-XIX-037** Every notifiable occurrence in SmartPRS 3 is a **registry entry** —
`notification_events` — declaring:

```
event_key            stable, namespaced, immutable        e.g. leave.application.submitted
module               owning bounded context
subject_entity       employee | request | payslip | device | tenant | run | none
description          business language, shown in the builder
severity_default     informational | actionable | warning | critical
payload_contract     the typed fields the event guarantees, with data types
                     -- this is what the placeholder catalogue exposes (R-XIX-012)
default_audiences    ordered audience_refs (a starting point, never a constraint)
default_channels     ordered channel list
suggested_template   the starter template key shipped in the seed pack
is_mandatory         0|1  -- see R-XIX-053
allow_digest         0|1
dedupe_scope         the natural key shape for idempotency (R-XIX-049)
is_system            platform-owned; a tenant may subscribe but not delete
```

**R-XIX-038** The catalogue is **platform-owned and versioned**; a tenant may **not**
invent an event key, because an event key that nothing emits is a promise the product
cannot keep. A tenant *may* register a **derived event** — an event fired by a customer
rule via the rule engine's `NOTIFY` action (Part VII), whose payload is the rule's declared
output. That is the extension path, and it is bounded.

**R-XIX-039** The catalogue at v3.0 covers, at minimum, every trigger the brief lists and
every trigger the as-is analysis found. Grouped:

| Group | Events |
|---|---|
| **Employee lifecycle** | `employee.created` · `.updated` (field-scoped) · `.joined` · `.confirmed` · `.probation_extended` · `.transferred` · `.promoted` · `.resigned` · `.notice_started` · `.exited` · `.rehired` · `.archived` · `.document_expiring` · `.document_expired` · `.birthday` · `.work_anniversary` |
| **Self-service** | `ess.profile_change_requested` · `.approved` · `.rejected` · `ess.contact_change_verification_required` · `ess.password_changed` · `ess.login_from_new_device` |
| **Attendance** | `attendance.absent_previous_day` · `.absent_streak` · `.late_arrival` · `.early_exit` · `.missing_punch` · `.regularisation_requested` · `.regularisation_approved` · `.regularisation_rejected` · `.overtime_recorded` · `.shift_changed` · `.roster_published` · `.day_recalculated` |
| **Leave** | `leave.application.submitted` · `.approved` · `.rejected` · `.withdrawn` · `.cancelled` · `.document_required` · `.document_overdue` · `leave.balance.low` · `leave.accrual.posted` · `leave.carry_forward.posted` · `leave.lapse.posted` · `leave.compoff.expiring_soon` · `leave.encashment.settled` |
| **Requests and workflow** | `workflow.step.assigned` · `.reminder_due` · `.sla_breached` · `.escalated` · `.delegated` · `.reassigned` · `.returned_for_edit` · `.decided` · `.cancelled` · `request.submitted` · `.approved` · `.rejected` |
| **Payroll** | `payroll.run.created` · `.preview_ready` · `.exception_raised` · `.submitted_for_approval` · `.approved` · `.finalised` · `.reopened` · `payslip.published` · `payslip.viewed` · `payroll.bank_file_ready` · `payroll.payment_confirmed` · `arrears.posted` |
| **Statutory** | `statutory.filing_due` · `.filing_overdue` · `.challan_generated` · `.return_filed` · `rulepack.available` · `rulepack.activated` · `rulepack.baseline_breach_blocked` · `tax.declaration_window_open` · `.proof_submission_due` · `.proof_rejected` |
| **Loans, advances, commission** | `loan.sanctioned` · `.instalment_due` · `.instalment_missed` · `.closed` · `commission.approved` · `.paid` · `.clawback_raised` · `incentive.published` |
| **Compliance (vertical)** | `credential.expiring` · `.expired` · `compliance.gate_blocked` · `.gate_overridden` · `policy.published` · `policy.acknowledgement_due` · `.acknowledgement_overdue` · `grievance.raised` · `.sla_breached` · `audit_pack.issued` |
| **Recruitment and onboarding** | `candidate.stage_changed` · `offer.issued` · `.accepted` · `.declined` · `.expiring` · `onboarding.task_assigned` · `.overdue` · `.completed` · `document.collection_due` |
| **Configuration governance** | `config.version.submitted_for_approval` · `.published` · `.superseded` · `.rollback_performed` · `rules.published` · `workflow.published` · `template.published` |
| **Platform and account** | `user.invited` · `.activated` · `.disabled` · `role.assigned` · `.revoked` · `apikey.created` · `.rotated` · `.revoked` · `licence.expiring` · `.expired` · `subscription.expiring` · `.grace_started` · `.suspended` · `.renewed` · `seat_limit.approaching` · `.reached` |
| **Operations health** (§XIX.7) | `ops.scheduler.heartbeat_missed` · `ops.queue.depth_exceeded` · `ops.queue.oldest_job_aged` · `ops.job.failed` · `ops.mail_queue.stalled` · `ops.webhook_queue.stalled` · `ops.biometric.sync_stale` · `ops.device.offline` · `ops.backup.failed` · `ops.backup.stale` · `ops.restore_test.overdue` · `ops.storage.low` · `ops.certificate.expiring` · `ops.audit.chain_break_detected` · `ops.error_rate.exceeded` · `ops.integration.credential_expiring` |

**R-XIX-040** Every event emitted anywhere in the product is emitted through the domain
event bus and **must** appear in this catalogue. *Test:* a CI sweep compares the set of
emitted event keys across all modules against the catalogue and fails on either direction —
an emitted event missing from the catalogue, or a catalogue entry nothing emits.

**R-XIX-041** **Leave submission notifies its approver by default.** Listed explicitly
because SmartPRS 2 sends nothing at all on leave submission, while sending on request
submission, and the omission is plainly unintentional (`E-…` §7 Q7 row 3, PO-19).

### XIX.3.2 The subscription model

A subscription is the join that replaces the hard-coded call site.

**R-XIX-042** A **subscription** is a spine S4 configuration artefact binding:

```
event × audience × channel × template × timing × conditions × priority
```

```
notification_subscription_version
  event_key, name, is_active
  scope_json, specificity_weight, priority          -- S3.1/S3.2, resolved per subject
  audience_ref[]        ordered; each an audience definition (§XIX.3.3)
  channel_plan          ordered channels with a fallback policy (§XIX.3.4)
  template_key          per channel, or one key whose version carries all channel bodies
  condition_spx         Boolean over the event payload and the resolved subject
  timing                immediate | delay(duration) | at(local_time) | digest(schedule)
                        | ladder([offsets])
  quiet_hours_policy    honour | override            -- override requires `is_mandatory`
  dedupe_key_expr       SPX Text expression (defaults to the event's dedupe_scope)
  rate_limit_ref        (§XIX.3.7)
  escalation_ref        (§XIX.7.3)
  language_policy       recipient | subject | tenant_default
  severity              overrides the event default
  retention_class       for the resulting delivery rows
```

**R-XIX-043** **Multiple subscriptions may match one event and all of them fire.**
Precedence (S3.2) selects the *template and channel plan* within a subscription key, not
the set of subscriptions. Two subscriptions for `leave.application.approved` — one to the
employee, one to the payroll inbox — are the normal case, not a conflict.

**R-XIX-044** A subscription may not be activated if the resolved template does not exist
for every channel in its plan, in every language its policy can reach, for every scope it
covers, or if a channel in the plan **has no configured adapter for the tenant**.
*This is the direct fix for a seventeen-template WhatsApp library with no plumbing
(`E-…` NEW-NOT-114): in SmartPRS 3 a channel that cannot send cannot be subscribed.*

**R-XIX-045** Subscriptions are **seeded** per tenant from an industry starter pack at
provisioning, so a new tenant communicates sensibly on day one, and every seeded
subscription is a normal editable artefact with no special status.
*This exists because SmartPRS 2 provisioning ends at "a tenant exists with one company and
one admin" and everything a Product Owner would call implementation is manual
(`I-…` §, provisioning narrative).*

**R-XIX-046** A subscription carries a **business description** and appears in a single
screen — "who gets told what, when" — filterable by event, audience, channel and module.
An administrator can answer "will this employee be emailed when their leave is approved?"
without reading code.

### XIX.3.3 Audience resolution

**R-XIX-047** An **audience is a rule**, never a literal list. The audience catalogue is
platform-owned and closed at the *kind* level; instances are tenant configuration.

| Kind | Resolves to | Parameters |
|---|---|---|
| `subject_employee` | the employee the event is about | — |
| `actor` | the user who caused the event | — |
| `requester` | the submitter of the request | — |
| `current_approver` | the holder of the active workflow step | — |
| `reporting_manager` | the subject's manager **as at the event date** | `level` (1..n), `include_dotted_line` |
| `team_leader` | the subject's TL assignment | — |
| `department_head` | head of the subject's department as at the event date | — |
| `hr_business_partner` | the HR user mapped to the subject's org unit | — |
| `role_in_scope` | holders of a named role **within a resolved org scope** | `role`, `scope_source` (subject's branch / department / company / tenant) |
| `org_unit_members` | everyone in a named org unit | `unit`, `include_descendants` |
| `distribution_list` | a named, maintained list of internal users and external addresses | `list_key` |
| `watchers` | users who subscribed to this specific record | — |
| `spx_selector` | employees matching an SPX Boolean over the employee context | `expression`, `max_size` |
| `external_contact` | a declared external party (a client bank, an auditor, a statutory portal contact) | `contact_key` |
| `escalation_target` | the target of the subscription's escalation ladder at step N | `step` |

**R-XIX-048** Audience resolution obeys five rules:

1. **As-at date.** Relationships resolve on the **event date**, not on today. A transfer
   approved yesterday does not redirect a notification about last month's payroll.
   *SmartPRS 2 cannot do this at all — it has no effective-dated org assignment (spine S3).*
2. **Failure is loud.** An audience that resolves to **nobody** raises
   `notification.audience_empty` against the subscription, visible on the operations
   dashboard, with the subject named. *SmartPRS 2 creates requests with a null approver,
   sends no email, and leaves them invisible to every inbox
   (`RequestController.php:571`; the inbox filter `approver_id = $myId ?? -1` can never
   match) — the "silent black hole" of `E-…` PO-16.*
3. **A declared fallback exists.** Every audience of kind `current_approver`,
   `reporting_manager`, `team_leader`, `department_head` or `hr_business_partner` declares
   a fallback audience used when resolution is empty.
4. **Size is bounded.** `spx_selector` and `org_unit_members` declare a `max_size`.
   Exceeding it blocks the send and raises an operator condition rather than fanning out.
5. **Permission is honoured.** A recipient receives only the fields the resolved masking
   allows for their role and relationship (R-XIX-033). The **same event may render two
   different bodies to two audiences**, and does.

**R-XIX-049** Every resolved audience member is de-duplicated **per message** across
audiences, so an employee who is both `subject_employee` and `watchers` receives one
message, not two.

### XIX.3.4 Channels, preference and fallback

**R-XIX-050** Channel selection is **per event, per subscription, per tenant**, expressed
as an ordered `channel_plan` with a declared fallback policy:

| Policy | Behaviour |
|---|---|
| `all` | send on every channel in the plan |
| `first_available` | send on the first channel with a configured adapter and a reachable address |
| `escalating` | send on channel 1; if not acknowledged within a declared window, send on channel 2 |
| `fallback_on_failure` | send on channel 1; on permanent failure, send on channel 2 |

**R-XIX-051** **Reachability is checked before despatch, not after.** A missing email
address, an unverified mobile number, an absent push token, a disabled in-app account each
produce a delivery row with status `unreachable` and a reason — not a silent skip.
*SmartPRS 2 skips the approver email entirely when the approver has no email address, with
no record (`RequestController.php:571`; `E-…` §7 Q7).*

**R-XIX-052** **Per-user preferences exist** and are honoured. A user may choose, per
notification **class** (not per event, which would be unusable):

```
class            e.g. my_approvals, my_leave, my_payroll, my_attendance,
                 team_activity, announcements, celebrations, system
per class:       channel opt-in/opt-out, digest vs immediate, quiet-hours behaviour
global:          preferred language, preferred timezone, working-hours window
```

*This exists because SmartPRS 2 has no preference store of any kind and no unsubscribe
link anywhere (`E-…` BR-NOT-024) — a DPDP-adjacent gap and a basic expectation.*

**R-XIX-053** **Mandatory classes cannot be opted out of.** An event declared
`is_mandatory` — a statutory notice, a payslip publication, a disciplinary communication,
a security event on the user's own account, a legally required acknowledgement — ignores
preference and quiet hours. The set of mandatory events is **platform-declared and
tenant-visible**, so a customer can see exactly what an employee cannot switch off.

**R-XIX-054** Every non-mandatory email carries a working unsubscribe that lands on the
preference centre, and the unsubscribe token is scoped to the user and the class, signed,
and expiring.

**R-XIX-055** **In-app is always available** and is never suppressed by preference — it is
the durable record the employee can return to. Preference governs *push and email* about
an in-app item, not the item itself.

**R-XIX-056** A tenant may **disable a channel entirely**, and may **restrict a channel to
named classes** (for example: WhatsApp for payslip-ready and OTP only). A disabled channel
fails subscription activation (R-XIX-044) rather than silently doing nothing.

**R-XIX-057** **Phone normalisation is configuration.** Each tenant declares a default
country calling code and a normalisation policy; numbers are stored and transmitted in
E.164; a number that cannot be normalised to a valid E.164 form is `unreachable` with a
reason and is surfaced in a data-quality report. *This exists because SmartPRS 2 takes the
last ten digits and prefixes the literal `'+91'` (`WaService.php:116-117, 148`), silently
misrouting every non-Indian number.*

### XIX.3.5 Timing, quiet hours and tenant-local scheduling

**R-XIX-058** **All scheduling is evaluated in the tenant's timezone**, which is a required
tenant attribute, with per-company and per-branch overrides where the customer operates
across zones. *This exists because SmartPRS 2 evaluates `send_hour` against the server's
hour (`SendGreetings.php:40`) while its own docblock at `:11-13` claims each company fires
at its own local time — a stated conflict between documentation and code
(`E-…` NEW-NOT-106).*

**R-XIX-059** Timing modes:

| Mode | Semantics |
|---|---|
| `immediate` | despatched on the next outbox relay pass |
| `delay(duration)` | despatched after a declared delay from the event |
| `at(local_time)` | despatched at a declared local time on the event date or the next occurrence |
| `ladder([offsets])` | despatched at each declared offset relative to a due date — e.g. `[-30d, -15d, -7d, -1d, 0d, +1d, +7d]` |
| `digest(schedule)` | accumulated into a digest and despatched on the digest's schedule |

**R-XIX-060** **A ladder offset is matched with catch-up semantics, not exact-day
equality.** If a scheduled pass is missed, the next pass fires every offset that has become
due since the last successful pass, deduplicated on the ledger key, and each message states
the date it was due. *This exists because SmartPRS 2 matches milestones with
`in_array($days, [...], true)` in four separate services —
`ProbationService.php:190`, `SubscriptionAlerts.php:56`, `GreetingService.php:145`,
`AbsenceService.php:105` — so a missed run loses the reminder permanently, including the
"expires today" and "completes today" steps (`L-…` DEF-L-05).*

**R-XIX-061** A ladder for a **catch-up window longer than a declared threshold** collapses
to a single "you have N overdue items" message rather than a burst. *This exists because
starting a SmartPRS 2 worker after a long outage drains the whole backlog at once, sending
months of stale notices — expired subscription warnings, greetings for birthdays long past
— with no age check, no `retryUntil()` and no uniqueness constraint on either job
(`L-…` §Q9D).*

**R-XIX-062** **Quiet hours** are declared per tenant (with per-employee override within
tenant-declared bounds) as a local-time window and a weekly-off/holiday policy. A message
arriving in quiet hours is **held and released at the window's end**, not dropped, unless
its event is `is_mandatory` or its severity is `critical`.

**R-XIX-063** **Digests** are first-class. A digest declares its schedule, its grouping,
its ordering, its **size cap with an explicit "and N more" overflow line and a deep link**,
and its empty behaviour (`suppress` or `send_empty`). *This exists because SmartPRS 2's
compliance digest silently shows the top 25 items with no indication that 275 others exist
(`ComplianceController.php:206`; `L-…` §Q8 item 3).*

### XIX.3.6 Deduplication and idempotency

**R-XIX-064** Every message carries a **dedupe key** derived from the subscription's
`dedupe_key_expr`, defaulting to the event's declared `dedupe_scope`. The pair
`(tenant_id, dedupe_key)` is **uniquely constrained in the database**, so idempotency is a
constraint, not a pre-check. *SmartPRS 2's `alreadySent()` returns `false` on exception —
it fails towards **re-sending** (`NotificationService.php:142-160`; `E-…` BR-NOT-003).*

**R-XIX-065** A **suppressed duplicate and a failed send are distinct outcomes** with
distinct statuses, distinguishable by the caller and visible in the ledger. *SmartPRS 2's
`NotificationService::send()` returns `null` for both (`L-…` DEF-L-12).*

**R-XIX-066** Dedupe keys are constructed from the **business event**, not from the
calendar. A yearly greeting keying on `(employee, occasion, year)` is correct for
greetings and is **not** the general rule. *(`L-…` requirement 30.)*

### XIX.3.7 Rate limiting and throttling

**R-XIX-067** Four independent limits apply, all configuration, all with declared
behaviour on breach:

| Limit | Scope | On breach |
|---|---|---|
| Per recipient, per class, per window | e.g. 5 attendance notices per employee per day | collapse into a digest |
| Per event, per subject, per window | e.g. one late-arrival notice per employee-day | suppress as duplicate |
| Per tenant, per channel, per window | provider quota protection | queue and smooth; raise `ops.rate_limit_reached` when the backlog exceeds a threshold |
| Per provider account | the provider's own published limit | adapter-level token bucket; never exceed |

**R-XIX-068** Bulk sends are **always asynchronous, batched and rate-smoothed**, never
inline. *This exists because a SmartPRS 2 broadcast makes one fifteen-second-timeout HTTP
call per recipient inside the HTTP request, so a 500-employee WhatsApp broadcast can
occupy the PHP worker for roughly two hours (`SendMessageController.php:72-95`;
`E-…` NEW-NOT-101).*

**R-XIX-069** A broadcast is a **campaign object** with an audience definition, a scheduled
window, a preview of the resolved recipient count, a per-recipient delivery ledger, a
cancel action while in flight, and a maximum body length. *SmartPRS 2's broadcast body has
no maximum length (`SendMessageController.php:27`) and only the log entry is truncated, to
200 characters.*

### XIX.3.8 Suppression

**R-XIX-070** Declared suppression conditions, all configuration per subscription:
employee on long leave, on notice period, exited, inactive, with an open regularisation for
the same day, or with an open request of the same type. Suppression is **recorded** as a
delivery row with status `suppressed` and the reason — never as silence.

---

## XIX.4 Delivery guarantees

This section is the direct replacement for a fabricated delivery audit and is the part of
this specification that most needs to be read literally.

### XIX.4.1 The outbox pattern

**R-XIX-071** When a domain transaction raises a notifiable event, the **outbox row is
written inside that same database transaction**. The relay reads the outbox and publishes
to the engine. Consequences, stated as guarantees:

- If the business transaction rolls back, no notification exists. *No orphan message.*
- If the business transaction commits, the notification is durably scheduled even if every
  downstream component is down at that instant. *No lost notification.*
- Delivery is **at-least-once**; the dedupe constraint (R-XIX-064) makes it
  effectively-once at the recipient.

**R-XIX-072** The relay is **idempotent and restartable**, tracks its position, and is
itself a monitored health signal (`ops.outbox.relay_lagging`).

**R-XIX-073** No domain path may call the notification engine synchronously in a way that
can fail the business transaction. A notification failure never rolls back a business
write. *This preserves the one place where SmartPRS 2's best-effort instinct is correct
(`E-…` BR-NOT-001, and Part IX §IX.9's notify-node exit condition) — but unlike SmartPRS 2
the failure is recorded and surfaced, not swallowed.*

### XIX.4.2 The delivery state model

**R-XIX-074** Delivery status is a **closed value set** with exactly these states and
exactly these transitions:

```
pending ──▶ queued ──▶ dispatching ──▶ accepted ──▶ delivered ──▶ read
   │           │            │              │             │
   │           │            └──▶ failed_transient ──▶ queued   (retry, bounded)
   │           │                       └──▶ failed_permanent ──▶ dead_lettered
   │           │
   │           └──▶ cancelled            (campaign cancelled, subject deleted)
   │
   ├──▶ suppressed        (preference, quiet hours held then expired, suppression rule)
   ├──▶ duplicate         (dedupe key already present)
   ├──▶ unreachable       (no address / unverified / no token)
   └──▶ blocked           (no adapter configured, or the channel is disabled)
```

**R-XIX-075** **`accepted` is written only on provider acknowledgement**, and carries the
provider's own message reference and the acknowledgement timestamp. `delivered` and `read`
are written only from a provider callback or a poll of the provider's status API. **No
state after `queued` may be written by the enqueuing code.**

> This is the single most important requirement in this part. *SmartPRS 2's
> `MailService::queue()` returns the `mail_log` id at insertion, before any delivery
> attempt (`MailService.php:119`), and `SubscriptionAlerts.php:107-108` records
> `subscription_alerts.status = 'sent'` on the strength of it. **SmartPRS 3 does not
> replicate this. The audit trail of a message is a record of what a transport
> acknowledged, never a record of what the application intended.***

**R-XIX-076** The word "sent" does not appear in the state vocabulary, in the UI, or in any
API response, precisely because it is the word SmartPRS 2 used ambiguously. The UI shows
`Queued`, `Accepted by provider`, `Delivered`, `Read`, `Failed`, `Suppressed`, `Duplicate`,
`Unreachable`, `Blocked`, `Dead-lettered`.

**R-XIX-077** **One delivery row per recipient per channel per message.** There is no
aggregate row. A failed SMS to one employee is findable, retryable and provable.
*SmartPRS 2 writes one aggregate `messages_log` row per broadcast with a recipient count
and no per-recipient outcome (`SendMessageController.php:109-125`;
`E-…` NEW-NOT-103).*

**R-XIX-078** A message that cannot be despatched because **no transport is configured**
produces status `blocked` **and** raises `ops.channel.not_configured` against the tenant.
It is an operator-visible condition, never a silent success. *SmartPRS 2 marks it
`skipped` and returns without throwing; the job succeeds having sent nothing, no
`failed_jobs` row is written, and nothing alerts (`MailService.php:148-152`;
`L-…` DEF-L-11, requirement 29).*

### XIX.4.3 Provider adapters

**R-XIX-079** Every channel reaches the outside world through a **provider adapter**
implementing one contract. A new provider is a new adapter, never a change to the engine.

```
interface ChannelProvider {
    channel(): Channel
    capabilities(): Capability[]      // send | bulk | template_registry | status_callback
                                      // | status_poll | media | scheduling | opt_out
    validateConfig(config): Diagnostics
    test(config): Diagnostics          // connectivity only; never a business send
    normaliseAddress(raw, policy): Address|Unreachable
    send(RenderedMessage, Address): ProviderAck|ProviderFailure
    sendBatch(RenderedMessage[], Address[]): ProviderAck[]
    parseCallback(signedRequest): DeliveryEvent[]
    pollStatus(providerRef[]): DeliveryEvent[]
    limits(): RateLimits
    health(): ProviderHealth
}
```

**R-XIX-080** A `ProviderFailure` is **classified** by the adapter as `transient`
(retryable) or `permanent` (dead-letter), with the provider's own code and message
recorded verbatim in a structured field. Classification is adapter data, not engine code,
because providers differ.

**R-XIX-081** **The credential travels in exactly one declared position**, defined by the
adapter's payload contract. *This exists because SmartPRS 2 posts the API key under three
field names simultaneously — `apikey`, `authkey`, `api_key` — to whatever host an
administrator typed (`SendMessageController::postProvider():234-266`; `E-…` SEC-12).*

**R-XIX-082** **Egress is restricted by scheme and host allow-list** (spine S17), blocking
loopback, link-local and private ranges unless a LAN connector explicitly permits them. An
administrator cannot direct a credential at an arbitrary host.

**R-XIX-083** **Provider credentials are encrypted at the application layer, are
write-only through the API, and are never returned in any read** (spine S13). *SmartPRS 2
stores `wa_settings.api_key` and `sms_settings.api_key` as plain `TEXT`
(`…000600…:141, :154`; `E-…` SEC-14), in a codebase that encrypts other secrets.*

**R-XIX-084** Adapters shipped at v3.0, each with the same ledger and the same contract:
SMTP (per-company identity with a platform fallback — the one part of SmartPRS 2's mail
design that is correct, `MailService::deliver():131-201`), a transactional email API
provider, a WhatsApp Business API provider, an SMS provider with DLT fields, push
(APNs/FCM), in-app, and a generic **connector recipe** for REST endpoints that need no PHP
at all (spine S10.1's pattern, applied to messaging).

### XIX.4.4 Retry, backoff and dead-letter

**R-XIX-085** Retry policy is **per channel and per failure class**, and is configuration:
maximum attempts, backoff base and multiplier, jitter, maximum age, and the dead-letter
destination. *SmartPRS 2 hard-codes `tries=3` / `backoff=30` for one job and `2`/`30` for
another (`SendNotification.php:33-34`; `NotifyLateArrivals.php:35-36`), WhatsApp has **no
retry at all** — one synchronous shot (`E-…` BR-NOT-008) — and one job's declared
`tries`/`backoff` are dead configuration because it swallows its own exceptions
(`L-…` DEF-L-10).*

**R-XIX-086** A job that declares a retry policy **must not swallow its own exceptions**.
*Test:* static analysis fails the build on a `catch` inside a queued job class that neither
rethrows nor calls `fail()`.

**R-XIX-087** Every time-sensitive message declares a **maximum age**. A message older than
its maximum age is dead-lettered with reason `expired`, not delivered. *(`L-…`
requirement 40.)*

**R-XIX-088** **Dead-lettered messages are visible, searchable, explainable and
re-drivable** from the operations dashboard, with the failure class, the provider's own
error and the full render context. A dead-letter event raises `ops.dead_letter.raised`.

**R-XIX-089** Work is routed to **named queues by class** — `interactive`, `bulk`,
`integration`, `reporting`, `alerts` — declared per subscription, never to one queue named
`mail`. *This exists because SmartPRS 2 dispatches every notification email to a queue
literally named `'mail'` (`SendNotification.php:41`), and a worker started as plain
`php artisan queue:work` never drains it — nothing enforces `--queue=mail`
(`E-…` BR-NOT-005).*

**R-XIX-090** **Credential-class messages** — first-login credentials, password reset, OTP,
MFA — have a **guaranteed delivery path that does not depend on a worker being installed**,
with retry. *SmartPRS 2's `'sync' => true` inline bypass (`MailService.php:107-116`) is
correct in intent — it was added in response to a real production incident recorded in the
comment at `:102-106` — but it has no retry: a failure logs a warning and is never
attempted again (`L-…` requirement 32).*

### XIX.4.5 Inbound delivery callbacks

**R-XIX-091** Every inbound provider callback is authenticated, **signature-verified with
HMAC over the raw body plus a timestamp and a nonce**, throttled, tenant-scoped and
**schema-parsed** — never substring-scanned. Replay outside the freshness window is
rejected. Every callback is logged with its verdict. *This exists because SmartPRS 2's
Interakt webhook is unauthenticated, unsigned, unthrottled and cross-tenant, infers status
by checking whether any of `"read"`, `"delivered"`, `"sent"` or `"failed"` appears anywhere
in the serialised JSON, swallows every exception, and always answers `{"ok":true}`
(`routes/web.php:198-204`; `RecruitMessagingController::interaktWebhook():380-417`;
`E-…` SEC-04).*

**R-XIX-092** Status transitions from callbacks are **monotonic**: a later callback may
only advance the state (`accepted → delivered → read`), never regress it. Out-of-order
callbacks are recorded and ignored for state purposes.

**R-XIX-093** Where a provider offers no callback, the adapter **polls** on a declared
schedule until a terminal state or the maximum age is reached.

### XIX.4.6 Failure surfacing

**R-XIX-094** Every failure in this engine is **recorded and surfaced**. Best-effort
delivery is retained — a notification failure never blocks a business action — but
best-effort **reporting** is abolished. Specifically:

| Failure | Recorded as | Surfaced |
|---|---|---|
| Render failure | delivery row `failed_permanent`, reason `render_error`, with the placeholder or expression that failed | ops dashboard + `ops.render_failed` |
| Audience empty | subscription condition, subject named | ops dashboard + `notification.audience_empty` |
| Adapter absent | `blocked` | ops dashboard + `ops.channel.not_configured` |
| Provider rejection | `failed_permanent` with the provider's code verbatim | delivery ledger + digest |
| Repeated transient failure | `dead_lettered` | `ops.dead_letter.raised` |
| Outbox relay lag | — | `ops.outbox.relay_lagging` |

**R-XIX-095** **Authorisation on every messaging surface fails closed** (spine Law 5) —
composing, sending, broadcasting, reading a delivery ledger, reading a template, reading
provider configuration. *This exists because `denyUnlessRole()` returns "allowed" from its
`catch` across 71 call sites, the broadcast screen among them
(`ApprovalService.php:365-380`; `SendMessageController.php:19`), and because
`GET /app/greetings/config` and `/log` have no role guard at all
(`GreetingController.php:25-41, 169-188`; `E-…` SEC-13).*

---

## XIX.5 The Email/Template Builder *(brief Section 60)*

**R-XIX-096** The builder is a **single screen serving every family**, differing only in
the channel bodies it presents and the constraints it enforces.

| Capability | Behaviour |
|---|---|
| **Create** | from blank, from a starter pack template, or by cloning an existing template |
| **Clone** | into the same tenant (new key) or into a different scope (same key, new scope) |
| **Edit** | edits a **draft version only**; a published version is immutable (R-XIX-006) |
| **Variable picker** | the derived catalogue (R-XIX-017), grouped, searchable, type-annotated, with a live sample value and the masking that will apply |
| **Blocks** | insert a reusable header, footer, signature or grievance-officer block by reference |
| **Conditions** | a visual condition builder over the event payload, with the SPX source view alongside (the same dual surface as the rule builder, Part VII) |
| **Preview** | three modes (R-XIX-028), side by side across languages and scopes |
| **Test send** | through the real adapter, `is_test = 1`, audited (R-XIX-029) |
| **Validate** | the full publish-time gate, runnable on demand from the draft (R-XIX-030) |
| **Assertions** | store expected outputs for named sample data; publishing runs them |
| **Diff** | version-to-version diff of source, rendered output and variable manifest |
| **Impact preview** | which subscriptions use this template, which scopes, how many employees, how many messages in the last 90 days |
| **Publish** | with effective date, reason, change note; maker-checker where declared `high_risk` |
| **Activate / deactivate** | a subscription-level action, distinct from publishing a template |
| **Version history** | every version, its effective range, who published it, why, and the assertions it passed |
| **Rollback** | creates a **new** version copying an earlier payload (spine S4, Part VI R-VI-013) — never resurrects a superseded row |

**R-XIX-097** **Documented variables are a product deliverable, not a wiki page.** The
builder's variable panel, the API's `GET /notifications/variables` and the generated
documentation all read the same derived catalogue. There is one source.

**R-XIX-098** Per-scope variants are created and managed **from within one template's
screen** — "this template, but for the Mumbai branch" — with the resulting specificity and
precedence shown explicitly, and with the ambiguity check run before the variant can be
published (spine S3.2).

**R-XIX-099** Starter templates shipped by Ametecs are **forked on edit**, keeping a clear
"original vs edited" state, so a pack update never overwrites a customer's edit and never
leaks one customer's edit to another. *(`H-…` requirement 58; SmartPRS 2's `kb_topics` is
deduplicated by title across the entire installation and always written with
`tenant_id = NULL` — NEW-SEED-101.)*

**R-XIX-100** The builder is **role-gated on read as well as write**. *Explicit because
SmartPRS 2 guards `saveConfig` and `test` but leaves `GET /app/greetings/config` and
`GET /app/greetings/log` open to any authenticated user, disclosing template content and
which employee had a birthday on which date (`GreetingController.php:25-41, 169-188`).*

---

## XIX.6 Secrets and PII in message logs

**R-XIX-101** The following are **prohibited** from every message body, every template
source, every delivery-ledger column, every render context persisted for debugging, and
every log line the engine writes:

| Prohibited | Instead |
|---|---|
| Passwords, temporary passwords, first-login credentials | a signed, single-use, expiring **set-password link** |
| OTPs and MFA codes | recorded as **type and outcome only**; the value is never persisted anywhere after despatch |
| API keys, tokens, provider secrets, webhook secrets | never rendered into a message; never logged |
| Full Aadhaar, PAN, bank account, IFSC-plus-account pairs, biometric templates | masked form in the body; the full value only in an authorised document behind a signed link |
| Full salary detail in a push or SMS body | a notification that the payslip is available, plus a deep link |
| Session identifiers, CSRF tokens, signed URL signatures | never |

> *This exists because `wa_log.body_values` stores the full JSON variable array for every
> WhatsApp send (`WaService.php:171`), and the `smartprs_welcome` template's fifth variable
> is literally `Temporary password: {{5}}` (`WaTemplateController.php:103-104`), written in
> clear and retained forever because no retention exists for any log table
> (`E-…` SEC-01/NEW-NOT-116, NEW-NOT-117; `L-…` SEC-L-03). SmartPRS 3 does not replicate
> this.*

**R-XIX-102** Prohibition is enforced at **three** points, because one is not enough:

1. **Publish time.** A template whose manifest includes a variable of PII class `secret`,
   or a variable whose masking profile is `never_transmit`, fails validation (R-XIX-030
   item 10).
2. **Render time.** The renderer refuses to bind a `secret`-class value; the attempt is a
   render failure, recorded and alerted, not a silent blank.
3. **Persist time.** A **redactor runs before the delivery row is written**, not after,
   applying declared patterns per field. The stored render context holds variable **names
   and classes**, plus values only for classes declared `loggable`.

**R-XIX-103** The delivery ledger stores: recipient reference (user or employee id, not a
free-text name), the resolved address in **masked form** plus a keyed hash of the full
address for matching, template and version ids, the variable manifest hash, the channel,
the provider reference, the status timeline, the provider's error, and the retry count.
**It does not store the rendered body by default.**

**R-XIX-104** **Body retention is a declared, per-class, per-tenant choice** with three
settings, defaulting to the middle one:

| Setting | Stores |
|---|---|
| `none` | no body, no variable values |
| `hash_only` (default) | a content hash, so "was this the message we sent?" is answerable without holding the content |
| `full` | the rendered body, for a declared retention period, for classes the tenant explicitly nominates (typically statutory and disciplinary correspondence) |

**R-XIX-105** **Every log-shaped table in this part has a declared retention class and an
enforced, scheduled pruning job**, with the pruning itself audited and its last run visible
in the operations dashboard. *This exists because seven SmartPRS 2 tables —
`activity_logs`, `notification_log`, `mail_log`, `wa_log`, `commission_logs`,
`messages_log`, `late_notifications` — grow forever, against a **published Privacy Policy
that commits to 90-day retention** (`PolicyController.php:12`), and the one clean command
that exists applies to an unused table and is not scheduled (`E-…` NEW-NOT-117, SEC-18).*

**R-XIX-106** Retention defaults are **configuration with a platform floor**, and the
shipped defaults are reconciled with the published privacy commitment before release.
**PO-XIX-06** records the values to be set.

**R-XIX-107** Exception messages and provider errors written to logs pass through a
**redactor** before they are written. *This exists because roughly sixty SmartPRS 2 call
sites interpolate `$e->getMessage()` into a log line, so a failed insert on `employees`
writes that row's PAN, bank account, salary and date of birth into the log file
(`L-…` §Q6 items 8–9, requirement 51) — and the error digest then emails those exact lines
off the customer's premises daily (`ErrorDigest.php:76-93`; `L-…` SEC-L-09).*

---

## XIX.7 Operational alerting *(brief Sections 92 and 93)*

### XIX.7.1 Health signals are events

**R-XIX-108** Every operational health condition in spine S18 is an **ordinary registered
event** in the catalogue of §XIX.3.1, with a configurable audience, channel plan, template,
threshold, timing and escalation. There is no separate alerting mechanism, no log-file
grep, and no privileged code path.

**R-XIX-109** A health signal is produced by a **declared check**, not by scraping a log
file. Each check declares its measurement, its evaluation interval, its warning and
critical thresholds (both **configuration**), its recovery condition and its owner.

| Check | Measurement | Default severity ladder | Notes |
|---|---|---|---|
| `scheduler.heartbeat` | age of the last completed scheduler pass | warn / critical by age | **The keystone signal.** |
| `queue.depth` | pending jobs per named queue | warn / critical by count | per queue, so `alerts` and `bulk` differ |
| `queue.oldest_job_age` | age of the oldest pending job per queue | warn / critical by age | catches a stalled worker a depth check misses |
| `worker.liveness` | last heartbeat per worker per queue | critical on absence | a worker consuming the wrong queues is detected |
| `job.failures` | failed jobs in a window, by class | warn / critical by rate | every job implements `failed()` |
| `mail_queue.stalled` | oldest `queued` delivery on the email channel | warn / critical by age | channel-specific |
| `webhook_queue.stalled` | oldest undelivered outbound webhook, plus DLQ depth | warn / critical | spine S17 |
| `outbox.relay_lag` | age of the oldest unrelayed outbox row | warn / critical | §XIX.4.1 |
| `biometric.sync_freshness` | age of the last successful sync per device and per tenant | warn / critical by age | *SmartPRS 2 stamps `last_sync_at` and nothing alerts on staleness; an operator must remember to open a screen and notice a date (`L-…` §Q4)* |
| `device.offline` | last contact per device | warn / critical | Part XI |
| `backup.last_success` | age and size of the newest verified backup | warn / critical by age | *SmartPRS 2 emails backup failures to nobody (`config/backup.php` omits `notifications`; `backup:monitor` is not scheduled)* |
| `backup.offbox_destination` | presence of at least one off-box destination | **persistent warning while absent** | spine S18; `config/backup.php:72-74` is local-disk only |
| `restore_test.last_success` | age of the last successful restore-to-scratch test | warn / critical by age | spine S18; SmartPRS 2 has **no restore path of any kind** |
| `licence.expiry` / `subscription.expiry` | days remaining | configurable ladder | §XIX.7.4 |
| `rulepack.expiry` | days until the active statutory pack's `effective_to` | warn / critical | Part XVII |
| `audit.chain_verification` | result of the incremental verify (Part XXIII) | **critical, non-suppressible** | §XIX.7.5 |
| `error_rate` | typed application errors per window, by module and severity | warn / critical | **typed events, not log greps** |
| `integration.credential_expiry` | days until a stored integration credential expires | warn | |
| `storage.headroom` | free space on the document and backup volumes | warn / critical | |
| `certificate.expiry` | TLS and signing certificate expiry | warn / critical | |
| `migration.state` | pending migrations on a running application | critical | spine S18 |

**R-XIX-110** **Thresholds, severities, audiences, channels and escalation are
configuration** for every check. *This exists because SmartPRS 2's only alerting mechanism
hard-codes its severity set (`['ERROR','CRITICAL','ALERT','EMERGENCY']`,
`ErrorDigest.php:48`), its detail volume (10 hits, 240 characters, `:76, :79`), its scan
window (2 MB / 4000 lines, `:46, :108`) and its schedule (07:45,
`routes/console.php:167`), and exposes only `--days` (`L-…` §Q8 items 30–33,
requirement 54).*

**R-XIX-111** **The error-rate signal is derived from typed application errors**, not from
scanning a text log. Every domain error is a structured event carrying module, severity,
tenant, correlation id and actor. *This exists because SmartPRS 2's digest scans a log file
for `ERROR|CRITICAL|ALERT|EMERGENCY` while every scheduled service logs at `warning` and
production sets `LOG_LEVEL=warning` — the warnings are written and never reported
(`ErrorDigest.php:48`; `.env.production.example:24`; `L-…` DEF-L-06) — and because the
substring match `str_contains($line, $lvl.':')` over-counts any business string containing
"ERROR:" (`ErrorDigest.php:62`).*

### XIX.7.2 The out-of-band rule

**R-XIX-112** **An alert path may not depend on the subsystem whose failure it reports.**
Concretely:

1. Every tenant and the platform declare **at least one out-of-band alert channel** that
   does not traverse the queue or the outbox — a direct SMTP send on a dedicated
   connection, an outbound webhook to a monitoring endpoint, or an SMS through a dedicated
   adapter. It is exercised on a schedule and its own success is a health signal.
2. `ops.scheduler.heartbeat_missed`, `ops.queue.*` and `ops.worker.*` alerts are despatched
   **only** on out-of-band channels.
3. The scheduler emits a **positive heartbeat** to an external endpoint on every pass, so
   the absence of a heartbeat is detectable **from outside the installation**. A system
   cannot reliably report its own silence.
4. The health endpoint reports **component-level status** — scheduler last-run, worker
   liveness, queue depth and age, integration freshness, backup age, migration state — not
   merely that the framework booted.

> *This exists because SmartPRS 2's error digest is delivered through the queue whose
> failure it cannot detect (`ErrorDigest.php:82` → `MailService::queue` →
> `SendNotification::dispatch`; `L-…` DEF-L-07), returns SUCCESS having sent nothing when
> `SMARTPRS_OPS_EMAIL` is unset (`:25-30`), and has no `try/catch` at all — an unguarded
> `filesize`/`fopen`/`fseek`/`fread` at `:105-112` means a permissions problem aborts the
> task and the error goes to a discarded stream. The failure of the scheduler silences the
> only thing that could have reported the failure of the scheduler (`L-…` §Q9A).*

**R-XIX-113** The installer **refuses to complete** without a working scheduler and queue
worker (spine S18), registers both, and the application detects and reports their absence
thereafter. *(`L-…` requirement 64 — "the single highest-leverage change in this area".)*

**R-XIX-114** Deployment restarts workers, and a worker running older code than the
application is a health signal. *(`queue:restart` appears nowhere in SmartPRS 2,
`L-…` requirement 65.)*

### XIX.7.3 Escalation

**R-XIX-115** Every health event may declare an **escalation ladder**: ordered steps, each
with a delay, an audience, a channel plan and an optional acknowledgement requirement.
Escalation stops on acknowledgement or on the recovery condition. Both acknowledgement and
recovery are recorded.

```
ops.backup.failed
  step 0  immediate   → role_in_scope(it_administrator, tenant)      email + in_app
  step 1  +2h unack   → distribution_list(ops_oncall)                email + sms
  step 2  +6h unack   → role_in_scope(tenant_owner, tenant)          email
                       + external_contact(ametecs_platform_ops)      webhook
  recovery: a verified backup newer than the threshold exists
```

**R-XIX-116** Escalation is a **scheduled evaluation, not a human's memory**, and a missed
escalation pass catches up under R-XIX-060.

**R-XIX-117** **Platform-visible and tenant-visible health are separated.** An on-premise
customer's operator sees their own installation's health; Ametecs sees SaaS health and,
where a support agreement permits, a **hash-and-status-only** feed from an on-premise
installation. No message content and no employee data ever leaves an installation through
a health feed. *This exists because SmartPRS 2's one alerting path emails the ten most
recent raw log lines — precisely the lines carrying exception text and SQL bound values —
to an address that is not necessarily the customer's (`ErrorDigest.php:76-93`;
`L-…` SEC-L-09).*

### XIX.7.4 Licence, subscription and seat alerting

**R-XIX-118** The expiry ladder is **configuration per tenant and per contract**, not a
literal. *SmartPRS 2 hard-codes `[15, 7, 3, 1, 0]` (`SubscriptionAlerts.php:35`) and a
seven-day grace (`SubscriptionService.php:34`); a reseller's contract may say 30/14/7/1
(`L-…` §Q8 items 6–7).*

**R-XIX-119** **Suspension is gated on evidence of warning.** A tenant may not be
auto-suspended unless the configured warning ladder has been **delivered** — that is,
unless the delivery ledger holds `accepted` or better for each mandatory step, or an
administrator has explicitly overridden with a recorded reason.

> This is the single requirement that closes the whole `MailService::queue()` failure. In
> SmartPRS 2 the suspension write is direct and unconditional
> (`SubscriptionAlerts.php:60-69`, `:65`) while the warnings are queued and recorded `sent`
> on the strength of a row id returned before despatch (`:107-108`). **SmartPRS 3 does not
> replicate this.** A suspension whose warnings were not acknowledged by a transport does
> not proceed; it raises `subscription.suspension_blocked_pending_notice` to the platform
> and to the tenant's owner.

**R-XIX-120** **Auto-suspension is itself configurable per tenant**, including "never".
*SmartPRS 2 suspends unconditionally (`L-…` §Q8 item 8); an enterprise customer will not
accept that.*

**R-XIX-121** Seat-limit approach and breach are events with configurable thresholds.

### XIX.7.5 Audit chain integrity

**R-XIX-122** `ops.audit.chain_break_detected` (Part XXIII §XXIII.7) is a **critical,
non-suppressible** event routed to the tenant's designated audit contact **and** to the
platform, on an out-of-band channel, with no quiet-hours honouring and no digesting. It
cannot be unsubscribed and its subscription cannot be deactivated.

---

## XIX.8 Scheduled communications and automation

Section 59's automation is not only event-driven; a large part of it is calendar-driven.
This is where SmartPRS 2's forty-five literals live.

**R-XIX-123** A **scheduled communication** is a configuration artefact declaring:

```
occasion_key        birthday | work_anniversary | probation_milestone |
                    credential_expiry | document_expiry | policy_ack_due |
                    festival | custom_date_field | service_milestone | ...
date_source         a declared date field on a declared entity — INCLUDING a
                    customer-defined date field from Part X
offsets             the ladder, relative to the source date (R-XIX-060)
recurrence          annual | one-off | on-change
eligibility_spx     e.g. service.completed_years >= policy.min_years
                         AND employee.stage <> 'exited'
audience_ref[]      §XIX.3.3
channel_plan        §XIX.3.4
template_key        §XIX.2
local_time          evaluated in the tenant's timezone (R-XIX-058)
dedupe_key_expr     §XIX.3.6
leap_day_policy     greet_on_28_feb | greet_on_01_mar | skip
```

**R-XIX-124** Occasions are **extensible**, driven by any date field including
customer-defined ones. *SmartPRS 2 has exactly two hard-coded blocks — birthday and joining
anniversary — matched by `DATE_FORMAT(dob,'%m-%d')` and `DATE_FORMAT(doj,'%m-%d')`
(`GreetingService::candidates():164-177`; `E-…` requirement 75.)*

**R-XIX-125** **29 February is handled by a declared policy**, not by accident.
*SmartPRS 2 never greets a 29-February birthday in a common year
(`GreetingService.php:134, 172-173`).*

**R-XIX-126** Candidate selection is **sargable**: the platform maintains indexed
month-day projections of every date field used as a `date_source`, maintained by the
persistence layer. *SmartPRS 2 full-scans `employees` per tenant per matching hour
(`E-…` NEW-NOT-107) — the parameters are bound, so there is no injection, but the query
cannot use an index.*

**R-XIX-127** A tenant with **zero employees**, and employees with a null or zero tenant,
are **not** silently excluded from sweeps; the sweep iterates the tenant registry, and a
null-tenant business row is a data-integrity alert (spine Law 6), not an invisible skip.
*SmartPRS 2 derives its tenant list from `DB::table('employees')->distinct()->pluck(...)
->filter()` (`GreetingService.php:136-138`; `E-…` NEW-NOT-109).*

**R-XIX-128** **A per-tenant failure inside a sweep does not abandon the remaining
tenants.** Each tenant is processed in its own isolated unit with its own error boundary;
a failure is recorded against that tenant, the sweep continues, and the command's exit
status reflects the outcome. *SmartPRS 2's greetings sweep wraps the entire tenant loop in
one `try/catch` → `Log::warning`, so a failure on tenant 3 of 20 silently abandons 4 to 20
and the command still exits SUCCESS with an under-count (`GreetingService.php:157`;
`L-…` §Q1 item 10).*

**R-XIX-129** **A disciplinary communication is not a greeting.** Warning, show-cause and
notice templates live in the disciplinary family with their own permissions, their own
approval requirement and their own audit class. *SmartPRS 2 keeps the late-arrival warning
template inside the Greetings configuration object, so HR editing "Greetings" edits a
warning letter, while its sending is controlled by a setting on a different screen
entirely (`GreetingService::defaults():54-58`; `E-…` NEW-NOT-111).*

**R-XIX-130** **No configuration payload is stored unvalidated.** Every scheduled
communication and every subscription payload is schema-validated against its declared
contract, with key allow-lists and length limits. *SmartPRS 2's `saveConfig` accepts any
array and `array_replace_recursive`s arbitrary keys into the persisted JSON with no
whitelist and no length limits (`GreetingController.php:51-57`).*

**R-XIX-131** **Dead configuration is prohibited.** A declared setting that nothing reads
fails the configuration contract test in CI. *SmartPRS 2 surfaces `hide_birth_year` on the
greetings configuration screen and never reads it anywhere
(`GreetingService.php:36`; `E-…` NEW-NOT-110).*

---

## XIX.9 Configuration surface — the Section 127 test

Each row states a customer requirement and the zero-code path that satisfies it. The
standard is the brief's: *"3 late arrivals over 10 minutes = half day" must not require
code.*

| # | Customer requirement | Zero-code path |
|---|---|---|
| 1 | "Notify the employee **and** their manager when leave is approved, but only for leave over 3 days" | Add a second audience `reporting_manager` to the subscription; set `condition_spx = leave.units > 3` |
| 2 | "Send payslip-ready over WhatsApp, not email" | Change the subscription's `channel_plan`; the WhatsApp template already exists in the starter pack |
| 3 | "Never message anyone between 20:00 and 08:00 local time" | Tenant quiet-hours window; mandatory classes override |
| 4 | "Our compliance officer, not HR, gets DRA expiry digests" | Change the audience to `role_in_scope(compliance_officer, company)` |
| 5 | "DRA renewal cycle is 90 days, not 30" | Change the `credential.expiring` ladder offsets |
| 6 | "Show all expiring items in the digest, not the top 25" | Change the digest size cap; the overflow line adjusts |
| 7 | "Chase overdue probation confirmations weekly until resolved" | Ladder `[0d, +7d, +14d, +21d]` with an escalation step |
| 8 | "Celebrate only 5, 10 and 15-year anniversaries" | `eligibility_spx: service.completed_years IN [5,10,15]` |
| 9 | "Greet at 10:00 in Hyderabad and 09:00 in Mumbai" | Branch-scoped variants of the scheduled communication |
| 10 | "Reminder ladder is 30/14/7/1 days, and never auto-suspend" | Subscription ladder; `auto_suspend = never` (R-XIX-120) |
| 11 | "Employees may switch off celebration emails but not payslip notices" | `celebrations` class is optional; `payroll` class is `is_mandatory` |
| 12 | "Add LinkedIn Profile to the offer letter" | Add the field in Part X; it appears in the picker (R-XIX-016) |
| 13 | "Our letters must carry the grievance officer's details" | Insert the `grievance_officer` block; it resolves per company |
| 14 | "Hindi for our Jaipur branch" | Publish a `hi` language variant; the ladder resolves it (R-XIX-010) |
| 15 | "Payslip emails on a priority lane; bulk announcements can wait" | `queue_class = interactive` versus `bulk` (R-XIX-089) |
| 16 | "Retry WhatsApp 5 times over an hour; don't retry SMS at all" | Per-channel retry policy (R-XIX-085) |
| 17 | "Keep disciplinary correspondence bodies for 7 years, everything else 90 days" | Per-class body retention (R-XIX-104) and retention class (R-XIX-105) |
| 18 | "Alert us when the queue backs up past 500 jobs" | `queue.depth` warning threshold |
| 19 | "Page the on-call rota if a backup fails twice" | Escalation ladder on `ops.backup.failed` |
| 20 | "Our UK subsidiary's numbers must work" | Per-scope country code and normalisation policy (R-XIX-057) |
| 21 | "Don't tell an employee on notice period about training deadlines" | Suppression condition on the subscription (R-XIX-070) |
| 22 | "One digest a day for attendance exceptions, not twelve emails" | `timing = digest(daily 09:00)` plus a per-recipient rate limit |
| 23 | "The client bank's auditor gets the compliance pack notice" | `external_contact` audience |
| 24 | "Broadcast to Field/FOS staff in the South zone" | Audience `spx_selector` over `employee.category` and `org.zone` — a **saved, reusable** definition, not eight fixed filters |
| 25 | "Warn but do not block when a policy falls below the statutory floor" | Rule pack severity (Part XVII) raises a compliance event this engine routes |

All twenty-five are configuration. **None requires a release.**

---

## XIX.10 Data model

Section 123 standard; conventions per spine S16. Every table carries
`tenant_id BIGINT UNSIGNED NOT NULL` participating in every unique index (Law 6), applied
by the persistence layer. Every table carries `created_at`, `created_by`, `updated_at`,
`updated_by` unless stated. **No table in this part is created at runtime** (Law 2) — the
direct replacement for seven SmartPRS 2 messaging tables built by application code
(`NotificationService::ensureTable():50-70`; `MailService::ensureTable():49-66`;
`WaService::ensureLog():25-39`; `WaTemplateController::ensure():72-92`;
`ConfigController::ensureTable():30-37`; `MasterController::ensureCols():501-507`;
`MobileDeviceController::ensure():36-54`).

Legend: **Hist** = history mechanism · **SD** = soft delete · **PII** = classification.

### XIX.10.1 `notification_events`

*Purpose:* the platform event catalogue (§XIX.3.1). Platform-owned; tenant rows only for
rule-derived events.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | UUIDv7 |
| `tenant_id` | bigint unsigned | Y | NULL | **NULL = platform-owned catalogue entry**; the sole declared exception to Law 6 in this part, because the catalogue is product content (mirrors the rule-pack pattern, spine S12) |
| `event_key` | varchar(120) | N | — | immutable, namespaced |
| `module` | varchar(48) | N | — | owning bounded context |
| `subject_entity` | varchar(48) | N | `none` | value set |
| `description` | varchar(500) | N | — | business language |
| `severity_default` | varchar(24) | N | `informational` | value set |
| `payload_contract` | json | N | — | typed field declarations |
| `default_audiences` | json | N | `[]` | ordered audience refs |
| `default_channels` | json | N | `[]` | ordered channels |
| `suggested_template_key` | varchar(120) | Y | NULL | starter pack |
| `is_mandatory` | tinyint(1) | N | 0 | preference cannot suppress |
| `allow_digest` | tinyint(1) | N | 1 | |
| `dedupe_scope` | varchar(200) | N | — | default dedupe key shape |
| `is_system` | tinyint(1) | N | 1 | |
| `status` | varchar(24) | N | `active` | value set |

PK `id` · Unique `(tenant_id, event_key)` · Index `(module, status)`, `(subject_entity)`.
Hist: platform-versioned with the release. SD: no (deactivate). Audit: create/deactivate.
PII: none.

### XIX.10.2 `templates` / `template_versions` / `template_channel_bodies`

*Purpose:* the template artefact and its effective-dated versions (§XIX.2.2).

`templates`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | UUIDv7 |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants.id` ON DELETE RESTRICT |
| `template_key` | varchar(120) | N | — | **immutable** (R-XIX-005) |
| `family` | varchar(24) | N | — | value set: email/whatsapp/sms/push/in_app/letter/document |
| `subject_entity` | varchar(48) | N | — | value set; drives the placeholder catalogue |
| `is_system` | tinyint(1) | N | 0 | seeded starters cannot be deleted, only deactivated |
| `pack_ref` | varchar(120) | Y | NULL | the content pack this forked from (R-XIX-099) |
| `is_forked` | tinyint(1) | N | 0 | "original vs edited" state |
| `status` | varchar(24) | N | `active` | value set |
| `deleted_at` | datetime | Y | NULL | |

PK `id` · Unique `(tenant_id, template_key)` · Index `(tenant_id, family, status)`.
Hist: versions. SD: yes. Audit: create/deactivate/fork. PII: none.
**No `name` column** — the display name lives on the version, so a rename is a version.

`template_versions` — the spine S4 envelope (`version_no, status, scope_json,
specificity_weight, priority, effective_from, effective_to, created_by, created_at,
submitted_by, approved_by, approved_at, published_at, superseded_by_version_id, reason,
change_note, checksum`) plus:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `template_id` | bigint unsigned | N | — | FK → `templates.id` ON DELETE RESTRICT |
| `display_name` | varchar(160) | N | — | |
| `description` | varchar(500) | Y | NULL | |
| `language` | varchar(12) | N | — | BCP-47 |
| `is_fallback_language` | tinyint(1) | N | 0 | terminal step of the ladder (R-XIX-010) |
| `direction` | varchar(4) | N | `ltr` | |
| `variable_manifest` | json | N | — | **resolved at publish** (R-XIX-014) |
| `variable_manifest_hash` | char(64) | N | — | pinned on every rendered artefact |
| `render_budget` | json | N | — | node, loop, size budgets applied |
| `sample_dataset_ref` | varchar(120) | Y | NULL | |
| `provider_registration_ref` | varchar(160) | Y | NULL | WhatsApp/SMS registration |
| `provider_status` | varchar(24) | Y | NULL | value set; from the provider, never inferred (R-XIX-025) |
| `provider_status_at` | datetime | Y | NULL | |

PK `id` · Unique `(tenant_id, template_id, version_no)` ·
Unique `(tenant_id, template_id, language, effective_from, scope_hash)` ·
Index `(tenant_id, template_id, status, effective_from, effective_to)`.
Hist: this **is** the history. SD: no — superseded, never deleted. Audit: every transition
with before/after (Part XXIII). PII: the manifest carries field references and PII classes,
not values.

`template_channel_bodies`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `template_version_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `channel` | varchar(24) | N | — | value set |
| `part` | varchar(32) | N | — | subject/body_html/body_text/title/deep_link/provider_template_name/dlt_template_id/data_payload |
| `content_source` | mediumtext | N | — | authored source |
| `content_ast` | mediumblob | N | — | **the compiled artefact that is rendered** (R-XIX-004) |
| `escaping_profile` | varchar(32) | N | — | value set |
| `provider_arity` | smallint | Y | NULL | validated against the registration |
| `segment_estimate` | smallint | Y | NULL | SMS |

PK `id` · Unique `(tenant_id, template_version_id, channel, part)`.
Hist: via the version. SD: no. Audit: via the version. PII: none.

`template_blocks`, `template_assets`, `template_assertions` follow the same shape: tenant
scoped, referenced by key, versioned with the template that uses them, assets stored by id
and never inlined (R-XIX-036).

### XIX.10.3 `notification_subscriptions` / `_versions`

*Purpose:* the event × audience × channel × template × timing binding (§XIX.3.2). Identity
table plus the S4 version envelope, with the version columns as listed in R-XIX-042
(`event_key`, `audience_refs json`, `channel_plan json`, `template_bindings json`,
`condition_spx text`, `condition_ast blob`, `timing json`, `quiet_hours_policy`,
`dedupe_key_expr`, `rate_limit_ref`, `escalation_ref`, `language_policy`, `severity`,
`queue_class`, `retention_class`, `is_active`).

PK `id` · Unique `(tenant_id, subscription_key, version_no)` ·
Index `(tenant_id, event_key, is_active, effective_from, effective_to)`.
Hist: versions. SD: no. Audit: full, with before/after. PII: none.
**Resolution index:** `(tenant_id, event_key, is_active)` covering `scope_hash`, so the
engine's hot path is one indexed lookup per event.

### XIX.10.4 `audience_definitions`

*Purpose:* named, reusable, rule-based audiences (§XIX.3.3), including the saved
segmentation that replaces SmartPRS 2's eight fixed broadcast filters.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` / `uuid` / `tenant_id` | | N | | |
| `audience_key` | varchar(120) | N | — | immutable |
| `kind` | varchar(48) | N | — | value set from R-XIX-047 |
| `parameters` | json | N | `{}` | kind-specific |
| `selector_spx` | text | Y | NULL | for `spx_selector` |
| `selector_ast` | blob | Y | NULL | compiled |
| `max_size` | int unsigned | Y | NULL | R-XIX-048 rule 4 |
| `fallback_audience_id` | bigint unsigned | Y | NULL | FK self, ON DELETE RESTRICT |
| `status` | varchar(24) | N | `active` | |

PK `id` · Unique `(tenant_id, audience_key)` · Index `(tenant_id, kind, status)`.
Hist: versioned as configuration. SD: yes. Audit: full. PII: `distribution_list` members
may hold external email addresses → **PII: contact**.

`distribution_list_members` holds `(tenant_id, list_id, user_id NULL, employee_id NULL,
external_address_encrypted, external_address_masked, address_hash, added_by, added_at)`.
External addresses are encrypted at rest and returned masked.

### XIX.10.5 `notification_messages`

*Purpose:* one row per event instance per subscription — the composed message before
fan-out.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` / `uuid` / `tenant_id` | | N | | |
| `event_key` | varchar(120) | N | — | |
| `event_occurred_at` | datetime | N | — | UTC |
| `event_business_date` | date | N | — | tenant business date |
| `subscription_version_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `template_version_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `subject_type` / `subject_id` | varchar(48) / bigint unsigned | Y | NULL | polymorphic, indexed |
| `actor_user_id` | bigint unsigned | Y | NULL | who caused it |
| `payload` | json | N | — | **redacted** per R-XIX-102 point 3 |
| `dedupe_key` | varchar(200) | N | — | |
| `severity` | varchar(24) | N | — | |
| `is_test` | tinyint(1) | N | 0 | R-XIX-029 |
| `campaign_id` | bigint unsigned | Y | NULL | FK, for broadcasts |
| `correlation_id` | char(36) | N | — | threads to the originating request |
| `workflow_instance_id` | bigint unsigned | Y | NULL | |
| `retention_class` | varchar(32) | N | — | drives pruning |

PK `id` · **Unique `(tenant_id, dedupe_key)`** — idempotency as a constraint (R-XIX-064) ·
Index `(tenant_id, event_key, event_occurred_at)`, `(tenant_id, subject_type, subject_id)`,
`(tenant_id, correlation_id)`, `(tenant_id, campaign_id)`.
Hist: none — immutable. SD: no. Audit: creation only. PII: payload is redacted; classified
**restricted**.

### XIX.10.6 `notification_deliveries`

*Purpose:* **one row per recipient per channel** (R-XIX-077). This table is also the in-app
feed — there is no separate feed table (`notifications_feed` in SmartPRS 2 is a migrated
table with zero references, `…000600…:199+`).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` / `uuid` / `tenant_id` | | N | | |
| `message_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `channel` | varchar(24) | N | — | value set |
| `recipient_user_id` | bigint unsigned | Y | NULL | FK |
| `recipient_employee_id` | bigint unsigned | Y | NULL | FK |
| `recipient_external_ref` | varchar(120) | Y | NULL | contact key, never a raw address |
| `address_masked` | varchar(120) | N | — | display form |
| `address_hash` | char(64) | N | — | keyed hash, for matching without storing (R-XIX-103) |
| `audience_kind` | varchar(48) | N | — | why this person is a recipient — shown in the trace |
| `language` | varchar(12) | N | — | resolved variant |
| `status` | varchar(24) | N | `pending` | the closed set of R-XIX-074 |
| `status_reason` | varchar(120) | Y | NULL | e.g. `quiet_hours_expired`, `no_adapter`, `preference_optout` |
| `queued_at` / `dispatching_at` / `accepted_at` / `delivered_at` / `read_at` / `failed_at` / `dead_lettered_at` | datetime | Y | NULL | **`accepted_at` written only on provider acknowledgement (R-XIX-075)** |
| `provider_id` | bigint unsigned | Y | NULL | FK → `channel_providers` |
| `provider_message_ref` | varchar(160) | Y | NULL | the provider's own id |
| `provider_error_code` | varchar(64) | Y | NULL | verbatim |
| `provider_error_message` | varchar(500) | Y | NULL | **redacted** |
| `failure_class` | varchar(24) | Y | NULL | transient / permanent |
| `attempt_count` | smallint unsigned | N | 0 | |
| `next_attempt_at` | datetime | Y | NULL | |
| `expires_at` | datetime | Y | NULL | maximum age (R-XIX-087) |
| `body_hash` | char(64) | Y | NULL | R-XIX-104 `hash_only` |
| `body_stored_ref` | varchar(200) | Y | NULL | R-XIX-104 `full` |
| `suppressed_by` | varchar(48) | Y | NULL | preference / quiet_hours / rule / rate_limit |
| `queue_class` | varchar(24) | N | — | |
| `retention_class` | varchar(32) | N | — | |

PK `id` · Unique `(tenant_id, message_id, channel, recipient_user_id, recipient_employee_id,
recipient_external_ref)` · Index `(tenant_id, recipient_user_id, channel, status, read_at)`
— the in-app feed query · Index `(tenant_id, status, next_attempt_at)` — the retry sweep ·
Index `(tenant_id, provider_id, provider_message_ref)` — callback matching ·
Index `(tenant_id, status, queued_at)` — the `mail_queue.stalled` health check.
Hist: the status timestamps. SD: no. Audit: creation and terminal state.
PII: **restricted** — masked addresses and keyed hashes only.

### XIX.10.7 `delivery_attempts` and `delivery_events`

`delivery_attempts` — one row per despatch attempt: `delivery_id`, `attempt_no`,
`started_at`, `finished_at`, `provider_id`, `outcome`, `provider_error_code`,
`latency_ms`, `queue_class`, `worker_ref`. Immutable. This is what makes "why did this take
four hours?" answerable.

`delivery_events` — one row per inbound provider callback or poll result: `delivery_id`,
`provider_id`, `event_type`, `occurred_at`, `received_at`, `signature_verified`,
`nonce`, `raw_payload_hash`, `applied` (0 when out-of-order, R-XIX-092), `reject_reason`.
Immutable. Raw payloads are stored hashed, not verbatim.

### XIX.10.8 `channel_providers` and `provider_credentials`

`channel_providers`: `tenant_id`, `provider_key`, `channel`, `adapter`, `adapter_version`,
`display_name`, `config json` (non-secret only), `sender_identity`, `country_policy`,
`rate_limits json`, `retry_policy json`, `egress_allowlist json`, `is_default`,
`status`, `last_health_at`, `last_health_result`. Unique `(tenant_id, provider_key)`.

`provider_credentials`: `tenant_id`, `provider_id`, `credential_key`,
`value_encrypted blob`, `algo`, `key_version`, `rotated_at`, `expires_at`,
`created_by`. **Write-only through the API; never returned in any read** (R-XIX-083).
PII/secret: **secret**. Audit: create/rotate/revoke, value never recorded.

### XIX.10.9 `notification_preferences`

`tenant_id`, `user_id`, `class_key`, `channel`, `opt_in`, `digest_mode`,
`quiet_hours_override json`, `language`, `timezone`, `updated_at`, `updated_by`,
`source` (self / hr / import). Unique `(tenant_id, user_id, class_key, channel)`.
Audit: full, with before/after. PII: none beyond the association.
A row's absence means "the class default applies"; the absence of a preference is never
read as an opt-out.

### XIX.10.10 `notification_digests` and `digest_items`

`notification_digests`: `tenant_id`, `recipient_user_id`, `digest_key`, `window_start`,
`window_end`, `scheduled_for`, `status`, `item_count`, `overflow_count`, `delivery_id`.
Unique `(tenant_id, recipient_user_id, digest_key, window_start)`.
`digest_items`: `digest_id`, `message_id`, `sort_key`, `included` (0 when beyond the size
cap — so the overflow line is truthful, R-XIX-063).

### XIX.10.11 `scheduled_communications` / `_versions`

Identity plus the S4 envelope, with the version columns of R-XIX-123, plus
`last_evaluated_at`, `last_evaluated_business_date` per tenant (which is what makes
catch-up correct rather than approximate, R-XIX-060).

### XIX.10.12 `notification_campaigns`

`tenant_id`, `campaign_key`, `name`, `audience_id`, `template_id`, `channel_plan`,
`scheduled_for`, `window_end`, `status` (`draft|scheduled|running|paused|cancelled|
completed`), `resolved_recipient_count`, `sent_count`, `accepted_count`,
`delivered_count`, `failed_count`, `created_by`, `approved_by`, `body_max_length_applied`.
Audit: full. *This replaces `messages_log`'s single aggregate row per broadcast.*

### XIX.10.13 `notification_outbox`

`tenant_id`, `event_key`, `payload json`, `occurred_at`, `business_date`,
`correlation_id`, `relayed_at`, `relay_attempts`, `subject_type`, `subject_id`.
Index `(relayed_at, id)` — the relay cursor. Rows are pruned after a declared retention
once relayed and materialised.

### XIX.10.14 Operations tables

`ops_health_checks`: `tenant_id` (NULL = platform), `check_key`, `measurement`,
`interval_seconds`, `warn_threshold`, `critical_threshold`, `recovery_condition`,
`owner_role`, `is_enabled`, `out_of_band_required` (R-XIX-112).

`ops_health_samples`: `tenant_id`, `check_key`, `sampled_at`, `value`, `status`,
`detail json`. Partitioned monthly; retention class `operational`.

`ops_incidents`: `tenant_id`, `check_key`, `opened_at`, `severity`, `current_step`,
`acknowledged_by`, `acknowledged_at`, `recovered_at`, `escalation_ref`, `message_id`.
The escalation ladder's state lives here, so escalation survives a restart.

`escalation_policies`: `tenant_id`, `policy_key`, `steps json` (delay, audience, channel
plan, acknowledgement requirement), `status`.

`rate_limit_policies`: `tenant_id`, `policy_key`, `dimension`, `window`, `limit`,
`on_breach` (`digest|suppress|smooth`), `status`.

### XIX.10.15 What is deliberately absent

| Absent | Why |
|---|---|
| A `sent` column or status | The word is banned (R-XIX-076) because SmartPRS 2 used it to mean "enqueued" |
| A separate in-app feed table | The delivery row **is** the feed (SmartPRS 2's `notifications_feed` is migrated and referenced nowhere) |
| An aggregate broadcast log | Replaced by the campaign object plus per-recipient deliveries |
| A `body_values` column | Prohibited by R-XIX-101; the manifest hash and optional body reference replace it |
| Any plaintext credential column | R-XIX-083 |
| Any runtime-created table | Law 2 |
| A second template store | R-XIX-003 |

---

## XIX.11 API surface

All `/api/v3`, UUIDs only, cursor pagination on collections, idempotency keys on unsafe
methods, one error envelope, and an **explicit policy binding on every route** (Law 5).

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/notifications/events` | the event catalogue with payload contracts | `notification.event.view` |
| GET | `/notifications/variables?entity=&event=` | the derived placeholder catalogue | `notification.template.view` |
| GET/POST | `/notifications/templates` | list / create | `notification.template.view` / `.manage` |
| POST | `/notifications/templates/{uuid}/versions` | draft a version | `notification.template.manage` |
| POST | `/notifications/templates/{uuid}/versions/{v}/preview` | sample / live-subject / multi-scope preview | `notification.template.manage` |
| POST | `/notifications/templates/{uuid}/versions/{v}/test-send` | test send through the real adapter | `notification.template.test` |
| POST | `/notifications/templates/{uuid}/versions/{v}/validate` | run the publish gate without publishing | `notification.template.manage` |
| POST | `/notifications/templates/{uuid}/versions/{v}/publish` | publish (maker-checker where `high_risk`) | `notification.template.publish` |
| GET | `/notifications/templates/{uuid}/versions/{v}/impact` | subscriptions, scopes, employees, 90-day volume | `notification.template.view` |
| GET | `/notifications/templates/{uuid}/versions/{a}/diff/{b}` | source, rendered and manifest diff | `notification.template.view` |
| GET/POST | `/notifications/subscriptions` | who gets told what, when | `notification.subscription.view` / `.manage` |
| POST | `/notifications/subscriptions/{uuid}/versions/{v}/simulate` | resolve audience, channels and template for a named subject and date, with the full trace | `notification.subscription.simulate` |
| POST | `/notifications/subscriptions/{uuid}/versions/{v}/publish` | publish | `notification.subscription.publish` |
| GET/POST | `/notifications/audiences` | audience definitions | `notification.audience.view` / `.manage` |
| POST | `/notifications/audiences/{uuid}/resolve` | resolved members for a subject and date, capped and audited | `notification.audience.simulate` |
| GET/POST | `/notifications/scheduled-communications` | occasions and ladders | `notification.schedule.view` / `.manage` |
| GET/POST | `/notifications/campaigns` | broadcast campaigns | `notification.campaign.view` / `.manage` |
| POST | `/notifications/campaigns/{uuid}/preview-audience` | resolved recipient count before sending | `notification.campaign.manage` |
| POST | `/notifications/campaigns/{uuid}/schedule` \| `/pause` \| `/cancel` | lifecycle | `notification.campaign.manage` |
| GET | `/notifications/deliveries?event=&status=&recipient=&from=&to=` | the per-recipient ledger | `notification.delivery.view` **scoped** |
| GET | `/notifications/deliveries/{uuid}` | one delivery with its attempts and provider events | scoped |
| GET | `/notifications/deliveries/{uuid}/explain` | the full trace (§XIX.12) | scoped |
| POST | `/notifications/deliveries/{uuid}/retry` | re-drive one delivery | `notification.delivery.retry` |
| GET/POST | `/notifications/dead-letters` \| `/dead-letters/{uuid}/redrive` | dead-letter queue | `notification.deadletter.manage` |
| GET/PUT | `/me/notification-preferences` | the preference centre | self only |
| GET | `/me/notifications?unread=&class=` | the in-app feed | self only |
| POST | `/me/notifications/{uuid}/read` \| `/read-all` | mark read | self only |
| GET | `/unsubscribe/{signed_token}` | signed, scoped, expiring unsubscribe landing | public, token-bound |
| GET/POST | `/notifications/providers` | channel provider configuration | `notification.provider.manage` |
| PUT | `/notifications/providers/{uuid}/credentials` | **write-only** credential set | `notification.provider.credentials` |
| POST | `/notifications/providers/{uuid}/test` | connectivity diagnostics, never a business send | `notification.provider.manage` |
| POST | `/webhooks/providers/{provider_key}` | inbound delivery callbacks | HMAC + timestamp + nonce; **no session** |
| GET | `/ops/health` | component-level health (R-XIX-112 point 4) | `ops.health.view`; a minimal liveness variant is unauthenticated |
| GET/PUT | `/ops/health-checks` | thresholds, intervals, audiences | `ops.health.manage` |
| GET | `/ops/incidents` \| POST `/ops/incidents/{uuid}/acknowledge` | incident and escalation state | `ops.incident.manage` |
| GET | `/ops/queues` \| `/ops/jobs/failed` | queue depth, oldest job, failed jobs | `ops.queue.view` |

**R-XIX-132** `GET /notifications/deliveries` and `/ops/*` are **role-gated on read**, and
every read of another person's delivery ledger is audited (Part XXIII §XXIII.4).
*Explicit because SmartPRS 2's greeting log — which discloses which `employee_id` had a
birthday on which date — is readable by any authenticated user
(`GreetingController::log():169-188`).*

---

## XIX.12 Events

Emitted by this part:

| Event | When | Consumers |
|---|---|---|
| `notification.queued` | a delivery row reaches `queued` | ops metrics |
| `notification.accepted` | provider acknowledgement | delivery analytics, R-XIX-119 suspension gate |
| `notification.delivered` / `.read` | provider callback or poll | analytics, read receipts |
| `notification.failed` / `.dead_lettered` | terminal failure | ops dashboard, digest |
| `notification.suppressed` / `.duplicate` / `.unreachable` / `.blocked` | non-delivery outcomes | data-quality reports |
| `notification.audience_empty` | an audience resolved to nobody | **ops dashboard** (R-XIX-048 rule 2) |
| `notification.render_failed` | a placeholder or expression failed at render | ops dashboard |
| `template.published` / `.superseded` | template lifecycle | cache invalidation, impact reports, audit |
| `subscription.published` / `.activated` / `.deactivated` | subscription lifecycle | audit, governance |
| `provider.health_degraded` / `.recovered` | adapter health | ops dashboard |
| `campaign.started` / `.completed` / `.cancelled` | broadcast lifecycle | audit |
| `ops.*` | every health signal of §XIX.7.1 | this engine itself, escalation |
| `subscription.suspension_blocked_pending_notice` | R-XIX-119 refused a suspension | platform ops, tenant owner |

Consumed: **every domain event in the catalogue**, from every module. This part is the
universal consumer; it produces no business state.

---

## XIX.13 Explainability *(spine S15)*

**R-XIX-133** `Explain(delivery)` returns the complete decision trace for any message.
A delivery without a trace is a programming error that throws (Law 4).

```
Message         leave.application.approved
Event at        14-Aug-2026 11:42:07 IST   (correlation 8f2a…c41)
Subject         Employee EMP-04417 (Priya Nair)
Actor           Ramesh K (user 8812), decided step 2 of workflow LEAVE-STD v6

Subscription    "Leave decision — employee and manager" v4
                matched on scope: company=Ametecs South, department=ALL
                specificity 100, priority 0; 1 other subscription also matched
Condition       leave.units > 0                                    → true

Audience        subject_employee            → EMP-04417            (channel: email, in_app)
                reporting_manager(level 1)  → EMP-00932 as at 14-Aug-2026
                                                                   (channel: email)
                                            -- resolved as at the EVENT date, not today

Template        LEAVE_DECISION_EMPLOYEE v7
                published 01-Jul-2026, effective 01-Jul-2026 →
                language: en  (recipient preference; hi variant exists, not preferred)
                manifest hash 3c9f…12b0  (14 variables: 9 from field_definitions,
                                          3 from the event payload, 2 platform)
Masked          employee.bank_account → not present in this template
Preference      class my_leave: email ON, push OFF (set by employee 02-Jun-2026)
Quiet hours     20:00–08:00 IST — event at 11:42, not applicable
Dedupe key      leave:app:44197:decided                            → first occurrence
Rate limit      my_leave: 5/day — 1st today                        → pass

Channel plan    email (primary), in_app (always)
Delivery        email  → queued 11:42:08 · dispatching 11:42:09
                       · ACCEPTED by provider 11:42:11 (ref 019a4f…)
                       · delivered 11:42:33 (callback, signature verified)
                       · read 12:07:41
                in_app → queued 11:42:08 · accepted 11:42:08 · read 11:58:02
Attempts        1 (no retry required)
Body            not stored (retention class: standard, mode hash_only)
                content hash 7ba2…9d41
```

**R-XIX-134** The trace names, for every recipient, **why they are a recipient** — the
audience kind and the relationship as at the event date. "Why did I get this?" and "why
did she not?" are both answerable from the record, without reading code.

**R-XIX-135** For a **non-delivery**, the trace is equally complete and states the
deciding factor exactly once: the preference row and when it was set, the quiet-hours
window and the release time, the dedupe key and the earlier message it collided with, the
rate limit and its window, the suppression rule and its condition, or the missing address.
*This exists because SmartPRS 2 cannot distinguish a suppressed duplicate from a failure at
all — both return `null` (`NotificationService.php:92-94, 142-160`).*

**R-XIX-136** `Explain(template_version)` returns the resolution that selected it: the
scope match, the specificity total, the priority, the competing versions considered and the
language ladder step that resolved.

---

## XIX.14 Migration from SmartPRS 2

| SmartPRS 2 asset | Verdict | Transformation |
|---|---|---|
| `wa_templates` (17 seeded + tenant edits) | **MIGRATE** | Each becomes a `whatsapp`-family template with one version, `effective_from` = migration date, provider registration reference carried across, `provider_status` reset to `submitted` unless the provider's own API confirms approval — **the auto-approve-on-2xx verdict is not trusted** (`WaTemplateController.php:417`) |
| `app_settings.greetings` JSON | **TRANSFORM** | Split into (a) a `birthday` and a `work_anniversary` scheduled communication with templates, and (b) a **separate disciplinary** late-arrival template in its own family (R-XIX-129). `hide_birth_year` is dropped — it was never read |
| `sms_templates` | **MIGRATE** | `sms`-family templates; DLT ids carried across |
| `letters` where `is_template = 1` | **MIGRATE** | `letter`-family templates; placeholders mapped to the derived catalogue, with an unmapped-placeholder report per template |
| `letters` where `is_template = 0` (issued) | **RENDER AND SNAPSHOT** | Each issued letter is **rendered once during migration and stored as a snapshot** with a content hash. *Without this their historical content is lost the moment a template is edited (`H-…` migration verdict).* |
| `mail_log` | **MIGRATE AS EVIDENCE, NOT AS TRUTH** | Rows land in `notification_deliveries` with `status = 'legacy_unverified'`, a reserved terminal status outside the R-XIX-074 state machine, and a `legacy_note` recording that SmartPRS 2 wrote `sent` before despatch. **No legacy row is mapped to `accepted`.** |
| `wa_log` | **MIGRATE WITH MANDATORY REDACTION** | `body_values` is **not migrated**. Migration writes the template reference and the arity only. *Because the column holds OTPs and plaintext temporary passwords (`WaService.php:171`; `WaTemplateController.php:103-104`).* The source column is scheduled for destruction in the decommissioning plan, with the destruction audited |
| `notification_log` | **MIGRATE** | Becomes in-app deliveries; `read_at` preserved; `dedupe_key` preserved |
| `messages_log` | **MIGRATE AS SUMMARY ONLY** | One campaign row per broadcast with `resolved_recipient_count`; **per-recipient outcomes do not exist and cannot be reconstructed** — stated in the migration report, not inferred |
| `late_notifications` | **MIGRATE** | Dedupe ledger entries |
| `subscription_alerts` | **MIGRATE WITH A CORRECTION FLAG** | Every row whose `status = 'sent'` is migrated as `legacy_unverified`, because the value was written from a row id returned before despatch (`SubscriptionAlerts.php:107-108`) |
| `wa_settings` / `sms_settings` | **MIGRATE, THEN ROTATE** | Credentials are encrypted on arrival and **every migrated key is flagged for mandatory rotation**, because it was stored in plaintext and may appear in backups and logs |
| `mobile_devices.push_token` | **DO NOT MIGRATE** | No sender ever existed; the tokens are of unknown provenance, were written with no tenant scope or ownership check (`MobileDeviceController::pushToken():173-189`), and are stale. Devices re-register |
| `notifications_feed` | **DROP** | Migrated table, zero references |
| Hard-coded call sites (17 in-scope triggers) | **REPLACE** | Each becomes a seeded subscription with a starter template, listed one-for-one in the migration report so the customer can confirm nothing was lost |
| The 45 hard-coded automation values | **SEED AS CONFIGURATION** | Each is seeded as the tenant's **current effective value**, explicitly labelled *"the value SmartPRS 2's code used"*, never as a recommended value |

**R-XIX-137** The migration produces a **communication inventory report** per tenant: every
event that notified in SmartPRS 2, its old audience and body, its new subscription and
template, and every gap — WhatsApp templates with no prior consumer, push with no prior
sender, leave submission which notified nobody. The customer signs the inventory before
cut-over.

**R-XIX-138** **No legacy delivery row is ever presented as verified delivery.** The UI
labels `legacy_unverified` explicitly wherever it appears.

---

## XIX.15 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | Employee has no email address | `unreachable` with reason; surfaced in a data-quality report; the channel plan falls back if one is declared. *SmartPRS 2 skips silently (`RequestController.php:571`)* |
| 2 | Approver resolution yields nobody | `notification.audience_empty` + the subscription's declared fallback; the workflow's own no-approver handling (Part IX) is separate and also fires |
| 3 | Provider returns 2xx with a body indicating failure | The adapter classifies from the body, not the status code; classification is adapter data |
| 4 | Provider acknowledges, then never calls back | `accepted` stands; a poll runs until the maximum age; a delivery stuck in `accepted` past a threshold raises `ops.delivery.unconfirmed` |
| 5 | Duplicate callback for the same provider ref | Idempotent; recorded in `delivery_events` with `applied = 0` |
| 6 | Callback arrives out of order (`delivered` before `accepted`) | Monotonic advance (R-XIX-092); the earlier state is inferred and timestamped from the callback |
| 7 | Recipient is deleted between queue and despatch | `cancelled` with reason `subject_removed`; never despatched |
| 8 | Employee exits between event and digest despatch | Suppression rule decides; default is to deliver statutory and payroll classes to the exit contact address and suppress the rest |
| 9 | Template published mid-flight | The message renders on the version **effective at the event date**, not at despatch time |
| 10 | Field removed from Part X while a template references it | Blocked at field-retirement time (R-XIX-015); if a stale reference somehow persists, render fails visibly rather than emitting a blank |
| 11 | Quiet hours cover the entire day (misconfiguration) | Publish-time validation rejects a window with no release point |
| 12 | Tenant timezone changed | Scheduled communications re-evaluate from the change date forward; already-despatched messages are untouched; a change is audited and previewed |
| 13 | Daylight saving transition in a non-Indian deployment | Local-time scheduling resolves through the IANA zone; a time that does not exist on the transition day shifts forward, a time that occurs twice fires once, with the rule declared |
| 14 | 29 February source date | Declared `leap_day_policy` (R-XIX-125) |
| 15 | Scheduler down for six days | Catch-up fires every due offset since the last successful pass, deduplicated; beyond the collapse threshold it becomes one summary message (R-XIX-060, R-XIX-061) |
| 16 | Worker down, queue drains later | Messages older than their maximum age are dead-lettered `expired`, not delivered (R-XIX-087) |
| 17 | Redis restarted without persistence | Queued work is lost; the outbox rows are **not**, because they are in the database — the relay re-materialises them. *This is the specific reason the outbox exists: in SmartPRS 2 every queued notification is silently destroyed with no `failed_jobs` row and no trace (`L-…` §Q9C)* |
| 18 | Provider rate limit reached mid-campaign | The campaign smooths and continues; `ops.rate_limit_reached` raised when the backlog exceeds the threshold; the campaign is never dropped |
| 19 | Campaign cancelled mid-flight | Un-despatched deliveries move to `cancelled`; despatched ones are unaffected and the counts say so |
| 20 | Audience larger than `max_size` | Send blocked, operator condition raised, nothing partially delivered |
| 21 | Two subscriptions produce identical messages to one recipient | De-duplicated per message (R-XIX-049); if the dedupe keys genuinely differ, both send and the trace explains why |
| 22 | A tenant disables email entirely | Subscriptions whose plan has no remaining channel fail activation (R-XIX-044); existing ones raise `ops.channel.not_configured` |
| 23 | Credential expired at the provider | Adapter health degrades, deliveries fail `transient` then dead-letter; `integration.credential_expiring` should have fired first |
| 24 | Alert channel itself fails | Out-of-band path (R-XIX-112) plus the external heartbeat; the platform detects silence from outside |
| 25 | Suspension due but warnings not acknowledged by any transport | Suspension **does not proceed** (R-XIX-119); `subscription.suspension_blocked_pending_notice` raised |
| 26 | Legal hold placed on a tenant's communications | Retention pruning is suspended for the held scope (Part XXIII §XXIII.8.4) |
| 27 | Employee opts out of a class that later becomes mandatory | Mandatory wins; the preference row is retained and re-applies if the class reverts, and the employee is told once that the class is now mandatory |
| 28 | A rendered letter exceeds the size budget | Render fails visibly with the offending loop named; no truncated PDF is produced |
| 29 | Unsubscribe token replayed after expiry | Rejected with a re-authentication path to the preference centre |
| 30 | Provider webhook signature fails | Rejected, counted, and `ops.webhook.signature_failures` raised on a threshold; never processed |

---

## XIX.16 Tests — exact-value regression scenarios

**P0 — must exist before any production traffic**

| # | Scenario | Exact assertion |
|---|---|---|
| T1 | Enqueue then inspect | After enqueue and before any adapter call, `status = 'queued'` and `accepted_at IS NULL`. **No code path can write `accepted_at` except the adapter acknowledgement handler** — asserted by static analysis and by fault injection |
| T2 | The SmartPRS 2 regression | Simulate a stopped worker for 20 days across a subscription ladder `[15,7,3,1,0]`. Assert: zero deliveries reach `accepted`; **the tenant is not suspended**; `subscription.suspension_blocked_pending_notice` is raised exactly once |
| T3 | Direct-send prohibition | CI sweep finds zero uses of `Mail::`, `Mail::raw`, `Notification::` or a provider host outside `Domain\Communication\Adapters` |
| T4 | Prohibited class at publish | A template binding a `secret`-class variable fails publication with the variable named |
| T5 | Prohibited class at persist | A forced render carrying an OTP produces a delivery row whose stored context contains the variable **name** and **no value**; the ledger is byte-searched for the OTP string and does not contain it |
| T6 | Dedupe as a constraint | Two concurrent emissions with the same dedupe key produce exactly one message row; the second returns `duplicate`, distinct from `failed` |
| T7 | Cross-tenant isolation | Generated per aggregate and per endpoint: no delivery, template, subscription, audience or provider row is readable across tenants |
| T8 | Fail-closed authorisation | Fault injection inside authorisation denies on every messaging route, including read routes |
| T9 | Outbox atomicity | A business transaction that rolls back leaves zero outbox rows; one that commits leaves exactly one, even with the relay stopped |
| T10 | Audience as-at date | An employee transferred on 20-Aug: an event dated 10-Aug resolves the **old** manager; an event dated 25-Aug resolves the new one |

**P1 — core behaviour**

| # | Scenario | Exact assertion |
|---|---|---|
| T11 | Placeholder derivation | Adding a Part X field `linkedin_url` makes `{{ employee.linkedin_url }}` publishable with no release; retiring it blocks while a current version references it |
| T12 | Manifest staleness | Renaming a `field_key` lists every referencing published version and blocks until each has a queued successor |
| T13 | Language ladder | Recipient `hi`, no `hi` variant, company default `en` → renders `en`; with no variant at any step, publication of the subscription is blocked |
| T14 | Precedence tie | Two template versions with equal specificity and priority for overlapping scopes → publication blocked, both named, affected employee count stated |
| T15 | WhatsApp arity | A body numbered `{{1}}, {{3}}` fails validation; declared arity 3 against a registration of arity 2 fails activation |
| T16 | Quiet hours | Event at 22:15 with a 20:00–08:00 window → released at 08:00 next local day; the same event marked `is_mandatory` → despatched at 22:15 |
| T17 | Catch-up | Scheduler misses six days across ladder `[-15,-7,-1,0]`; the next pass fires every offset become due, exactly once each, each stating its due date |
| T18 | Catch-up collapse | A 45-day outage produces one summary message, not 45 |
| T19 | Digest overflow | 300 expiring credentials with a cap of 25 → 25 items listed, `overflow_count = 275`, the overflow line present and the deep link resolving |
| T20 | Monotonic callbacks | `read` then `delivered` for the same ref → state stays `read`; the late event is recorded `applied = 0` |
| T21 | Retry and dead-letter | Transient failures under a policy of 5 attempts, base 30 s, multiplier 2, jitter ±20% → attempts at the declared intervals, then `dead_lettered`, re-drivable |
| T22 | Maximum age | A message enqueued 8 days ago with a 72-hour maximum age is dead-lettered `expired`, not delivered |
| T23 | Per-recipient ledger | A 500-recipient campaign produces exactly 500 delivery rows; failing one leaves 499 `delivered` and 1 findable and retryable |
| T24 | Blocked, not skipped | Sending on a channel with no adapter yields `blocked` **and** `ops.channel.not_configured`; the job does not report success |
| T25 | Masking parity | The same field masked in email, WhatsApp, SMS, push, in-app, PDF and preview — seven assertions, one value |
| T26 | Phone normalisation | `+44 20 7946 0958` under a UK-permitted policy normalises to `+442079460958`; under an India-only policy it is `unreachable` with a reason — **never silently rewritten to `+912079460958`** |
| T27 | Webhook security | Unsigned, stale, replayed and cross-tenant callbacks are each rejected and counted; a valid one applies exactly once |
| T28 | Egress allow-list | A provider URL pointing at `127.0.0.1`, `169.254.169.254` or a private range is rejected at configuration time |
| T29 | Credential never read back | `GET` on any provider returns no credential field under any role, including platform support |
| T30 | Retention enforced | Rows past their retention class are pruned on schedule; the prune is audited; a legal hold suspends it for the held scope |
| T31 | Per-tenant sweep isolation | A forced failure on tenant 3 of 20 leaves tenants 4–20 processed, records the failure against tenant 3, and the command's exit status is non-zero |
| T32 | Leap day | 29-Feb source date under each of the three policies produces the declared outcome in 2027 and in 2028 |
| T33 | Sargability | The candidate query for a 100,000-employee tenant uses the month-day index; an execution-plan assertion, not a timing assertion |
| T34 | No dead configuration | Every declared configuration key is read by at least one code path |
| T35 | Out-of-band alerting | With the queue and the relay both stopped, `ops.scheduler.heartbeat_missed` still reaches its audience |
| T36 | Health thresholds are configuration | Changing the queue-depth warning threshold changes the alert with no release |
| T37 | Escalation survives restart | An incident escalating at +2h escalates correctly across an application restart at +1h |
| T38 | Test send isolation | A test send never reaches a live audience, is marked `is_test`, is excluded from analytics, and is audited |
| T39 | Assertions gate publication | A template whose stored assertion no longer renders to its expected output cannot be published |
| T40 | Explain completeness | Every terminal delivery state yields a trace naming the deciding factor exactly once |

**P2 — migration**

| # | Scenario | Exact assertion |
|---|---|---|
| T41 | Legacy status | Every migrated `mail_log` row lands as `legacy_unverified`; zero land as `accepted` |
| T42 | `wa_log` redaction | The migrated ledger, byte-searched for every value present in the source `body_values`, returns zero hits |
| T43 | Issued letters snapshotted | Every migrated issued letter has a stored rendered artefact and a content hash; editing its template does not change it |
| T44 | Inventory completeness | The communication inventory lists all 17 in-scope SmartPRS 2 triggers and both gap classes (WhatsApp with no consumer, push with no sender) |
| T45 | Seeded literals labelled | All 45 migrated automation values carry the "value SmartPRS 2's code used" provenance label |

---

## XIX.17 Open items — PRODUCT OWNER DECISION REQUIRED

**PO-XIX-01 — Is WhatsApp a real notification channel for HR events?**
*Context:* seventeen templates are seeded, testable and approvable in SmartPRS 2, and
nothing sends from them (`WaTemplateController::tenantLibrary():130-185`;
`E-…` PO-10). The customer-visible promise is currently unmet.
**Recommendation: yes, wire it, for a declared subset** — payslip-ready, approval-pending,
decision notices and OTP. *Trade-off:* WhatsApp Business API carries per-message cost and a
template-approval cycle measured in days, so it must be per-tenant opt-in with a cost
warning at activation. *If the answer is no,* the template library is removed rather than
left as an unfulfilled promise.

**PO-XIX-02 — Which notification classes are mandatory?**
*Context:* R-XIX-053 requires the mandatory set to be platform-declared and tenant-visible.
**Recommendation: mandatory =** payslip publication, statutory notices and filings, security
events on the user's own account, legally required acknowledgements, disciplinary
communications, and account lifecycle. **Optional =** celebrations, announcements, team
activity, digests, training nudges. *Trade-off:* a larger mandatory set reduces
opt-out-driven support load and increases DPDP exposure; a smaller one is the reverse.

**PO-XIX-03 — Retention per log class, reconciled with the published privacy policy.**
*Context:* SmartPRS 2's published Privacy Policy commits to 90 days
(`PolicyController.php:12`) and nothing implements any retention; seven tables grow forever
and one holds plaintext passwords (`E-…` PO-11, SEC-18).
**Recommendation:** delivery metadata **13 months** (so year-on-year comparison works);
message bodies **`hash_only` by default**; statutory and disciplinary bodies **retained to
the statutory record period, set by the rule pack, not asserted here**; operational health
samples **90 days**; dead letters **180 days**. *This is a PRODUCT OWNER **and LEGAL**
decision*, because the published commitment and the operational need differ and the
published commitment must be amended or the retention shortened — one or the other, before
release.

**PO-XIX-04 — Is push notification in v3.0?**
*Context:* SmartPRS 2 has token storage and no sender anywhere
(`E-…` BR-NOT-021). Shipping tokens without a sender is what created the current state.
**Recommendation: yes, but gated on the mobile application's own roadmap.** If the mobile
client is not in the v3.0 release, **the push channel, the token registry and the device
approval flow ship together or not at all.** *Trade-off:* deferring costs a channel;
shipping half of it recreates the defect.

**PO-XIX-05 — Does the mobile device gate become a real server-side control?**
*Context:* it is marketed as an anti-fraud layer and is enforced nowhere on the server
(`E-…` SEC-09, PO-09). **Recommendation: build the server-side enforcement**, because the
claim is already made to customers; the alternative is to withdraw the claim in writing.

**PO-XIX-06 — Auto-suspension policy.**
*Context:* SmartPRS 2 suspends unconditionally after a seven-day grace
(`SubscriptionAlerts.php:60-69`; `SubscriptionService.php:34`).
**Recommendation: default to warn-only for enterprise contracts and suspend-after-grace for
self-serve, both per-tenant configurable, and both gated on delivered warnings
(R-XIX-119).** *Trade-off:* warn-only increases revenue leakage; unconditional suspension
has already produced the failure mode in §XIX.1.1.

**PO-XIX-07 — Who receives platform-level operational alerts for an on-premise install?**
*Context:* `.env.production.example:62-63` promises `SMARTPRS_OPS_EMAIL` is the backup and
digest recipient; it is read only by `ErrorDigest.php:25`, and backup failures reach nobody
(`L-…` DOC-L-12, OQ-L-04).
**Recommendation:** the customer's own named operator is the primary audience in every
case; Ametecs receives a **hash-and-status-only** feed where a support agreement permits
it, never message content or employee data (R-XIX-117). *Trade-off:* Ametecs cannot
proactively support installations that decline the feed.

**PO-XIX-08 — Digest defaults per class.**
*Context:* attendance and workflow reminders are the highest-volume classes and are the
ones most likely to train employees to ignore notifications.
**Recommendation:** attendance exceptions and team activity default to a **daily digest**;
approvals and decisions default to **immediate**. *Trade-off:* digesting approvals slows
decisions; immediate attendance notices are the commonest complaint in HR products.

**PO-XIX-09 — Does the engine send to external parties by default?**
*Context:* the `external_contact` audience kind allows a client bank or auditor to be
notified directly.
**Recommendation: available but off by default**, requiring an explicit per-contact
opt-in recorded with the contact's own consent reference, because outbound communication to
a third party carries contractual and data-protection exposure the tenant must accept
knowingly.

**PO-XIX-10 — The five WhatsApp default template names and the welcome password.**
*Context:* SmartPRS 2 falls back to literal template names
(`smartprs_welcome`, `_payment`, `_renewal`, `_lead`, `_otp` —
`WaService::templateNameFor():74-103`) and the welcome template carries the plaintext
temporary password with the flag defaulting to **true**
(`SignupController.php:413`; `L-…` §Q8 item 45).
**Recommendation: the password is never transmitted, on any channel, under any flag**
(R-XIX-101); the welcome message carries a signed, single-use, expiring set-password link.
*This is recorded as a decision rather than a design choice only because it changes a
customer-visible onboarding flow.*

---

## XIX.18 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XIX-001 – R-XIX-003 | One engine; no path outside it; payslip as a family (§XIX.2.1) | 3 |
| R-XIX-004 – R-XIX-011 | Template artefact, versioning, scoping, resolution (§XIX.2.2–2.3) | 8 |
| R-XIX-012 – R-XIX-017 | The derived placeholder catalogue (§XIX.2.4) | 6 |
| R-XIX-018 – R-XIX-023 | Template body language, conditions, loops, formatters, escaping (§XIX.2.5) | 6 |
| R-XIX-024 – R-XIX-027 | Channel constraints and provider registration (§XIX.2.6) | 4 |
| R-XIX-028 – R-XIX-032 | Preview, test send, publish-time validation (§XIX.2.7) | 5 |
| R-XIX-033 – R-XIX-036 | Masking and PII in rendered output (§XIX.2.8) | 4 |
| R-XIX-037 – R-XIX-041 | The event catalogue (§XIX.3.1) | 5 |
| R-XIX-042 – R-XIX-046 | Subscriptions (§XIX.3.2) | 5 |
| R-XIX-047 – R-XIX-049 | Audience resolution (§XIX.3.3) | 3 |
| R-XIX-050 – R-XIX-057 | Channels, preference, fallback, reachability (§XIX.3.4) | 8 |
| R-XIX-058 – R-XIX-063 | Timing, quiet hours, ladders, catch-up, digests (§XIX.3.5) | 6 |
| R-XIX-064 – R-XIX-066 | Deduplication and idempotency (§XIX.3.6) | 3 |
| R-XIX-067 – R-XIX-070 | Rate limiting, bulk sending, suppression (§XIX.3.7–3.8) | 4 |
| R-XIX-071 – R-XIX-073 | The outbox pattern (§XIX.4.1) | 3 |
| R-XIX-074 – R-XIX-078 | The delivery state model and the acknowledgement rule (§XIX.4.2) | 5 |
| R-XIX-079 – R-XIX-084 | Provider adapters, credentials, egress (§XIX.4.3) | 6 |
| R-XIX-085 – R-XIX-090 | Retry, dead-letter, queue classes, credential mail (§XIX.4.4) | 6 |
| R-XIX-091 – R-XIX-093 | Inbound callbacks (§XIX.4.5) | 3 |
| R-XIX-094 – R-XIX-095 | Failure surfacing; fail-closed authorisation (§XIX.4.6) | 2 |
| R-XIX-096 – R-XIX-100 | The builder (§XIX.5) | 5 |
| R-XIX-101 – R-XIX-107 | Secrets, PII, redaction, retention (§XIX.6) | 7 |
| R-XIX-108 – R-XIX-122 | Operational alerting, out-of-band rule, escalation, licence, audit integrity (§XIX.7) | 15 |
| R-XIX-123 – R-XIX-131 | Scheduled communications (§XIX.8) | 9 |
| R-XIX-132 | API read gating (§XIX.11) | 1 |
| R-XIX-133 – R-XIX-136 | Explainability (§XIX.13) | 4 |
| R-XIX-137 – R-XIX-138 | Migration reporting (§XIX.14) | 2 |
| **Total** | | **138** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| **1 — no business literal** | Every trigger, audience, channel, body, subject, placeholder, threshold, ladder, retry policy, queue name, quiet-hours window, country code, digest size, retention period and alert threshold is configuration. §XIX.9 demonstrates twenty-five customer requirements satisfied with no release; §XIX.1.6's forty-five SmartPRS 2 literals are each answered | Static analysis over `Domain\Communication` and `Domain\Ops`; T34 (no dead configuration); T36 (thresholds are configuration) |
| **2 — no schema change at runtime** | All tables in §XIX.10 are migration-owned. There is no successor to `NotificationService::ensureTable():50-70`, `MailService::ensureTable():49-66`, `WaService::ensureLog():25-39`, `WaTemplateController::ensure():72-92`, `ConfigController::ensureTable():30-37` or `MobileDeviceController::ensure():36-54` | CI grep for DDL outside migrations; `migrate:fresh` parity |
| **3 — nothing overwritten** | Templates and subscriptions are versioned S4 artefacts; a published version is immutable; messages, deliveries, attempts and provider events are append-only; issued letters are rendered and snapshotted | "Which template rendered this message?" answered from the pinned version id and manifest hash; T43 |
| **4 — every number explains itself** | §XIX.13: every delivery and every **non**-delivery carries a complete trace naming the subscription, the condition, the audience kind, the template version, the language ladder step, the preference, the dedupe key, the rate limit and the provider timeline. A delivery without a trace throws | T40 |
| **5 — authorisation fails closed** | Every route in §XIX.11 carries an explicit policy binding, **including reads**; provider credentials are never returned; delivery-ledger reads are scoped and audited; the preference centre and the in-app feed resolve the subject from the session | Route-policy sweep; T8 fault injection; the `GreetingController` open-read shape asserted absent |
| **6 — tenancy is structural** | `tenant_id NOT NULL` on every table and in every unique index, applied by the persistence layer. The single documented exception is the platform-owned event catalogue (`notification_events.tenant_id IS NULL`), which holds no business data. No audience, label or provider lookup is unscoped | T7 generated cross-tenant tests per aggregate and per endpoint; the `SendMessageController.php:165-168` unscoped-label shape asserted absent |
| **7 — money is exact** | This part produces no money. Where money appears in a rendered message it is a **stored fact** formatted by the `money` formatter in Indian grouping (₹1,50,000.00) from integer paise; no template may perform money arithmetic — arithmetic lives in Part VIII formulas | Static analysis: no arithmetic operator over a `Money`-typed variable in any template AST |
