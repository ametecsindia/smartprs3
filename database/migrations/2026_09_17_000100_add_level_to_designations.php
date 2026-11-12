<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DESIGNATION CARRIES A SENIORITY LEVEL, 0-50.
 *
 * A plain number, set per designation under Organization Setup → Role /
 * Designation Setup — for ranking and org-chart display only. It does NOT
 * decide which modules a profile can reach: two designations in different
 * departments (an HR Executive and a Field Executive, say) can both sit at
 * level 0 without colliding, because module/screen access is a separate
 * question answered by User Administration → Roles & Permissions, keyed off
 * the designation's Role, never off this number. Nullable and editable
 * through the existing Designations module, same as `role` and `grade`
 * before it — every designation already on file can have its Level filled
 * in without a second import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('designations') && !Schema::hasColumn('designations', 'level')) {
            Schema::table('designations', function (Blueprint $table): void {
                $table->unsignedTinyInteger('level')->nullable()
                    ->comment('seniority level, 0-50 — display/ranking only, never access control');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
