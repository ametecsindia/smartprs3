<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SELF-SERVE SIGNUP & CHECKOUT (owner, 12 Nov 2026: smartprs.com's /signup, SmartPRS 2 logic) — what SmartPRS 2's
 * SignupController keeps on a signup besides the plan: the buyer's state and GSTIN (GST split on the invoice), the web
 * address they chose, the one-time setup & onboarding charge, the Razorpay payment id, and the workspace it became.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('signups')) {
            return;
        }

        foreach ([
            'state' => fn (Blueprint $t) => $t->string('state', 60)->nullable(),
            'gstin' => fn (Blueprint $t) => $t->string('gstin', 20)->nullable(),
            'subdomain' => fn (Blueprint $t) => $t->string('subdomain', 30)->nullable(),
            'setup_charge' => fn (Blueprint $t) => $t->decimal('setup_charge', 18, 4)->nullable(),
            'gateway_payment_id' => fn (Blueprint $t) => $t->string('gateway_payment_id')->nullable(),
            'tenant_ref' => fn (Blueprint $t) => $t->unsignedBigInteger('tenant_ref')->nullable()->comment('The workspace (tenants.id) this signup became'),
        ] as $column => $add) {
            if (!Schema::hasColumn('signups', $column)) {
                Schema::table('signups', static function (Blueprint $t) use ($add): void {
                    $add($t);
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
