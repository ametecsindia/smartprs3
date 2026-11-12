<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Scheduler;

use SmartPRS\Platform\Persistence\CrossTenantEscape;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * THE PLATFORM SCHEDULE — spine S2, Part IX §IX.7.6.
 *
 * `CrossTenantEscape::DECLARED_CALL_SITES` has named this class since the
 * tenancy layer was written: "the scheduler sweep: walks every tenant to
 * enqueue its per-tenant work". Nothing implemented it, so the allow-list
 * described a door with no room behind it. This is the room.
 *
 * The shape is the point. A scheduled job is the ONE thing in the platform that
 * legitimately has no tenant — it exists to visit all of them — and that is
 * exactly the shape SmartPRS 2's tenancy hole takes: `when($tid, …)` skips its
 * callback when the tenant is null, so anything that lost the variable read
 * every customer silently. Here the crossing is a positive grant that is asked
 * for, audited before the work runs, and closed in a `finally`; inside the
 * loop, each tenant's work runs under a NORMAL TenantContext, so the job body
 * is ordinary tenant-scoped code that could not reach another tenant if it
 * tried.
 *
 * §IX.7.6 also asks for the sweep's own record: its high-water mark, duration
 * and backlog. R-IX-067 makes the health check fail loudly when that record is
 * stale, because "users must not be left to infer it from missing reminders".
 */
final class TenantSweep
{
    /** @var \Closure(array<string, mixed>): void */
    private readonly \Closure $auditSink;

    /**
     * @param callable(array<string, mixed>): void $auditSink where the cross-tenant grant records itself
     */
    public function __construct(
        private readonly \PDO $pdo,
        callable $auditSink,
    ) {
        $this->auditSink = $auditSink(...);
    }

    /**
     * Run one job across every active tenant.
     *
     * @param  callable(TenantContext, int): array{processed?: int, suppressed?: int, high_water_at?: ?string}  $work
     * @return array{processed: int, suppressed: int, tenants: int, duration_ms: int, high_water_at: ?string}
     */
    public function run(string $jobKey, callable $work, \DateTimeImmutable $now): array
    {
        $started = hrtime(true);

        // The grant is opened around the TENANT LIST only. The work itself runs
        // per tenant under a normal context — a grant held open for the whole
        // sweep would make every job body cross-tenant-capable for as long as
        // the sweep ran, which is the capability this class exists to contain.
        $tenants = (new CrossTenantEscape($this->auditSink))->run(
            'platform.scheduler.run',
            "the {$jobKey} sweep enumerates tenants to enqueue their own work",
            fn (): array => TenantScopedQuery::acrossTenants($this->pdo, 'tenants')
                ->where('status_code', '=', 'active')
                ->orderBy('id')
                ->select(['id', 'code']),
        );

        $processed = 0;
        $suppressed = 0;
        $highWater = null;
        $error = null;

        foreach ($tenants as $row) {
            $context = new TenantContext();
            $context->establish((int) $row['id']);

            try {
                $result = $work($context, (int) $row['id']);
            } catch (\Throwable $e) {
                // One tenant's failure must not stop the others: a sweep that
                // aborts on the first bad tenant leaves every later tenant
                // unswept, and nothing says which ones. The error is recorded
                // on the heartbeat, which is what the health check reads.
                $error = ($error === null ? '' : $error . ' | ')
                    . $row['code'] . ': ' . $e::class . ' ' . $e->getMessage();

                continue;
            } finally {
                $context->release();
            }

            $processed += $result['processed'] ?? 0;
            $suppressed += $result['suppressed'] ?? 0;

            if (isset($result['high_water_at']) && ($highWater === null || $result['high_water_at'] > $highWater)) {
                $highWater = $result['high_water_at'];
            }
        }

        $duration = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->heartbeat($jobKey, $now, $duration, $processed, $suppressed, $highWater, $error);

        return [
            'processed' => $processed,
            'suppressed' => $suppressed,
            'tenants' => count($tenants),
            'duration_ms' => $duration,
            'high_water_at' => $highWater,
        ];
    }

    /**
     * R-IX-067's heartbeat.
     *
     * Written even when the run failed, and especially then: a health check
     * that only sees successful runs cannot tell "no run happened" from "the
     * run went badly", and those need different responses.
     */
    private function heartbeat(
        string $jobKey,
        \DateTimeImmutable $now,
        int $duration,
        int $processed,
        int $suppressed,
        ?string $highWater,
        ?string $error,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO scheduler_heartbeats
               (job_key, started_at, finished_at, duration_ms, processed, suppressed, high_water_at, error)
             VALUES (:job, :started, :finished, :duration, :processed, :suppressed, :high_water, :error)'
        );

        // Written with a prepared statement rather than through
        // TenantScopedQuery: that builder's job is to stamp and enforce a
        // tenant, and this table has none by declaration (TableRegistry).
        // Reaching for `acrossTenants()` here would open the audited escape to
        // write a row about the scheduler's own health, which is not what the
        // escape is for.
        $statement->execute([
            'job' => $jobKey,
            'started' => $now->format('Y-m-d H:i:s'),
            'finished' => $now->modify("+{$duration} milliseconds")->format('Y-m-d H:i:s'),
            'duration' => $duration,
            'processed' => $processed,
            'suppressed' => $suppressed,
            'high_water' => $highWater,
            'error' => $error,
        ]);
    }

    /**
     * R-IX-067: is SLA processing degraded?
     *
     * "The health check MUST fail, visibly and loudly, when the scheduler
     * heartbeat is older than the configured margin, and the approvals inbox
     * MUST display a banner stating that SLA processing is degraded."
     *
     * A job that has NEVER run reports degraded, not healthy. The absence of a
     * heartbeat and a stale one mean the same thing to a user waiting for a
     * reminder, and the safe reading of "no evidence" is not "fine".
     *
     * @return array{healthy: bool, last_run_at: ?string, minutes_since: ?int, reason: string}
     */
    public function health(string $jobKey, \DateTimeImmutable $now, int $marginMinutes = 5): array
    {
        $statement = $this->pdo->prepare(
            'SELECT started_at, error FROM scheduler_heartbeats
             WHERE job_key = :job ORDER BY started_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['job' => $jobKey]);
        $last = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($last === false) {
            return [
                'healthy' => false,
                'last_run_at' => null,
                'minutes_since' => null,
                'reason' => "{$jobKey} has never run. Spine S2 makes the scheduler mandatory, so this is an "
                    . 'incident rather than a configuration choice.',
            ];
        }

        $since = intdiv(
            $now->getTimestamp() - (new \DateTimeImmutable((string) $last['started_at']))->getTimestamp(),
            60,
        );

        if ($last['error'] !== null && $last['error'] !== '') {
            return [
                'healthy' => false,
                'last_run_at' => (string) $last['started_at'],
                'minutes_since' => $since,
                'reason' => "{$jobKey} last ran {$since} minute(s) ago and reported: {$last['error']}",
            ];
        }

        return $since > $marginMinutes
            ? [
                'healthy' => false,
                'last_run_at' => (string) $last['started_at'],
                'minutes_since' => $since,
                'reason' => "{$jobKey} last ran {$since} minute(s) ago, over the {$marginMinutes} minute "
                    . 'margin. SLA processing is degraded: reminders and escalations are late, and the '
                    . 'inbox must say so rather than leaving users to infer it (R-IX-067).',
            ]
            : [
                'healthy' => true,
                'last_run_at' => (string) $last['started_at'],
                'minutes_since' => $since,
                'reason' => "{$jobKey} last ran {$since} minute(s) ago",
            ];
    }
}
