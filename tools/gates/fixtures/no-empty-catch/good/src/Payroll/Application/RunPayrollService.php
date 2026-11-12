<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Application;

/** CLEAN FIXTURE — one rethrow, one wrap, one reviewed and annotated swallow. */
final class RunPayrollService
{
    public function run(int $runId): string
    {
        try {
            $this->engine->calculate($runId);
        } catch (\Throwable $e) {
            throw new PayrollRunFailed($runId, $e);
        }

        try {
            $this->cache->warm($runId);
        } catch (\Throwable $e) {
            // gate:allow-swallow cache warm-up is an optimisation; the run is already committed
        }

        try {
            return $this->engine->summarise($runId);
        } catch (\Throwable $e) {
            $this->log->error('summary failed', ['run_id' => $runId, 'cause' => $e->getMessage()]);
            throw $e;
        }
    }
}
