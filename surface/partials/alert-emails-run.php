<?php
/**
 * ONE RUN OF THE AUTOMATIC ALERT EMAILS — everything due today, sent and
 * logged. Shared by "Run now" (surface/actions/alert-emails.php) and the daily
 * automatic run app.php starts on the first signed-in page after the
 * configured time (alertEmailsDaily()). Needs app.php's org helpers.
 */
declare(strict_types=1);

use SmartPRS\Surface\AlertEmails;
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;

if (!function_exists('alertEmailsRun')) {
    /** @return array{sent: int, failed: int, skipped: int, due: int, problems: list<string>} */
    function alertEmailsRun(SmartPRS\Surface\RowStore $store, AlertEmails $alerts, SmartPRS\Surface\Probation $probation, array $users, string $today,
        ?callable $absentOn = null): array
    {
        $employees = liveRows($store, 'employees');
        $byId = [];

        foreach ($store->all('employees') as $e) {
            $byId[(string) ($e['id'] ?? '')] = $e;
        }

        $lookups = attendanceLookups($store, employmentLabels($store));
        $describe = static function (array $e) use ($store, $lookups): array {
            $org = employeeOrgCells($store, $e, $lookups);
            $doj = Employment::joinedOn($e);

            return ['emp_name' => (string) ($e['name'] ?? ''), 'emp_code' => Employment::code($e), 'designation' => $org['designation'],
                'department' => $org['department'], 'branch' => $org['branch'], 'company' => $org['company'] !== '' ? $org['company'] : 'the company',
                'reporting_manager' => (string) $org['reports_to'], 'doj' => $doj === '' ? '' : Dates::dmy($doj),
                'email' => trim((string) (($e['email'] ?? '') ?: ($e['personal_email'] ?? '')))];
        };
        // THE INSTALMENTS THIS MONTH'S PAYROLL WILL TAKE — the payroll close's own
        // recovery engine, so the email and the payslip carry the same figures.
        $recoveries = new SmartPRS\Surface\Recoveries($store);
        $emisFor = static function (array $e, string $period) use ($recoveries): array {
            $out = [];

            foreach ((array) ($recoveries->forEmployment($e, $period)['lines'] ?? []) as $line) {
                $code = (string) ($line['code'] ?? '');
                $out[] = ['code' => $code, 'amount' => (string) ($line['amount'] ?? ''),
                    'label' => str_starts_with($code, SmartPRS\Surface\Recoveries::ADVANCE) ? 'salary advance' : 'loan',
                    'detail' => (string) ($line['trace'] ?? '')];
            }

            return $out;
        };
        $due = $alerts->due($employees, $today, $describe,
            static fn (array $e): bool => $probation->onProbation($e),
            static fn (array $e): ?string => $probation->endDate($e, $probation->configFor($e)),
            $emisFor, $absentOn);

        return ['due' => count($due)] + $alerts->dispatch($due, $byId, $users);
    }

    /**
     * THE DAILY AUTOMATIC RUN: once a day, on the first signed-in page after the
     * saved "send after" time, when sending is on. Never breaks the page.
     */
    function alertEmailsDaily(SmartPRS\Surface\RowStore $store, AlertEmails $alerts, SmartPRS\Surface\Probation $probation, array $users,
        ?callable $absentOn = null): void
    {
        try {
            $smtp = $alerts->smtp();
            $today = date('Y-m-d');

            if (!$smtp['enabled'] || date('H:i') < (string) $smtp['send_after'] || $alerts->ranOn($today)) {
                return;
            }

            $alerts->markRan($today, 'started'); // first, so a second page in the same minute does not run it again
            $r = alertEmailsRun($store, $alerts, $probation, $users, $today, $absentOn);
            error_log("SmartPRS alert emails {$today}: {$r['sent']} sent, {$r['failed']} failed, {$r['skipped']} already sent");
        } catch (\Throwable $e) {
            error_log('SmartPRS alert emails: ' . $e->getMessage()); // reported, never breaks the page
        }
    }
}
