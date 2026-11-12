<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine S3: classification dimensions are NOT tree nodes. designation, grade,
 * employee_category, employment_type, employment_stage, shift, location,
 * pay_group and union/settlement group are orthogonal attributes of an
 * employment, effective-dated on the assignment row — never a column on the
 * employee.
 *
 * `classification_types.scope_dimension` is what binds a tenant's vocabulary to
 * the fixed weight table of spine S3.2, so Scope::specificity() and the database
 * agree on what a dimension is called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64);
            $table->string('label', 191);
            $table->string('scope_dimension', 64)->nullable()->comment('spine S3.1 dimension key, or NULL if not scopeable');
            $table->boolean('is_hierarchical')->default(false)->comment('grades ladder; employee categories do not');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'code'], 'classification_types_tenant_code_unique');
            $table->unique(['tenant_id', 'uuid'], 'classification_types_tenant_uuid_unique');
        });

        Schema::create('classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('classification_type_id')->constrained('classification_types')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('classifications')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64);
            $table->string('label', 191);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'classification_type_id', 'code'], 'classifications_tenant_type_code_unique');
            $table->unique(['tenant_id', 'uuid'], 'classifications_tenant_uuid_unique');
            $table->index(['tenant_id', 'classification_type_id', 'sort'], 'classifications_tenant_type_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
        Schema::dropIfExists('classification_types');
    }
};
