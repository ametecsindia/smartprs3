<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the onboarding tables.
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
        if (!Schema::hasTable('self_onboarding')) {
            Schema::create('self_onboarding', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->uuid('uuid')->nullable();
                $t->string('token')->nullable();
                $t->string('temp_emp_code')->nullable();
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('candidate_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('mode')->nullable();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->boolean('email_verified')->nullable();
                $t->string('mobile')->nullable();
                $t->boolean('mobile_verified')->nullable();
                $t->string('whatsapp')->nullable();
                $t->boolean('wa_verified')->nullable();
                $t->json('data')->nullable();
                $t->json('flags')->nullable();
                $t->string('selfie_path')->nullable();
                $t->unsignedTinyInteger('progress')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('pin_hash')->nullable();
                $t->timestamp('link_expires_at')->nullable();
                $t->timestamp('link_disabled_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('self_onboarding_docs')) {
            Schema::create('self_onboarding_docs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('self_onboarding_id')->nullable();
                $t->string('kind')->nullable();
                $t->string('path')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('self_onboarding_otps')) {
            Schema::create('self_onboarding_otps', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('self_onboarding_id')->nullable();
                $t->string('channel')->nullable();
                $t->string('code_hash')->nullable();
                $t->unsignedTinyInteger('attempts')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('code_of_conduct_ack')) {
            Schema::create('code_of_conduct_ack', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->boolean('acknowledged')->nullable();
                $t->date('ack_date')->nullable();
                $t->timestamps();
            });
        }

    }
};
