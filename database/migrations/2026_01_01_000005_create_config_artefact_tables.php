<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine S4 / V.8 — the universal configuration artefact. Every configurable
 * concept in SmartPRS 3 is an instance of this: pay cycles, salary components,
 * formulas, rules, workflows, shifts, leave types, holiday calendars, gate
 * policies, templates, connector recipes, retention classes.
 *
 * A new configurable concept is registered by adding an artefact_type and a
 * typed payload table — never by inventing a second versioning mechanism
 * (R-V-050).
 *
 * Two deliberate omissions of a foreign key, both commented at the column:
 *  - the *_by_user_id columns, because the identity module's `users` table lands
 *    in a later migration; the constraints are added there, not skipped.
 *  - payload_table / payload_id, because the payload target is chosen by
 *    artefact_type and no single-target FK can express it. Referential integrity
 *    for payloads is asserted by the publish validator and a nightly integrity
 *    job, and every payload table's own FK to config_versions is RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_artefacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('artefact_type', 64)->comment('rule|formula|workflow|leave_type|… — registry data, never an ENUM');
            $table->string('artefact_key', 128);
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('owning_context', 32)->comment('the bounded context that owns it (V.8.3)');
            $table->boolean('is_high_risk')->default(false)->comment('S4: diff preview + approval required before publish');
            $table->boolean('is_continuous')->default(false)->comment('S4: a gap in the effective timeline is rejected');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'artefact_type', 'artefact_key'], 'config_artefacts_tenant_type_key_unique');
            $table->unique(['tenant_id', 'uuid'], 'config_artefacts_tenant_uuid_unique');
            $table->index(['tenant_id', 'artefact_type'], 'config_artefacts_tenant_type_index');
        });

        Schema::create('config_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // RESTRICT: a version referenced by an issued payslip, a settled
            // attendance day or a gate decision can never be deleted (V.8.2 A9).
            $table->foreignId('config_artefact_id')->constrained('config_artefacts')->restrictOnDelete();
            $table->foreignId('status_value_id')->constrained('status_values')->restrictOnDelete()
                ->comment('domain config_version_status: draft|testing|pending_approval|published|superseded|archived');
            $table->foreignId('superseded_by_version_id')->nullable()->constrained('config_versions')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->unsignedInteger('version_no');
            $table->json('scope_json')->nullable()->comment('canonical Scope, for display and diffing (V.5.2)');
            $table->unsignedInteger('specificity')->default(0)->comment('derived from the S3.2 weight table; never hand-entered');
            $table->integer('priority')->default(0)->comment('S3.2 tie-break 2; a further tie blocks publication');
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('inclusive; NULL means in force until superseded (V.5.3)');
            $table->string('payload_table', 64)->nullable();
            $table->unsignedBigInteger('payload_id')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->text('reason')->nullable();
            $table->text('change_note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'config_artefact_id', 'version_no'], 'config_versions_tenant_artefact_no_unique');
            $table->unique(['tenant_id', 'uuid'], 'config_versions_tenant_uuid_unique');
            $table->index(
                ['tenant_id', 'config_artefact_id', 'effective_from', 'effective_to'],
                'config_versions_resolution_index',
            );
            $table->index(['tenant_id', 'status_value_id'], 'config_versions_tenant_status_index');
        });

        Schema::create('config_version_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // CASCADE: predicates are part of the version, not a record of their own.
            $table->foreignId('config_version_id')->constrained('config_versions')->cascadeOnDelete();
            $table->string('dimension', 64)->comment('one of the spine S3.1 dimensions');
            $table->string('operator', 8)->default('IN');
            $table->string('value', 191);
            $table->unsignedInteger('weight')->default(0)->comment('the S3.2 weight of this dimension, denormalised for indexed ordering');

            $table->unique(
                ['tenant_id', 'config_version_id', 'dimension', 'value'],
                'config_version_scopes_tenant_version_dimension_value_unique',
            );
            $table->index(['tenant_id', 'dimension', 'value'], 'config_version_scopes_tenant_dimension_value_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_version_scopes');
        Schema::dropIfExists('config_versions');
        Schema::dropIfExists('config_artefacts');
    }
};
