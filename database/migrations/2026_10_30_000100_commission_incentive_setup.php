<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMISSION SETUP AND INCENTIVE SETUP (owner, 30 Oct 2026): one incentive_schemes table, two screens in Organization
 * Configuration. scheme_kind says which screen a row belongs to (a row without one is an older scheme, no longer
 * offered); the Applies-to filters gain Role, Designation and Employee; TDS becomes "applicable?" + Flat ₹ or %.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('incentive_schemes')) {
            return;
        }

        $cols = [
            'scheme_kind' => fn (Blueprint $t) => $t->string('scheme_kind', 20)->nullable(),
            'scope_role' => fn (Blueprint $t) => $t->string('scope_role', 120)->nullable(),
            'designation_id' => fn (Blueprint $t) => $t->unsignedBigInteger('designation_id')->nullable(),
            'employee_id' => fn (Blueprint $t) => $t->unsignedBigInteger('employee_id')->nullable(),
            'tds_applicable' => fn (Blueprint $t) => $t->boolean('tds_applicable')->nullable(),
            'tds_mode' => fn (Blueprint $t) => $t->string('tds_mode', 10)->nullable(),
            'tds_flat' => fn (Blueprint $t) => $t->decimal('tds_flat', 18, 4)->nullable(), // money — read through Money, never a float
        ];

        foreach ($cols as $col => $add) {
            if (!Schema::hasColumn('incentive_schemes', $col)) {
                Schema::table('incentive_schemes', function (Blueprint $table) use ($add): void {
                    $add($table);
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
