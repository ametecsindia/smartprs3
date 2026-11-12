<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Application;

/** VIOLATING FIXTURE — 426 of SmartPRS 2's 843 catch blocks look like these. */
final class RunPayrollService
{
    public function run(int $runId): ?string
    {
        try {
            $this->engine->calculate($runId);
        } catch (\Throwable $e) {
        }

        try {
            return $this->engine->summarise($runId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function post(int $runId): bool
    {
        try {
            return $this->ledger->post($runId);
        } catch (\Throwable $e) {
            // nothing to do here
        }
        return false;
    }
}
