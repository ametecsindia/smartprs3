<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPER ADMIN, LICENSING AND INTEGRATIONS at SmartPRS 2 parity (owner, 16 Oct 2026).
 *
 * - `platform_users`: the Super Admin's own logins (/super). NOT `users`: a users row
 *   carries a tenant FK, and the platform account belongs to no tenant.
 * - `smartept_webhook_endpoints`: SmartEPT's signed attendance webhook (Time & Attendance →
 *   API Keys & Integrations), per tenant.
 * - `punch_receipts`: the idempotency ledger every machine punch claims first (unique
 *   tenant + external id), so a retried batch is a duplicate and never a second punch.
 * - The licence desk columns SmartPRS 2 added at request time (invoice, term, expiry
 *   mode, seats, server limit, mismatch), the workspace slug the mobile app types
 *   (tenants.subdomain), and the employee code a phone was registered for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_users')) {
            Schema::create('platform_users', function (Blueprint $t): void {
                $t->id();
                $t->string('name')->nullable();
                $t->string('email', 191);
                $t->string('password');
                $t->string('role', 32)->default('super_admin'); // super_admin | staff
                $t->dateTime('disabled_at')->nullable();
                $t->dateTime('last_login_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['email'], 'platform_users_email_uq');
            });
        }

        if (!Schema::hasTable('smartept_webhook_endpoints')) {
            Schema::create('smartept_webhook_endpoints', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->unsignedBigInteger('employer_company_id')->nullable();
                $t->string('name')->nullable();
                $t->string('slug', 32);
                $t->text('secret');
                $t->json('events')->nullable();
                $t->boolean('active')->default(true);
                $t->dateTime('last_received_at')->nullable();
                $t->string('last_event', 64)->nullable();
                $t->string('last_status')->nullable();
                $t->unsignedInteger('received_count')->default(0);
                $t->unsignedInteger('accepted_count')->default(0);
                $t->unsignedInteger('rejected_count')->default(0);
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['slug'], 'smartept_webhook_endpoints_slug_uq');
            });
        }

        if (!Schema::hasTable('punch_receipts')) {
            Schema::create('punch_receipts', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('external_id', 120);
                $t->dateTime('created_at')->nullable();
                $t->unique(['tenant_id', 'external_id'], 'punch_receipts_tenant_ext_uq');
            });
        }

        Schema::table('tenants', function (Blueprint $t): void {
            $t->string('subdomain', 60)->nullable();
        });

        Schema::table('onprem_clients', function (Blueprint $t): void {
            $t->string('invoice_no', 30)->nullable();
            $t->string('invoice_token', 64)->nullable();
            $t->date('invoice_date')->nullable();
            $t->integer('licence_term_months')->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->string('expiry_mode', 12)->nullable();
            $t->unsignedInteger('seats')->nullable();
        });

        Schema::table('licences', function (Blueprint $t): void {
            $t->unsignedInteger('server_limit')->nullable();
            $t->dateTime('last_mismatch_at')->nullable();
            $t->unsignedInteger('seats')->nullable();
        });

        Schema::table('mobile_devices', function (Blueprint $t): void {
            $t->string('emp_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped (law 3).
    }
};
