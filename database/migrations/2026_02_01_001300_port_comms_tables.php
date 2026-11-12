<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the comms tables.
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
        if (!Schema::hasTable('messages_log')) {
            Schema::create('messages_log', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('target')->nullable();
                $t->unsignedInteger('recipients')->nullable();
                $t->string('channels')->nullable();
                $t->text('message')->nullable();
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sms_settings')) {
            Schema::create('sms_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('provider')->nullable();
                $t->string('api_url')->nullable();
                $t->text('api_key')->nullable();
                $t->string('sender_id')->nullable();
                $t->string('dlt_entity_id')->nullable();
                $t->string('dlt_principal_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('sms_templates')) {
            Schema::create('sms_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('type')->nullable();
                $t->string('dlt_template_id')->nullable();
                $t->text('content')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('wa_settings')) {
            Schema::create('wa_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('provider')->nullable();
                $t->string('api_url')->nullable();
                $t->text('api_key')->nullable();
                $t->string('sender_number')->nullable();
                $t->string('waba_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('smartept_webhook_endpoints')) {
            Schema::create('smartept_webhook_endpoints', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('slug')->nullable();
                $t->text('secret')->nullable();
                $t->json('events')->nullable();
                $t->boolean('active')->nullable();
                $t->timestamp('last_received_at')->nullable();
                $t->string('last_event')->nullable();
                $t->string('last_status')->nullable();
                $t->unsignedInteger('received_count')->nullable();
                $t->unsignedInteger('accepted_count')->nullable();
                $t->unsignedInteger('rejected_count')->nullable();
                $t->timestamps();
            });
        }

    }
};
