<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the learning tables.
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
        if (!Schema::hasTable('training_programs')) {
            Schema::create('training_programs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->string('category')->nullable();
                $t->string('mode')->nullable();
                $t->boolean('mandatory')->nullable();
                $t->string('validity')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('training_records')) {
            Schema::create('training_records', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('program_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('completed_on')->nullable();
                $t->decimal('score')->nullable();
                $t->date('expiry')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('training_subjects')) {
            Schema::create('training_subjects', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('program_id')->nullable();
                $t->string('module')->nullable();
                $t->string('subject')->nullable();
                $t->decimal('hours')->nullable();
                $t->text('content')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('tests')) {
            Schema::create('tests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('name')->nullable();
                $t->string('category')->nullable();
                $t->string('target_type')->nullable();
                $t->string('target')->nullable();
                $t->unsignedInteger('questions')->nullable();
                $t->decimal('pass_mark')->nullable();
                $t->date('scheduled_on')->nullable();
                $t->unsignedBigInteger('linked_program_id')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('test_attempts')) {
            Schema::create('test_attempts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->unsignedBigInteger('test_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->decimal('score')->nullable();
                $t->date('attempted_on')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('kb_topics')) {
            Schema::create('kb_topics', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('category')->nullable();
                $t->string('icon')->nullable();
                $t->string('title')->nullable();
                $t->text('body')->nullable();
                $t->json('roles')->nullable();
                $t->integer('sort')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('category')->nullable();
                $t->string('question')->nullable();
                $t->text('answer')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('helpdesk')) {
            Schema::create('helpdesk', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // renamed: which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('category')->nullable();
                $t->string('subject')->nullable();
                $t->string('priority')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->timestamps();
            });
        }

    }
};
