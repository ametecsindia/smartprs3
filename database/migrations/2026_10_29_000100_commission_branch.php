<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMMISSION ENTRY'S BRANCH (owner, 29 Oct 2026: "if Company or branch is selected … it applies to the respective
 * company or branch employees with Commission as their salary type"). Commission Entries offer Company and Branch;
 * an entry saved for one employee takes that employee's branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commissions') || Schema::hasColumn('commissions', 'branch_id')) {
            return;
        }

        Schema::table('commissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->comment('Branch of the employee the entry is for');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
