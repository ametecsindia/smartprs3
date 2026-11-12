<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SCHEME'S OWN PAYOUT DATE (owner, 28 Oct 2026: "if separate payout is selected, should have option to enter
 * the custom date"). Shown on Commission & Incentive Schemes only for a Separate payout; the Commission Calculator
 * copies it onto every entry it creates (commissions.payout_date already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('incentive_schemes') || Schema::hasColumn('incentive_schemes', 'payout_date')) {
            return;
        }

        Schema::table('incentive_schemes', function (Blueprint $table): void {
            $table->date('payout_date')->nullable()->comment('Separate payout date');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
