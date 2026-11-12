<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VERIFICATION SITS AT THE EMPLOYEE'S BRANCH (owner, 21 Oct 2026: "If the employee is selected, the
 * Company and Branch should automatically update"). `employer_company_id` came with the port; the branch
 * is new. Nullable — rows saved before this take the branch on their next save.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bgv') || Schema::hasColumn('bgv', 'branch_id')) {
            return;
        }

        Schema::table('bgv', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->comment('The employee\'s branch (branches.id); NULL = not set');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
