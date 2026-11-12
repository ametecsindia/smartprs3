<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the payroll tables.
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
        if (!Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('schedule_id')->nullable();
                $t->integer('lot')->nullable();
                $t->string('cycle_label')->nullable();
                $t->date('pay_date')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedInteger('employees_count')->nullable();
                $t->decimal('net_total', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->timestamp('locked_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('payslips')) {
            Schema::create('payslips', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->uuid('uuid')->nullable();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('run_id')->nullable();
                $t->string('month')->nullable();
                $t->json('earnings')->nullable();
                $t->json('deductions')->nullable();
                $t->decimal('gross', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('total_ded', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('net', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('net_words')->nullable();
                $t->timestamp('emailed_at')->nullable();
                $t->string('pdf_path')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('salary_components')) {
            Schema::create('salary_components', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('code')->nullable();
                $t->string('name')->nullable();
                $t->string('ctype', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('calc_type', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('calc_value')->nullable();
                $t->boolean('taxable')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('salary_schedules')) {
            Schema::create('salary_schedules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('pay_cycle')->nullable();
                $t->json('lots')->nullable();
                $t->string('applicable_to')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('deductions')) {
            Schema::create('deductions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('type', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('month')->nullable();
                $t->string('source_ref')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('advances')) {
            Schema::create('advances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('per_month', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('balance', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('loans')) {
            Schema::create('loans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('emi', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->integer('tenure')->nullable();
                $t->decimal('outstanding', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('type')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->date('date')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('bonus_encashment')) {
            Schema::create('bonus_encashment', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('type')->nullable();
                $t->string('basis')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('month')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('clawbacks')) {
            Schema::create('clawbacks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('portfolio')->nullable();
                $t->string('month')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('reason')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('payout_recon')) {
            Schema::create('payout_recon', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('bank')->nullable();
                $t->string('portfolio')->nullable();
                $t->string('month')->nullable();
                $t->decimal('expected', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('received', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('variance', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

    }
};
