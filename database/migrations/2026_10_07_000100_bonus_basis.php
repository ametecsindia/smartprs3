<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BONUS & ENCASHMENT BASIS AS A SELECTION (owner, 7 Oct): "the basis is text
 * without any validation — make it proper selection along with the field name".
 * `basis` (existing) now holds fixed / percent / days; these two say OF WHAT
 * (a salary component code) and HOW MUCH (the % or the number of days), so
 * payroll works the amount out instead of somebody typing it. Nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bonus_encashment')) {
            return;
        }

        Schema::table('bonus_encashment', function (Blueprint $table): void {
            if (!Schema::hasColumn('bonus_encashment', 'basis_component')) {
                $table->string('basis_component', 32)->nullable()->comment('Salary component code the % / days are of (BASIC, GROSS, CTC …)');
            }

            if (!Schema::hasColumn('bonus_encashment', 'basis_value')) {
                $table->decimal('basis_value', 18, 4)->nullable()->comment('The % or the number of days');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
