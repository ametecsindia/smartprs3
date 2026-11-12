<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payload tables for the rule engine (Part VII) and the formula engine
 * (Part VIII).
 *
 * Spine S4: scope, priority, effective dates, version number, status and the
 * approval trail live on `config_versions` and are NOT repeated here. A payload
 * table carries only what is specific to its artefact type — which is why
 * "a new configurable concept is an artefact_type and a typed payload table,
 * never a second versioning mechanism" (R-V-050) stays true rather than being a
 * slogan. Every payload row points back at exactly one config_versions row with
 * a RESTRICT foreign key.
 *
 * The `*_ast` columns are the evaluated artefact, not the source. A published
 * expression evaluates its stored canonical AST, so a parser upgrade cannot
 * change what an already-published rule does (R-VII-009). The source is kept
 * beside it for reading and for diffing, and the two are bound by the checksum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Part VII: rules -------------------------------------------------

        Schema::create('rule_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('config_version_id')->constrained('config_versions')->restrictOnDelete();

            $table->string('decision_point', 64)->comment('§4.1 — fixes the symbol table and the action set');
            $table->string('module', 32)->comment('attendance|leave|payroll|compliance|platform');
            $table->text('business_description')
                ->comment('§4.1 mandatory, min 20 chars — printed in every trace. SmartPRS 2 l1_cut is a rule nobody can find');

            $table->text('when_src');
            $table->binary('when_ast');
            $table->text('except_src')->nullable();
            $table->binary('except_ast')->nullable();
            $table->json('then_json')->comment('the ordered action list, 1..20 (§7.7)');

            $table->string('on_conflict', 32)->default('highest_priority_only');
            $table->boolean('stop_on_match')->default(false);
            $table->string('severity', 16)->default('normal');
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_money_affecting')->default(false)
                ->comment('derived from the actions; forces requires_approval');
            $table->json('tags_json')->nullable();
            $table->string('source_ref', 255)->nullable()->comment('the SmartPRS 2 citation this replaces');
            $table->char('checksum', 64)->comment('SHA-256 over canonical AST + actions + scope + dates');
            $table->unsignedInteger('catalogue_version')->comment('R-VII-017: the function catalogue it compiled against');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'config_version_id'], 'rule_payloads_tenant_version_unique');
            $table->index(['tenant_id', 'decision_point'], 'rule_payloads_tenant_point_index');
        });

        // ---- Part VIII: formulas ---------------------------------------------

        Schema::create('formula_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('config_version_id')->constrained('config_versions')->restrictOnDelete();

            $table->string('calculation_point', 64);
            $table->string('module', 32);
            $table->text('business_description');

            $table->text('expression_src');
            $table->binary('expression_ast')->comment('the evaluated artefact (R-VII-009)');
            $table->string('result_type', 64)->comment('Money | Days | Rate<Money/Hours> | … — equal to the inferred type, never coerced');
            $table->string('unit', 32)->nullable()
                ->comment('ISO currency for Money; the day-length policy code for a duration');

            // §6.3. There is no default rounding anywhere in SmartPRS 3, so
            // there is no default here either: the columns are NOT NULL, and a
            // component formula that declares `none` is refused at publish.
            $table->string('rounding_mode', 16)->comment('half_up|half_down|half_even|up|down|ceil|floor|none — mandatory');
            $table->decimal('rounding_unit', 18, 4)->default('0.0100');
            $table->string('rounding_point', 16)->default('final');
            $table->string('reference_value', 16)->default('rounded')->comment('what a DEPENDENT reads');
            $table->string('formula_kind', 16)->default('component');
            $table->unsignedInteger('sequence_no')->default(0)
                ->comment('PRESENTATION order only. Evaluation order is topological (R-VIII-037)');

            $table->json('declared_slices_json');
            // R-VIII-012: derived by the compiler from the AST, never authored.
            // A hand-kept list is a second source of truth, and a stale one
            // breaks both cycle detection and the topological order.
            $table->json('depends_on_json');
            $table->string('severity', 16)->default('normal');
            $table->boolean('requires_approval')->default(false);
            $table->string('source_ref', 255)->nullable();
            $table->char('checksum', 64);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'config_version_id'], 'formula_payloads_tenant_version_unique');
            $table->index(['tenant_id', 'calculation_point'], 'formula_payloads_tenant_point_index');
        });

        // R-VIII-047: publication is refused with zero sample cases, and
        // R-VIII-049 re-runs the pinned cases of every transitive dependent when
        // anything they depend on changes. Both need the cases to be rows.
        Schema::create('formula_sample_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('formula_payload_id')->constrained('formula_payloads')->cascadeOnDelete();

            $table->string('case_name', 128);
            $table->string('case_class', 32)
                ->comment('nominal|boundary|zero|negative|proration|statutory_boundary (§9.10)');
            $table->string('sample_employee_code', 64);
            $table->string('period_code', 32);
            $table->date('as_of');
            $table->json('context_overrides_json')->nullable();
            // §9.10: "The expected value is asserted EXACTLY, in minor units",
            // not within a tolerance. A tolerance is how a rounding defect
            // survives a regression suite.
            $table->bigInteger('expected_value_minor');
            $table->string('expected_rounding_step', 64)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'formula_payload_id', 'case_name'], 'formula_sample_cases_unique');
        });

        // §14.5 / R-VIII-013: all twelve gates run on every publication and the
        // outcome of each is STORED — so a blocked publication is explainable
        // and a passed one is evidenced. Three years later, "the usual checks
        // were run" is not an answer about a payslip.
        Schema::create('config_publication_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('config_version_id')->constrained('config_versions')->restrictOnDelete();

            $table->string('gate_code', 16)->comment('G1..G12 for a formula, W01..W18 for a workflow');
            $table->string('gate_name', 128);
            $table->string('severity', 16)->comment('pass|lint|warn|block');
            $table->json('messages_json')->nullable();
            $table->dateTime('checked_at');

            $table->unique(['tenant_id', 'config_version_id', 'gate_code'], 'config_publication_checks_unique');
            $table->index(['tenant_id', 'severity'], 'config_publication_checks_tenant_severity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_publication_checks');
        Schema::dropIfExists('formula_sample_cases');
        Schema::dropIfExists('formula_payloads');
        Schema::dropIfExists('rule_payloads');
    }
};
