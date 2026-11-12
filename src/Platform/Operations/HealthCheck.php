<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * CHECKS THAT REPORT WHAT THEY COULD NOT RUN — Part XXXII Phase 9,
 * Part XXXI R-XXXI-091.
 *
 * The pattern this whole build keeps returning to, applied to health: a check
 * that throws is not a check that passed. So a probe raising an exception
 * becomes an `unknown` outcome, `unknown` counts as failing, and the report
 * names it — because the alternative is a green dashboard produced by a
 * monitoring system that cannot reach the thing it monitors, which is the worst
 * possible arrangement of those two facts.
 *
 * Every check carries its OBSERVED VALUE and its THRESHOLD, not just a colour.
 * "Disk: red" sends somebody to look; "disk: 94% used, threshold 85%, since
 * 03:10" tells them what to do and how long it has been true.
 */
final class HealthCheck
{
    public const OK = 'ok';
    public const FAILING = 'failing';
    public const UNKNOWN = 'unknown';

    /** @var array<string, array{probe: callable, threshold: string, remediation: string, owner: string}> */
    private array $checks = [];

    /**
     * @param callable(): array{status: string, observed: string} $probe
     */
    public function register(
        string $code,
        callable $probe,
        string $threshold,
        string $remediation,
        string $owner,
    ): void {
        if (trim($remediation) === '' || trim($owner) === '') {
            throw new \InvalidArgumentException(
                "Health check '{$code}' has no remediation or no owner. An alert nobody owns, with no stated "
                . 'next step, is a notification that trains people to ignore notifications.'
            );
        }

        $this->checks[$code] = compact('probe', 'threshold', 'remediation', 'owner');
    }

    /**
     * @return array{ok: bool, checks: array<string, array<string, string>>, failing: list<string>}
     */
    public function run(\DateTimeImmutable $asOf = new \DateTimeImmutable()): array
    {
        $results = [];
        $failing = [];

        foreach ($this->checks as $code => $check) {
            try {
                $outcome = ($check['probe'])();
                $status = $outcome['status'];
                $observed = $outcome['observed'];
            } catch (\Throwable $e) {
                // A probe that threw has not passed. Converting the failure into
                // a typed UNKNOWN keeps it visible; letting it escape or
                // swallowing it gives a green board on a system nobody can reach.
                $status = self::UNKNOWN;
                $observed = 'the probe could not run: ' . $e->getMessage();
            }

            $results[$code] = [
                'status' => $status,
                'observed' => $observed,
                'threshold' => $check['threshold'],
                'remediation' => $check['remediation'],
                'owner' => $check['owner'],
                'checked_at' => $asOf->format('c'),
            ];

            if ($status !== self::OK) {
                $failing[] = "{$code} ({$status}: {$observed}) — {$check['remediation']}, owner "
                    . $check['owner'];
            }
        }

        return ['ok' => $failing === [], 'checks' => $results, 'failing' => $failing];
    }

    /** @return list<string> */
    public function registered(): array
    {
        return array_keys($this->checks);
    }
}
