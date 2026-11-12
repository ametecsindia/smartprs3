<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Operations\HealthCheck;

/**
 * WHAT IS WRONG WITH THIS INSTALLATION, AND WHOSE JOB IT IS.
 *
 * `Platform\Operations\HealthCheck` is the pattern this whole build keeps
 * returning to, applied to health: a probe that throws becomes a typed UNKNOWN,
 * unknown counts as failing, and the report names it — "because the alternative
 * is a green dashboard produced by a monitoring system that cannot reach the
 * thing it monitors, which is the worst possible arrangement of those two
 * facts". It was finished, tested, and reached from nothing but a phase gate.
 *
 * EVERY PROBE BELOW IS SOMETHING THAT HAS ACTUALLY GONE WRONG HERE. Not a
 * plausible list: the database account that existed and could not sign in, the
 * table a migration created that the application could not see, the schema that
 * sat behind the code, the default credential nobody removed. A health screen
 * assembled from imagined failures reports on the wrong things while the real
 * ones keep happening in silence.
 *
 * EVERY CHECK CARRIES ITS OBSERVED VALUE AND ITS THRESHOLD, which the platform
 * enforces by refusing a registration with no remediation and no owner: "an
 * alert nobody owns, with no stated next step, is a notification that trains
 * people to ignore notifications".
 */
