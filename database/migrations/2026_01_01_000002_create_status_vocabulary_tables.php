<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine S16: "No database ENUM types. Status vocabularies are reference tables
 * with codes, so adding a value is data." SmartPRS 2 has twelve live cases where
 * code writes a value the column's ENUM forbids.
 *
 * PLATFORM-SCOPED SET (2 of 2): `status_domains` has no tenant_id. A domain is a
 * namespace the platform owns (`config_version_status`, `employment_status`,
 * `payroll_run_status`); the *values* inside it are tenant data, seeded per
 * tenant at provisioning, so a tenant adding HALF_DAY_LOP is a data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_domains', function (Blueprint $table): void {
            $table->id();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('domain_key', 64);
            $table->string('label', 191);
            $table->text('description')->nullable();
            $table->string('owning_context', 32)->comment('the bounded context that owns this vocabulary (V.3)');
            $table->boolean('is_tenant_extensible')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['domain_key'], 'status_domains_key_unique');
            $table->unique(['uuid'], 'status_domains_uuid_unique');
        });

        Schema::create('status_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('status_domain_id')->constrained('status_domains')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64);
            $table->string('label', 191);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_system')->default(false)->comment('seeded by the platform; a tenant may not delete it');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'status_domain_id', 'code'], 'status_values_tenant_domain_code_unique');
            $table->unique(['tenant_id', 'uuid'], 'status_values_tenant_uuid_unique');
            $table->index(['tenant_id', 'status_domain_id', 'sort'], 'status_values_tenant_domain_sort_index');
        });

        Schema::create('status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('status_domain_id')->constrained('status_domains')->restrictOnDelete();
            $table->foreignId('from_status_value_id')->constrained('status_values')->restrictOnDelete();
            $table->foreignId('to_status_value_id')->constrained('status_values')->restrictOnDelete();
            $table->string('permission_key', 128)->nullable()->comment('verb.resource gating this transition (S13)');
            $table->string('workflow_artefact_key', 128)->nullable()->comment('workflow to run instead of a direct move (S8)');
            $table->boolean('requires_reason')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['tenant_id', 'status_domain_id', 'from_status_value_id', 'to_status_value_id'],
                'status_transitions_tenant_domain_pair_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
        Schema::dropIfExists('status_values');
        Schema::dropIfExists('status_domains');
    }
};
