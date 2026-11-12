<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the identity tables.
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
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->timestamp('email_verified_at')->nullable();
                $t->string('password')->nullable();
                $t->rememberToken('rememberToken')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('function')->nullable();
                $t->unsignedBigInteger('manager_id')->nullable();
                $t->unsignedBigInteger('leader_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('key')->nullable();
                $t->json('value')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('action')->nullable();
                $t->string('entity')->nullable();
                $t->unsignedBigInteger('entity_id')->nullable();
                $t->text('detail')->nullable();
                $t->string('ip')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->string('name')->nullable();
                $t->string('prefix')->nullable();
                $t->string('key_hash')->nullable();
                $t->json('scopes')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->boolean('active')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('notifications_feed')) {
            Schema::create('notifications_feed', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('type')->nullable();
                $t->string('message')->nullable();
                $t->string('link')->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }

    }
};
