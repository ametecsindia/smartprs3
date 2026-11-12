<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WORKING SHIFT IS SCOPED LIKE A PAY CYCLE.
 *
 * Organization Setup → Working Shifts: a shift for a company, a branch, a
 * department, a designation, a team or one employee — blank means ALL. The
 * ported table knew only the company. All nullable, ids into the org registers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shifts')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $table): void {
            foreach (['branch_id', 'department_id', 'designation_id', 'team_id', 'employee_id'] as $column) {
                if (!Schema::hasColumn('shifts', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->comment('scope: blank = all');
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
