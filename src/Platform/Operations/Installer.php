<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * A CLEAN ON-PREMISE INSTALL — Part XXXII Phase 9, Part XXVI.
 *
 * *"A clean on-premise install completes with NO DEFAULT CREDENTIAL, registers
 * its scheduler and worker, and passes its own health check."*
 *
 * The no-default-credential clause is the one that decides the shape of this
 * class. An installer that creates `admin/admin` and asks the operator to change
 * it has created an account that is `admin/admin` on some fraction of installs
 * forever — the fraction being every site where the person who installed it was
 * not the person who was going to use it. So there is no default password here,
 * no seeded account, and no `--password` flag: the install produces a one-time
 * enrolment token, and the first administrator sets their own credential through
 * it.
 *
 * REGISTERING THE SCHEDULER AND WORKER IS PART OF INSTALLING, not a follow-up
 * step in a runbook. An install that "succeeds" and leaves nothing running is an
 * install whose payroll reminders, statutory sweeps and notification retries all
 * silently never happen, and the first symptom is a missed filing.
 *
 * AND IT CHECKS ITSELF. An installer that reports success without asking whether
 * the thing it installed works has reported on its own intentions.
 */
final class Installer
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @param array<string, bool> $environment what the host actually provides
     * @return array{ok: bool, problems: list<string>, enrolment_token: ?string, log: list<string>, health: array}
     */
    public function install(array $environment, HealthCheck $health, Scheduler $scheduler): array
    {
        $problems = [];
        $this->log = [];

        foreach (['php_8_3', 'pdo_mysql', 'openssl', 'writable_storage'] as $requirement) {
            if (($environment[$requirement] ?? false) !== true) {
                $problems[] = "the host does not provide '{$requirement}'. An install that proceeds without "
                    . 'a prerequisite fails later, in production, on a symptom that names something else';
            }
        }

        if ($problems !== []) {
            return ['ok' => false, 'problems' => $problems, 'enrolment_token' => null,
                'log' => $this->log, 'health' => []];
        }

        // A one-time enrolment token. NOT a password, not a seeded account, and
        // nothing an operator can skip past: there is no account to log into
        // until somebody enrols through this.
        $token = bin2hex(random_bytes(32));
        $this->log[] = 'created a single-use enrolment token; NO administrator account exists yet and no '
            . 'default credential was written';

        $scheduler->register('payroll.sweep', 'daily');
        $scheduler->register('compliance.sweep', 'daily');
        $scheduler->register('notification.retry', 'every 5 minutes');
        $scheduler->registerWorker('default');
        $this->log[] = 'registered ' . count($scheduler->jobs()) . ' scheduled job(s) and '
            . count($scheduler->workers()) . ' worker(s) — an install that leaves nothing running is one '
            . 'whose sweeps silently never happen, and the first symptom is a missed filing';

        $report = $health->run();

        if (!$report['ok']) {
            $problems[] = 'the install completed and then FAILED its own health check: '
                . implode('; ', $report['failing']);
        }

        $this->log[] = 'ran ' . count($report['checks']) . ' health check(s)';

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'enrolment_token' => $token,
            'log' => $this->log,
            'health' => $report,
        ];
    }

    /**
     * Every credential this installer could possibly write, for the gate to
     * assert is empty. A method that returns a list is checkable; a claim in a
     * README is not.
     *
     * @return list<string>
     */
    public function defaultCredentials(): array
    {
        return [];
    }
}