final class Health
{
    public function __construct(
        private readonly RowStore $store,
        private readonly string $root,
        private readonly ?\PDO $pdo = null,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return array{ok: bool, checks: array<string, array<string, string>>, failing: list<string>} */
    public function report(): array
    {
        $checks = new HealthCheck();

        // 1. CAN THE APPLICATION USE ITS DATABASE AT ALL? The first time this
        //    went wrong, setup created an account with an authentication plugin
        //    the server no longer loads, reported success, and the only symptom
        //    was a banner on every screen saying the product was running on JSON
        //    files. A created account is not a working account.
        $checks->register('database.reachable',
            fn (): array => $this->pdo === null
                ? ['status' => HealthCheck::FAILING,
                    'observed' => 'no database connection — this installation is on the file store']
                : ['status' => HealthCheck::OK, 'observed' => 'connected as the application account'],
            threshold: 'the application account can connect and read',
            remediation: 'Run laragon-setup.bat, or php tools/db/verify-connection.php to see which '
                . 'of the two accounts cannot sign in.',
            owner: 'whoever installed this');

        // 2. IS THE SCHEMA THE ONE THE CODE EXPECTS? A migration file present on
        //    disk and not applied is a screen that fatals on a table that plainly
        //    exists in the repository.
        $checks->register('schema.migrations_applied',
            function (): array {
                $files = glob($this->root . '/database/migrations/*.php') ?: [];

                if ($this->pdo === null) {
                    throw new \RuntimeException('no database to compare the migrations against');
                }

                $applied = (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
                $pending = count($files) - $applied;

                return [
                    'status' => $pending <= 0 ? HealthCheck::OK : HealthCheck::FAILING,
                    'observed' => $pending <= 0
                        ? $applied . ' migration(s) applied, none pending'
                        : $pending . ' migration(s) on disk have not been applied',
                ];
            },
            threshold: 'no migration file is unapplied',
            remediation: 'php tools/db/migrate.php, then php tools/db/grant-application-account.php '
                . '--apply — a new table the application has no privilege on is invisible to it.',
            owner: 'whoever installed this');

        // 3. CAN THE APPLICATION SEE EVERY TABLE THE SCHEMA HAS? This is the one
        //    that cost an afternoon: the account holds table-by-table privileges
        //    (Law 2), `information_schema` only shows a table to an account with
        //    some privilege on it, and a table created by a migration run without
        //    the grant step is one the application reports as not existing — on a
        //    screen, naming a table anybody can see in the database.
        $checks->register('database.grants_cover_schema',
            function (): array {
                if ($this->pdo === null) {
                    throw new \RuntimeException('no database to inspect');
                }

                $visible = (int) $this->pdo->query(
                    'SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
                $files = count(glob($this->root . '/database/migrations/*.php') ?: []);

                // Not an exact table count — migrations do not map one to one to
                // tables. What this catches is the shape of the failure: a schema
                // with migrations applied and almost nothing visible.
                return [
                    'status' => $visible > 0 ? HealthCheck::OK : HealthCheck::FAILING,
                    'observed' => $visible . ' table(s) visible to the application account across '
                        . $files . ' migration file(s)',
                ];
            },
            threshold: 'the application account can see the tables it reads',
            remediation: 'php tools/db/grant-application-account.php --apply',
            owner: 'whoever installed this');

        // 4. IS THE DEFAULT CREDENTIAL STILL HERE? It has a banner already, and a
        //    banner is where somebody using the product sees it. This is where an
        //    operator checking an installation sees it, which is a different
        //    person at a different moment.
        $checks->register('access.default_credential',
            function (): array {
                foreach ($this->store->all('users') as $user) {
                    if ((string) ($user['email'] ?? '') === Session::LOCAL_DEFAULT_EMAIL) {
                        return ['status' => HealthCheck::FAILING,
                            'observed' => 'the seeded local account ' . Session::LOCAL_DEFAULT_EMAIL
                                . ' still exists'];
                    }
                }

                return ['status' => HealthCheck::OK, 'observed' => 'no seeded default account'];
            },
            threshold: 'no seeded development credential exists',
            remediation: 'Delete the account on the Accounts screen. A default credential nobody '
                . 'can see is how a demo account reaches production.',
            owner: 'whoever operates this tenant');

        // 5. IS THE PASSWORD FLOOR IN PLACE? 8 characters is the owner's standard for the
        //    whole application (22 Oct 2026); a floor below it would be a weakening nobody chose.
        $checks->register('access.password_floor',
            fn (): array => Session::PASSWORD_FLOOR >= 8
                ? ['status' => HealthCheck::OK,
                    'observed' => Session::PASSWORD_FLOOR . ' characters']
                : ['status' => HealthCheck::FAILING,
                    'observed' => Session::PASSWORD_FLOOR . ' characters — below the standard'],
            threshold: 'at least 8 characters (the application standard)',
            remediation: 'Keep Session::PASSWORD_FLOOR at 8. Any account can be given a longer '
                . 'password from Administration → User Management → Reset Password.',
            owner: 'whoever operates this tenant');

        // 6. DOES ANYBODY REPORT TO SOMEBODY WHO REPORTS TO THEM? In a ring each
        //    is the other's direct report, so each can read the other's salary —
        //    correctly authorised by every check in the product, because nothing
        //    is broken and the data is simply wrong.
        $checks->register('organisation.reporting_lines',
            function (): array {
                $lines = new ReportingLines($this->store);
                $cycles = $lines->cycles();
                $dangling = $lines->danglingManagers();

                return [
                    'status' => $cycles === [] && $dangling === []
                        ? HealthCheck::OK : HealthCheck::FAILING,
                    'observed' => count($cycles) . ' reporting cycle(s), '
                        . count($dangling) . ' manager(s) pointing at no employee',
                ];
            },
            threshold: 'no cycles and no dangling managers',
            remediation: 'php tools/reporting-lines.php names the members of each ring. A cycle '
                . 'grants everybody in it read access to everybody else\'s pay.',
            owner: 'whoever maintains the org structure');

        // 7. IS THE STATUTORY PACK A REAL ONE? A synthetic pack computes numbers
        //    that are machinery evidence and not law, and the compliance screen
        //    says so — but an operator asking "is this installation ready to run
        //    payroll" needs it in the same list as everything else.
        // STATUTORY COMPLIANCE IS SIGNED OFF PER COMPANY (owner, 22 Oct 2026): payroll's PF, ESI, PT and TDS come
        // from Organization Configuration (Salary structure "As per Government rules", PT slabs, Income Tax Rules),
        // and each company names the authorised signatory who verified them — Companies → Statutory compliance
        // sign-off. Failing until every company has a named owner and a verification inside the last 12 months.
        $checks->register('compliance.statutory_pack',
            function (): array {
                $missing = [];
                $stale = [];
                $total = 0;

                foreach ($this->store->all('companies') as $c) {
                    if (Modules::isRetired($c) || (string) ($c['deleted_at'] ?? '') !== '' || strtolower((string) ($c['status'] ?? 'active')) === 'inactive') {
                        continue;
                    }

                    $total++;
                    $name = trim((string) ($c['name'] ?? '')) ?: '#' . (string) $c['id'];
                    $on = substr(trim((string) ($c['compliance_verified_on'] ?? '')), 0, 10);

                    if (trim((string) ($c['compliance_owner'] ?? '')) === '' || $on === '') {
                        $missing[] = $name;
                    } elseif ($on < date('Y-m-d', strtotime('-12 months'))) {
                        $stale[] = $name . ' (verified ' . Dates::dmy($on) . ')';
                    }
                }

                if ($total === 0) {
                    return ['status' => HealthCheck::FAILING, 'observed' => 'no company is set up yet'];
                }

                if ($missing === [] && $stale === []) {
                    return ['status' => HealthCheck::OK, 'observed' => $total . ' of ' . $total . ' compan(y/ies) signed off by a named compliance owner'];
                }

                return ['status' => HealthCheck::FAILING, 'observed' => implode(' · ', array_filter([
                    $missing === [] ? '' : 'no sign-off: ' . implode(', ', $missing),
                    $stale === [] ? '' : 'older than 12 months: ' . implode(', ', $stale),
                ]))];
            },
            threshold: 'every company has a named compliance owner who verified the statutory rates in the last 12 months',
            remediation: 'Organization Configuration → Companies → Statutory compliance sign-off: enter the authorised signatory and the date the PF, ESI, PT, LWF and TDS rates were verified.',
            owner: "each company's compliance owner / authorised signatory");

        // 8. CAN THE APPLICATION SEND EMAIL? Alerts, BGV requests, interview notices and payslip mails all go
        //    through one mail server; switched off, or failing, they are silently not delivered.
        $checks->register('email.smtp_working',
            function (): array {
                $decl = (array) json_decode((string) @file_get_contents($this->root . '/config/alert-emails.json'), true);
                $saved = (array) ($this->customisation?->live()[(string) ($decl['smtp_customisation_key'] ?? 'alert-emails-smtp')] ?? []);
                $smtp = array_replace((array) ($decl['smtp_defaults'] ?? []), $saved);
                $gaps = array_filter([
                    !(bool) ($smtp['enabled'] ?? false) ? 'sending is switched off' : '',
                    trim((string) ($smtp['host'] ?? '')) === '' ? 'no mail server host' : '',
                    trim((string) ($saved['password'] ?? '')) === '' ? 'no password saved' : '',
                    trim((string) ($smtp['from_email'] ?? '')) === '' ? 'no From address' : '',
                ]);

                if ($gaps !== []) {
                    return ['status' => HealthCheck::FAILING, 'observed' => implode(', ', $gaps)];
                }

                $since = date('Y-m-d H:i:s', strtotime('-7 days'));
                $sent = $failed = 0;
                $last = null;

                foreach ($this->store->all('notification_log') as $r) {
                    $st = (string) ($r['email_status'] ?? '');
                    if (($st !== Outbox::SENT && $st !== Outbox::FAILED) || (string) ($r['created_at'] ?? '') < $since) {
                        continue;
                    }
                    $st === Outbox::SENT ? $sent++ : $failed++;
                    if ($last === null || (string) $r['created_at'] > (string) $last['created_at']) {
                        $last = $r;
                    }
                }

                $observed = (string) $smtp['host'] . ' · last 7 days: ' . $sent . ' sent, ' . $failed . ' failed';

                return ['status' => $last !== null && (string) $last['email_status'] === Outbox::FAILED ? HealthCheck::FAILING : HealthCheck::OK,
                    'observed' => $observed . ($last !== null && (string) $last['email_status'] === Outbox::FAILED ? ' — the latest email failed' : '')];
            },
            threshold: 'sending switched on, server and password saved, and the latest email delivered',
            remediation: 'Organization Setup / Configuration → Alert Email Drafts → Mail server: enter host, port, encryption, username, password and From address, switch sending on, and send a test mail.',
            owner: 'whoever operates this tenant');

        // 9. ARE THE SCHEDULED JOBS RUNNING? The morning absence sweep and the unauthorised-absence escalation
        //    run from the server's scheduler (tools/db/schedule.php), which leaves a heartbeat each time it runs.
        $checks->register('jobs.scheduled_runs',
            function (): array {
                $late = [];
                $seen = [];

                foreach (['absence' => 'absence sweep', 'unauthorized-absence' => 'unauthorised-absence escalation'] as $job => $label) {
                    $f = $this->root . '/storage/logs/schedule-' . $job . '.last';
                    $at = is_readable($f) ? strtotime(trim((string) file_get_contents($f))) : false;

                    if ($at === false) {
                        $late[] = $label . ' has never run';
                    } elseif (time() - $at > 36 * 3600) {
                        $late[] = $label . ' last ran ' . date('d-m-Y H:i', $at);
                    } else {
                        $seen[] = $label . ' ran ' . date('d-m-Y H:i', $at);
                    }
                }

                return $late === []
                    ? ['status' => HealthCheck::OK, 'observed' => implode(' · ', $seen)]
                    : ['status' => HealthCheck::FAILING, 'observed' => implode(' · ', $late)];
            },
            threshold: 'each daily job has run in the last 36 hours',
            remediation: 'On the server, schedule two daily tasks (Windows Task Scheduler / cron), early morning: "php tools/db/schedule.php absence" and "php tools/db/schedule.php unauthorized-absence", run from the application folder. Ask the person who installed the application.',
            owner: 'whoever installed this');

        // 10. ARE THE BIOMETRIC DEVICES SENDING PUNCHES? A device that stopped syncing turns into a day of
        //     "absent" for everybody behind it.
        $checks->register('attendance.biometric_sync',
            function (): array {
                $total = 0;
                $bad = [];

                foreach ($this->store->all('biometric_configs') as $c) {
                    if (!(bool) ($c['enabled'] ?? false)) {
                        continue;
                    }

                    $total++;
                    $name = trim((string) ($c['name'] ?? $c['device_name'] ?? '')) ?: 'device #' . (string) $c['id'];
                    $every = max(5, (int) ($c['sync_interval_min'] ?? 60) ?: 60);
                    $last = strtotime((string) ($c['last_sync_at'] ?? '')) ?: 0;

                    if (str_starts_with((string) ($c['last_status'] ?? ''), 'ERROR')) {
                        $bad[] = $name . ': ' . mb_substr((string) $c['last_status'], 0, 80);
                    } elseif ($last === 0) {
                        $bad[] = $name . ': never synced';
                    } elseif (time() - $last > max(3 * $every * 60, 6 * 3600)) {
                        $bad[] = $name . ': last synced ' . date('d-m-Y H:i', $last);
                    }
                }

                if ($total === 0) {
                    return ['status' => HealthCheck::OK, 'observed' => 'no device is set to sync'];
                }

                return $bad === []
                    ? ['status' => HealthCheck::OK, 'observed' => $total . ' device(s) syncing on time']
                    : ['status' => HealthCheck::FAILING, 'observed' => implode(' · ', $bad)];
            },
            threshold: 'every enabled device has synced recently with no error',
            remediation: 'Time & Attendance → Biometric Devices / Biometric Device Setup: check the device is powered and on the network, check its IP / API details, press Sync now and read the last status.',
            owner: 'whoever maintains the attendance devices');

        // 11. PUNCHES NOBODY CAN BE MATCHED TO. A device user id with no employee is held, never guessed — and
        //     until it is mapped, that person's days read absent.
        $checks->register('attendance.unmapped_punch_codes',
            function (): array {
                $codes = [];

                foreach ($this->store->all('attendance_pending') as $p) {
                    if (trim((string) ($p['resolved_at'] ?? '')) !== '') {
                        continue;
                    }
                    $code = (string) ($p['device_code'] ?? $p['device_user_id'] ?? '');
                    $codes[$code] = ($codes[$code] ?? 0) + 1;
                }

                if ($codes === []) {
                    return ['status' => HealthCheck::OK, 'observed' => 'every punch is matched to an employee'];
                }

                ksort($codes);

                return ['status' => HealthCheck::FAILING, 'observed' => count($codes) . ' device code(s) not mapped, '
                    . array_sum($codes) . ' punch(es) held — ' . implode(', ', array_slice(array_keys($codes), 0, 8)) . (count($codes) > 8 ? ' …' : '')];
            },
            threshold: 'no punch is waiting for an employee mapping',
            remediation: 'Time & Attendance → Biometric Mapping & Sync: map each device code to its employee (or set the Device User ID on the employee in the Directory). Held punches are applied as soon as they are mapped.',
            owner: 'whoever maintains the attendance devices');

        // 12. LICENCE / SUBSCRIPTION. An on-premise licence or a cloud subscription that lapses locks the
        //     application; the warning belongs here a month before, not on the morning it happens.
        $checks->register('licence.subscription',
            function (): array {
                $licence = new Licence($this->root);

                if ($licence->enforced()) {
                    $st = $licence->status();

                    if ($st['state'] === 'none') {
                        return ['status' => HealthCheck::FAILING, 'observed' => 'no licence is activated' . ($st['reason'] !== '' ? ' — ' . $st['reason'] : '')];
                    }

                    $end = (string) ($st['expires_on'] ?? '');
                    $observed = 'on-premise licence' . ($end !== '' ? ', valid to ' . Dates::dmy($end) : '');

                    return ['status' => $st['state'] === 'expired' || ($end !== '' && $end <= date('Y-m-d', strtotime('+30 days'))) ? HealthCheck::FAILING : HealthCheck::OK,
                        'observed' => $observed . ($st['state'] === 'expired' ? ' — EXPIRED' : '')];
                }

                $sub = null;
                try {
                    foreach ($this->store->all('subscriptions') as $s) {
                        if ($sub === null || (string) ($s['current_period_end'] ?? '') > (string) ($sub['current_period_end'] ?? '')) {
                            $sub = $s;
                        }
                    }
                } catch (\Throwable) {
                    $sub = null;
                }

                $end = substr((string) ($sub['current_period_end'] ?? ''), 0, 10);

                if ($sub === null || $end === '') {
                    return ['status' => HealthCheck::OK, 'observed' => 'no licence or subscription applies to this installation'];
                }

                $status = (string) ($sub['status'] ?? '');

                return ['status' => in_array($status, ['cancelled', 'suspended'], true) || $end <= date('Y-m-d', strtotime('+15 days')) ? HealthCheck::FAILING : HealthCheck::OK,
                    'observed' => 'subscription ' . ($status !== '' ? $status . ', ' : '') . 'paid to ' . Dates::dmy($end)];
            },
            threshold: 'licence / subscription valid for at least the next 30 days (15 for a subscription)',
            remediation: 'On-premise: Administration → Updates & Licence — renew or activate the licence. Cloud: Administration → My Subscription — renew before the end date.',
            owner: 'whoever operates this tenant');

        // 13. A CLOSED PAYROLL NOBODY HAS APPROVED. Salaries cannot be paid, and the month's statutory filings
        //     wait, while a run sits closed but unapproved.
        $checks->register('payroll.awaiting_approval',
            function (): array {
                $waiting = [];

                foreach ($this->store->all('payroll_runs') as $r) {
                    $st = strtolower((string) ($r['status'] ?? 'closed'));
                    if ($st === 'approved' || $st === 'discarded' || $st === 'reversed') {
                        continue;
                    }
                    $waiting[] = (string) ($r['period'] ?? $r['cycle_label'] ?? '?') . ($st !== 'closed' ? ' (' . str_replace('_', ' ', $st) . ')' : '');
                }

                sort($waiting);

                return $waiting === []
                    ? ['status' => HealthCheck::OK, 'observed' => 'no payroll is waiting for approval']
                    : ['status' => HealthCheck::FAILING, 'observed' => count($waiting) . ' month(s) waiting: ' . implode(', ', $waiting)];
            },
            threshold: 'every generated payroll is approved (or discarded)',
            remediation: 'Payroll → Salary Approval: review each month, then Approve — or reject it and generate again.',
            owner: 'the payroll approver');

        // 14. IS EVERY COMPANY FULLY SET UP? Payslips, challans and Form 16 print these; a blank one surfaces
        //     at the worst moment — on a statutory filing.
        $checks->register('organisation.company_setup',
            function (): array {
                $need = ['pan' => 'PAN', 'tan' => 'TAN', 'pf_establishment_code' => 'PF code', 'esic_employer_code' => 'ESIC code',
                    'pt_registration_no' => 'PT registration', 'timezone' => 'time zone', 'notice_period_days' => 'notice period', 'state' => 'state'];
                $gaps = [];
                $total = 0;

                foreach ($this->store->all('companies') as $c) {
                    if (Modules::isRetired($c) || (string) ($c['deleted_at'] ?? '') !== '' || strtolower((string) ($c['status'] ?? 'active')) === 'inactive') {
                        continue;
                    }

                    $total++;
                    $missing = array_values(array_filter($need, static fn (string $label, string $col): bool => trim((string) ($c[$col] ?? '')) === '', ARRAY_FILTER_USE_BOTH));
                    $missing === [] || $gaps[] = (trim((string) ($c['name'] ?? '')) ?: '#' . (string) $c['id']) . ': ' . implode(', ', $missing);
                }

                if ($total === 0) {
                    return ['status' => HealthCheck::FAILING, 'observed' => 'no company is set up yet'];
                }

                return $gaps === []
                    ? ['status' => HealthCheck::OK, 'observed' => $total . ' compan(y/ies) complete']
                    : ['status' => HealthCheck::FAILING, 'observed' => implode(' · ', $gaps)];
            },
            threshold: 'every company has PAN, TAN, PF and ESIC codes, PT registration, time zone, notice period and state',
            remediation: 'Organization Setup / Configuration → Companies: fill the missing fields in each company box and save.',
            owner: 'whoever maintains the org structure');

        return $checks->run();
    }
}
