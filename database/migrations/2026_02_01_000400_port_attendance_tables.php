<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the attendance tables.
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
        if (!Schema::hasTable('attendance')) {
            Schema::create('attendance', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->date('date')->nullable();
                $t->dateTime('punch_in')->nullable();
                $t->dateTime('punch_out')->nullable();
                $t->string('source', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('hours')->nullable();
                $t->integer('lates_mtd')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('device_id')->nullable();
                $t->boolean('geo_ok')->nullable();
                $t->boolean('biometric_ok')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('emp_code')->nullable();
                $t->string('emp_name')->nullable();
                $t->date('log_date')->nullable();
                $t->dateTime('punch_at')->nullable();
                $t->string('direction')->nullable();
                $t->string('source')->nullable();
                $t->string('device_sn')->nullable();
                $t->string('device_user_id')->nullable();
                $t->string('external_id')->nullable();
                $t->string('verify_mode')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_pending')) {
            Schema::create('attendance_pending', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('device_sn')->nullable();
                $t->string('device_user_id')->nullable();
                $t->dateTime('punch_at')->nullable();
                $t->string('direction')->nullable();
                $t->string('verify_mode')->nullable();
                $t->string('external_id')->nullable();
                $t->string('source')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->string('device_code')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('code')->nullable();
                $t->string('company_name')->nullable();
                $t->string('start_time')->nullable();
                $t->string('end_time')->nullable();
                $t->integer('grace_min')->nullable();
                $t->decimal('full_day_hours')->nullable();
                $t->decimal('half_day_hours')->nullable();
                $t->integer('break_budget')->nullable();
                $t->decimal('night_allowance', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('holidays')) {
            Schema::create('holidays', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->date('date')->nullable();
                $t->string('name')->nullable();
                $t->string('applies_to')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('name')->nullable();
                $t->string('unique_id')->nullable();
                $t->string('direction', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('api_url')->nullable();
                $t->text('api_key')->nullable();
                $t->string('location')->nullable();
                $t->timestamp('last_sync_at')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('biometric_configs')) {
            Schema::create('biometric_configs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('provider')->nullable();
                $t->boolean('enabled')->nullable();
                $t->string('base_url')->nullable();
                $t->string('endpoint')->nullable();
                $t->string('corp_id')->nullable();
                $t->string('username')->nullable();
                $t->text('password')->nullable();
                $t->string('empcode')->nullable();
                $t->string('emp_prefix')->nullable();
                $t->string('in_machine_id')->nullable();
                $t->string('out_machine_id')->nullable();
                $t->timestamp('last_sync_at')->nullable();
                $t->string('last_status')->nullable();
                $t->integer('last_count')->nullable();
                $t->string('label')->nullable();
                $t->string('branch')->nullable();
                $t->string('device_mode')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('geofence_rules')) {
            Schema::create('geofence_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('start', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('lat')->nullable();
                $t->decimal('lng')->nullable();
                $t->decimal('radius_km')->nullable();
                $t->string('outside', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('late_policy')) {
            Schema::create('late_policy', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->integer('grace_min')->nullable();
                $t->decimal('full_day_hours')->nullable();
                $t->integer('lates_before_cut')->nullable();
                $t->string('addl_late')->nullable();
                $t->string('weekoff_action')->nullable();
                $t->boolean('no_cut_on_weekoff')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('late_notifications')) {
            Schema::create('late_notifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('emp_code')->nullable();
                $t->date('log_date')->nullable();
                $t->integer('late_min')->nullable();
                $t->string('channel')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }

    }
};
