<?php

declare(strict_types=1);

/**
 * PORTED FROM SmartPRS 2 — the commercial tables.
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
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable();
                $t->string('name')->nullable();
                $t->unsignedBigInteger('plan_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedInteger('seats_used')->nullable();
                $t->unsignedInteger('seats_licensed')->nullable();
                $t->decimal('mrr', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('deployment', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('owner_email')->nullable();
                $t->string('subdomain')->nullable();
                $t->timestamp('trial_ends_at')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $t) {
                $t->id();
                $t->string('name')->nullable();
                $t->decimal('base_price', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('per_user_price', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('billing_cycle', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->unsignedInteger('seat_max')->nullable();
                $t->json('features')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('plan_id')->nullable();
                $t->unsignedInteger('seats')->nullable();
                $t->string('cycle')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('current_period_end')->nullable();
                $t->date('next_renewal')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->uuid('uuid')->nullable();
                $t->string('number')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->decimal('tax', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->date('issued_on')->nullable();
                $t->date('due_on')->nullable();
                $t->string('gateway')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->uuid('uuid')->nullable();
                $t->unsignedBigInteger('invoice_id')->nullable();
                $t->decimal('amount', 18, 4)->nullable(); // money — read through Money, never as a float
                $t->string('gateway')->nullable();
                $t->string('method')->nullable();
                $t->string('gateway_txn_id')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('payment_gateways')) {
            Schema::create('payment_gateways', function (Blueprint $t) {
                $t->id();
                $t->string('gateway')->nullable();
                $t->string('mode', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->string('key_id')->nullable();
                $t->text('secret')->nullable();
                $t->string('webhook_url')->nullable();
                $t->string('status', 64)->nullable(); // vocabulary-checked, not free text or an enum
                $t->timestamps();
            });
        }

    }
};
