<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the org tables.
 *
 * Generated from the schema SmartPRS 2's migrations actually build (captured by
 * executing them), with the corrections in docs/SCHEMA-CORRECTIONS.md applied.
 * Every correction is listed there; none is applied silently.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each create is guarded: MySQL DDL is not transactional, so a migration that stops
     * half way must repair itself on the next run rather than dying on the first table
     * that already exists. The guard is written out per table rather than factored into a
     * helper because the tenancy gate reads these names statically.
     */
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->string('type')->nullable();
                $t->string('pan')->nullable();
                $t->string('gstin')->nullable();
                $t->string('address')->nullable();
                $t->string('phone')->nullable();
                $t->string('email')->nullable();
                $t->string('website')->nullable();
                $t->string('color')->nullable();
                $t->string('logo_path')->nullable();
                $t->string('logo_text')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('city')->nullable();
                $t->string('pincode')->nullable();
                $t->decimal('lat')->nullable();
                $t->decimal('lng')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->unsignedBigInteger('head_employee_id')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('designations')) {
            Schema::create('designations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->unsignedBigInteger('department_id')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('company_emails')) {
            Schema::create('company_emails', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('department')->nullable();
                $t->string('from_name')->nullable();
                $t->string('from_email')->nullable();
                $t->text('signature')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('banks')) {
            Schema::create('banks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->timestamps();
            });
        }

    }
};
