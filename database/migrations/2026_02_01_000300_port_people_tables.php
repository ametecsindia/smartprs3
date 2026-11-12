<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the people tables.
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
        if (!Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->uuid('uuid')->nullable();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('emp_code')->nullable();
                $t->string('name')->nullable();
                $t->date('dob')->nullable();
                $t->string('gender')->nullable();
                $t->string('mobile')->nullable();
                $t->boolean('mobile_verified')->nullable();
                $t->string('whatsapp')->nullable();
                $t->boolean('wa_verified')->nullable();
                $t->string('email')->nullable();
                $t->boolean('email_verified')->nullable();
                $t->string('address')->nullable();
                $t->unsignedBigInteger('department_id')->nullable();
                $t->unsignedBigInteger('designation_id')->nullable();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('team_id')->nullable();
                $t->string('type', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('reporting_manager_id')->nullable();
                $t->date('doj')->nullable();
                $t->decimal('ctc', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('salary_type', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('comm_pct')->nullable();
                $t->unsignedBigInteger('schedule_id')->nullable();
                $t->boolean('pf_applicable')->nullable();
                $t->string('esi_applicable', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('pt_state')->nullable();
                $t->string('uan')->nullable();
                $t->string('pan')->nullable();
                $t->string('dra_status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('dra_expiry')->nullable();
                $t->string('pcc_status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('pcc_expiry')->nullable();
                $t->date('pcc_deadline')->nullable();
                $t->string('docs_status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->boolean('docs_stored')->nullable();
                $t->decimal('home_lat')->nullable();
                $t->decimal('home_lng')->nullable();
                $t->string('geo_start', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('geo_radius_km')->nullable();
                $t->string('geo_outside', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('bank_name')->nullable();
                $t->string('bank_acc')->nullable();
                $t->string('ifsc')->nullable();
                $t->string('device_user_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamp('deleted_at')->nullable();
                $t->string('department')->nullable();
                $t->string('designation')->nullable();
                $t->string('branch')->nullable();
                $t->string('team')->nullable();
                $t->string('reporting_manager')->nullable();
                $t->string('team_leader')->nullable();
                $t->string('shift')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->string('archived_by')->nullable();
                $t->timestamp('backup_due_at')->nullable();
                $t->string('backup_by')->nullable();
                $t->string('dra_declared')->nullable();
                $t->string('pcc_declared')->nullable();
                $t->string('esic_no')->nullable();
                $t->string('employment_type')->nullable();
                $t->string('marital_status')->nullable();
                $t->string('father')->nullable();
                $t->string('nationality')->nullable();
                $t->string('aadhaar')->nullable();
                $t->string('permanent_address')->nullable();
                $t->string('emergency_name')->nullable();
                $t->string('emergency_phone')->nullable();
                $t->string('blood_group')->nullable();
                $t->string('category')->nullable();
                $t->string('national_id')->nullable();
                $t->string('id_marks')->nullable();
                $t->string('account_holder')->nullable();
                $t->string('bank_branch')->nullable();
                $t->string('also_works_for')->nullable();
                $t->string('employment_stage')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('employee_archives')) {
            Schema::create('employee_archives', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('emp_code')->nullable();
                $t->string('name')->nullable();
                $t->longText('snapshot')->nullable();
                $t->string('archived_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('employee_company')) {
            Schema::create('employee_company', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('employee_references')) {
            Schema::create('employee_references', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('name')->nullable();
                $t->string('relation')->nullable();
                $t->string('aadhaar')->nullable();
                $t->string('pan')->nullable();
                $t->string('mobile')->nullable();
                $t->string('doc_path')->nullable();
                $t->boolean('verify_email')->nullable();
                $t->boolean('verify_sms')->nullable();
                $t->boolean('verify_call')->nullable();
                $t->boolean('verify_whatsapp')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('kind')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('expiry')->nullable();
                $t->string('file_path')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('letters')) {
            Schema::create('letters', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('letter_type')->nullable();
                $t->date('issued_on')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('pdf_path')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('transfers')) {
            Schema::create('transfers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('type')->nullable();
                $t->string('from_company')->nullable();
                $t->string('from_branch')->nullable();
                $t->string('to_company')->nullable();
                $t->unsignedBigInteger('to_company_id')->nullable();
                $t->string('to_branch')->nullable();
                $t->string('to_department')->nullable();
                $t->date('effective_date')->nullable();
                $t->string('reason')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamp('applied_at')->nullable();
                $t->string('accept_token')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->timestamp('order_sent_at')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('remarks')->nullable();
                $t->string('fin_year')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('increments')) {
            Schema::create('increments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('cycle')->nullable();
                $t->decimal('old_ctc', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('new_ctc', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('pct')->nullable();
                $t->date('effective')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('approver_name')->nullable();
                $t->string('decided_by')->nullable();
                $t->string('remarks')->nullable();
                $t->unsignedBigInteger('approver_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('exits')) {
            Schema::create('exits', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->date('last_day')->nullable();
                $t->decimal('advances_recovered', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('dues', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('fnf_amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->boolean('assets_returned')->nullable();
                $t->boolean('exit_interview')->nullable();
                $t->string('rehire', 64)->nullable(); // vocabulary-checked, not free text or an enum
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

        if (!Schema::hasTable('assets')) {
            Schema::create('assets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('asset_type')->nullable();
                $t->string('asset_id')->nullable();
                $t->unsignedBigInteger('issued_to_employee_id')->nullable();
                $t->date('issue_date')->nullable();
                $t->date('return_date')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('bgv')) {
            Schema::create('bgv', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('agency')->nullable();
                $t->string('checks')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('completed_on')->nullable();
                $t->timestamps();
            });
        }

    }
};
