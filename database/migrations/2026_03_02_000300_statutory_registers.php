<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STATUTORY & COMPLIANCE REGISTERS at SmartPRS 2 parity.
 *
 * SmartPRS 2 keeps PF/ESI applicability per employee (UAN, ESI number, from
 * when), TDS declarations (regime, PAN, investments), a POSH complaints register
 * (confidential, separate from customer complaints), notified minimum wages
 * by state / zone / category, a statutory bonus register and a quarterly TDS
 * return tracker with acknowledgement. None of those tables were ported; the
 * compliance calendar's "filed" marks need a home too. All nullable, every new
 * table carries the tenant FK exactly like the ported attendance tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pf_esi_registrations')) {
            Schema::create('pf_esi_registrations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id'); // NOT NULL: a registration is somebody's
                $t->string('pf_applicable', 16)->nullable();
                $t->string('uan', 32)->nullable();
                $t->string('pf_number', 64)->nullable();
                $t->date('pf_from')->nullable();
                $t->string('esi_applicable', 16)->nullable();
                $t->string('esi_number', 32)->nullable();
                $t->date('esi_from')->nullable();
                $t->string('dispensary')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('tds_declarations')) {
            Schema::create('tds_declarations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id'); // NOT NULL: a declaration is somebody's
                $t->string('financial_year', 16)->nullable();
                $t->string('pan', 16)->nullable();
                $t->string('regime', 16)->nullable();
                $t->decimal('section_80c', 18, 4)->nullable();
                $t->decimal('section_80d', 18, 4)->nullable();
                $t->decimal('hra_exempt', 18, 4)->nullable();
                $t->decimal('other_exempt', 18, 4)->nullable();
                $t->decimal('declared_tds', 18, 4)->nullable();
                $t->date('declared_on')->nullable();
                $t->string('verified_by')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('posh_complaints')) {
            Schema::create('posh_complaints', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->string('subject'); // NOT NULL: a complaint with no subject is not one
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('respondent')->nullable();
                $t->date('incident_date')->nullable();
                $t->text('description')->nullable();
                $t->text('ic_comment')->nullable();
                $t->date('closed_on')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('min_wages')) {
            Schema::create('min_wages', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->string('state', 64); // NOT NULL: a notification is a state's
                $t->string('zone', 64)->nullable();
                $t->string('category', 64)->nullable();
                $t->decimal('monthly_min', 18, 4)->nullable();
                $t->decimal('daily_min', 18, 4)->nullable();
                $t->date('effective_from')->nullable();
                $t->string('notification_ref')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('statutory_bonus_register')) {
            Schema::create('statutory_bonus_register', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id'); // NOT NULL: a bonus is somebody's
                $t->string('financial_year', 16)->nullable();
                $t->decimal('bonus_wage', 18, 4)->nullable();
                $t->decimal('bonus_pct', 18, 4)->nullable();
                $t->decimal('amount', 18, 4)->nullable();
                $t->date('paid_on')->nullable();
                $t->string('paid_by')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('compliance_filings')) {
            Schema::create('compliance_filings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->string('obligation', 64); // NOT NULL: a filing is of an obligation
                $t->string('period', 16)->nullable();
                $t->date('filed_on')->nullable();
                $t->string('reference')->nullable();
                $t->decimal('amount', 18, 4)->nullable();
                $t->string('filed_by')->nullable();
                $t->string('status', 64)->nullable();
                $t->string('remarks')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (Schema::hasTable('tds_returns')) {
            Schema::table('tds_returns', static function (Blueprint $t): void {
                foreach (['financial_year' => 'string', 'filed_on' => 'date', 'acknowledgement' => 'string',
                    'remarks' => 'string', 'deleted_at' => 'datetime'] as $column => $type) {
                    if (!Schema::hasColumn('tds_returns', $column)) {
                        match ($type) {
                            'date' => $t->date($column)->nullable(),
                            'datetime' => $t->dateTime($column)->nullable(),
                            default => $t->string($column)->nullable(),
                        };
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
