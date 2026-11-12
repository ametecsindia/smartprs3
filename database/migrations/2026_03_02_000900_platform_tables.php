<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAAS PLATFORM at SmartPRS 2 parity.
 *
 * Two tables SmartPRS 2 kept were never ported: `quotations` (a priced offer
 * to a lead, payable by link) and the feature-flag register. Both are
 * platform-wide — a quotation exists BEFORE the tenant does, and a flag row
 * carrying a NULL tenant is the global default — so neither carries the
 * tenant FK the ported per-tenant tables do; both are declared in
 * TableRegistry::PLATFORM_TABLES.
 *
 * Columns the ported tables lack for the screens: an invoice's subscription
 * and period, a coupon's window start, a lead's converted tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->unsignedBigInteger('plan_id')->nullable();
                $t->string('company')->nullable();
                $t->string('email')->nullable();
                $t->string('state', 60)->nullable(); // buyer's GST state, decides the split
                $t->unsignedInteger('seats')->nullable();
                $t->string('cycle', 20)->nullable();
                $t->string('coupon_code', 40)->nullable();
                $t->decimal('subtotal', 18, 4)->nullable();
                $t->decimal('discount', 18, 4)->nullable();
                $t->decimal('tax', 18, 4)->nullable();
                $t->decimal('total', 18, 4)->nullable();
                $t->date('valid_till')->nullable();
                $t->string('status', 20)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('converted_tenant_id')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('feature_flags')) {
            Schema::create('feature_flags', function (Blueprint $t): void {
                $t->id();
                $t->string('flag_key', 64); // NOT NULL: a flag with no key is nothing
                // NOT `tenant_id`: the platform owns this row and points it AT a
                // tenant (NULL = the global default), as licences point at a client.
                $t->unsignedBigInteger('tenant_ref')->nullable();
                $t->string('value', 8)->nullable(); // on|off
                $t->string('note')->nullable();
                $t->string('updated_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        foreach ([
            'invoices' => ['subscription_id' => 'unsignedBigInteger', 'period_from' => 'date', 'period_to' => 'date', 'notes' => 'string'],
            'coupons' => ['valid_from' => 'date'],
            'leads' => ['converted_tenant_id' => 'unsignedBigInteger'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column => $type) {
                    if (!Schema::hasColumn($table, $column)) {
                        $t->{$type}($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('feature_flags');
    }
};
