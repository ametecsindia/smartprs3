<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the leave tables.
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
        if (!Schema::hasTable('leaves')) {
            Schema::create('leaves', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('type_id')->nullable();
                $t->date('from_date')->nullable();
                $t->date('to_date')->nullable();
                $t->decimal('days')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('reason')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->decimal('days_per_year')->nullable();
                $t->boolean('carry_forward')->nullable();
                $t->boolean('paid')->nullable();
                $t->timestamps();
            });
        }

    }
};
