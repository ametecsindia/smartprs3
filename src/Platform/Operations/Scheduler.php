<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * WHAT IS ACTUALLY RUNNING — Part XXXII Phase 9, Part XXXI §XXXI.7.
 *
 * A registry, and a heartbeat. The heartbeat is the part that matters: a
 * scheduler that has stopped looks exactly like a scheduler with nothing to do,
 * and the difference is a filing deadline. So `isAlive()` asks when it last
 * beat, and a stale heartbeat is a failing health check rather than a quiet
 * absence of alerts.
 */
final class Scheduler
{
    /** @var array<string, string> job key => cadence */
    private array $jobs = [];

    /** @var list<string> */
    private array $workers = [];

    private ?\DateTimeImmutable $lastHeartbeat = null;

    public function register(string $jobKey, string $cadence): void
    {
        if (trim($jobKey) === '' || trim($cadence) === '') {
            throw new \InvalidArgumentException('A job with no key or no cadence is a job that never runs.');
        }

        $this->jobs[$jobKey] = $cadence;
    }

    public function registerWorker(string $class): void
    {
        $this->workers[] = $class;
    }

    public function beat(\DateTimeImmutable $at): void
    {
        $this->lastHeartbeat = $at;
    }

    /** A scheduler that stopped looks like one with nothing to do. This is the difference. */
    public function isAlive(\DateTimeImmutable $asOf, int $toleranceMinutes = 15): bool
    {
        return $this->lastHeartbeat !== null
            && ($asOf->getTimestamp() - $this->lastHeartbeat->getTimestamp()) <= $toleranceMinutes * 60;
    }

    /** @return array<string, string> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /** @return list<string> */
    public function workers(): array
    {
        return $this->workers;
    }
}
