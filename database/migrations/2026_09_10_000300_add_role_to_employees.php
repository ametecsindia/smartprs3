<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN EMPLOYEE'S OWN ROLE, SEPARATE FROM THEIR DESIGNATION.
 *
 * Designation (Collections Executive, Team Leader) is the job title; Role is
 * set per employee, independently — two people holding the same designation
 * can carry different roles. Shown beside Designation on the Directory, the
 * Add/Edit Employee form, the bulk upload sample and import, the org filter
 * bar, and the ID card.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'role')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('role')->nullable()
                    ->comment('this employee\'s own role, set independently of their designation');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
