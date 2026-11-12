<?php

declare(strict_types=1);

/**
 * THE SLA SWEEP OVER THE REQUESTS THIS PRODUCT ACTUALLY RUNS.
 *
 *   php tools/workflow-sweep.php --tenant=1
 *   php tools/workflow-sweep.php --tenant=1 --at="2026-09-08 18:00"   (a hypothetical clock)
 *
 * `tools/db/schedule.php timers` sweeps the platform's `workflow_timers` TABLE,
 * which nothing on any screen writes to yet. This sweeps the requests that are
 * really open in `workflow_requests`, against the `sla_hours` really declared in
 * `config/workflows.json`, through `Surface\Sla` — which asks
 * `Platform\Workflow\Sla\Sweep` for every decision and computes none of its own.
 *
 * IT REPORTS. IT DOES NOT NOTIFY, ESCALATE, REASSIGN OR DECIDE. There is no
 * notification channel in this build, so a breach printed here has been told to
 * whoever is reading this terminal and to nobody else — not to the approver it
 * is waiting on. That is printed at the end of every run, on purpose, because a
 * job named "sweep" that exits 0 quietly reads like a job that handled it.
 *
 * IT EXITS NON-ZERO WHEN SOMETHING HAS BREACHED, so a scheduler notices. A
 * sweep whose only signal is text nobody reads is the same as no sweep. Exit 1
 * for breaches or withheld decisions, exit 2 for a refusal to run at all.
 *
 * ponytail: one tenant per invocation, named on the command line. Looping every
 * tenant needs the platform's `TenantSweep` and a cross-tenant grant it audits
 * (see `tools/db/schedule.php`); until this runs unattended, a cron line per
 * tenant is smaller than a privilege escalation.
 */

require_once __DIR__ . '/../tests/lib/bootstrap.php';

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Requests;
use SmartPRS\Surface\Sla;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\WorkflowCustomisation;

$options = [];

foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? '1';
    }
}

$tenant = (string) ($options['tenant'] ?? '');

// REFUSED, not defaulted. Every read below is tenant-scoped and an unscoped
// SqlStore matches `tenant_id = NULL`, which returns nothing and looks exactly
// like "no request is overdue" — the one wrong answer this tool must never give.
if ($tenant === '') {
    fwrite(STDERR,
        "Usage: php tools/workflow-sweep.php --tenant=<id> [--at=\"YYYY-MM-DD HH:MM\"]\n\n"
        . "  Refused rather than swept without a tenant. Every read here is tenant-scoped, and an\n"
        . "  unscoped sweep returns no rows — which is indistinguishable from nothing being overdue.\n");
    exit(2);
}

$envFile = __DIR__ . '/../.env';
$pdo = Database::connect($envFile);

if ($pdo !== null) {
    $store = (new SqlStore($pdo))->forTenant($tenant);
    $storage = 'MySQL';
} elseif (is_file($envFile)) {
    // A machine with a .env meant to run on MySQL. Sweeping the JSON store
    // instead would report on data the application is not using.
    fwrite(STDERR, "Refused: there is a .env file and no database connection — " . Database::why() . ".\n"
        . "  Sweeping the JSON store instead would report on rows this application is not reading.\n");
    exit(2);
} else {
    $store = (new Store(__DIR__ . '/../storage'))->forTenant($tenant);
    $storage = 'the JSON store';
}

try {
    $now = new DateTimeImmutable((string) ($options['at'] ?? 'now'));
} catch (Exception $e) {
    fwrite(STDERR, "Refused: --at is not a readable instant ({$e->getMessage()}).\n");
    exit(2);
}

try {
    $requests = new Requests($store, __DIR__ . '/../config/workflows.json',
        new WorkflowCustomisation(new Customisation($store)));
    $review = (new Sla($requests))->review($now);
} catch (Throwable $e) {
    // Refused rather than half-worked: a sweep that reports on the workflows it
    // could read and says nothing about the ones it could not is a report that
    // is wrong in the direction of reassurance.
    fwrite(STDERR, "Refused: {$e->getMessage()}\n");
    exit(2);
}

printf("workflow:sla   tenant %s, %s, as of %s\n\n", $tenant, $storage, $review['as_of']);

$counts = $review['counts'];

foreach ($review['requests'] as $row) {
    printf("  %-13s %-10s %-42s %s\n",
        $row['state'],
        $row['request_id'],
        (string) $row['workflow_label'] . ' · ' . (string) $row['step_label'],
        match ((string) $row['state']) {
            // The breach instant, from fire_at — never the sweep's clock.
            Sla::BREACHED => Sla::overBy((int) $row['over_by_minutes'])
                . ', breached at ' . (string) $row['breached_at'],
            Sla::WITHIN => 'due ' . (string) $row['due_at'],
            Sla::NO_DEADLINE => 'no due-within declared for this step',
            default => (string) $row['why'],
        });

    if ($row['suppressed']) {
        printf("               WITHHELD  %s\n", (string) $row['why_suppressed']);
    }
}

if ($review['requests'] === []) {
    echo "  no open requests\n";
}

// ---------------------------------------------------------------------------
// One line per approver, not one per task (R-IX-061, coalesced by the platform).

if ($review['digest'] !== []) {
    echo "\nWHO WOULD BE CHASED, IF ANYTHING CHASED ANYBODY — one digest per recipient:\n";

    foreach ($review['digest'] as $entry) {
        printf("  %-16s %d overdue\n", (string) $entry['recipient'], (int) $entry['count']);

        foreach ($entry['lines'] as $line) {
            echo "                   {$line}\n";
        }
    }
}

printf("\n%d open · %d within · %d breached · %d with no due-within declared · %d unanswerable\n",
    $counts['open'], $counts['within'], $counts['breached'], $counts['no_deadline'], $counts['unanswerable']);

// NOT "on time". Said every run, because the number is otherwise read as one.
if ($counts['no_deadline'] > 0) {
    printf("\n%d request(s) sit on a step that declares NO due-within. They are not on time — nobody\n"
        . "set a deadline for them, and they are counted separately so they cannot flatter the\n"
        . "within-SLA figure above.\n", $counts['no_deadline']);
}

echo "\n" . wordwrap($review['reported_not_sent'], 92) . "\n";

if ($counts['suppressed'] > 0) {
    fwrite(STDERR,
        "\n  ALERT: {$counts['suppressed']} automatic decision(s) were WITHHELD — their timers fired past\n"
        . "  the stale threshold, so the sweep was not running when it should have been. An automatic\n"
        . "  decision taken during an outage nobody noticed is a decision nobody took (R-IX-066).\n");
}

exit($counts['breached'] > 0 || $counts['suppressed'] > 0 || $counts['unanswerable'] > 0 ? 1 : 0);
