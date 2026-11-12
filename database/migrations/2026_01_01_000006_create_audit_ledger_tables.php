<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine Law 5 / S14 — the audit ledger. Append-only, hash-chained per tenant,
 * with a daily signed anchor so a chain break is detectable and attributable to
 * a date.
 *
 * `audit_entries` has created_at and no updated_at, on purpose: there is no
 * update path. The application database user is granted INSERT and SELECT on
 * this table only — no UPDATE, no DELETE — and the AuditService is the sole
 * writer. SmartPRS 2's chain is already broken in four places precisely because
 * other code inserts here directly.
 *
 * tenant_id is RESTRICT, not CASCADE: deleting a tenant must not be able to
 * delete its evidence. Purge is a governed, audited retention operation (S16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->unsignedBigInteger('seq')->comment('gapless per-tenant chain position, allocated FOR UPDATE');
            $table->dateTime('occurred_at')->comment('UTC instant (S16)');
            $table->date('business_on')->comment('the tenant business date the action fell on');

            $table->unsignedBigInteger('actor_user_id')->nullable()->comment('FK added with the identity migration');
            $table->string('actor_label', 191)->nullable()->comment('who, as displayed at the time; never resolved later');
            $table->string('actor_ip', 45)->nullable();
            $table->string('actor_device', 191)->nullable();

            $table->string('action', 128)->comment('verb.resource, matching the permission vocabulary (S13)');
            $table->string('entity_type', 128);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->binary('entity_uuid', length: 16, fixed: true)->nullable();

            $table->json('old_value')->nullable()->comment('field-level before, mandatory for employee/salary/bank/role/statutory/rule');
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('workflow_instance_id')->nullable()->comment('FK added with the workflow migration');
            $table->foreignId('rule_version_id')->nullable()->constrained('config_versions')->restrictOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->char('prev_hash', 64)->nullable()->comment('NULL only for a tenant chain genesis entry');
            $table->char('hash', 64);
            $table->dateTime('created_at');

            $table->unique(['tenant_id', 'seq'], 'audit_entries_tenant_seq_unique');
            $table->unique(['tenant_id', 'hash'], 'audit_entries_tenant_hash_unique');
            $table->unique(['tenant_id', 'uuid'], 'audit_entries_tenant_uuid_unique');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'audit_entries_tenant_entity_index');
            $table->index(['tenant_id', 'occurred_at'], 'audit_entries_tenant_occurred_index');
            $table->index(['tenant_id', 'action'], 'audit_entries_tenant_action_index');
            $table->index(['tenant_id', 'actor_user_id'], 'audit_entries_tenant_actor_index');
        });

        Schema::create('audit_anchors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->date('anchor_on');
            $table->unsignedBigInteger('first_seq');
            $table->unsignedBigInteger('last_seq');
            $table->unsignedBigInteger('entry_count');
            $table->char('chain_head_hash', 64)->comment('the hash of the last entry of that day');
            $table->char('anchor_hash', 64)->comment('HashChain::anchor() over tenant, date, head and count');
            $table->text('signature')->nullable()->comment('signed by the Ametecs anchor key; the private key never leaves Ametecs (S12/S18)');
            $table->string('public_key_id', 64)->nullable();
            $table->dateTime('verified_at')->nullable()->comment('last successful full-chain verification for this day');
            $table->dateTime('created_at');

            $table->unique(['tenant_id', 'anchor_on'], 'audit_anchors_tenant_date_unique');
            $table->unique(['tenant_id', 'anchor_hash'], 'audit_anchors_tenant_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_anchors');
        Schema::dropIfExists('audit_entries');
    }
};
