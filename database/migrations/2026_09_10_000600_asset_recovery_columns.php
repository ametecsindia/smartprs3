<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASSETS — RECOVERY ON RETURN (10 Sep): the owner asked that, once a damaged
 * or lost asset comes back, the Issue Tracker can record what is owed and
 * whether it has been settled — a separate question from `damage_resolution`
 * (what happened to the asset itself: waived off, replaced, written off...).
 *
 * `recovery_amount` — DECIMAL(18,4), Law 7: money is exact, never a float.
 * `recovery_status` — Paid / Waived off / On hold (config/assets.json
 * declares the words). Both nullable, additive, no data moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assets')) {
            Schema::table('assets', function (Blueprint $t): void {
                if (!Schema::hasColumn('assets', 'recovery_amount')) {
                    $t->decimal('recovery_amount', 18, 4)->nullable();
                }

                if (!Schema::hasColumn('assets', 'recovery_status')) {
                    $t->string('recovery_status')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
