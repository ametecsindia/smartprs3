<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the workflow tables.
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
        if (!Schema::hasTable('attendance_corrections')) {
            Schema::create('attendance_corrections', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('emp_code')->nullable();
                $t->string('emp_name')->nullable();
                $t->date('log_date')->nullable();
                $t->string('sub_type')->nullable();
                $t->time('orig_in')->nullable();
                $t->time('orig_out')->nullable();
                $t->time('req_in')->nullable();
                $t->time('req_out')->nullable();
                $t->time('app_in')->nullable();
                $t->time('app_out')->nullable();
                $t->string('reason')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('remarks')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->boolean('payroll_locked')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('employee_import_jobs')) {
            Schema::create('employee_import_jobs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('token')->nullable();
                $t->string('filename')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('dup_mode')->nullable();
                $t->unsignedInteger('total_rows')->nullable();
                $t->unsignedInteger('created_count')->nullable();
                $t->unsignedInteger('updated_count')->nullable();
                $t->unsignedInteger('skipped_count')->nullable();
                $t->unsignedInteger('error_count')->nullable();
                $t->text('mapping')->nullable();
                $t->text('errors')->nullable();
                $t->string('imported_by')->nullable();
                $t->timestamp('imported_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('notification_log')) {
            Schema::create('notification_log', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('kind')->nullable();
                $t->string('title')->nullable();
                $t->text('body')->nullable();
                $t->string('url')->nullable();
                $t->string('channels')->nullable();
                $t->string('email_status')->nullable();
                $t->unsignedBigInteger('mail_log_id')->nullable();
                $t->string('dedupe_key')->nullable();
                $t->text('meta')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('probation_events')) {
            Schema::create('probation_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('action')->nullable();
                $t->string('details')->nullable();
                $t->date('effective')->nullable();
                $t->string('by')->nullable();
                $t->timestamp('at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('statutory_configs')) {
            Schema::create('statutory_configs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('scope')->nullable();
                $t->string('scope_value')->nullable();
                $t->string('kind')->nullable();
                $t->text('payload')->nullable();
                $t->date('effective_from')->nullable();
                $t->string('note')->nullable();
                $t->string('created_by')->nullable();
                $t->timestamps();
            });
        }

    }
};
