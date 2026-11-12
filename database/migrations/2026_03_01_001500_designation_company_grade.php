<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DESIGNATION HAS A COMPANY AND A GRADE / BAND.
 *
 * SmartPRS 2's Designations screen: Designation, Department, Grade / Band, per
 * company. The ported table had the department link only. Both nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('designations')) {
            return;
        }

        Schema::table('designations', function (Blueprint $table): void {
            if (!Schema::hasColumn('designations', 'employer_company_id')) {
                $table->unsignedBigInteger('employer_company_id')->nullable()->comment('which employer within the tenant offers this title');
            }

            if (!Schema::hasColumn('designations', 'grade')) {
                $table->string('grade', 32)->nullable()->comment('grade / band, e.g. A, B, C or L1, L2');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
