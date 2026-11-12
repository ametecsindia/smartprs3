<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the offroll tables.
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
        if (!Schema::hasTable('offroll_agents')) {
            Schema::create('offroll_agents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('vendor')->nullable();
                $t->string('mobile')->nullable();
                $t->string('payout_type')->nullable();
                $t->decimal('rate', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('dra')->nullable();
                $t->string('pcc')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('agent_authorizations')) {
            Schema::create('agent_authorizations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('bank')->nullable();
                $t->string('portfolio')->nullable();
                $t->string('auth_no')->nullable();
                $t->date('valid_to')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('complaints')) {
            Schema::create('complaints', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('source')->nullable();
                $t->string('channel')->nullable();
                $t->string('severity')->nullable();
                $t->text('description')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('date')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('escalations')) {
            Schema::create('escalations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->date('date')->nullable();
                $t->string('bank')->nullable();
                $t->string('team')->nullable();
                $t->string('issue')->nullable();
                $t->text('reason')->nullable();
                $t->string('level')->nullable();
                $t->string('severity')->nullable();
                $t->string('priority', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('penalty', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('action_taken')->nullable();
                $t->unsignedBigInteger('handled_by')->nullable();
                $t->date('updated_on')->nullable(); // was DATETIME in SmartPRS 2
                $t->date('resolved_on')->nullable(); // was DATETIME in SmartPRS 2
                $t->timestamps();
            });
        }

    }
};
