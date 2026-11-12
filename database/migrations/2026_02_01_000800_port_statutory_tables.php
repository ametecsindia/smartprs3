<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the statutory tables.
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
        if (!Schema::hasTable('pf_esi_settings')) {
            Schema::create('pf_esi_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->decimal('pf_ee_pct')->nullable();
                $t->decimal('pf_er_pct')->nullable();
                $t->decimal('eps_pct')->nullable();
                $t->decimal('esi_ee_pct')->nullable();
                $t->decimal('esi_er_pct')->nullable();
                $t->decimal('esi_ceiling', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pt_slabs')) {
            Schema::create('pt_slabs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('state', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('wage_from', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('wage_to', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('tds_rules')) {
            Schema::create('tds_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->decimal('incentive_pct', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('commission_pct', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('salary_tds_mode')->nullable();
                $t->boolean('higher_if_no_pan')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('tds_returns')) {
            Schema::create('tds_returns', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('quarter')->nullable();
                $t->unsignedInteger('deductees')->nullable();
                $t->decimal('tax_deducted', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('deposited', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->date('due_date')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

    }
};
