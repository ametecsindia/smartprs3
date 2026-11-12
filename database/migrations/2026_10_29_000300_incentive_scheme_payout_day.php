<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SEPARATE PAYOUT ON A DAY OF THE MONTH, NOT ONE CALENDAR DATE (owner, 29 Oct 2026: a fixed date "might fix this
 * particular date of month and in next month it may refuse"). Each commission entry gets its own date: the scheme's
 * payout day in its payroll month or the month after. incentive_schemes.payout_date is kept (older schemes use its day).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('incentive_schemes')) {
            return;
        }

        foreach (['payout_day' => 'int', 'payout_offset' => 'string'] as $col => $type) {
            if (Schema::hasColumn('incentive_schemes', $col)) {
                continue;
            }
            Schema::table('incentive_schemes', function (Blueprint $table) use ($col, $type): void {
                $type === 'int' ? $table->unsignedTinyInteger($col)->nullable() : $table->string($col, 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
