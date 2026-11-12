<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SLA timer table (Part IX §IX.7.3) and the sweep's own heartbeat.
 *
 * §IX.7.1 is blunt about what this replaces: SmartPRS 2 has no `due_at`, no
 * `sla_*`, no `reminder_sent_at` and no `escalate_at` column anywhere, and of
 * the twelve scheduled commands it registers, none touches a pending approval.
 * An item assigned to somebody who never acts is pending forever, invisibly.
 *
 * "Every timed obligation is a durable row, not an in-memory schedule." The
 * consequence is §IX.7.6's table of behaviour when the scheduler is not
 * running: no data loss, the backlog processed in `fire_at` order, breach
 * timestamps computed from the stored `fire_at` rather than the sweep's wall
 * clock, and automatic decisions on stale timers SUPPRESSED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_timers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->restrictOnDelete();
            // Nullable: a node-level timer (an expiry on the whole step) has no
            // one task, and an instance-level one has neither.
            $table->unsignedBigInteger('workflow_task_id')->nullable();

            $table->string('node_key', 64);
            $table->string('timer_kind', 16)->comment('due|reminder|escalation|expiry|stale_check');
            // DATETIME(3): the sweep orders by this, and two timers in the same
            // second on a busy tenant must still have a defined order.
            $table->dateTime('fire_at')->comment('UTC, computed ONCE at activation from the node calendar');
            $table->unsignedInteger('sequence_no')->default(0)->comment('the nth reminder');
            $table->integer('offset_minutes')->nullable()
                ->comment('the declared offset relative to due_at; negative is a pre-due nudge');
            $table->string('status', 16)->default('scheduled')->comment('scheduled|fired|cancelled|superseded');

            $table->dateTime('fired_at')->nullable()->comment('when the sweep actually got to it');
            $table->string('outcome', 32)->nullable()->comment('the SweepDecision action taken');
            $table->text('outcome_reason')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // R-IX-060: reminders are idempotent per (task, kind, sequence),
            // "so a scheduler catch-up cannot send the same reminder twice".
            // The node key is in the key because an instance-level timer has no
            // task id to be unique by.
            $table->unique(
                ['tenant_id', 'workflow_instance_id', 'node_key', 'workflow_task_id', 'timer_kind', 'sequence_no'],
                'workflow_timers_idempotency_unique',
            );
            // The sweep's own query: status + fire_at, in that order, so
            // `WHERE status = 'scheduled' AND fire_at <= now() ORDER BY fire_at`
            // is one index range rather than a scan of every timer ever written.
            $table->index(['status', 'fire_at'], 'workflow_timers_sweep_index');
            $table->index(['tenant_id', 'workflow_instance_id'], 'workflow_timers_tenant_instance_index');
        });

        /**
         * §IX.7.6: "record the sweep's high-water mark and duration", and
         * R-IX-067's heartbeat — the health check fails loudly when this is
         * older than the configured margin, and the inbox shows a banner
         * saying SLA processing is degraded.
         *
         * Platform-scoped: the sweep runs on the tenant-independent schedule,
         * and a heartbeat that a tenant owned would go missing exactly when a
         * tenant's own data was the problem.
         */
        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('job_key', 64)->comment('workflow:timers, audit:anchor, …');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('suppressed')->default(0)
                ->comment('R-IX-066 withholdings — a non-zero count is an operator alert');
            $table->dateTime('high_water_at')->nullable()
                ->comment('the fire_at of the newest timer this run processed');
            $table->dateTime('oldest_overdue_at')->nullable()
                ->comment('the fire_at of the oldest timer still scheduled and due');
            $table->unsignedInteger('overdue_count')->default(0);
            $table->text('error')->nullable();

            $table->index(['job_key', 'started_at'], 'scheduler_heartbeats_job_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('workflow_timers');
    }
};
