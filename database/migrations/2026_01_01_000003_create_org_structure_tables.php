<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine S3: one table holds a typed tree. Level types are data, seeded with the
 * platform ladder (platform → tenant → legal_entity → company → establishment →
 * state → zone → branch → department → sub_department → team) and extensible per
 * tenant. cost_centre, business_unit and client_process attach at any level.
 *
 * Adjacency (`parent_id`) is the source of truth; `path` is the materialised
 * ancestor chain, maintained by the write service in the same transaction as the
 * parent change, so subtree queries are one indexed LIKE rather than a recursion.
 *
 * ponytail: the org unit's *name history* (V.6.3 — "a department renamed in July
 * does not rewrite May's payslip") is not here. It belongs with the People
 * migration that introduces employment assignments, since nothing before that
 * can read it. Do not add a mutable `name` habit in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64)->comment('legal_entity|company|…|team|cost_centre — data, never an ENUM');
            $table->string('label', 191);
            $table->unsignedInteger('level_order')->comment('position in the ladder; ties are legal for free-attaching types');
            $table->string('scope_dimension', 64)->nullable()->comment('the spine S3.1 dimension this type targets');
            $table->boolean('attaches_at_any_level')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'code'], 'org_unit_types_tenant_code_unique');
            $table->unique(['tenant_id', 'uuid'], 'org_unit_types_tenant_uuid_unique');
        });

        Schema::create('org_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('org_unit_type_id')->constrained('org_unit_types')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('path', 255)->comment('materialised: /1/17/230/ of ancestor ids, self included');
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'code'], 'org_units_tenant_code_unique');
            $table->unique(['tenant_id', 'uuid'], 'org_units_tenant_uuid_unique');
            $table->index(['tenant_id', 'parent_id'], 'org_units_tenant_parent_index');
            $table->index(['tenant_id', 'org_unit_type_id'], 'org_units_tenant_type_index');
            $table->index(['tenant_id', 'path'], 'org_units_tenant_path_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
        Schema::dropIfExists('org_unit_types');
    }
};
