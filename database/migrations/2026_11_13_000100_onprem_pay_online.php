<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ON-PREM LICENCE PAID ONLINE (owner, 13 Nov 2026: "exactly as SmartPRS2") — the Razorpay order the public pay page
 * (public/licence.php) opened for the client's balance, so the payment that comes back is matched to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onprem_clients') && !Schema::hasColumn('onprem_clients', 'gateway_order_id')) {
            Schema::table('onprem_clients', static function (Blueprint $t): void {
                $t->string('gateway_order_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
