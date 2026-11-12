<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEME TARGET AND ELIGIBILITY (owner, 29 Oct 2026: "clear settings for each of the scheme selected with target or
 * minimum collected, etc and eligible for incentive"). Commission & Incentive Schemes → Target & eligibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('incentive_schemes')) {
            return;
        }

        foreach (['target_amount' => 'decimal', 'min_collected' => 'decimal', 'pay_on' => 'string', 'eligible_salary_types' => 'string'] as $col => $type) {
            if (Schema::hasColumn('incentive_schemes', $col)) {
                continue;
            }
            Schema::table('incentive_schemes', function (Blueprint $table) use ($col, $type): void {
                $type === 'decimal' ? $table->decimal($col, 18, 4)->nullable() : $table->string($col)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
