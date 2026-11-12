<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow engine's tables (Part IX) — the definition payload, and the
 * runtime an instance actually moves through.
 *
 * §IX.6.3 says stating that decisions are immutable is not enough, because
 * SmartPRS 2's problem is that nothing stops the write. Four mechanisms, and
 * three of them are here in the schema rather than in application code:
 *
 *   1. The conditional update. `workflow_tasks.row_version` and a status column
 *      that the single decision repository method updates only WHERE the row is
 *      still pending. Zero affected rows is a 409, and the caller is handed the
 *      decision that already stands.
 *   2. The database privilege. The application account has no DELETE on
 *      workflow_tasks, workflow_transitions or audit_entries — asserted by
 *      tools/gates/checks/db-privilege.php, not by hoping.
 *   3. The transition ledger, append-only, with UNIQUE (tenant_id,
 *      instance_id, seq) allocated under a row lock on the instance. A replayed
 *      decision cannot obtain a second seq.
 *   4. The completion outbox, with UNIQUE (tenant_id, instance_id,
 *      completion_event_key), so even a hypothetical double-finalise cannot
 *      produce two transfer orders (R-IX-026).
 *
 * R-IX-046: a decision comment lives on the TRANSITION, never as an
 * overwritable column on the item. SmartPRS 2 writes `'remarks' => $v['remarks']
 * ?? null` on every decide, so a blank re-decide erases the prior reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- the definition payload ------------------------------------------

        Schema::create('workflow_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('config_version_id')->constrained('config_versions')->restrictOnDelete();

            $table->string('subject_entity_key', 64);
            $table->string('subject_amount_path', 128)->nullable()->comment('typed Money; W07 refuses amount routing without it');
            $table->string('start_node_key', 64);
            $table->text('business_description');

            $table->boolean('allows_self_approval')->default(false);
            $table->string('self_approval_override', 32)->default('none');
            // R-IX-037: refused outright on a Section 56 operation. There is no
            // configuration by which one person alone completes maker-checker —
            // which is what `admin` satisfying both isHR() and isFinance() is.
            $table->boolean('is_section_56_operation')->default(false);
            $table->json('segregation_sets_json')->nullable();
            $table->string('return_policy', 32)->default('restart');
            $table->string('cancel_policy', 40)->default('requester_until_first_decision');
            $table->string('comment_policy', 32)->default('optional');
            $table->text('instance_key_expr')->nullable()->comment('SPX Text → the idempotency key (§IX.6.4)');
            $table->string('sla_calendar_key', 64)->nullable();
            $table->string('timezone_source', 64)->default('tenant');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'config_version_id'], 'workflow_payloads_tenant_version_unique');
            $table->index(['tenant_id', 'subject_entity_key'], 'workflow_payloads_tenant_entity_index');
        });

        Schema::create('workflow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_payload_id')->constrained('workflow_payloads')->cascadeOnDelete();

            $table->string('node_key', 64);
            $table->string('node_type', 32)->comment('one of the thirteen (§IX.4) — closed catalogue, R-IX-020');
            $table->string('label', 191)->comment('R-IX-012: never empty; an unnamed step is an unexplainable step');
            $table->unsignedInteger('display_order')->default(0);
            // R-IX-011: schema-validated per node_type at publish. An unknown key
            // is a publish error, never a silent discard — SmartPRS 2's safeRow()
            // drops nine known fields with no error and no log.
            $table->json('params_json')->nullable();
            $table->text('guard_expr')->nullable();
            $table->string('requires_comment', 16)->default('never');
            $table->string('requires_attachment', 16)->default('never');
            $table->string('required_permission', 128)->nullable();
            $table->unsignedInteger('sla_minutes')->nullable();
            $table->string('sla_calendar_key', 64)->nullable();
            $table->json('reminder_schedule_json')->nullable();
            $table->string('escalation_policy', 32)->default('none');
            $table->string('escalation_target', 128)->nullable();
            // R-IX-064: "escalate once and then nothing" recreates the original
            // problem one level higher.
            $table->unsignedInteger('escalate_levels_max')->default(1);
            // R-IX-066: how late a timer may fire before an automatic decision
            // is WITHHELD. Nullable because only auto_approve/auto_reject nodes
            // need it, and W16 refuses those without one.
            $table->unsignedInteger('stale_threshold_minutes')->nullable();
            $table->string('reassignment_rights', 32)->default('none');
            $table->boolean('delegation_allowed')->default(true);
            $table->boolean('reassignment_resets_sla')->default(false)
                ->comment('R-IX-040: default false — a resettable clock makes SLA reporting meaningless');
            $table->string('on_no_participant', 32)->default('fail_instance');
            $table->boolean('is_terminal')->default(false);

            $table->unique(['tenant_id', 'workflow_payload_id', 'node_key'], 'workflow_nodes_unique');
        });

        Schema::create('workflow_node_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_node_id')->constrained('workflow_nodes')->cascadeOnDelete();

            $table->unsignedInteger('seq')->default(0);
            $table->string('kind', 32)->comment('role_in_scope|named_user|reporting_line|group|spx_expression + shorthands');
            $table->json('params_json')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->boolean('exclude_requester')->default(true);
            $table->boolean('exclude_prior_actors')->default(true);

            $table->unique(['tenant_id', 'workflow_node_id', 'seq'], 'workflow_node_participants_unique');
        });

        Schema::create('workflow_definition_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_payload_id')->constrained('workflow_payloads')->cascadeOnDelete();

            $table->string('from_node_key', 64);
            $table->string('outcome_code', 64);
            $table->string('to_node_key', 64);
            $table->text('condition_expr')->nullable();
            $table->string('label', 191)->nullable();
            // First match wins, in declared order — an ordered list an
            // administrator reads top to bottom is explainable; "whichever
            // matched" is not.
            $table->unsignedInteger('evaluation_order')->default(0);

            $table->unique(
                ['tenant_id', 'workflow_payload_id', 'from_node_key', 'outcome_code', 'evaluation_order'],
                'workflow_definition_transitions_unique',
            );
        });

        // ---- the runtime ------------------------------------------------------

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            // R-IX-051: an in-flight instance completes on the version it
            // started on. Publishing a new workflow version never re-routes
            // something already in flight, so the version is pinned here.
            $table->foreignId('config_version_id')->constrained('config_versions')->restrictOnDelete();
            $table->string('workflow_artefact_key', 128);
            $table->foreignId('status_value_id')->constrained('status_values')->restrictOnDelete()
                ->comment('domain workflow_instance_state — never a database ENUM');

            $table->string('subject_entity_key', 64);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('requester_user_id');
            $table->string('current_node_key', 64)->nullable();
            $table->string('instance_key', 191)->nullable()->comment('§IX.6.4 idempotency key');
            // R-IX-043: a correction is a NEW instance pointing at what it
            // supersedes. There is no transition out of a terminal state, so
            // SmartPRS 2's reopen — status='pending', decided_by=NULL,
            // decided_at=NULL — has nowhere to write.
            $table->unsignedBigInteger('supersedes_instance_id')->nullable();
            $table->unsignedInteger('next_seq')->default(1)->comment('allocated under a row lock on this row');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'workflow_instances_tenant_uuid_unique');
            $table->index(['tenant_id', 'subject_entity_key', 'subject_id'], 'workflow_instances_tenant_subject_index');
            $table->index(['tenant_id', 'status_value_id'], 'workflow_instances_tenant_status_index');
        });

        Schema::create('workflow_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->restrictOnDelete();
            $table->foreignId('status_value_id')->constrained('status_values')->restrictOnDelete()
                ->comment('domain workflow_task_state; pending is the only non-terminal one');

            $table->string('node_key', 64);
            $table->unsignedBigInteger('assignee_user_id');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            // Mechanism (1) of §IX.6.3. The decision statement carries
            // `AND status_code = 'pending' AND row_version = :expected`; zero
            // affected rows is 409 workflow.task_already_decided.
            $table->unsignedInteger('row_version')->default(1);
            $table->dateTime('due_at')->nullable();
            $table->unsignedBigInteger('supersedes_task_id')->nullable()
                ->comment('a reassigned task closes and a NEW row opens; the history is a chain of rows');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['tenant_id', 'assignee_user_id', 'status_value_id'], 'workflow_tasks_inbox_index');
            $table->index(['tenant_id', 'workflow_instance_id', 'node_key'], 'workflow_tasks_node_index');
            $table->index(['tenant_id', 'due_at'], 'workflow_tasks_sla_index');
        });

        // Mechanism (3): append-only, and a replayed decision cannot obtain a
        // second seq for the same instance.
        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->restrictOnDelete();

            $table->unsignedInteger('seq');
            $table->string('from_node_key', 64)->nullable();
            $table->string('outcome_code', 64);
            $table->string('to_node_key', 64)->nullable();
            $table->unsignedBigInteger('workflow_task_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('comment')->nullable()->comment('R-IX-046: append-only per transition, never overwritable');
            // R-IX-050: a decision arriving after the node is decided is still
            // written, as an attempt. That a second approver disagreed is
            // material even when it does not change the outcome.
            $table->boolean('was_attempt')->default(false);
            $table->json('resolution_json')->nullable()
                ->comment('R-IX-028: who resolved, from which spec, and every exclusion and substitution');
            $table->dateTime('occurred_at');

            $table->unique(['tenant_id', 'workflow_instance_id', 'seq'], 'workflow_transitions_seq_unique');
        });

        // Mechanism (4): even a hypothetical double-finalise cannot produce two
        // transfer orders. SmartPRS 2's transfer-order and increment-letter side
        // effects are unguarded and re-fire.
        Schema::create('workflow_completion_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->restrictOnDelete();

            $table->string('completion_event_key', 128);
            $table->json('payload_json');
            $table->dateTime('available_at');
            $table->dateTime('dispatched_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->unique(
                ['tenant_id', 'workflow_instance_id', 'completion_event_key'],
                'workflow_completion_outbox_unique',
            );
            $table->index(['tenant_id', 'dispatched_at', 'available_at'], 'workflow_completion_outbox_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_completion_outbox');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_definition_transitions');
        Schema::dropIfExists('workflow_node_participants');
        Schema::dropIfExists('workflow_nodes');
        Schema::dropIfExists('workflow_payloads');
    }
};
