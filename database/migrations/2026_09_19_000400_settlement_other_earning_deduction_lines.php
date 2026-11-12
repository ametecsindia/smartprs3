<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OTHER EARNINGS AND DEDUCTIONS ON A FULL AND FINAL SETTLEMENT.
 *
 * `settlements` already carries `encashments` (JSON) for the one kind of
 * exit-only earning the platform computed on its own. Everything else HR
 * adds at exit time — a one-time bonus, a notice-period recovery, an
 * asset-damage deduction — has nowhere to go, so the settlement screen has
 * no editable line for it. These two columns give it the same shape as
 * `encashments`: a JSON list of lines, each naming a component declared in
 * config/salary-components.json (Law 1 — no business literal in code) with
 * an amount and a note, never a free-text label typed at the moment of
 * settlement.
 *
 * Additive and nullable — a settlement recorded before this migration reads
 * back with an empty list on both columns (`Settlement::ported()` treats a
 * missing column the same as an empty JSON array), so nothing already
 * settled changes shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settlements')) {
            return;
        }

        Schema::table('settlements', function (Blueprint $t): void {
            if (!Schema::hasColumn('settlements', 'other_earnings')) {
                $t->json('other_earnings')->nullable()->after('encashments')
                    ->comment('ad-hoc earning lines added at settlement — component_code, amount, note');
            }

            if (!Schema::hasColumn('settlements', 'other_deductions')) {
                $t->json('other_deductions')->nullable()->after('other_earnings')
                    ->comment('ad-hoc deduction lines added at settlement — component_code, amount, note');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('settlements')) {
            return;
        }

        Schema::table('settlements', function (Blueprint $t): void {
            if (Schema::hasColumn('settlements', 'other_deductions')) {
                $t->dropColumn('other_deductions');
            }

            if (Schema::hasColumn('settlements', 'other_earnings')) {
                $t->dropColumn('other_earnings');
            }
        });
    }
};
