<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the incentive tables.
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
        if (!Schema::hasTable('incentive_schemes')) {
            Schema::create('incentive_schemes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->string('portfolio')->nullable();
                $t->string('basis')->nullable();
                $t->json('slabs')->nullable();
                $t->boolean('clawback')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('commissions')) {
            Schema::create('commissions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('portfolio')->nullable();
                $t->string('month')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('commission_logs')) {
            Schema::create('commission_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('commission_id')->nullable();
                $t->string('action')->nullable();
                $t->string('details')->nullable();
                $t->string('by')->nullable();
                $t->timestamp('at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('commission_payments')) {
            Schema::create('commission_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('commission_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->date('paid_on')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('mode')->nullable();
                $t->string('reference')->nullable();
                $t->string('note')->nullable();
                $t->string('by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('points_ledger')) {
            Schema::create('points_ledger', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('event')->nullable();
                $t->string('category', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->integer('points')->nullable();
                $t->date('date')->nullable();
                $t->string('note')->nullable();
                $t->string('source_ref')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('point_rules')) {
            Schema::create('point_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('event')->nullable();
                $t->string('category', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->integer('points')->nullable();
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }

    }
};
