<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ENTITY SCHEMA ENGINE — Part X §X.2, Phase 3.
 *
 * The three tables that make "adding one new employee field touches zero code
 * files" true rather than aspirational:
 *
 *   `entity_field_definitions`   what a field IS — every attribute of X.2.1,
 *                                as data, versioned and effective-dated
 *   `entity_field_surface_rules` who sees it and who may write it, per surface
 *                                and per role (X.4.2)
 *   `entity_field_values`        where a custom field's value actually sits —
 *                                ONE typed row, and R-X-005's exactly-one-v_*
 *                                column
 *
 * R-X-004 is the reason `entity_field_values` exists at all: no application
 * request path, queued job, console command, installer step or seeder may
 * execute DDL. SmartPRS 2 creates 34 `employees` columns, six tables and several
 * master tables at request time, always untyped. A tenant administrator adding
 * "LinkedIn profile" here inserts one definition row, and nothing about the
 * schema changes.
 *
 * `audit_field_changes` is stage 9 of the write pipeline. It hangs off
 * `audit_entries` rather than duplicating the actor and the timestamp, so the
 * hash chain that protects the entry protects the field rows with it — and the
 * write fails if this write fails, because auditing is not best-effort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);

            // R-X-002: a field definition IS a config artefact, so it points at
            // the version that published it rather than carrying a second,
            // parallel versioning mechanism (R-V-050).
            $table->foreignId('config_version_id')->nullable()->constrained('config_versions')->restrictOnDelete();

            $table->string('entity_key', 32)->comment("'employee' today; the engine is not employee-specific");
            // R-X-007: immutable, and the join to the SPX symbol, the template
            // token, the import header, the export column and the API property.
            $table->string('field_key', 64);
            $table->string('label', 191);
            $table->text('description');
            $table->text('help_text')->nullable();

            $table->string('group_key', 64)->comment("value_set('employee_field_group') — a tenant may add a tab");
            $table->unsignedInteger('display_order')->default(0);

            $table->string('authoring_type_code', 32)->comment('one of the 32 (X.3.2)');
            $table->string('data_type_code', 32)->comment('one of the 16 storage types');
            $table->string('storage_kind_code', 16)->default('attribute')
                ->comment('column | attribute | generated — R-X-004: attribute needs no DDL');

            $table->string('default_visibility_code', 16)->default('hidden')
                ->comment('step 3 of resolution; the default default is HIDDEN (R-X-030)');
            $table->string('default_required_code', 16)->default('never');
            $table->text('default_expression')->nullable()->comment('SPX, evaluated at create time');

            $table->string('uniqueness_scope_code', 16)->default('none')
                ->comment('none|tenant|legal_entity|global — materialised as a real index (X.2.5)');
            $table->string('pii_class_code', 16)->default('none');
            $table->string('masking_rule_code', 16)->default('none');
            $table->boolean('is_encrypted')->default(false);
            $table->string('history_mode_code', 16)->default('audit_only')->comment('audit_only | dated (X.6.1)');

            $table->string('value_set_key', 64)->nullable();
            $table->json('constraints_json')->nullable()->comment('min/max, lengths, regex, scale, selections');
            $table->json('locked_attributes_json')->nullable()
                ->comment('R-X-009: what a tenant may not change about a system field');

            $table->boolean('is_system')->default(false);
            // R-X-008: renaming a key is not supported; "retire and replace"
            // leaves this link so historical values, audit rows, exports and
            // letters continue to resolve.
            $table->unsignedBigInteger('superseded_by_field_definition_id')->nullable();

            $table->json('scope_json')->nullable();
            $table->unsignedInteger('specificity')->default(0);
            $table->integer('priority')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version_no')->default(1);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'entity_field_definitions_tenant_uuid_unique');
            // Unique per (tenant, entity, key, version): the key is immutable
            // across versions, so a second version of the same field is a new
            // row and not an edit of this one.
            $table->unique(
                ['tenant_id', 'entity_key', 'field_key', 'version_no'],
                'entity_field_definitions_key_version_unique',
            );
            $table->index(['tenant_id', 'entity_key', 'group_key', 'display_order'], 'entity_field_definitions_group_index');
        });

        Schema::create('entity_field_surface_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('entity_field_definition_id')
                ->constrained('entity_field_definitions')->cascadeOnDelete();

            $table->string('surface_code', 32)->comment('one of the thirteen (R-X-029) — closed, platform-owned');
            // Nullable = the all-roles row, which is step 2 of resolution and is
            // strictly less specific than a row naming a role.
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('role_key', 64)->nullable();

            $table->string('visibility_code', 16)->comment('hidden | read | write');
            $table->string('required_code', 16)->default('never');
            $table->text('required_expression')->nullable()->comment('SPX; an error here DENIES (R-X-030)');
            $table->string('masking_override_code', 16)->nullable();
            $table->string('change_workflow_artefact_key', 128)->nullable()
                ->comment('spine S8: this change routes to maker-checker');

            // R-X-032, the directory surface only. A field hidden here cannot be
            // filtered or searched on, and the API REJECTS such a filter.
            $table->boolean('is_list_column')->default(false);
            $table->unsignedInteger('list_order')->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->boolean('is_quick_search')->default(false);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['tenant_id', 'entity_field_definition_id', 'surface_code', 'role_key'],
                'entity_field_surface_rules_unique',
            );
        });

        /**
         * R-X-005: exactly ONE `v_*` column is populated per row, determined by
         * `data_type_code`. The CHECK constraint is what makes that a guarantee
         * rather than a convention — a row with two populated columns has two
         * answers to "what is this employee's value", and the reader picks by
         * accident.
         */
        Schema::create('entity_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('entity_field_definition_id')
                ->constrained('entity_field_definitions')->restrictOnDelete();

            $table->string('entity_key', 32);
            $table->unsignedBigInteger('entity_id')->comment('the employment, person or other subject');
            $table->string('data_type_code', 32);

            $table->string('v_text', 255)->nullable();
            $table->text('v_long_text')->nullable();
            $table->bigInteger('v_number')->nullable();
            $table->decimal('v_decimal', 18, 4)->nullable();
            $table->date('v_date')->nullable();
            $table->dateTime('v_datetime')->nullable();
            $table->boolean('v_bool')->nullable();
            $table->string('v_code', 64)->nullable();
            $table->json('v_json')->nullable();
            // X.2.6: the lookup and uniqueness handle for an encrypted value.
            // The plaintext is never indexed and never appears here.
            $table->string('v_blind_index', 128)->nullable();

            $table->unsignedInteger('row_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['tenant_id', 'entity_key', 'entity_id', 'entity_field_definition_id'],
                'entity_field_values_unique',
            );
            $table->index(['tenant_id', 'entity_field_definition_id', 'v_text'], 'entity_field_values_text_index');
            $table->index(['tenant_id', 'entity_field_definition_id', 'v_code'], 'entity_field_values_code_index');
            $table->index(['tenant_id', 'entity_field_definition_id', 'v_date'], 'entity_field_values_date_index');
            $table->index(['tenant_id', 'v_blind_index'], 'entity_field_values_blind_index');
        });

        /**
         * Stage 9. One row per CHANGED FIELD, hanging off the audit entry that
         * carries the actor, the surface, the reason and the correlation id.
         *
         * SmartPRS 2's `storeEmployee()` writes no audit row at all, so "who
         * changed this employee's bank account, and to what" is unanswerable
         * there. Old and new travel together because either alone is not
         * evidence: "it was changed to X" without the previous value cannot
         * distinguish a correction from a substitution.
         */
        Schema::create('audit_field_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('audit_entry_id')->constrained('audit_entries')->restrictOnDelete();

            $table->string('entity_key', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('field_key', 64);
            $table->string('surface_code', 32);

            // Masked or hashed per PII class (X.2.6): a restricted field's audit
            // row records THAT it changed and a hash proving what it changed to,
            // never the value itself.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('old_value_hash', 64)->nullable();
            $table->string('new_value_hash', 64)->nullable();
            $table->string('pii_class_code', 16)->default('none');
            $table->string('outcome_code', 32)->comment('applied | cleared | routed_for_approval');

            $table->dateTime('created_at')->nullable();

            $table->index(['tenant_id', 'entity_key', 'entity_id', 'field_key'], 'audit_field_changes_subject_index');
            $table->index(['tenant_id', 'audit_entry_id'], 'audit_field_changes_entry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_field_changes');
        Schema::dropIfExists('entity_field_values');
        Schema::dropIfExists('entity_field_surface_rules');
        Schema::dropIfExists('entity_field_definitions');
    }
};
